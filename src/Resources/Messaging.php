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
        $taskId = $options['task_id'] ?? $this->defaultTaskId;
        if ($taskId === null || $taskId === '') {
            throw new \InvalidArgumentException('Messaging::send() requires a task_id (pass task_id in $options or set a default).');
        }

        $senderId = $options['external_sender_id'] ?? $options['sender_id'] ?? null;
        if ($senderId === null || $senderId === '') {
            throw new \InvalidArgumentException('Messaging::send() requires an external_sender_id (the end-user identity).');
        }

        // Resolved up front so it can be surfaced on a failed send for an
        // idempotent retry (the server dedupes on external_message_id).
        $externalMessageId = $options['external_message_id'] ?? self::uuid();

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
