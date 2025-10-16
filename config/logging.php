<?php

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'deprecations' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/kayra.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        'async' => [
            'driver' => 'async',
            'queue' => storage_path('logs/queue.jsonl'),
            'flush_interval' => 100,
        ],
    ],
];