<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * Ingress credentials for one tenant (`/admin/tenants/{tenant}/api-clients`). An
 * api-client is the auth principal for `/v1/*` calls: it owns the bearer API key,
 * an optional `allowed_task_ids` allowlist, and rate/quota limits. Obtain via
 * `$milo->apiClients($tenant)`. Requires a `provisioner`, `admin`, or `owner`
 * credential (a tenant-scoped one mints only for its own tenant).
 */
final class ApiClients extends Resource
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
        return '/tenants/' . rawurlencode($this->tenantId) . '/api-clients';
    }

    /** @return array<int,Item> */
    public function list(): array
    {
        return $this->items($this->adminGet($this->base()), 'api_clients');
    }

    public function get(string $clientId): Item
    {
        return $this->item($this->adminGet($this->base() . '/' . rawurlencode($clientId)), 'api_client');
    }

    /**
     * Create an api-client and mint its bearer key. The opaque key
     * (`milo_sk_…`) comes back ONCE on the response as `bearer_token` — register
     * it for {@see Messaging} and store it; it is unrecoverable afterward (rotate
     * to mint a new one). Pass `generate_bearer_token: false` to create without a
     * key.
     *
     * @param array<string,mixed> $attributes
     */
    public function create(array $attributes): Item
    {
        return Item::from($this->adminPost($this->base(), $attributes)->data);
    }

    /**
     * Rotate (or first-issue) the api-client's bearer key. Returns the new opaque
     * key ONCE as `bearer_token` (`->get('bearer_token')`); the previous key stops
     * working immediately.
     */
    public function rotateBearer(string $clientId): Item
    {
        return Item::from(
            $this->adminPost($this->base() . '/' . rawurlencode($clientId) . '/bearer-token')->data
        );
    }

    /** @param array<string,mixed> $attributes */
    public function update(string $clientId, array $attributes, ?int $expectedConfigVersion = null): Item
    {
        $body = $this->withExpectedVersion($attributes, $expectedConfigVersion);

        return $this->item($this->adminPut($this->base() . '/' . rawurlencode($clientId), $body), 'api_client');
    }

    public function enable(string $clientId): Item
    {
        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($clientId) . '/enable'), 'api_client');
    }

    public function disable(string $clientId): Item
    {
        return $this->item($this->adminPost($this->base() . '/' . rawurlencode($clientId) . '/disable'), 'api_client');
    }

    /** Soft delete (disable) by default; `hard: true` removes the item (owner only). */
    public function delete(string $clientId, bool $hard = false): void
    {
        $this->adminDelete($this->base() . '/' . rawurlencode($clientId), $hard ? ['hard' => 'true'] : []);
    }
}
