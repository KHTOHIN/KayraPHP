<?php

declare(strict_types=1);

return [
    /*
    |----------------------------------------------------------------------
    | Template directories
    |----------------------------------------------------------------------
    | Searched in order. A template may use either the .kayra.php or the plain
    | .php extension; .kayra.php is preferred because editors can be taught to
    | treat it as a template rather than as PHP source.
    */

    'paths' => [
        dirname(__DIR__) . '/app/Views',
        dirname(__DIR__) . '/resources/views',
    ],

    /*
    |----------------------------------------------------------------------
    | Compiled template cache
    |----------------------------------------------------------------------
    | In production the compiled file is used without checking whether the
    | source changed. Run `kayra view:cache` as part of deployment.
    */

    'cache' => dirname(__DIR__) . '/storage/framework/views',
];
