<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Response;
use Milo\Sdk\Transport\Transporter;

/**
 * Base for every resource. Control-plane resources call the admin API under
 * `/admin/*` with the `X-Admin-Token` (+ audit `X-Admin-Actor`) headers; the
 * data-plane {@see Messaging} resource overrides with HMAC signing.
 */
abstract class Resource
{
    public function __construct(
        protected readonly Transporter $transporter,
        protected readonly Config $config,
    ) {
    }

    /** @return array<string,string> */
    protected function adminHeaders(): array
    {
        $headers = [];
        if ($this->config->adminToken !== null && $this->config->adminToken !== '') {
            $headers['X-Admin-Token'] = $this->config->adminToken;
        }
        if ($this->config->adminActor !== null && $this->config->adminActor !== '') {
            $headers['X-Admin-Actor'] = $this->config->adminActor;
        }

        return $headers;
    }

    /** @param array<string,scalar> $query */
    protected function adminGet(string $path, array $query = []): Response
    {
        return $this->transporter->request('GET', '/admin' . $path, null, $query, $this->adminHeaders());
    }

    /** @param array<string,mixed> $body */
    protected function adminPost(string $path, array $body = []): Response
    {
        // POST writes are not auto-retried: creates are not idempotent (409 on repeat).
        return $this->transporter->request('POST', '/admin' . $path, $body, [], $this->adminHeaders(), retry: false);
    }

    /** @param array<string,mixed> $body */
    protected function adminPut(string $path, array $body = []): Response
    {
        return $this->transporter->request('PUT', '/admin' . $path, $body, [], $this->adminHeaders(), retry: false);
    }

    /** @param array<string,scalar> $query */
    protected function adminDelete(string $path, array $query = []): Response
    {
        return $this->transporter->request('DELETE', '/admin' . $path, null, $query, $this->adminHeaders(), retry: false);
    }

    /**
     * Merge an optional optimistic-locking guard into a write body.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    protected function withExpectedVersion(array $body, ?int $expectedConfigVersion): array
    {
        if ($expectedConfigVersion !== null) {
            $body['expected_config_version'] = $expectedConfigVersion;
        }

        return $body;
    }

    /**
     * Wrap a single sub-key of a response body as an {@see Item}
     * (e.g. `{ "tenant": {...} }` -> Item of the tenant).
     */
    protected function item(Response $response, string $key): Item
    {
        $data = $response->data[$key] ?? $response->data;

        return Item::from(is_array($data) ? $data : []);
    }

    /**
     * Wrap a list sub-key as an array of {@see Item}s
     * (e.g. `{ "tenants": [...] }`).
     *
     * @return array<int,Item>
     */
    protected function items(Response $response, string $key): array
    {
        $list = $response->data[$key] ?? [];

        return array_map(
            static fn (array $row): Item => Item::from($row),
            is_array($list) ? array_values(array_filter($list, 'is_array')) : [],
        );
    }
}
