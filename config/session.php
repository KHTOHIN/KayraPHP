<?php

declare(strict_types=1);

return [
    /*
    |----------------------------------------------------------------------
    | Cookie
    |----------------------------------------------------------------------
    */

    'cookie' => env('SESSION_COOKIE', 'kayra_session'),

    'path' => '/',

    // Leave empty to scope the cookie to the exact host that set it.
    'domain' => env('SESSION_DOMAIN', ''),

    /*
    | 'Lax'    — sent on top-level navigation; the right default
    | 'Strict' — never sent cross-site; breaks inbound links to logged-in pages
    | 'None'   — sent everywhere, and then Secure is mandatory
    */
    'same_site' => env('SESSION_SAME_SITE', 'Lax'),

    /*
    | null follows the request scheme: Secure over https, off over plain http
    | so local development works. Set true explicitly in production.
    */
    'secure' => env('SESSION_SECURE'),

    /*
    |----------------------------------------------------------------------
    | Lifetime
    |----------------------------------------------------------------------
    | Seconds of inactivity before a session expires. Enforced on read as
    | well as by garbage collection, so an uncollected file is still dead.
    */

    'lifetime' => (int) env('SESSION_LIFETIME', 7200),

    /*
    |----------------------------------------------------------------------
    | Storage
    |----------------------------------------------------------------------
    | Session payloads are encrypted at rest with APP_KEY.
    */

    'files' => dirname(__DIR__) . '/storage/framework/sessions',

    /*
    |----------------------------------------------------------------------
    | CSRF exemptions
    |----------------------------------------------------------------------
    | Paths excluded from CSRF verification, as fnmatch() patterns. Use this
    | only for endpoints authenticated some other way — an inbound webhook
    | verified by signature, for example. Every entry here is an endpoint a
    | third-party site can make a browser submit, so keep the list short.
    */

    'csrf_except' => [
        'api/*',
    ],
];
