<?php

declare(strict_types=1);

use Kayra\Foundation\Providers\DatabaseServiceProvider;
use Kayra\Foundation\Providers\FoundationServiceProvider;
use Kayra\Foundation\Providers\RoutingServiceProvider;
use Kayra\Foundation\Providers\SecurityServiceProvider;
use Kayra\Foundation\Providers\ViewServiceProvider;
use Kayra\Http\Middleware\HandleCors;
use Kayra\Http\Middleware\MethodOverride;
use Kayra\Http\Middleware\SecurityHeaders;
use Kayra\Http\Middleware\StartSession;
use Kayra\Http\Middleware\ThrottleRequests;
use Kayra\Http\Middleware\TrustProxies;
use Kayra\Http\Middleware\ValidateHost;
use Kayra\Http\Middleware\ValidateSignature;
use Kayra\Http\Middleware\VerifyCsrfToken;

return [
    /*
    |----------------------------------------------------------------------
    | Application
    |----------------------------------------------------------------------
    */

    'name'  => env('APP_NAME', 'KayraPHP'),

    // 'local' | 'testing' | 'production'
    'env'   => env('APP_ENV', 'production'),

    // Debug output is force-disabled in production regardless of this value.
    'debug' => env('APP_DEBUG', false),

    'url'   => env('APP_URL', 'http://localhost:8000'),
    'key'   => env('APP_KEY'),

    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale'   => env('APP_LOCALE', 'en'),

    /*
    |----------------------------------------------------------------------
    | Runtime
    |----------------------------------------------------------------------
    | 'auto' picks Swoole when the extension is present and we are on the CLI,
    | and the standard SAPI runtime otherwise. Force it with 'fpm' or 'swoole'.
    */

    'runtime' => env('APP_RUNTIME', 'auto'),
    'host'    => env('APP_HOST', '127.0.0.1'),
    'port'    => env('APP_PORT', '8000'),

    /*
    |----------------------------------------------------------------------
    | Service providers
    |----------------------------------------------------------------------
    | register() runs on all of them first, then boot(). Order therefore only
    | matters for boot-time side effects.
    */

    'providers' => [
        FoundationServiceProvider::class,
        RoutingServiceProvider::class,
        ViewServiceProvider::class,
        SecurityServiceProvider::class,
        DatabaseServiceProvider::class,

        App\Providers\AppServiceProvider::class,
    ],

    /*
    |----------------------------------------------------------------------
    | Route files
    |----------------------------------------------------------------------
    */

    'route_files' => [
        dirname(__DIR__) . '/routes/web.php',
        dirname(__DIR__) . '/routes/api.php',
    ],

    /*
    |----------------------------------------------------------------------
    | Global middleware
    |----------------------------------------------------------------------
    | Runs on every request, in this order.
    |
    | TrustProxies is first so that later middleware sees the corrected scheme
    | and client address; ValidateHost then checks the host it produced.
    */

    'middleware' => [
        TrustProxies::class,
        ValidateHost::class,
        HandleCors::class,
        SecurityHeaders::class,
        MethodOverride::class,
    ],

    /*
    |----------------------------------------------------------------------
    | Route middleware aliases
    |----------------------------------------------------------------------
    | Usable as ->middleware('auth'). A value may be a list, which makes it a
    | middleware group.
    */

    'middleware_aliases' => [
        'auth'     => App\Middlewares\AuthMiddleware::class,

        // `throttle:60,1` — 60 requests per minute. Parameters after the colon
        // reach the middleware constructor.
        'throttle' => ThrottleRequests::class,

        'signed'   => ValidateSignature::class,

        // A name may map to an ordered list, which makes it a middleware group.
        // 'web' is the one to put on anything that renders a form.
        'session'  => [StartSession::class],
        'web'      => [StartSession::class, VerifyCsrfToken::class],
    ],
];
