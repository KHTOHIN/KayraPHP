<?php

declare(strict_types=1);

namespace Kayra\Runtime;

use Kayra\Exceptions\BadRequestHttpException;
use Kayra\Exceptions\Handler as ExceptionHandler;
use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The classic one-request-per-process runtime: php-fpm, mod_php, or the
 * built-in development server.
 */
final class FpmRuntime implements RuntimeInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly ResponseEmitter $emitter = new ResponseEmitter(),
    ) {
    }

    public static function isAvailable(): bool
    {
        return PHP_SAPI !== 'cli' || php_sapi_name() === 'cli-server';
    }

    public function name(): string
    {
        return 'php-' . PHP_SAPI;
    }

    public function isLongRunning(): bool
    {
        return false;
    }

    public function run(RequestHandlerInterface $kernel): void
    {
        try {
            $request = Request::fromGlobals();
        } catch (Throwable $e) {
            // Building the request can fail on a request target that no parser
            // accepts. That happens before the kernel exists, so it must be
            // caught here or it escapes as an uncaught error.
            $this->emitter->emit($this->badRequest($e));
            $this->app->terminate();

            return;
        }

        $response = $kernel->handle($request);

        $this->emitter->emit($response);

        // Hand the connection back before running teardown, so a slow shutdown
        // task does not keep the client waiting.
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $this->app->terminate();
    }

    /**
     * Render an unparseable request as a 400 through the normal handler, so it
     * is logged and formatted like every other error.
     */
    private function badRequest(Throwable $e): ResponseInterface
    {
        return $this->app->get(ExceptionHandler::class)->render(
            new BadRequestHttpException('The request target could not be parsed.', $e),
            // A synthetic request: the real one could not be built.
            new Request('GET', '/'),
        );
    }
}
