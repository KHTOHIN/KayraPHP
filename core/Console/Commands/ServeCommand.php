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
        $mode = $args['--mode'] ?? 'standard'; // standard or ultra

        if (!is_dir($publicDir)) {
            echo "❌ Error: Public directory not found at {$publicDir}\n";
            exit(1);
        }

        // Check for ultra mode with Swoole
        if ($mode === 'ultra' && extension_loaded('swoole')) {
            self::startSwooleServer($app, $host, $port, $publicDir);
        } else {
            // Fallback to standard PHP server
            if ($mode === 'ultra') {
                echo "⚠️ Ultra mode requested but Swoole extension not available. Falling back to standard mode.\n";
                echo "   Install Swoole extension for better performance.\n\n";
            }

            echo "🚀 Starting Kayra development server (standard mode)\n";
            echo "👉 URL: http://{$host}:{$port}\n";
            echo "📂 Serving from: {$publicDir}\n";
            echo "Press Ctrl+C to stop.\n\n";

            $cmd = sprintf('php -S %s:%d -t %s', $host, $port, $publicDir);
            passthru($cmd);
        }
    }

    /**
     * Start a Swoole HTTP server for ultra performance
     */
    private static function startSwooleServer(Application $app, string $host, int $port, string $publicDir): void
    {
        echo "🚀 Starting Kayra development server (ultra mode with Swoole)\n";
        echo "👉 URL: http://{$host}:{$port}\n";
        echo "📂 Serving from: {$publicDir}\n";
        echo "Press Ctrl+C to stop.\n\n";

        // Create Swoole HTTP server
        $server = new \Swoole\HTTP\Server($host, $port);

        // Configure server
        $server->set([
            'worker_num' => 4,
            'task_worker_num' => 2,
            'document_root' => $publicDir,
            'enable_static_handler' => true,
        ]);

        // Handle requests
        $server->on('request', function (\Swoole\Http\Request $swooleRequest, \Swoole\Http\Response $swooleResponse) use ($app) {
            // Convert Swoole request to Kayra request
            $request = \Kayra\Http\Request::createFromSwoole($swooleRequest);

            // Get application instance
            $app->boot();

            // Handle the request
            $response = require __DIR__ . '/../../../../bootstrap/kernel.php';
            $response = handleRequest($request, $app);

            // Send response back to client
            $response->sendToSwoole($swooleResponse);
        });

        // Start server
        $server->start();
    }
}
