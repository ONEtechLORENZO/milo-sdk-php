<?php

declare(strict_types=1);

namespace Milo\Sdk;

/**
 * Static entry point, mirroring `OpenAI`:
 *
 *   $milo = Milo::client(baseUrl: $url, adminToken: $token);   // quick start
 *   $milo = Milo::factory()->withBaseUrl($url)->withAdminToken($token)
 *                          ->withApiClient('web_app', $secret)->make();   // full control
 */
final class Milo
{
    /**
     * @param array<string,string> $apiClients map of api-client id => HMAC signing secret
     */
    public static function client(
        string $baseUrl,
        ?string $adminToken = null,
        ?string $adminActor = null,
        array $apiClients = [],
    ): Client {
        $factory = (new Factory())->withBaseUrl($baseUrl);
        if ($adminToken !== null) {
            $factory->withAdminToken($adminToken, $adminActor);
        }
        foreach ($apiClients as $clientId => $secret) {
            $factory->withApiClient($clientId, $secret);
        }

        return $factory->make();
    }

    public static function factory(): Factory
    {
        return new Factory();
    }
}
