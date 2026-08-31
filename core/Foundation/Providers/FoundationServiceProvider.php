<?php

declare(strict_types=1);

namespace Kayra\Foundation\Providers;

use Kayra\Exceptions\Handler as ExceptionHandler;
use Kayra\Foundation\Application;
use Kayra\Foundation\ServiceProvider;
use Kayra\Runtime\FpmRuntime;
use Kayra\Runtime\FrankenPhpRuntime;
use Kayra\Runtime\ResponseEmitter;
use Kayra\Runtime\RuntimeInterface;
use Kayra\Runtime\SwooleRuntime;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Registers logging, error handling and the runtime.
 */
final class FoundationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerLogger();
        $this->registerExceptionHandler();
        $this->registerRuntime();
    }

    private function registerLogger(): void
    {
        $this->app->singleton(LoggerInterface::class, function (Application $app): LoggerInterface {
            $config = $app->config();

            $logger = new Logger($config->string('app.name', 'kayra'));
            $level = Level::fromName($config->string('logging.level', 'debug'));

            $channel = $config->string('logging.channel', 'daily');

            $handler = match ($channel) {
                'stderr' => new StreamHandler('php://stderr', $level),
                'stdout' => new StreamHandler('php://stdout', $level),
                'single' => new StreamHandler(
                    $app->ensureDirectory($app->storagePath('logs')) . '/kayra.log',
                    $level,
                ),
                default => new RotatingFileHandler(
                    $app->ensureDirectory($app->storagePath('logs')) . '/kayra.log',
                    $config->int('logging.days', 14),
                    $level,
                ),
            };

            // Structured logs in production so log aggregators can parse them;
            // readable lines in development.
            $handler->setFormatter(
                $app->isProduction()
                    ? new JsonFormatter()
                    : new LineFormatter(null, null, true, true),
            );

            $logger->pushHandler($handler);

            return $logger;
        }, lazy: true);

        $this->app->alias('log', LoggerInterface::class);
    }

    private function registerExceptionHandler(): void
    {
        $this->app->singleton(
            ExceptionHandler::class,
            static fn (Application $app): ExceptionHandler => new ExceptionHandler(
                $app,
                $app->get(LoggerInterface::class),
            ),
        );
    }

    private function registerRuntime(): void
    {
        $this->app->singleton(RuntimeInterface::class, static function (Application $app): RuntimeInterface {
            $configured = $app->config()->string('app.runtime', 'auto');

            $emitter = $app->get(ResponseEmitter::class);

            return match ($configured) {
                'swoole' => new SwooleRuntime(
                    $app,
                    $app->config()->string('app.host', '127.0.0.1'),
                    $app->config()->int('app.port', 8000),
                ),
                'frankenphp' => new FrankenPhpRuntime($app, $emitter),
                'fpm'        => new FpmRuntime($app, $emitter),

                // Auto-detect. FrankenPHP is checked first because when it is
                // present the process is already inside its worker loop.
                default => match (true) {
                    FrankenPhpRuntime::isAvailable() => new FrankenPhpRuntime($app, $emitter),
                    SwooleRuntime::isAvailable() && PHP_SAPI === 'cli' => new SwooleRuntime($app),
                    default => new FpmRuntime($app, $emitter),
                },
            };
        });

        $this->app->singleton(ResponseEmitter::class, static fn (): ResponseEmitter => new ResponseEmitter());
    }
}
