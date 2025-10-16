<?php

namespace Kayra\Console\Commands;

use Kayra\Foundation\Application;

class CacheClearCommand
{
    public static function run(Application $app, array $args): void
    {
        $cacheDir = $app->storagePath('cache');
        $files = glob($cacheDir . '/*');

        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }

        echo "Cache cleared successfully.\n";
    }
}