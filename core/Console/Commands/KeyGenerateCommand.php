<?php

namespace Kayra\Console\Commands;

use Kayra\Foundation\Application;

class KeyGenerateCommand
{
    public static function run(Application $app, array $args): void
    {
        $envFile = $app->storagePath('../.env');
        $key = base64_encode(random_bytes(32));

        if (!file_exists($envFile)) {
            echo ".env file not found!\n";
            return;
        }

        $content = file_get_contents($envFile);
        $content = preg_replace(
            '/^APP_KEY=.*$/m',
            "APP_KEY={$key}",
            $content
        );

        file_put_contents($envFile, $content);
        echo "New APP_KEY generated successfully.\n";
    }
}