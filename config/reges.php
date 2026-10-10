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
    // these empty and bind their own CredentialsProvider instead.
    'username' => env('REGES_USERNAME'),
    'password' => env('REGES_PASSWORD'),

    // Access tokens are kept encrypted in this cache store; null is the default store
    'tokens' => [
        'store' => env('REGES_CACHE_STORE'),
        'prefix' => 'reges:token:',
    ],

    // Local copy of the nomenclators, filled by reges:nomenclators. Use a store
    // that cache:clear does not empty if validation must not lapse.
    'nomenclators' => [
        'store' => env('REGES_NOMENCLATOR_CACHE_STORE', env('REGES_CACHE_STORE')),
        'prefix' => 'reges:nomenclator:',
    ],

    // How reges:results reads the result queue. Applications sharing one
    // registry each need their own consumer id.
    'results' => [
        'consumer_id' => env('REGES_CONSUMER_ID'),
        'limit' => 500,
    ],

    // Messages stored by Outbox::queue() and sent by reges:send. Set enabled
    // to false if the application keeps its own; no table is created then.
    'outbox' => [
        'enabled' => env('REGES_OUTBOX', true),
        'table' => 'reges_messages',
        // Most messages reges:send attempts in one run
        'limit' => 100,
        // Seconds between attempts after a transient failure; doubles after the 60th attempt
        'retry_delay' => 60,
        // Seconds after which an undelivered message is given up on; 0 never gives up
        'give_up_after' => 3 * 24 * 60 * 60,
        // Seconds after which a message left in "sending" by a dead process is sent again
        'sending_timeout' => 600,
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
