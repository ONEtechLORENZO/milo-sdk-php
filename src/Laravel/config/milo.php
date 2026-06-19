<?php

declare(strict_types=1);

/**
 * Milo SDK configuration. Publish with:
 *   php artisan vendor:publish --tag=milo-config
 */
return [
    // API Gateway invoke URL INCLUDING the stage segment, e.g.
    // https://abc123.execute-api.eu-south-1.amazonaws.com/prod
    'base_url' => env('MILO_BASE_URL', ''),

    // Admin (control-plane) token + audit actor. Needs role admin/owner for writes.
    'admin_token' => env('MILO_ADMIN_TOKEN'),
    'admin_actor' => env('MILO_ADMIN_ACTOR'),

    // Per-api-client bearer keys (milo_sk_…) for the data plane (messaging).
    // Key = api-client id, value = the bearer key. Prefer config over env for
    // more than one client.
    'api_clients' => array_filter([
        env('MILO_API_CLIENT_ID', '') => env('MILO_API_CLIENT_SECRET', ''),
    ], static fn ($secret, $id) => $id !== '' && $secret !== '', ARRAY_FILTER_USE_BOTH),

    // API Gateway usage-plan key (sent as x-api-key). REQUIRED against staging/prod
    // (api_require_api_key=true) or /v1 writes 403 at the gateway. Edge quota
    // credential, not auth. Retrieve: aws apigateway get-api-key --include-value.
    'api_gateway_key' => env('MILO_API_GATEWAY_KEY'),

    // HTTP behaviour.
    'timeout' => (float) env('MILO_TIMEOUT', 30),
    'max_retries' => (int) env('MILO_MAX_RETRIES', 3),
];
