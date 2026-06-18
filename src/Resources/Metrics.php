<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Responses\Item;

/**
 * CloudWatch pipeline metrics (`/admin/metrics`) — a GLOBAL read, scoped by an
 * optional `?tenant=`. A tenant-scoped token must name one of its own tenants.
 * Obtain via `$milo->metrics()`.
 */
final class Metrics extends Resource
{
    /**
     * @param array<string,scalar> $params from, to, period, tenant, task, stage
     */
    public function get(array $params = []): Item
    {
        return Item::from($this->adminGet('/metrics', $params)->data);
    }
}
