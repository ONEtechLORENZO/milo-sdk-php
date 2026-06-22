<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Config;
use Milo\Sdk\Exception\ApiException;
use Milo\Sdk\Responses\ConversationState;
use Milo\Sdk\Responses\ExportResult;
use Milo\Sdk\Responses\MessageResult;
use Milo\Sdk\Responses\Reply;
use Milo\Sdk\Responses\SendResult;
use Milo\Sdk\Transport\Transporter;

/**
 * The runtime data plane (`/v1/*`) for one tenant + api-client: send a message,
 * poll for the reply, read conversation state/history, close. HMAC-signed per
 * request (docs/API.md §2). Obtain via `$milo->messaging($tenant, $clientId)`.
 *
 * Identity is external-first: pass a stable `external_sender_id` and Milo
 * resolves the internal contact + active conversation. The same sender id
 * continues the same conversation; a closed conversation transparently opens a
 * new one (see {@see SendResult::wasReopened()}).
 *
 * Auth is a bearer key (`Authorization: Bearer milo_sk_…`) — no per-request
 * signature, timestamp, or replay window; send it only over HTTPS.
 */
final class Messaging extends Resource
{
    public function __construct(
        Transporter $transporter,
        Config $config,
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $apiKey,
        private readonly ?string $defaultTaskId = null,
    ) {
        parent::__construct($transporter, $config);
    }

    /**
     * Submit one inbound message (`POST /v1/messages`).
     *
     * @param array{
     *   task_id?:string, external_sender_id?:string, sender_id?:string,
     *   external_sender_name?:string, channel?:string, channel_account_id?:string,
     *   conversation_id?:string, external_message_id?:string, metadata?:array<string,mixed>
     * } $options
     */
    public function send(string $text, array $options = []): SendResult
    {
        $externalMessageId = $options['external_message_id'] ?? self::uuid();
        $payload = $this->buildMessagePayload($text, $options, $externalMessageId);

        try {
            $response = $this->signedWrite('/v1/messages', $payload);
        } catch (\Milo\Sdk\Exception\MiloException $e) {
            // Surface the (possibly auto-generated) id so the caller can retry
            // the same message safely — writes are not auto-retried by the SDK.
            $e->externalMessageId ??= $externalMessageId;
            throw $e;
        }

        return SendResult::from($response->data)->bindPoller($this, $this->tenantId);
    }

    /**
     * Submit one message and get the reply (or pending tool calls) on the SAME
     * call — the SYNCHRONOUS interactive path (`POST /v1/messages` with
     * `sync:true`). For a command-bar / live chat where the user waits; bypasses
     * the debounce grouping (which means no message grouping, by design). Returns a
     * {@see MessageResult} with status `completed` | `tool_calls_pending` | `failed`.
     * Use {@see runToolsSync()} to drive a client-tool turn end to end.
     *
     * @param array{
     *   task_id?:string, external_sender_id?:string, sender_id?:string,
     *   external_sender_name?:string, channel?:string, channel_account_id?:string,
     *   conversation_id?:string, external_message_id?:string, metadata?:array<string,mixed>
     * } $options
     */
    public function sendSync(string $text, array $options = []): MessageResult
    {
        $externalMessageId = $options['external_message_id'] ?? self::uuid();
        $payload = $this->buildMessagePayload($text, $options, $externalMessageId);
        $payload['sync'] = true;

        try {
            $response = $this->signedWrite('/v1/messages', $payload);
        } catch (\Milo\Sdk\Exception\MiloException $e) {
            $e->externalMessageId ??= $externalMessageId;
            throw $e;
        }

        return MessageResult::from($response->data);
    }

    /**
     * Build the `POST /v1/messages` body shared by send()/sendSync(). Validates the
     * required task_id + external sender identity.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function buildMessagePayload(string $text, array $options, string $externalMessageId): array
    {
        $taskId = $options['task_id'] ?? $this->defaultTaskId;
        if ($taskId === null || $taskId === '') {
            throw new \InvalidArgumentException('Messaging requires a task_id (pass task_id in $options or set a default).');
        }

        $senderId = $options['external_sender_id'] ?? $options['sender_id'] ?? null;
        if ($senderId === null || $senderId === '') {
            throw new \InvalidArgumentException('Messaging requires an external_sender_id (the end-user identity).');
        }

        $payload = [
            'tenant_id' => $this->tenantId,
            'task_id' => $taskId,
            'channel' => $options['channel'] ?? 'api',
            'channel_account_id' => $options['channel_account_id'] ?? 'default',
            'external_message_id' => $externalMessageId,
            'external_sender' => array_filter([
                'id' => $senderId,
                'name' => $options['external_sender_name'] ?? null,
            ], static fn ($v) => $v !== null),
            'message' => [
                'type' => 'text',
                'text' => $text,
                'timestamp' => self::nowIso(),
            ],
        ];
        if (isset($options['conversation_id'])) {
            $payload['conversation_id'] = $options['conversation_id'];
        }
        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        return $payload;
    }

    /** Poll the reply for a submitted message (`GET /v1/messages/{id}/result`). */
    public function result(string $externalMessageId): MessageResult
    {
        $path = '/v1/messages/' . rawurlencode($externalMessageId) . '/result';
        $response = $this->signedRead($path);

        return MessageResult::from($response->data);
    }

