<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Builder\ToolBuilder;
use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * Tenant tool registry (`/admin/tenants/{tenant}/tools`). A tool item is EITHER
 * self-contained (`tool_type`/`spec`/`input_schema`/...) OR a thin binding to a
 * global catalog def (`catalog_tool_id` + per-tenant `variables`/`secret_ref`).
 * A binding may never set catalog-owned security fields — the admin API rejects
 * that. Obtain via `$milo->tools($tenant)`.
 */
final class Tools extends Resource
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
        return '/tenants/' . rawurlencode($this->tenantId) . '/tools';
    }

    public function builder(string $toolId): ToolBuilder
    {
        return new ToolBuilder($this, $toolId);
    }

    /** @return array<int,Item> */
    public function list(): array
    {
        return $this->items($this->adminGet($this->base()), 'tools');
    }

    public function get(string $toolId): Item
    {
        return $this->item($this->adminGet($this->base() . '/' . rawurlencode($toolId)), 'tool');
    }

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes): Item
    {
        return $this->item($this->adminPost($this->base(), $attributes), 'tool');
    }

    /**
     * Bind a global catalog tool into this tenant. The catalog owns the security
     * contract; the binding only supplies enable state, per-tenant `variables`,
     * `secret_ref`, and timeout/retry overrides.
     *
     * @param array<string,mixed> $variables
     */
    public function bindCatalog(
        string $toolId,
        string $catalogToolId,
        array $variables = [],
        ?string $secretRef = null,
        bool $enabled = true,
    ): Item {
        $body = [
            'tool_id' => $toolId,
            'catalog_tool_id' => $catalogToolId,
            'enabled' => $enabled,
            'variables' => $variables,
        ];
        if ($secretRef !== null) {
            $body['secret_ref'] = $secretRef;
        }

        return $this->item($this->adminPost($this->base(), $body), 'tool');
    }

    /** @param array<string,mixed> $attributes */
    public function update(string $toolId, array $attributes, ?int $expectedConfigVersion = null): Item
    {
        $body = $this->withExpectedVersion($attributes, $expectedConfigVersion);

        return $this->item($this->adminPut($this->base() . '/' . rawurlencode($toolId), $body), 'tool');
    }

    public function enable(string $toolId): Item
    {
        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($toolId) . '/enable'), 'tool');
    }

    public function disable(string $toolId): Item
    {
        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($toolId) . '/disable'), 'tool');
    }

    public function delete(string $toolId, bool $hard = false): void
    {
        $this->adminDelete($this->base() . '/' . rawurlencode($toolId), $hard ? ['hard' => 'true'] : []);
    }

    /**
     * Config dry-run: validate sample input + resolve the catalog + evaluate the
     * side-effect policy WITHOUT executing the tool.
     *
     * @param array<string,mixed> $sampleInput
     */
    public function test(string $toolId, array $sampleInput = [], ?string $executionPolicy = null): Item
    {
        $body = ['sample_input' => $sampleInput];
        if ($executionPolicy !== null) {
            $body['execution_policy'] = $executionPolicy;
        }

        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($toolId) . '/test', $body), 'tool');
    }
}
