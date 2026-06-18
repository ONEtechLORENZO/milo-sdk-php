<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Responses\Item;

/**
 * Tenant control plane (`/admin/tenants`). Requires an `admin`+ token for
 * writes. `prompt_variables` are the per-tenant `{{var}}` values rendered into
 * task prompts — {@see setVariables()} is the convenience for "update its
 * variables".
 */
final class Tenants extends Resource
{
    /** @return array<int,Item> */
    public function list(): array
    {
        return $this->items($this->adminGet('/tenants'), 'tenants');
    }

    public function get(string $tenantId): Item
    {
        return $this->item($this->adminGet('/tenants/' . rawurlencode($tenantId)), 'tenant');
    }

    /**
     * Create a tenant. Body keys: `tenant_id` (required), `display_name`,
     * `status`, `default_language`, `default_region`, `enabled_channels`,
     * `allowed_prompt_arns`, `allowed_prompt_prefix`, `prompt_variables`,
     * `quotas`, `billing`.
     *
     * @param array<string,mixed> $attributes
     */
    public function create(array $attributes): Item
    {
        return $this->item($this->adminPost('/tenants', $attributes), 'tenant');
    }

    /** @param array<string,mixed> $attributes */
    public function update(string $tenantId, array $attributes, ?int $expectedConfigVersion = null): Item
    {
        $body = $this->withExpectedVersion($attributes, $expectedConfigVersion);

        return $this->item($this->adminPut('/tenants/' . rawurlencode($tenantId), $body), 'tenant');
    }

    /**
     * Replace just the tenant `prompt_variables`. Reads the current item to
     * carry `config_version` forward (optimistic locking) unless one is given.
     *
     * @param array<string,mixed> $variables
     */
    public function setVariables(string $tenantId, array $variables, ?int $expectedConfigVersion = null): Item
    {
        return $this->update($tenantId, ['prompt_variables' => $variables], $expectedConfigVersion);
    }

    public function enable(string $tenantId): Item
    {
        return $this->item($this->adminPost('/tenants/' . rawurlencode($tenantId) . '/enable'), 'tenant');
    }

    public function disable(string $tenantId): Item
    {
        return $this->item($this->adminPost('/tenants/' . rawurlencode($tenantId) . '/disable'), 'tenant');
    }

    /**
     * Delete a tenant. Soft (disable) by default; `hard: true` removes the item
     * entirely and requires an `owner` token (and an empty tenant).
     */
    public function delete(string $tenantId, bool $hard = false): void
    {
        $this->adminDelete('/tenants/' . rawurlencode($tenantId), $hard ? ['hard' => 'true'] : []);
    }
}
