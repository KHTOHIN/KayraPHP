<?php

declare(strict_types=1);

return [
    /*
    |----------------------------------------------------------------------
    | Log channel
    |----------------------------------------------------------------------
    | 'daily'  rotating file in storage/logs
    | 'single' one file in storage/logs
    | 'stderr' standard error — the right choice inside a container
    | 'stdout' standard output
    */

    'channel' => env('LOG_CHANNEL', 'daily'),

    // emergency|alert|critical|error|warning|notice|info|debug
    'level' => env('LOG_LEVEL', 'debug'),

    // Days of rotated files to keep, for the 'daily' channel.
    'days' => 14,
];
