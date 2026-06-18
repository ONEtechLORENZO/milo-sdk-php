<?php

declare(strict_types=1);

namespace Milo\Sdk\Transport;

use Milo\Sdk\Config;
use Milo\Sdk\Exception\QuotaExceededException;
use Milo\Sdk\Exception\RateLimitedException;
use Milo\Sdk\Exception\ServerException;
use Milo\Sdk\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The single HTTP chokepoint. Auth-agnostic: callers (resources) supply the
 * already-computed auth headers (admin token or HMAC). Responsibilities:
 * JSON encode/decode, a stable `X-Request-Id`, bounded retry+backoff on 429/5xx
 * honouring `Retry-After`, and mapping error responses to typed exceptions.
 */
final class Transporter
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string,mixed>|null $json    body to JSON-encode (null = no body)
     * @param array<string,scalar>     $query   query-string params
     * @param array<string,string>     $headers extra headers (auth, etc.)
     * @param bool                     $retry   retry 429/5xx (off for non-idempotent writes the caller can't safely repeat)
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $query = [],
        array $headers = [],
        bool $retry = true,
        ?string $rawBody = null,
    ): Response {
        $url = $this->config->trimmedBaseUrl() . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $body = $rawBody;
        if ($body === null && $json !== null) {
            $body = self::encodeJson($json);
        }

        $attempts = $retry ? $this->config->maxRetries : 0;
        $lastError = null;

        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->sendOnce($method, $url, $body, $headers);
            } catch (ClientExceptionInterface $e) {
                // Network-level failure: retry like a 5xx, then surface as transport error.
                $lastError = new TransportException(
                    "Milo transport error: {$e->getMessage()}",
                    0,
                    $e,
                );
                if ($attempt < $attempts) {
                    $this->backoff($attempt, null);
                    continue;
                }
                throw $lastError;
            }

            if ($response->status < 400) {
                return $response;
            }

            $exception = ErrorMapper::toException(
                $response->status,
                $response->data,
                $response->headers,
                $response->requestId(),
            );

            // Retry transient backpressure only: per-minute rate limit (429) and
            // 5xx. A monthly quota (QuotaExceededException) is a RateLimitedException
            // subtype but is NOT transient, so it is explicitly excluded.
            $retryable = $exception instanceof ServerException
                || ($exception instanceof RateLimitedException && !$exception instanceof QuotaExceededException);
            if ($retryable && $attempt < $attempts) {
                $retryAfter = $exception instanceof RateLimitedException ? $exception->retryAfter() : null;
                $this->logger->info('milo.retry', [
                    'status' => $response->status,
                    'attempt' => $attempt + 1,
                    'path' => $path,
                ]);
                $this->backoff($attempt, $retryAfter);
                continue;
            }

            throw $exception;
        }

        // Unreachable in practice; the loop either returns or throws.
        throw $lastError ?? new TransportException('Milo request exhausted retries');
    }

    private function sendOnce(string $method, string $url, ?string $body, array $headers): Response
    {
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Request-Id', self::requestId());

        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $psrResponse = $this->http->sendRequest($request);

        $status = $psrResponse->getStatusCode();
        $raw = (string) $psrResponse->getBody();
        $data = $raw === '' ? [] : (json_decode($raw, true) ?? ['error' => $raw]);
        if (!is_array($data)) {
            $data = ['data' => $data];
        }

        $outHeaders = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $outHeaders[strtolower($name)] = implode(', ', $values);
        }

        return new Response($status, $data, $outHeaders);
    }

    private function backoff(int $attempt, ?int $retryAfterSeconds): void
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $delayMs = $retryAfterSeconds * 1000;
        } else {
            // Exponential with full jitter.
            $base = $this->config->retryBaseDelay * (2 ** $attempt);
            $delayMs = (int) (mt_rand(0, (int) ($base * 1000)));
        }
        usleep($delayMs * 1000);
    }

    /**
     * @param array<string,mixed> $value
     */
    public static function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function requestId(): string
    {
        return 'milo-php-' . bin2hex(random_bytes(8));
    }
}
