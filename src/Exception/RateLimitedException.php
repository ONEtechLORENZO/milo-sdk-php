<?php

declare(strict_types=1);

namespace Milo\Sdk\Exception;

/**
 * HTTP 429 — per-client rate limit or monthly quota exceeded, or queue
 * backpressure. {@see retryAfter()} surfaces the server's `Retry-After`
 * (seconds) when present. The transport already retries 429 a bounded number of
 * times honouring this header before giving up and throwing.
 */
class RateLimitedException extends ApiException
{
    /**
     * Seconds to wait before retrying — the `Retry-After` header, falling back
     * to the data-plane `retry_after_seconds` body field when the header is
     * absent (the rate-limit 429 sends both).
     */
    public function retryAfter(): ?int
    {
        $v = $this->headers['retry-after'] ?? null;
        if (is_numeric($v)) {
            return (int) $v;
        }

        $body = $this->body['retry_after_seconds'] ?? null;

        return is_numeric($body) ? (int) $body : null;
    }
}
