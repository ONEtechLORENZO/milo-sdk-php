<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Builder\TaskBuilder;
use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * Task control plane for one tenant (`/admin/tenants/{tenant}/tasks`). Covers
 * CRUD plus the draft -> publish -> rollback lifecycle and immutable version
 * snapshots. Obtain via `$milo->tasks($tenant)`.
 *
 * A tool-enabled task MUST use a direct model (inline prompt + `model.model_id`)
 * — a Prompt Manager ARN can't carry a dynamic toolConfig, and the admin API
 * rejects such a config. {@see builder()} defaults accordingly.
 */
final class Tasks extends Resource
{
    public function __construct(
        Transporter $transporter,
        Config $config,
        private readonly string $tenantId,
    ) {
        parent::__construct($transporter, $config);
    }

    private function base(): string
    {
        return '/tenants/' . rawurlencode($this->tenantId) . '/tasks';
    }

    /** A fluent builder for the nested task config. Call ->create()/->publish() to persist. */
    public function builder(string $taskId): TaskBuilder
    {
        return new TaskBuilder($this, $taskId);
    }

    /** @return array<int,Item> */
    public function list(): array
    {
        return $this->items($this->adminGet($this->base()), 'tasks');
    }

    public function get(string $taskId): Item
    {
        return $this->item($this->adminGet($this->base() . '/' . rawurlencode($taskId)), 'task');
    }

    /** Task-health report (tool allowlist resolution, execution gating, prompt). */
    public function health(string $taskId): Item
    {
        return $this->item($this->adminGet($this->base() . '/' . rawurlencode($taskId) . '/health'), 'health');
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): Item
    {
        return $this->item($this->adminPost($this->base(), $attributes), 'task');
    }

    /** @param array<string,mixed> $attributes */
    public function update(string $taskId, array $attributes, ?int $expectedConfigVersion = null): Item
    {
        $body = $this->withExpectedVersion($attributes, $expectedConfigVersion);

        return $this->item($this->adminPut($this->base() . '/' . rawurlencode($taskId), $body), 'task');
    }

    public function enable(string $taskId): Item
    {
        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($taskId) . '/enable'), 'task');
    }

    public function disable(string $taskId): Item
    {
        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($taskId) . '/disable'), 'task');
    }

    public function duplicate(string $taskId, string $newTaskId, ?string $displayName = null): Item
    {
        $body = ['new_task_id' => $newTaskId];
        if ($displayName !== null) {
            $body['display_name'] = $displayName;
        }

        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($taskId) . '/duplicate', $body), 'task');
    }

    public function delete(string $taskId, bool $hard = false): void
    {
        $this->adminDelete($this->base() . '/' . rawurlencode($taskId), $hard ? ['hard' => 'true'] : []);
    }

    // --- draft -> publish -> rollback lifecycle -------------------------------

    public function getDraft(string $taskId): Item
    {
        return $this->item($this->adminGet($this->base() . '/' . rawurlencode($taskId) . '/draft'), 'draft');
    }

    /** @param array<string,mixed> $attributes partial task config staged onto the draft */
    public function saveDraft(string $taskId, array $attributes): Item
    {
        return $this->item($this->adminPut($this->base() . '/' . rawurlencode($taskId) . '/draft', $attributes), 'draft');
    }

    /** Copy draft -> live and snapshot an immutable version. */
    public function publish(string $taskId, ?int $expectedConfigVersion = null): Item
    {
        $body = $this->withExpectedVersion([], $expectedConfigVersion);

        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($taskId) . '/publish', $body), 'task');
    }

    /** @return array<int,Item> */
    public function versions(string $taskId): array
    {
        return $this->items($this->adminGet($this->base() . '/' . rawurlencode($taskId) . '/versions'), 'versions');
    }

    public function version(string $taskId, int $version): Item
    {
        return $this->item(
            $this->adminGet($this->base() . '/' . rawurlencode($taskId) . '/versions', ['version' => $version]),
            'version',
        );
    }

    public function rollback(string $taskId, int $version, ?int $expectedConfigVersion = null): Item
    {
        $body = $this->withExpectedVersion(['version' => $version], $expectedConfigVersion);

        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($taskId) . '/rollback', $body), 'task');
    }
}
