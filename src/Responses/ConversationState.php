<?php

declare(strict_types=1);

namespace Milo\Sdk\Responses;

/** `GET /v1/conversations/{id}` — conversation status + latest reply summary. */
final class ConversationState extends Response
{
    public ?string $conversationId = null;
    public ?string $status = null;
    public ?string $taskId = null;

    protected function hydrate(): void
    {
        $this->conversationId = $this->attributes['conversation_id'] ?? null;
        $this->status = $this->attributes['status'] ?? null;
        $this->taskId = $this->attributes['task_id'] ?? null;
    }

    /** `closed` is terminal — a message for it opens a new conversation. */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
