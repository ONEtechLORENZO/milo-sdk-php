<?php

declare(strict_types=1);

namespace Milo\Sdk\Responses;

/**
 * The result of `GET /v1/conversations/export` (docs/API.md §3.6) — the
 * conversation seal/export/purge lifecycle. Statuses:
 *   - `not_ready` — the export is still being built; keep polling.
 *   - `ready`     — the transcript package is available ({@see package()}).
 *   - `purged`    — handed back to the client and Milo's content copy deleted;
 *                   no package, only a {@see purgedAt()} timestamp.
 *
 * {@see Messaging::export()} maps the endpoint's 404 (not built) / 410 (purged)
 * onto `not_ready` / `purged`, so callers branch on status, not exceptions.
 */
final class ExportResult extends Response
{
    public ?string $status = null;
    public ?string $reason = null;

    protected function hydrate(): void
    {
        $this->status = isset($this->attributes['status']) ? (string) $this->attributes['status'] : null;
        $this->reason = isset($this->attributes['reason']) ? (string) $this->attributes['reason'] : null;
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function notReady(): bool
    {
        return $this->status === 'not_ready';
    }

    public function isPurged(): bool
    {
        return $this->status === 'purged';
    }

    /** The export package (transcript + metadata) when {@see isReady()}, else null. */
    public function package(): ?array
    {
        $pkg = $this->attributes['export'] ?? null;

        return is_array($pkg) ? $pkg : null;
    }

    /** The purge timestamp when {@see isPurged()}, else null. */
    public function purgedAt(): ?string
    {
        $v = $this->attributes['purged_at'] ?? null;

        return is_string($v) ? $v : null;
    }
}
