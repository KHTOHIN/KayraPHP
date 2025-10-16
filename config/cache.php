<?php

return [
    'default' => env('CACHE_DRIVER', 'file'),
    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache'),
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'host' => env('CACHE_HOST', '127.0.0.1'),
            'port' => env('CACHE_PORT', 6379),
        ],
    ],
];