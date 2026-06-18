<?php

declare(strict_types=1);

namespace Milo\Sdk\Responses;

/**
 * The result of `GET /v1/messages/{id}/result` (docs/API.md §3.2). Statuses:
 * `pending` (keep polling), `completed` (reply ready), `failed` (terminal; a
 * fallback reply may still be present).
 */
final class MessageResult extends Response
{
    public ?string $status = null;
    public ?string $conversationId = null;
    public ?string $externalMessageId = null;
    public ?Reply $reply = null;

    protected function hydrate(): void
    {
        $this->status = isset($this->attributes['status']) ? (string) $this->attributes['status'] : null;
        $this->conversationId = $this->attributes['conversation_id'] ?? null;
        $this->externalMessageId = $this->attributes['external_message_id'] ?? null;
        if (isset($this->attributes['reply']) && is_array($this->attributes['reply'])) {
            $this->reply = Reply::from($this->attributes['reply']);
        }
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /** The reply text, or null when still pending / no fallback. */
    public function text(): ?string
    {
        return $this->reply?->text;
    }

    /** Token usage `{ inputTokens, outputTokens, totalTokens }` from `debug.usage`. */
    public function usage(): ?array
    {
        $usage = $this->attributes['debug']['usage'] ?? null;

        return is_array($usage) ? $usage : null;
    }
}
