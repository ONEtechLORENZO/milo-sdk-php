<?php

declare(strict_types=1);

namespace Milo\Sdk\Builder;

use Milo\Sdk\Resources\Tasks;
use Milo\Sdk\Responses\Item;

/**
 * Fluent builder for the deep task config. Accumulates the nested
 * prompt/model/memory/tools/secrets/delivery blocks, then persists via a
 * terminal {@see create()}, {@see saveDraft()}, or {@see publish()}.
 *
 *   $milo->tasks('acme')->builder('support')
 *       ->displayName('Support agent')
 *       ->inlinePrompt('You are {{brand}} support. Be concise.')
 *       ->model('eu.amazon.nova-micro-v1:0')
 *       ->withTool('order_lookup')      // tool-enabled => inline/direct model (enforced server-side)
 *       ->enable()
 *       ->publish();
 */
final class TaskBuilder
{
    /** @var array<string,mixed> */
    private array $config;

    public function __construct(
        private readonly Tasks $tasks,
        private readonly string $taskId,
    ) {
        $this->config = ['task_id' => $taskId];
    }

    public function displayName(string $name): self
    {
        $this->config['display_name'] = $name;

        return $this;
    }

    public function description(string $description): self
    {
        $this->config['description'] = $description;

        return $this;
    }

    public function enable(bool $enabled = true): self
    {
        $this->config['enabled'] = $enabled;

        return $this;
    }

    /** @param array<string,mixed> $variables per-task `{{var}}` values */
    public function variables(array $variables): self
    {
        $this->config['prompt_variables'] = $variables;

        return $this;
    }

    /** Inline (in-process) system prompt — the default path; required for tool-enabled tasks. */
    public function inlinePrompt(string $systemTemplate): self
    {
        $this->config['prompt'] = [
            'provider' => 'inline',
            'system_template' => $systemTemplate,
        ];

        return $this;
    }

    /** Bedrock Prompt Manager prompt (legacy/opt-in). Cannot be combined with tools. */
    public function promptManager(string $promptArn, string $versionStrategy = 'pinned'): self
    {
        $this->config['prompt'] = [
            'provider' => 'bedrock_prompt_manager',
            'prompt_arn' => $promptArn,
            'version_strategy' => $versionStrategy,
        ];

        return $this;
    }

    public function model(string $modelId, ?string $region = null, ?int $maxOutputTokens = null, ?float $temperature = null): self
    {
        $this->config['model'] = array_filter([
            'model_id' => $modelId,
            'bedrock_region' => $region,
            'max_output_tokens' => $maxOutputTokens,
            'temperature' => $temperature,
        ], static fn ($v) => $v !== null);

        return $this;
    }

    public function history(bool $include = true, ?int $maxMessages = null): self
    {
        $this->config['memory_access'] = array_filter([
            'include_active_conversation_history' => $include,
            'max_history_messages' => $maxMessages,
        ], static fn ($v) => $v !== null);

        return $this;
    }

    /** Add one tool to the allowlist (creates/extends the tools block). */
    public function withTool(string $toolId): self
    {
        $tools = $this->config['tools'] ?? ['enabled' => true, 'enabled_tool_ids' => []];
        $tools['enabled'] = true;
        $tools['enabled_tool_ids'] = array_values(array_unique([...($tools['enabled_tool_ids'] ?? []), $toolId]));
        $this->config['tools'] = $tools;

        return $this;
    }

    /**
     * Configure the tools block wholesale.
     *
     * @param array<int,string> $toolIds
     */
    public function tools(array $toolIds, string $executionPolicy = 'read_only_auto', ?int $maxCallsPerTurn = null): self
    {
        $this->config['tools'] = array_filter([
            'enabled' => $toolIds !== [],
            'enabled_tool_ids' => array_values($toolIds),
            'execution_policy' => $executionPolicy,
            'max_tool_calls_per_turn' => $maxCallsPerTurn,
        ], static fn ($v) => $v !== null);

        return $this;
    }

    public function callbackSecret(string $secretRef): self
    {
        $secrets = $this->config['secrets'] ?? [];
        $secrets['callback_secret_ref'] = $secretRef;
        $this->config['secrets'] = $secrets;

        return $this;
    }

    public function delivery(string $mode, ?string $url = null, ?string $secretRef = null): self
    {
        $this->config['delivery'] = array_filter([
            'mode' => $mode,
            'url' => $url,
            'secret_ref' => $secretRef,
        ], static fn ($v) => $v !== null);

        return $this;
    }

    /**
     * Final conversation export (seal/export/purge — Milo is not the archive). On
     * close the transcript is handed back to the client and Milo's content copy is
     * purged. mode: `webhook` (signed POST to a CONFIGURED url) | `poll` |
     * `client_storage`. An absent block defaults to client_storage + 24h server-side.
     */
    public function conversationExport(
        string $mode,
        ?string $url = null,
        ?string $secretRef = null,
        ?bool $deleteAfterAck = null,
        ?int $maxRetentionHours = null,
    ): self {
        $this->config['conversation_export'] = array_filter([
            'mode' => $mode,
            'url' => $url,
            'secret_ref' => $secretRef,
            'delete_after_ack' => $deleteAfterAck,
            'max_retention_after_close_hours' => $maxRetentionHours,
        ], static fn ($v) => $v !== null);

        return $this;
    }

    /** Set any raw config key not covered by a dedicated method. */
    public function set(string $key, mixed $value): self
    {
        $this->config[$key] = $value;

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->config;
    }

    // --- terminal persistence -------------------------------------------------

    /** Create the live task. */
    public function create(): Item
    {
        return $this->tasks->create($this->config);
    }

    /** Update the existing live task. */
    public function update(?int $expectedConfigVersion = null): Item
    {
        return $this->tasks->update($this->taskId, $this->config, $expectedConfigVersion);
    }

    /** Stage the config onto the task draft (production untouched). */
    public function saveDraft(): Item
    {
        return $this->tasks->saveDraft($this->taskId, $this->config);
    }

    /**
     * Create the task, then publish an immutable version snapshot. For an
     * existing task, prefer {@see saveDraft()} followed by
     * `$milo->tasks($tenant)->publish($taskId)`.
     */
    public function publish(): Item
    {
        $this->tasks->create($this->config);

        return $this->tasks->publish($this->taskId);
    }
}
