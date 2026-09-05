<?php

declare(strict_types=1);

return [
    /*
    |----------------------------------------------------------------------
    | Default guard
    |----------------------------------------------------------------------
    */

    'default' => env('AUTH_GUARD', 'web'),

    /*
    |----------------------------------------------------------------------
    | Guards
    |----------------------------------------------------------------------
    | 'session' — cookie-backed, for browsers. Requires StartSession.
    | 'token'   — bearer tokens, for APIs. Tokens are stored hashed.
    |
    | Use them per route: ->middleware('auth') or ->middleware('auth:api').
    */

    'guards' => [

        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver'      => 'token',
            'provider'    => 'users',
            'token_model' => App\Models\ApiToken::class,
            'user_key'    => 'user_id',
            'hash_column' => 'token',
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | User providers
    |----------------------------------------------------------------------
    | The model must use the Kayra\Auth\Concerns\AuthenticatesUsers trait.
    */

    'providers' => [

        'users' => [
            'driver' => 'model',
            'model'  => App\Models\User::class,
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Password hashing
    |----------------------------------------------------------------------
    | 'bcrypt' works on every PHP build. 'argon2id' is stronger where it is
    | compiled in — check with `php -i | grep argon`.
    |
    | Raising the cost is safe at any time: existing hashes are upgraded on
    | each user's next successful login, with no reset required.
    |
    | bcrypt silently truncates passwords at 72 bytes, so the hasher rejects
    | longer ones outright rather than hashing only the first 72.
    */

    'hashing' => [
        'driver' => env('HASH_DRIVER', 'bcrypt'),

        // bcrypt
        'cost' => (int) env('BCRYPT_COST', 12),

        // argon2
        'memory'  => 65536,
        'time'    => 4,
        'threads' => 1,
    ],

    /*
    |----------------------------------------------------------------------
    | Password resets
    |----------------------------------------------------------------------
    | Tokens are stored hashed, expire, and are single-use.
    */

    'passwords' => [
        'table'   => 'password_resets',
        'expires' => (int) env('PASSWORD_RESET_EXPIRES', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies
    |--------------------------------------------------------------------------
    | Model => policy class. The Gate consults these before any closure gate,
    | so a policy always wins over an ad-hoc rule of the same name.
    |
    | An ability with no policy and no gate is denied. That is deliberate:
    | forgetting to write a rule locks the door rather than opening it.
    */

    'policies' => [
        App\Models\Post::class => App\Policies\PostPolicy::class,
    ],

];
