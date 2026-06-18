<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Responses\Item;

/**
 * Global tool catalog (`/admin/catalog/*`) — NOT tenant-scoped, `owner`-gated
 * for writes. A catalog def is the tenant-agnostic, secret-free security
 * contract that tenant tool bindings reference. Also exposes MCP server
 * discovery/import (plug-and-play, no redeploy). Obtain via `$milo->catalog()`.
 */
final class Catalog extends Resource
{
    /** @return array<int,Item> */
    public function listTools(): array
    {
        return $this->items($this->adminGet('/catalog/tools'), 'catalog_tools');
    }

    public function getTool(string $toolId): Item
    {
        return $this->item($this->adminGet('/catalog/tools/' . rawurlencode($toolId)), 'catalog_tool');
    }

    /** @param array<string,mixed> $attributes */
    public function createTool(array $attributes): Item
    {
        return $this->item($this->adminPost('/catalog/tools', $attributes), 'catalog_tool');
    }

    /** @param array<string,mixed> $attributes */
    public function updateTool(string $toolId, array $attributes, ?int $expectedConfigVersion = null): Item
    {
        $body = $this->withExpectedVersion($attributes, $expectedConfigVersion);

        return $this->item($this->adminPut('/catalog/tools/' . rawurlencode($toolId), $body), 'catalog_tool');
    }

    public function enableTool(string $toolId): Item
    {
        return $this->item($this->adminPost('/catalog/tools/' . rawurlencode($toolId) . '/enable'), 'catalog_tool');
    }

    public function disableTool(string $toolId): Item
    {
        return $this->item($this->adminPost('/catalog/tools/' . rawurlencode($toolId) . '/disable'), 'catalog_tool');
    }

    /**
     * Preview an MCP server's tools (`tools/list`) without writing anything.
     *
     * @param array<string,mixed> $params endpoint, transport, secret_ref, tenant, timeout_ms
     */
    public function discoverMcp(array $params): Item
    {
        return Item::from($this->adminPost('/catalog/mcp/discover', $params)->data);
    }

    /**
     * Import selected MCP tools: writes a catalog def + a per-tenant binding for
     * each, re-discovering for authoritative schemas.
     *
     * @param array<string,mixed> $params endpoint, transport, tenant, tools[], secret_ref, catalog_endpoint, variables, id_prefix
     */
    public function importMcp(array $params): Item
    {
        return Item::from($this->adminPost('/catalog/mcp/import', $params)->data);
    }
}
