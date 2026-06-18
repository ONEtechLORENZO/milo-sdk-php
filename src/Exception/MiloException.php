<?php

declare(strict_types=1);

namespace Milo\Sdk\Exception;

/**
 * Base type for every exception thrown by the SDK. Catch this to handle any
 * Milo failure uniformly.
 */
class MiloException extends \RuntimeException
{
    /**
     * The `external_message_id` of the send that failed, when known. Set by
     * {@see \Milo\Sdk\Resources\Messaging::send()} so a caller can safely retry
     * the SAME message id (server idempotency dedupes) even when the SDK
     * generated the id. Null for non-send failures.
     */
    public ?string $externalMessageId = null;
}