    /** Conversation state (`GET /v1/conversations/{id}`). */
    public function conversation(string $conversationId): ConversationState
    {
        $path = '/v1/conversations/' . rawurlencode($conversationId);

        return ConversationState::from($this->signedRead($path)->data);
    }

    /**
     * Active message history, newest-last (`GET /v1/conversations/{id}/messages`).
     *
     * @return array<int,Reply>
     */
    public function messages(string $conversationId): array
    {
        $path = '/v1/conversations/' . rawurlencode($conversationId) . '/messages';
        $data = $this->signedRead($path)->data;
        $list = $data['messages'] ?? [];

        return array_map(
            static fn (array $row): Reply => Reply::from($row),
            is_array($list) ? array_values(array_filter($list, 'is_array')) : [],
        );
    }

    /**
     * Poll the CONVERSATION for an assistant reply and return its
     * {@see ConversationState}. Use this (not {@see SendResult::poll()} /
     * {@see result()}) to await a reply: Milo debounce-groups inbound messages, so
     * the reply is keyed by a grouped id and the by-`external_message_id` result
     * endpoint won't find it — but `conversation_state.last_reply` always has it.
     *
     * Returns as soon as a non-empty reply is present and (when `$newerThanIso` is
     * given) its `replied_at` is later than that timestamp — pass the previous
     * turn's `replied_at` (a server timestamp) to wait for the NEXT reply without
     * any client-clock dependency. On timeout it returns the last observed state;
     * the caller checks {@see ConversationState::hasReply()}.
     */
    public function pollConversation(
        string $conversationId,
        ?string $newerThanIso = null,
        int $maxAttempts = 30,
        float $intervalSeconds = 1.0,
        float $initialDelaySeconds = 0.0,
    ): ConversationState {
        if ($initialDelaySeconds > 0) {
            usleep((int) ($initialDelaySeconds * 1_000_000));
        }
        // Compare to second precision so a fractional/`Z` vs no-fraction format
        // mismatch can't cause a false negative; turns are always >= the debounce
        // window apart, so the new reply lands in a later second.
        $floor = $newerThanIso === null ? null : substr($newerThanIso, 0, 19);
        $state = $this->conversation($conversationId);
        for ($i = 0; ; $i++) {
            if ($state->hasReply()
                && ($floor === null || substr((string) $state->repliedAt(), 0, 19) > $floor)) {
                return $state;
            }
            if ($i + 1 >= $maxAttempts) {
                return $state;
            }
            usleep((int) ($intervalSeconds * 1_000_000));
            $state = $this->conversation($conversationId);
        }
    }

    /**
     * Submit CLIENT-executed tool results to resume a paused turn
     * (`POST /v1/conversations/{id}/tool-results`). `$externalMessageId` is the
     * `pending_external_message_id` from the conversation state (the grouped id the
     * parked turn is keyed by — debounce means it differs from the send id);
     * `$toolResults` maps each `tool_call_id` to your tool's output. Returns the
     * 202 body; the reply (or the next tool round) is read by polling the
     * conversation. {@see SendResult::runTools()} drives the whole loop for you.
     *
     * @param array<string,mixed> $toolResults
     * @return array<string,mixed>
     */
    public function submitToolResults(string $conversationId, string $externalMessageId, array $toolResults): array
    {
        $path = '/v1/conversations/' . rawurlencode($conversationId) . '/tool-results';

        return $this->signedWrite($path, [
            'external_message_id' => $externalMessageId,
            'tool_results' => $toolResults,
        ])->data;
    }

    /**
     * Submit CLIENT-executed tool results and get the next step on the SAME call
     * (SYNCHRONOUS: `POST /v1/conversations/{id}/tool-results` with `sync:true`).
     * Returns a {@see MessageResult} — `completed` (the reply) or another
     * `tool_calls_pending` round. The async {@see submitToolResults()} returns a
     * 202 and you poll; this returns the resumed turn inline.
     *
     * @param array<string,mixed> $toolResults
     */
    public function submitToolResultsSync(string $conversationId, string $externalMessageId, array $toolResults): MessageResult
    {
        $path = '/v1/conversations/' . rawurlencode($conversationId) . '/tool-results';

        return MessageResult::from($this->signedWrite($path, [
            'sync' => true,
            'external_message_id' => $externalMessageId,
            'tool_results' => $toolResults,
        ])->data);
    }

