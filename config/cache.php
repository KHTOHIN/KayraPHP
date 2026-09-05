<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default store
    |--------------------------------------------------------------------------
    */

    'default' => env('CACHE_STORE', 'file'),

    /*
    |--------------------------------------------------------------------------
    | Default lifetime
    |--------------------------------------------------------------------------
    | Seconds, used when a call passes no TTL of its own. A store may override
    | it with its own 'ttl'.
    */

    'ttl' => (int) env('CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Stores
    |--------------------------------------------------------------------------
    | 'array' lives as long as the process, which makes it the right choice for
    | tests and for holding a value a single request reads more than once.
    |
    | 'file' signs every entry with the application key, so a cache file edited
    | on disk is discarded rather than unserialised. That check is what keeps a
    | writable cache directory from being a code-execution path.
    |
    | Register another driver with CacheManager::extend('redis', ...).
    */

    'stores' => [
        'array' => [
            'driver' => 'array',
        ],

        'file' => [
            'driver' => 'file',
            'path'   => dirname(__DIR__) . '/storage/framework/cache',
        ],
    ],
];
