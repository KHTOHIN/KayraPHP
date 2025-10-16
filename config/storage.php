<?php

return [
    'default' => env('STORAGE_DRIVER', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('uploads'),
            'auto_fold' => true, // Year/month/day subfolders
            'public' => false,
        ],
        'minio' => [
            'driver' => 'minio',
            'endpoint' => env('MINIO_ENDPOINT', 'localhost:9000'),
            'key' => env('MINIO_ACCESS_KEY', 'minioadmin'),
            'secret' => env('MINIO_SECRET_KEY', 'minioadmin'),
            'bucket' => 'kayra',
            'public' => false,
        ],
    ],
];