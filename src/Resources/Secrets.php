<?php

declare(strict_types=1);

namespace Milo\Sdk\Resources;

use Milo\Sdk\Config;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * Secret references for one tenant (`/admin/tenants/{tenant}/secrets`). Writes a
 * value to SSM/Secrets Manager under the enforced
 * `/{prefix}/{stage}/{tenant}/...` namespace and stores only the REFERENCE; the
 * value is never returned or logged. Obtain via `$milo->secrets($tenant)`.
 */
final class Secrets extends Resource
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
        return '/tenants/' . rawurlencode($this->tenantId) . '/secrets';
    }

    /**
     * Set/generate/clear a secret and optionally bind it to a tool/task/client.
     *
     * @param array<string,mixed> $params scope, backend, ref, value, generate, clear, tool, task, client, bind, json_key
     */
    public function set(array $params): Item
    {
        return Item::from($this->adminPost($this->base(), $params)->data);
    }

    /** Check that a secret ref exists + its shape (namespace-enforced; no value returned). */
    public function test(string $ref): Item
    {
        return Item::from($this->adminPost($this->base() . '/test', ['ref' => $ref])->data);
    }
}
