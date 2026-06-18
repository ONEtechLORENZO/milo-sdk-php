<?php

declare(strict_types=1);

namespace Milo\Sdk\Exception;

/**
 * An error HTTP response from Milo (4xx/5xx). Subclasses specialise on status.
 * The decoded JSON error body is preserved so callers can read the machine
 * discriminator (`code`/`reason`), `hint`, and `errors[]` without reparsing.
 *
 * The two planes shape errors differently:
 *   - Data plane (`/v1/*`): `{ "status": "rejected"|"error", "message": "...",
 *     "reason": "tenant_disabled"|"rate_limited"|"quota_exceeded"|... }`. The
 *     machine discriminator is `reason` (there is no `code`); {@see reason()}.
 *   - Admin API: `{ "error": "...", "code": "...", "hint": "..." }` or, on
 *     validation, `{ "error": "Validation failed", "errors": [{field,message}] }`.
 * {@see errorCode} is populated from `code` (admin) or `reason` (data plane).
 */
class ApiException extends MiloException
{
    /**
     * @param array<string,mixed> $body    Decoded JSON error body (or [] if none).
     * @param array<string,string> $headers Lower-cased response header map.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorCode = null,
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message);
    }

    /** The server-supplied actionable hint, when present. */
    public function hint(): ?string
    {
        $hint = $this->body['hint'] ?? null;

        return is_string($hint) ? $hint : null;
    }

    /**
     * The data-plane machine discriminator (`reason`), e.g. `tenant_disabled`,
     * `rate_limited`, `quota_exceeded`, `signature_mismatch`. Null on the admin
     * plane (which uses `code` — see {@see $errorCode}).
     */
    public function reason(): ?string
    {
        $reason = $this->body['reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * Field-level validation errors from the admin API, normalised to
     * [field => message]. Empty unless this is a validation failure.
     *
     * @return array<string,string>
     */
    public function fieldErrors(): array
    {
        $out = [];
        foreach ((array) ($this->body['errors'] ?? []) as $err) {
            if (is_array($err) && isset($err['field'])) {
                $out[(string) $err['field']] = (string) ($err['message'] ?? '');
            }
        }

        return $out;
    }
}
