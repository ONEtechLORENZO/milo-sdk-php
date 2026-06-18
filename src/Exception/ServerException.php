<?php

declare(strict_types=1);

namespace Milo\Sdk\Exception;

/**
 * HTTP 5xx — an internal Milo error. For `POST /v1/messages` it is safe to
 * retry with the SAME `external_message_id` (the pipeline is idempotent). The
 * transport already retries 5xx a bounded number of times before throwing.
 */
class ServerException extends ApiException
{
}
