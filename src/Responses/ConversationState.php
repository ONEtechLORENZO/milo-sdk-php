<?php

declare(strict_types=1);

namespace Milo\Sdk\Responses;

/** `GET /v1/conversations/{id}` — conversation status + latest reply summary. */
final class ConversationState extends Response
{
    public ?string $conversationId = null;
    public ?string $status = null;
    public ?string $taskId = null;
    /** The durable latest assistant reply ({text, replied_at, usage, cost, …}) or null. */
    public ?array $lastReply = null;

    protected function hydrate(): void
    {
        $this->conversationId = $this->attributes['conversation_id'] ?? null;
        $this->status = $this->attributes['status'] ?? null;
        $this->taskId = $this->attributes['task_id'] ?? null;
        $lr = $this->attributes['last_reply'] ?? null;
        $this->lastReply = is_array($lr) ? $lr : null;
    }

    /** `closed` is terminal — a message for it opens a new conversation. */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /** The latest reply record, or null. Carries text + replied_at + usage + cost. */
    public function lastReply(): ?array
    {
        return $this->lastReply;
    }

    /** The latest reply's text, or null. */
    public function replyText(): ?string
    {
        $text = $this->lastReply['text'] ?? null;
        return is_string($text) ? $text : null;
    }

    /** The latest reply's ISO-8601 timestamp, or null. */
    public function repliedAt(): ?string
    {
        $at = $this->lastReply['replied_at'] ?? null;
        return is_string($at) ? $at : null;
    }

    /** True when a non-empty assistant reply is present. */
    public function hasReply(): bool
    {
        return ($this->lastReply['text'] ?? '') !== '';
    }
}
