<?php

declare(strict_types=1);

namespace Kayra\Runtime;

use Kayra\Exceptions\BadRequestHttpException;
use Kayra\Exceptions\Handler as ExceptionHandler;
use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/**
 * FrankenPHP worker-mode runtime.
 *
 * FrankenPHP boots the application once and then hands it request after
 * request, like Swoole, but keeps the familiar superglobal-based request model:
 * each call to frankenphp_handle_request() repopulates $_SERVER, $_GET and the
 * rest before invoking the callback. That means {@see Request::fromGlobals()}
 * works unchanged and only the loop around it differs.
 *
 * Requests are handled one at a time per worker, so the container's execution
 * contexts do not come into play here — but terminate() is still mandatory, and
 * still runs in a finally block.
 */
final class FrankenPhpRuntime implements RuntimeInterface
{
    /**
     * @param int $maxRequests Restart the worker after this many requests, as a
     *                         backstop against slow leaks in application code.
     *                         Zero disables recycling.
     */
    public function __construct(
        private readonly Application $app,
        private readonly ResponseEmitter $emitter = new ResponseEmitter(),
        private readonly int $maxRequests = 1000,
    ) {
    }

    public static function isAvailable(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    public function name(): string
    {
        return 'frankenphp-worker';
    }

    public function isLongRunning(): bool
    {
        return true;
    }

    public function run(RequestHandlerInterface $kernel): void
    {
        if (!self::isAvailable()) {
            throw new RuntimeException(
                'frankenphp_handle_request() is unavailable. Start this script through FrankenPHP in worker mode.',
            );
        }

        // Warm the graph before the first request rather than during it.
        $handled = 0;

        $handler = function () use ($kernel, &$handled): void {
            try {
                try {
                    $request = Request::fromGlobals();
                } catch (Throwable $e) {
                    $this->emitter->emit(
                        $this->app->get(ExceptionHandler::class)->render(
                            new BadRequestHttpException('The request target could not be parsed.', $e),
                            new Request('GET', '/'),
                        ),
                    );

                    return;
                }

                $this->emitter->emit($kernel->handle($request));
            } catch (Throwable $e) {
                // The kernel renders its own exceptions; reaching here means the
                // failure was in the runtime itself.
                if (!headers_sent()) {
                    http_response_code(500);
                }

                echo 'Internal Server Error';
                error_log('[kayra] runtime failure: ' . $e->getMessage());
            } finally {
                $this->app->terminate();
                $handled++;
            }
        };

        /** @var callable(callable(): void): bool $loop */
        $loop = 'frankenphp_handle_request';

        while ($loop($handler)) {
            // Reclaim cycles between requests; a long-running worker otherwise
            // accumulates them until the collector fires at an arbitrary moment.
            gc_collect_cycles();

            if ($this->maxRequests > 0 && $handled >= $this->maxRequests) {
                break;
            }
        }
    }
}
