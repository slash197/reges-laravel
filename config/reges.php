<?php

declare(strict_types=1);

return [

    // "test" or "production"
    'environment' => env('REGES_ENVIRONMENT', 'test'),

    // OAuth client issued by REGES for your software ("reges-api" on the test environment)
    'client_id' => env('REGES_CLIENT_ID'),
    'client_secret' => env('REGES_CLIENT_SECRET'),

    // Name and version of the software sending the messages, reported in every header
    'client_application' => env('REGES_CLIENT_APPLICATION', env('APP_NAME', 'Laravel')),
    'client_version' => env('REGES_CLIENT_VERSION', '1.0'),

    // Default human user reported in the header; can be set per message instead
    'user' => env('REGES_USER'),

    // Overrides for the environment's hosts; leave empty to use the defaults
    'api_url' => env('REGES_API_URL'),
    'token_url' => env('REGES_TOKEN_URL'),

    // API key pair of the one registry the container-bound client works on
    // (Setari -> Acces -> Chei API). Applications with a key per tenant leave
    // these empty and build clients through RegesFactory instead.
    'username' => env('REGES_USERNAME'),
    'password' => env('REGES_PASSWORD'),

    // Access tokens are kept encrypted in this cache store; null is the default store
    'tokens' => [
        'store' => env('REGES_CACHE_STORE'),
        'prefix' => 'reges:token:',
    ],

    // Receives request metadata, never message contents; null is the default channel
    'log_channel' => env('REGES_LOG_CHANNEL'),

    // Used for the built-in Guzzle client; ignored when the application binds
    // its own Psr\Http\Client\ClientInterface
    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
    ],

];
