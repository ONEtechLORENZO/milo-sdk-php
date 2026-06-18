<?php

declare(strict_types=1);

namespace Milo\Sdk\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Milo\Sdk\Client;
use Milo\Sdk\Resources\ApiClients;
use Milo\Sdk\Resources\Audit;
use Milo\Sdk\Resources\Billing;
use Milo\Sdk\Resources\Catalog;
use Milo\Sdk\Resources\Channels;
use Milo\Sdk\Resources\Conversations;
use Milo\Sdk\Resources\Messaging;
use Milo\Sdk\Resources\Metrics;
use Milo\Sdk\Resources\Secrets;
use Milo\Sdk\Resources\Tasks;
use Milo\Sdk\Resources\Tenants;
use Milo\Sdk\Resources\Tools;
use Milo\Sdk\Resources\Usage;

/**
 * `Milo` facade — proxies to the singleton {@see Client}.
 *
 *   Milo::tenants()->create([...]);
 *   Milo::messaging('acme', 'web_app')->send('hi')->poll();
 *
 * @method static Tenants tenants()
 * @method static Tasks tasks(string $tenantId)
 * @method static Tools tools(string $tenantId)
 * @method static Catalog catalog()
 * @method static ApiClients apiClients(string $tenantId)
 * @method static Channels channels(string $tenantId)
 * @method static Secrets secrets(string $tenantId)
 * @method static Usage usage(string $tenantId)
 * @method static Billing billing(string $tenantId)
 * @method static Audit audit(string $tenantId)
 * @method static Conversations conversations(string $tenantId)
 * @method static Metrics metrics()
 * @method static Messaging messaging(string $tenantId, string $clientId, ?string $apiKey = null, ?string $defaultTaskId = null)
 *
 * @see Client
 */
final class Milo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'milo';
    }
}
