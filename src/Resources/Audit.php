<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * Config-change audit trail for one tenant (`/admin/tenants/{tenant}/audit`).
 * Hashes + field names only, never secret values. Obtain via `$milo->audit($tenant)`.
 */
final class Audit extends Resource
{
    public function __construct(
        Transporter $transporter,
        Config $config,
        private readonly string $tenantId,
    ) {
        parent::__construct($transporter, $config);
    }

    /**
     * @return array<int,Item>
     */
    public function list(?string $action = null, int $limit = 100): array
    {
        $query = array_filter(['action' => $action, 'limit' => $limit], static fn ($v) => $v !== null);

        return $this->items($this->adminGet('/tenants/' . rawurlencode($this->tenantId) . '/audit', $query), 'audit');
    }
}
