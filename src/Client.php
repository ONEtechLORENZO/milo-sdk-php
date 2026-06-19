<?php

declare(strict_types=1);

namespace Milo\Sdk;

use Milo\Sdk\Resources\ApiClients;
use Milo\Sdk\Resources\Audit;
use Milo\Sdk\Resources\Billing;
use Milo\Sdk\Resources\Channels;
use Milo\Sdk\Resources\Conversations;
use Milo\Sdk\Resources\Messaging;
use Milo\Sdk\Resources\Metrics;
use Milo\Sdk\Resources\Secrets;
use Milo\Sdk\Resources\Tasks;
use Milo\Sdk\Resources\Tenants;
use Milo\Sdk\Resources\Usage;
use Milo\Sdk\Responses\Item;
use Milo\Sdk\Transport\Transporter;

/**
 * The unified Milo client. Like `openai-php`'s client, it is a thin facade over
 * resource accessors — control plane (X-Admin-Token) and data plane (bearer key)
 * live behind one object; the two auth schemes are an internal per-resource detail.
 *
 *   $milo->tenants()->create([...]);                 // control plane
 *   $milo->messaging('acme', 'web_app')->send('hi'); // data plane
 */
final class Client
{
    public function __construct(
        private readonly Transporter $transporter,
        private readonly Config $config,
    ) {
    }

    // --- control plane (X-Admin-Token) ---------------------------------------

    public function tenants(): Tenants
    {
        return new Tenants($this->transporter, $this->config);
    }

    public function tasks(string $tenantId): Tasks
    {
        return new Tasks($this->transporter, $this->config, $tenantId);
    }

    public function apiClients(string $tenantId): ApiClients
    {
        return new ApiClients($this->transporter, $this->config, $tenantId);
    }

    public function channels(string $tenantId): Channels
    {
        return new Channels($this->transporter, $this->config, $tenantId);
    }

    public function secrets(string $tenantId): Secrets
    {
        return new Secrets($this->transporter, $this->config, $tenantId);
    }

    public function usage(string $tenantId): Usage
    {
        return new Usage($this->transporter, $this->config, $tenantId);
    }

    public function billing(string $tenantId): Billing
    {
        return new Billing($this->transporter, $this->config, $tenantId);
    }

    public function audit(string $tenantId): Audit
    {
        return new Audit($this->transporter, $this->config, $tenantId);
    }

    public function conversations(string $tenantId): Conversations
    {
        return new Conversations($this->transporter, $this->config, $tenantId);
    }

    public function metrics(): Metrics
    {
        return new Metrics($this->transporter, $this->config);
    }

    // --- data plane (bearer key) ---------------------------------------------

    /**
     * Messaging surface for a tenant + api-client. The bearer key is taken from
     * the explicit `$apiKey`, else the one registered for the client id via
     * {@see Factory::withApiClient()}.
     */
    public function messaging(
        string $tenantId,
        string $clientId,
        ?string $apiKey = null,
        ?string $defaultTaskId = null,
    ): Messaging {
        $key = $apiKey ?? $this->config->apiKeyFor($clientId);
        if ($key === null || $key === '') {
            throw new \InvalidArgumentException(
                "No API key for api-client '{$clientId}'. Pass it explicitly or register it with Factory::withApiClient().",
            );
        }

        return new Messaging(
            $this->transporter,
            $this->config,
            $tenantId,
            $clientId,
            $key,
            $defaultTaskId,
        );
    }

    // --- system ---------------------------------------------------------------

    /** Public liveness/readiness (`GET /admin/health`). */
    public function health(): Item
    {
        return Item::from($this->transporter->request('GET', '/admin/health')->data);
    }

    /** Public config + resolved auth context (`GET /admin/config`). */
    public function adminConfig(): Item
    {
        return Item::from($this->transporter->request('GET', '/admin/config')->data);
    }

    public function config(): Config
    {
        return $this->config;
    }
}
