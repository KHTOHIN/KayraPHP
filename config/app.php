<?php

return [
    'name' => env('APP_NAME', 'KayraPHP'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'key' => env('APP_KEY'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'architecture' => env('APP_ARCHITECTURE', 'mvc'), // Options: mvc, factory-service, domain-driven, custom
    'providers' => [
        App\Providers\AppServiceProvider::class,
    ],
    'middleware' => [
        App\Middlewares\AuthMiddleware::class,
    ],
];
