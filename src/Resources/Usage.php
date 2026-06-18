<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * Usage rollups + raw metering events for one tenant
 * (`/admin/tenants/{tenant}/usage`). Read-only. Obtain via `$milo->usage($tenant)`.
 */
final class Usage extends Resource
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
        return '/tenants/' . rawurlencode($this->tenantId) . '/usage';
    }

    /** Aggregated day/client rollups for a window (default last 30 days). */
    public function summary(?string $from = null, ?string $to = null): Item
    {
        $query = array_filter(['from' => $from, 'to' => $to], static fn ($v) => $v !== null);

        return Item::from($this->adminGet($this->base(), $query)->data);
    }

    /**
     * Raw usage events, newest-first.
     *
     * @return array<int,Item>
     */
    public function events(?string $from = null, ?string $to = null, ?string $client = null, int $limit = 100): array
    {
        $query = array_filter([
            'from' => $from,
            'to' => $to,
            'client' => $client,
            'limit' => $limit,
        ], static fn ($v) => $v !== null);

        return $this->items($this->adminGet($this->base() . '/events', $query), 'events');
    }
}
