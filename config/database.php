<?php

declare(strict_types=1);

return [
    /*
    |----------------------------------------------------------------------
    | Default connection
    |----------------------------------------------------------------------
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |----------------------------------------------------------------------
    | Connections
    |----------------------------------------------------------------------
    | Credentials come from the environment. Never commit them: config files
    | are cached by `kayra optimize`, and a cached secret is a secret in the
    | build artefact.
    */

    'connections' => [

        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_DATABASE', dirname(__DIR__) . '/database/database.sqlite'),
        ],

        'mysql' => [
            'driver'   => 'mysql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'kayra'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8mb4',
        ],

        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'kayra'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
        ],

        'sqlsrv' => [
            'driver'   => 'sqlsrv',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'kayra'),
            'username' => env('DB_USERNAME', 'sa'),
            'password' => env('DB_PASSWORD', ''),
        ],

    ],

    /*
    |----------------------------------------------------------------------
    | Migrations
    |----------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'kayra_migrations',
        'path'  => dirname(__DIR__) . '/database/migrations',
    ],
];
