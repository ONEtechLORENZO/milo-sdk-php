<?php

declare(strict_types=1);

namespace Milo\Sdk\Laravel;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Milo\Sdk\Client;
use Milo\Sdk\Factory;

/**
 * Laravel integration. Auto-discovered via composer `extra.laravel`. Binds the
 * {@see Client} as a singleton built from `config/milo.php`, so apps can
 * `Milo::tenants()` / inject `Client $milo`. Publish the config with:
 *
 *   php artisan vendor:publish --tag=milo-config
 */
final class MiloServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/milo.php', 'milo');

        $this->app->singleton(Client::class, static function ($app): Client {
            /** @var array<string,mixed> $config */
            $config = $app['config']['milo'] ?? [];

            $factory = (new Factory())->withBaseUrl((string) ($config['base_url'] ?? ''));

            if (!empty($config['admin_token'])) {
                $factory->withAdminToken((string) $config['admin_token'], $config['admin_actor'] ?? null);
            }
            if (!empty($config['timeout'])) {
                $factory->withTimeout((float) $config['timeout']);
            }
            if (isset($config['max_retries'])) {
                $factory->withMaxRetries((int) $config['max_retries']);
            }
            if (!empty($config['api_gateway_key'])) {
                $factory->withApiGatewayKey((string) $config['api_gateway_key']);
            }
            foreach ((array) ($config['api_clients'] ?? []) as $clientId => $secret) {
                if (is_string($secret) && $secret !== '') {
                    $factory->withApiClient((string) $clientId, $secret);
                }
            }

            return $factory->make();
        });

        $this->app->alias(Client::class, 'milo');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/config/milo.php' => $this->app->configPath('milo.php'),
            ], 'milo-config');
        }
    }

    /** @return array<int,string> */
    public function provides(): array
    {
        return [Client::class, 'milo'];
    }
}
