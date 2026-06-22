<?php

declare(strict_types=1);

namespace Milo\Sdk\Responses;

/**
 * The result of `GET /v1/messages/{id}/result` (docs/API.md §3.2). Statuses:
 * `pending` (keep polling), `completed` (reply ready), `failed` (terminal; a
 * fallback reply may still be present), `tool_calls_pending` (the model asked for
 * CLIENT-executed tools — run them and submit results to resume the turn).
 */
final class MessageResult extends Response
{
    public ?string $status = null;
    public ?string $conversationId = null;
    public ?string $externalMessageId = null;
    public ?Reply $reply = null;
    /** @var array<int,array{tool_call_id:string,name:string,input:array<string,mixed>}> */
    public array $toolCalls = [];

    protected function hydrate(): void
    {
        $this->status = isset($this->attributes['status']) ? (string) $this->attributes['status'] : null;
        $this->conversationId = $this->attributes['conversation_id'] ?? null;
        $this->externalMessageId = $this->attributes['external_message_id'] ?? null;
        if (isset($this->attributes['reply']) && is_array($this->attributes['reply'])) {
            $this->reply = Reply::from($this->attributes['reply']);
        }
        $calls = $this->attributes['tool_calls'] ?? [];
        $this->toolCalls = is_array($calls) ? array_values(array_filter($calls, 'is_array')) : [];
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

    /** The model is awaiting CLIENT-executed tool results. */
    public function isToolCallsPending(): bool
    {
        return $this->status === 'tool_calls_pending';
    }

    /**
     * The tool calls the client must execute, each `{tool_call_id, name, input}`.
     *
     * @return array<int,array{tool_call_id:string,name:string,input:array<string,mixed>}>
     */
    public function toolCalls(): array
    {
        return $this->toolCalls;
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /** The reply text, or null when still pending / no fallback. For a structured
     * (output_schema) reply this is the JSON serialized as a string; use
     * {@see json()} for the parsed object. */
    public function text(): ?string
    {
        return $this->reply?->text;
    }

    /**
     * The STRUCTURED reply object for a task with an `output_schema` (the model's
     * reply forced to that JSON Schema), or null for a plain text reply. Parsed —
     * ready to use, no `json_decode` needed.
     *
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        $json = $this->attributes['reply']['json'] ?? null;

        return is_array($json) ? $json : null;
    }

    /** True when the reply is structured JSON (the task pins an output_schema). */
    public function isJson(): bool
    {
        return ($this->attributes['reply']['type'] ?? null) === 'json';
    }

    /** Token usage `{ inputTokens, outputTokens, totalTokens }` from `debug.usage`. */
    public function usage(): ?array
    {
        $usage = $this->attributes['debug']['usage'] ?? null;

        return is_array($usage) ? $usage : null;
    }
}
