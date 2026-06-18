<?php

declare(strict_types=1);

namespace Milo\Sdk\Exception;

/**
 * HTTP 409 — a write conflict. For admin config writes this is
 * `config_version_conflict` (optimistic locking): another writer bumped the
 * item since you read it. {@see currentConfigVersion()} returns the server's
 * current version so you can refetch + retry. Also raised for
 * already-exists creates and hard-delete constraint violations.
 */
class ConflictException extends ApiException
{
    /** The server's current `config_version`, when it reported one. */
    public function currentConfigVersion(): ?int
    {
        $v = $this->body['current_config_version'] ?? null;

        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : null);
    }
}
