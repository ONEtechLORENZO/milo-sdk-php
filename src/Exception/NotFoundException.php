<?php

declare(strict_types=1);

namespace Milo\Sdk\Exception;

/** HTTP 404 — unknown tenant/task/tool/message/conversation id. */
class NotFoundException extends ApiException
{
}