    /**
     * Drive a full CLIENT-tool turn SYNCHRONOUSLY and return the final
     * {@see MessageResult}. Starting from a `tool_calls_pending` result (from
     * {@see sendSync()}), run each call via
     * `$executor(string $name, array $input, string $toolCallId): mixed` and submit
     * results, repeating until a final reply — the OpenAI propose→execute→submit
     * loop, inline (no polling). `$result` is returned unchanged if it isn't
     * pending. The sync external_message_id IS the parked-turn key (no debounce
     * grouping), so each round resumes cleanly.
     */
    public function runToolsSync(MessageResult $result, callable $executor, int $maxRounds = 8): MessageResult
    {
        for ($round = 0; $round < $maxRounds && $result->isToolCallsPending(); $round++) {
            $conv = (string) $result->conversationId;
            $extId = (string) $result->externalMessageId;
            $results = [];
            foreach ($result->toolCalls() as $call) {
                $id = (string) ($call['tool_call_id'] ?? '');
                $results[$id] = $executor((string) ($call['name'] ?? ''), (array) ($call['input'] ?? []), $id);
            }
            $result = $this->submitToolResultsSync($conv, $extId, $results);
        }

        return $result;
    }

    /**
     * Close a conversation (terminal). Idempotent; a reopen attempt is `410`.
     *
     * The deployed route is `POST /v1/conversations/close` with
     * `conversation_id` in the BODY (not the path) — see
     * `infra/infra/infra_stack.py` (CloseRequestModel requires tenant_id +
     * conversation_id) and the close Lambda which reads `conversation_id` from
     * the body. The signed canonical path is therefore `/v1/conversations/close`.
     *
     * @return array<string,mixed>
     */
    public function close(string $conversationId, ?string $reason = null, ?string $taskId = null): array
    {
        $body = array_filter([
            'tenant_id' => $this->tenantId,
            'conversation_id' => $conversationId,
            'task_id' => $taskId ?? $this->defaultTaskId,
            'client_id' => $this->clientId,
            'close_reason' => $reason,
        ], static fn ($v) => $v !== null);

        return $this->signedWrite('/v1/conversations/close', $body)->data;
    }

    /**
     * Retrieve the final conversation export package
     * (`GET /v1/conversations/export`), for tasks configured with
     * `conversation_export.mode = poll | client_storage`.
     *
     * Returns an {@see ExportResult}: `not_ready` (still building — keep polling),
     * `ready` ({@see ExportResult::package()} has the transcript), or `purged`
     * (handed back + deleted). The endpoint's 404/410 are mapped to not_ready/
     * purged so you branch on status, not exceptions. `task_id` is sent because a
     * task-scoped bearer key is allowlisted per task.
     */
    public function export(string $conversationId, ?string $taskId = null): ExportResult
    {
        $query = array_filter([
            'tenant_id' => $this->tenantId,
            'task_id' => $taskId ?? $this->defaultTaskId,
            'conversation_id' => $conversationId,
        ], static fn ($v) => $v !== null && $v !== '');

        try {
            return ExportResult::from($this->signedRead('/v1/conversations/export', $query)->data);
        } catch (ApiException $e) {
            // 404 = export not built yet; 410 = already purged. Both carry a
            // content-free status body — surface it as a status, not an error.
            if ($e->status === 404 || $e->status === 410) {
                $body = $e->body;
                if (!isset($body['status'])) {
                    $body['status'] = $e->status === 410 ? 'purged' : 'not_ready';
                }

                return ExportResult::from($body);
            }
            throw $e;
        }
    }

    /**
     * Acknowledge retrieval of the export package so Milo purges its content copy
     * now rather than waiting for the retention timer
     * (`POST /v1/conversations/export/ack`, discriminated by an `action` field).
     * Idempotent; a no-op once already purged.
     *
     * @return array<string,mixed>
     */
    public function acknowledgeExport(string $conversationId, ?string $taskId = null): array
    {
        $body = array_filter([
            'action' => 'ack_export',
            'tenant_id' => $this->tenantId,
            'task_id' => $taskId ?? $this->defaultTaskId,
            'conversation_id' => $conversationId,
        ], static fn ($v) => $v !== null);

        return $this->signedWrite('/v1/conversations/export/ack', $body)->data;
    }

    // --- auth helpers ---------------------------------------------------------

    /** @return array<string,string> */
    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey];
    }

    /**
     * @param array<string,mixed> $body
     */
    private function signedWrite(string $path, array $body): \Milo\Sdk\Transport\Response
    {
        $raw = Transporter::encodeJson($body);

        // retry:false — POST writes are idempotent server-side via external_message_id,
        // but a blind transport retry is unsafe; the caller re-sends with the same
        // external_message_id instead (surfaced on a thrown exception).
        return $this->transporter->request('POST', $path, null, [], $this->authHeaders(), retry: false, rawBody: $raw);
    }

    /** @param array<string,scalar> $query */
    private function signedRead(string $path, array $query = []): \Milo\Sdk\Transport\Response
    {
        return $this->transporter->request('GET', $path, null, $query, $this->authHeaders(), retry: true);
    }

    /** ISO-8601 UTC second precision, for the message content timestamp. */
    private static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s') . 'Z';
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
