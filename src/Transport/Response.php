<?php

declare(strict_types=1);

namespace Milo\Sdk\Transport;

/**
 * The low-level result of a transport call: HTTP status, decoded JSON body, and
 * a lower-cased header map. Resources wrap this into typed
 * {@see \Milo\Sdk\Responses\Response} DTOs; callers rarely touch it directly.
 */
final class Response
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $data,
        public readonly array $headers = [],
    ) {
    }

    public function requestId(): ?string
    {
        return $this->headers['x-request-id'] ?? $this->headers['x-amzn-requestid'] ?? null;
    }
}
