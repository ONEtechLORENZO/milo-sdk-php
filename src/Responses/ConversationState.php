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
    /** @var array<int,array{tool_call_id:string,name:string,input:array<string,mixed>}> */
    public array $pendingToolCalls = [];
    /** The grouped external_message_id to echo back when submitting tool results. */
    public ?string $pendingExternalMessageId = null;

    protected function hydrate(): void
    {
        $this->conversationId = $this->attributes['conversation_id'] ?? null;
        $this->status = $this->attributes['status'] ?? null;
        $this->taskId = $this->attributes['task_id'] ?? null;
        $lr = $this->attributes['last_reply'] ?? null;
        $this->lastReply = is_array($lr) ? $lr : null;
        $calls = $this->attributes['pending_tool_calls'] ?? [];
        $this->pendingToolCalls = is_array($calls) ? array_values(array_filter($calls, 'is_array')) : [];
        $this->pendingExternalMessageId = $this->attributes['pending_external_message_id'] ?? null;
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

    /**
     * Client tool calls the model is waiting on, each `{tool_call_id, name, input}`.
     *
     * @return array<int,array{tool_call_id:string,name:string,input:array<string,mixed>}>
     */
    public function pendingToolCalls(): array
    {
        return $this->pendingToolCalls;
    }

    /** True when the model is paused awaiting CLIENT-executed tool results. */
    public function hasPendingToolCalls(): bool
    {
        return $this->pendingToolCalls !== [];
    }

    /** The id to echo back to `submitToolResults()` (the grouped message id). */
    public function pendingExternalMessageId(): ?string
    {
        return $this->pendingExternalMessageId;
    }
}
