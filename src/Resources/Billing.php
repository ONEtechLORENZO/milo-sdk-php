<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * Invoice-style billing derived on read from the month's usage rollups
 * (`/admin/tenants/{tenant}/billing`). No automated charging. Obtain via
 * `$milo->billing($tenant)`.
 */
final class Billing extends Resource
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
        return '/tenants/' . rawurlencode($this->tenantId) . '/billing';
    }

    /** Derived line items for a month (`YYYY-MM`, default current). */
    public function forMonth(?string $month = null): Item
    {
        $query = $month !== null ? ['month' => $month] : [];

        return Item::from($this->adminGet($this->base(), $query)->data);
    }

    /** Record an audited billing adjustment (amount + note only). */
    public function addAdjustment(string $month, float $amountUsd, string $note): Item
    {
        $body = ['month' => $month, 'amount_usd' => $amountUsd, 'note' => $note];

        return Item::from($this->adminPost($this->base() . '/adjustments', $body)->data);
    }
}
