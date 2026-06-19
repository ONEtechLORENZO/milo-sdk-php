<?php

declare(strict_types=1);

namespace Milo\Sdk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory as GuzzleHttpFactory;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Milo\Sdk\Transport\Transporter;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Fluent builder for a {@see Client}, mirroring `OpenAI::factory()`. The HTTP
 * client + PSR-17 factories are resolved in this order: an explicitly injected
 * one ({@see withHttpClient()} etc.), then Guzzle if it is installed (so the
 * configured timeout applies), then PSR-18/PSR-17 auto-discovery (php-http/
 * discovery) of whatever client the host project provides. So the minimum is
 * `Milo::factory()->withBaseUrl($url)->withAdminToken($t)->make()` with no hard
 * dependency on Guzzle.
 */
final class Factory
{
    private ?string $baseUrl = null;
    private ?string $adminToken = null;
    private ?string $adminActor = null;
    /** @var array<string,string> */
    private array $apiClients = [];
    private float $timeout = 30.0;
    private int $maxRetries = 3;
    private ?string $apiGatewayKey = null;
    private ?ClientInterface $httpClient = null;
    private ?RequestFactoryInterface $requestFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;
    private ?LoggerInterface $logger = null;

    public function withBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function withAdminToken(string $token, ?string $actor = null): self
    {
        $this->adminToken = $token;
        if ($actor !== null) {
            $this->adminActor = $actor;
        }

        return $this;
    }

    public function withAdminActor(string $actor): self
    {
        $this->adminActor = $actor;

        return $this;
    }

    /** Register an api-client's bearer API key (milo_sk_…) for `$client->messaging()`. */
    public function withApiClient(string $clientId, string $apiKey): self
    {
        $this->apiClients[$clientId] = $apiKey;

        return $this;
    }

    /**
     * The API Gateway usage-plan key (sent as `x-api-key`). Required against
     * staging/prod, which deploy with `api_require_api_key=true` — without it the
     * gateway rejects `/v1` writes with `403 Forbidden`. It is an edge quota
     * credential, NOT auth (the bearer key is the auth boundary).
     */
    public function withApiGatewayKey(string $key): self
    {
        $this->apiGatewayKey = $key;

        return $this;
    }

    public function withTimeout(float $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function withMaxRetries(int $retries): self
    {
        $this->maxRetries = $retries;

        return $this;
    }

    public function withHttpClient(ClientInterface $client): self
    {
        $this->httpClient = $client;

        return $this;
    }

    public function withRequestFactory(RequestFactoryInterface $factory): self
    {
        $this->requestFactory = $factory;

        return $this;
    }

    public function withStreamFactory(StreamFactoryInterface $factory): self
    {
        $this->streamFactory = $factory;

        return $this;
    }

    public function withLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    public function make(): Client
    {
        if ($this->baseUrl === null || $this->baseUrl === '') {
            throw new \InvalidArgumentException('Milo factory: a base URL is required (withBaseUrl).');
        }

        $config = new Config(
            baseUrl: $this->baseUrl,
            adminToken: $this->adminToken,
            adminActor: $this->adminActor,
            apiClients: $this->apiClients,
            timeout: $this->timeout,
            maxRetries: $this->maxRetries,
            apiGatewayKey: $this->apiGatewayKey,
        );

        $transporter = new Transporter(
            http: $this->httpClient ?? $this->defaultHttpClient(),
            requestFactory: $this->requestFactory ?? $this->defaultRequestFactory(),
            streamFactory: $this->streamFactory ?? $this->defaultStreamFactory(),
            config: $config,
            logger: $this->logger ?? new \Psr\Log\NullLogger(),
        );

        return new Client($transporter, $config);
    }

    private function defaultHttpClient(): ClientInterface
    {
        // Prefer Guzzle when installed so the configured timeout applies; else
        // discover whatever PSR-18 client the host project ships.
        if (class_exists(GuzzleClient::class)) {
            return new GuzzleClient(['timeout' => $this->timeout, 'http_errors' => false]);
        }

        return Psr18ClientDiscovery::find();
    }

    private function defaultRequestFactory(): RequestFactoryInterface
    {
        if (class_exists(GuzzleHttpFactory::class)) {
            return new GuzzleHttpFactory();
        }

        return Psr17FactoryDiscovery::findRequestFactory();
    }

    private function defaultStreamFactory(): StreamFactoryInterface
    {
        if (class_exists(GuzzleHttpFactory::class)) {
            return new GuzzleHttpFactory();
        }

        return Psr17FactoryDiscovery::findStreamFactory();
    }
}
