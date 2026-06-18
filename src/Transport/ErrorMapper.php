<?php

declare(strict_types=1);

namespace Milo\Sdk\Transport;

use Milo\Sdk\Exception\ApiException;
use Milo\Sdk\Exception\AuthException;
use Milo\Sdk\Exception\ConflictException;
use Milo\Sdk\Exception\NotFoundException;
use Milo\Sdk\Exception\QuotaExceededException;
use Milo\Sdk\Exception\RateLimitedException;
use Milo\Sdk\Exception\ServerException;
use Milo\Sdk\Exception\ValidationException;

/**
 * Maps an error HTTP response to the right typed {@see ApiException} subclass,
 * preserving the decoded error body, headers, and request id.
 */
final class ErrorMapper
{
    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    public static function toException(int $status, array $body, array $headers, ?string $requestId): ApiException
    {
        // Admin plane carries `code`; the data plane carries `reason`. Surface
        // whichever is present as the machine discriminator so a caller can
        // branch without reparsing the body.
        $code = match (true) {
            isset($body['code']) && is_string($body['code']) => $body['code'],
            isset($body['reason']) && is_string($body['reason']) => $body['reason'],
            default => null,
        };
        $message = self::messageFrom($body, $status);

        $class = match (true) {
            $status === 400 => ValidationException::class,
            $status === 401, $status === 403 => AuthException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 422 => ValidationException::class,
            // A monthly quota 429 is NOT transiently retryable (no Retry-After,
            // won't clear in seconds); keep it a RateLimitedException subtype so
            // existing catches still fire but the transport skips the retry.
            $status === 429 && $code === 'quota_exceeded' => QuotaExceededException::class,
            $status === 429 => RateLimitedException::class,
            $status >= 500 => ServerException::class,
            default => ApiException::class,
        };

        return new $class($message, $status, $code, $body, $headers, $requestId);
    }

    /**
     * @param array<string,mixed> $body
     */
    private static function messageFrom(array $body, int $status): string
    {
        // Data plane: { error, message, hint }. Admin: { error: "Validation failed", errors: [...] }.
        $parts = [];
        foreach (['message', 'error'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                $parts[] = $body[$key];
            }
        }
        if ($parts === []) {
            return "Milo request failed with HTTP {$status}";
        }

        return implode(': ', array_unique($parts));
    }
}
