<?php

declare(strict_types=1);

namespace Milo\Sdk;

/**
 * Immutable client configuration. Build via {@see Factory} or {@see Milo::client()}.
 *
 * `baseUrl` is the API Gateway invoke URL INCLUDING the stage segment
 * (e.g. `https://abc123.execute-api.eu-south-1.amazonaws.com/prod`). The SDK
 * appends `/admin/...` for the control plane and `/v1/...` for the data plane.
 * Data-plane requests carry an `Authorization: Bearer milo_sk_…` key.
 *
 * `apiGatewayKey` is the API Gateway usage-plan key (sent as `x-api-key`). It is
 * an EDGE quota credential, NOT auth — but staging/prod deploy with
 * `api_require_api_key=true`, so writes are rejected with `403 Forbidden` at the
 * gateway without it. When set, it is added to every request (the gateway ignores
 * it on ungated routes).
 */
final class Config
{
    /**
     * @param array<string,string> $apiClients map of api-client id => bearer API key (milo_sk_…)
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly ?string $adminToken = null,
        public readonly ?string $adminActor = null,
        public readonly array $apiClients = [],
        public readonly float $timeout = 30.0,
        public readonly int $maxRetries = 3,
        public readonly float $retryBaseDelay = 0.5,
        public readonly ?string $apiGatewayKey = null,
    ) {
    }

    /** baseUrl with any trailing slash removed, for clean path concatenation. */
    public function trimmedBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    /** The registered bearer API key for an api-client id, if any. */
    public function apiKeyFor(string $clientId): ?string
    {
        return $this->apiClients[$clientId] ?? null;
    }
}
