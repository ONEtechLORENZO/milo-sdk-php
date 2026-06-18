<?php

declare(strict_types=1);

namespace Milo\Sdk\Exception;

/**
 * A transport-level failure (connection error, DNS, TLS, timeout) — i.e. the
 * request never produced an HTTP response. Distinct from {@see ApiException},
 * which carries a real HTTP status from the server.
 */
class TransportException extends MiloException
{
}
