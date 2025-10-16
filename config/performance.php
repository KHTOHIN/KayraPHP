<?php

return [
    'mode' => env('PERFORMANCE_MODE', 'standard'), // standard | ultra
    'engine' => env('PERFORMANCE_ENGINE', 'swoole'), // swoole | roadrunner
    'preload' => true,
    'opcache' => [
        'enable' => true,
        'preload_file' => 'opcache.preload.php',
    ],
];