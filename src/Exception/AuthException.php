<?php

declare(strict_types=1);

namespace Milo\Sdk\Exception;

/**
 * HTTP 401/403 — bad/expired signature or token, replay window exceeded,
 * disabled tenant/api-client, insufficient admin role, or task not allowed for
 * the client.
 */
class AuthException extends ApiException
{
}
