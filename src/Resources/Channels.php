<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * Opt-in per-channel-account config (`/admin/tenants/{tenant}/channels`):
 * disable a channel account, set a default task, or override debounce. A missing
 * item means "unconfigured = allowed". Obtain via `$milo->channels($tenant)`.
 */
final class Channels extends Resource
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
        return '/tenants/' . rawurlencode($this->tenantId) . '/channels';
    }

    private function path(string $channel, string $account): string
    {
        return $this->base() . '/' . rawurlencode($channel) . '/' . rawurlencode($account);
    }

    /** @return array<int,Item> */
    public function list(): array
    {
        return $this->items($this->adminGet($this->base()), 'channels');
    }

    public function get(string $channel, string $account): Item
    {
        return $this->item($this->adminGet($this->path($channel, $account)), 'channel');
    }

    /** @param array<string,mixed> $attributes channel, channel_account_id, default_task_id, debounce_seconds, enabled */
    public function create(array $attributes): Item
    {
        return $this->item($this->adminPost($this->base(), $attributes), 'channel');
    }

    /** @param array<string,mixed> $attributes */
    public function update(string $channel, string $account, array $attributes, ?int $expectedConfigVersion = null): Item
    {
        $body = $this->withExpectedVersion($attributes, $expectedConfigVersion);

        return $this->item($this->adminPut($this->path($channel, $account), $body), 'channel');
    }

    public function enable(string $channel, string $account): Item
    {
        return $this->item($this->adminPost($this->path($channel, $account) . '/enable'), 'channel');
    }

    public function disable(string $channel, string $account): Item
    {
        return $this->item($this->adminPost($this->path($channel, $account) . '/disable'), 'channel');
    }

    public function delete(string $channel, string $account, bool $hard = false): void
    {
        $this->adminDelete($this->path($channel, $account), $hard ? ['hard' => 'true'] : []);
    }
}
