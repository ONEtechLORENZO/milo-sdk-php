<?php

declare(strict_types=1);

namespace Milo\Sdk\Responses;

use Milo\Sdk\Resources\Messaging;

/**
 * The `202` response to `POST /v1/messages` (docs/API.md §3.1). `status` is
 * `accepted` or `duplicate`. When the target conversation was closed, Milo mints
 * a fresh `conversation_id` and reports the prior one in
 * `previous_conversation_id` — callers MUST switch to the new id
 * ({@see wasReopened()}).
 */
final class SendResult extends Response
{
    public ?string $status = null;
    public ?string $conversationId = null;
    public ?string $externalMessageId = null;
    public ?string $previousConversationId = null;
    public ?string $resultStatus = null;

    /** Set by {@see Messaging::send()} so {@see poll()} can retrieve the reply. */
    private ?Messaging $messaging = null;
    private ?string $tenantId = null;

    protected function hydrate(): void
    {
        $this->status = isset($this->attributes['status']) ? (string) $this->attributes['status'] : null;
        $this->conversationId = $this->attributes['conversation_id'] ?? null;
        $this->externalMessageId = $this->attributes['external_message_id'] ?? null;
        $this->previousConversationId = $this->attributes['previous_conversation_id'] ?? null;
        $this->resultStatus = $this->attributes['result_status'] ?? null;
    }

    public function bindPoller(Messaging $messaging, string $tenantId): self
    {
        $this->messaging = $messaging;
        $this->tenantId = $tenantId;

        return $this;
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }

    /** True when a new conversation was opened because the prior one was closed. */
    public function wasReopened(): bool
    {
        return $this->previousConversationId !== null && $this->previousConversationId !== '';
    }

    /**
     * Await the assistant reply by polling the CONVERSATION — the reliable path,
     * since Milo debounce-groups inbound messages and the by-id result endpoint
     * (used by {@see poll()}) can't see a grouped reply. Reads the current reply
     * timestamp as a baseline first so it returns THIS turn's reply, not a prior
     * one (server timestamps only — no client clock). Returns a
     * {@see ConversationState}; check `->hasReply()` / `->replyText()`.
     */
    public function waitForReply(int $maxAttempts = 30, float $intervalSeconds = 1.0): ConversationState
    {
        if ($this->messaging === null || $this->conversationId === null) {
            throw new \LogicException('waitForReply() requires a SendResult produced by Messaging::send()');
        }
        $baseline = $this->messaging->conversation($this->conversationId)->repliedAt();

        return $this->messaging->pollConversation(
            $this->conversationId,
            $baseline,
            $maxAttempts,
            $intervalSeconds,
            $intervalSeconds, // skip the debounce window before the first check
        );
    }

    /**
     * Poll the by-id result endpoint until the reply is no longer pending; returns
     * the terminal {@see MessageResult}, throws on timeout.
     *
     * NB: this resolves the reply by `external_message_id`, which does NOT work for
     * debounce-grouped messages (the reply is keyed by the grouped id). For the
     * common case prefer {@see waitForReply()}, which polls the conversation.
     *
     * The reply can't exist until the inbound debounce window has elapsed and the
     * processor has run, so the first poll right after the 202 is always pending.
     * `$initialDelaySeconds` waits once up front to avoid burning that attempt
     * (default 0 keeps the prior behaviour / fast tests).
     */
    public function poll(int $maxAttempts = 30, float $intervalSeconds = 1.0, float $initialDelaySeconds = 0.0): MessageResult
    {
        if ($this->messaging === null || $this->externalMessageId === null) {
            throw new \LogicException('poll() requires a SendResult produced by Messaging::send()');
        }

        if ($initialDelaySeconds > 0) {
            usleep((int) ($initialDelaySeconds * 1_000_000));
        }

        for ($i = 0; $i < $maxAttempts; $i++) {
            $result = $this->messaging->result($this->externalMessageId);
            if (!$result->isPending()) {
                return $result;
            }
            usleep((int) ($intervalSeconds * 1_000_000));
        }

        throw new \RuntimeException("Milo result for {$this->externalMessageId} still pending after {$maxAttempts} attempts");
    }
}
