<?php

namespace Kayra\Console\Commands;

use Kayra\Foundation\Application;

class ServeCommand
{
    public static function run(Application $app, array $args): void
    {
        $host = $args['--host'] ?? '127.0.0.1';
        $port = $args['--port'] ?? 8000;
        $publicDir = $app->basePath() . '/public';

        if (!is_dir($publicDir)) {
            echo "❌ Error: Public directory not found at {$publicDir}\n";
            exit(1);
        }

        echo "🚀 Starting Kayra development server\n";
        echo "👉 URL: http://{$host}:{$port}\n";
        echo "📂 Serving from: {$publicDir}\n";
        echo "Press Ctrl+C to stop.\n\n";

        $cmd = sprintf('php -S %s:%d -t %s', $host, $port, $publicDir);
        passthru($cmd);
    }
}