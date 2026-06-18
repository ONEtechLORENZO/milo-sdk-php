<?php

declare(strict_types=1);

namespace Milo\Sdk\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that records every request and returns programmed responses
 * in FIFO order, so tests can assert exactly what the SDK put on the wire.
 */
final class RecordingClient implements ClientInterface
{
    /** @var array<int,RequestInterface> */
    public array $requests = [];

    /** @var array<int,ResponseInterface|\Throwable> */
    private array $queue = [];

    /** @param array<string,mixed> $json */
    public function queueJson(int $status, array $json, array $headers = []): self
    {
        $this->queue[] = new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($json));

        return $this;
    }

    public function queueThrowable(\Throwable $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            return new Response(200, ['Content-Type' => 'application/json'], '{}');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests)];
    }

    /** @return array<string,mixed> */
    public function lastJsonBody(): array
    {
        return json_decode((string) $this->lastRequest()->getBody(), true) ?? [];
    }
}
