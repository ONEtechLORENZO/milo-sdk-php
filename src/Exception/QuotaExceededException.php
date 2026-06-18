<?php

declare(strict_types=1);

namespace Milo\Sdk\Exception;

/**
 * HTTP 429 with `reason: "quota_exceeded"` — a MONTHLY tenant/api-client cap was
 * hit (distinct from the per-minute rate limit). Extends {@see RateLimitedException}
 * so existing `catch (RateLimitedException)` handlers still fire, but the
 * transport does NOT retry it: a monthly quota will not clear within a few
 * seconds of backoff, and the server sends no `Retry-After`. Raise the limit or
 * wait for the next period. The cap details are in the body (`quota`/`used`/`limit`).
 */
final class QuotaExceededException extends RateLimitedException
{
}
