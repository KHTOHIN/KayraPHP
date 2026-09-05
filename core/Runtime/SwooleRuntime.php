<?php

declare(strict_types=1);

namespace Kayra\Runtime;

use Kayra\Exceptions\BadRequestHttpException;
use Kayra\Exceptions\Handler as ExceptionHandler;
use Kayra\Foundation\Application;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Runtime\Swoole\SwooleHttpResponse;
use Kayra\Runtime\Swoole\SwooleHttpServer;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/**
 * Swoole HTTP server runtime.
 *
 * The application is booted once per worker and then reused. Everything that
 * belongs to a single request lives in the container's scoped bucket, which
 * {@see Application::terminate()} clears in a finally block after every
 * request — so a thrown exception cannot leave state behind for the next one.
 */
final class SwooleRuntime implements RuntimeInterface
{
    /**
     * @param array<string, mixed> $options Passed through to Swoole\Http\Server::set().
     */
    public function __construct(
        private readonly Application $app,
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8000,
        private readonly array $options = [],
    ) {
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('swoole') || extension_loaded('openswoole');
    }

    public function name(): string
    {
        return 'swoole';
    }

    public function isLongRunning(): bool
    {
        return true;
    }

    public function run(RequestHandlerInterface $kernel): void
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('The swoole extension is not loaded.');
        }

        /** @var class-string $serverClass */
        $serverClass = '\Swoole\Http\Server';

        // Swoole\Http\Server only exists when the extension is loaded, so it is
        // constructed dynamically and described by a local interface. See
        // Kayra\Runtime\Swoole\SwooleHttpServer for why.
        /** @var SwooleHttpServer $server */
        $server = new $serverClass($this->host, $this->port);

        $server->set([
            'enable_coroutine'   => true,
            'http_compression'   => true,
            'worker_num'         => $this->options['worker_num'] ?? swoole_cpu_num(),
            ...$this->options,
        ]);

        $server->on('request', function (object $swooleRequest, object $swooleResponse) use ($kernel): void {
            /** @var SwooleHttpResponse $swooleResponse */
            try {
                try {
                    $request = Request::fromSwoole($swooleRequest);
                } catch (Throwable $e) {
                    // Unparseable request target: answer 400 rather than letting
                    // the worker surface a raw error.
                    $this->send(
                        $this->app->get(ExceptionHandler::class)->render(
                            new BadRequestHttpException('The request target could not be parsed.', $e),
                            new Request('GET', '/'),
                        ),
                        $swooleResponse,
                    );

                    return;
                }

                $this->send($kernel->handle($request), $swooleResponse);
            } catch (Throwable $e) {
                // The kernel already renders exceptions; reaching here means the
                // failure was in the runtime itself.
                $swooleResponse->status(500);
                $swooleResponse->end('Internal Server Error');

                error_log('[kayra] runtime failure: ' . $e->getMessage());
            } finally {
                // The single most important line for long-running safety.
                $this->app->terminate();
            }
        });

        $server->start();
    }

    private function send(\Psr\Http\Message\ResponseInterface $response, object $swooleResponse): void
    {
        /** @var SwooleHttpResponse $swooleResponse */
        $swooleResponse->status($response->getStatusCode());

        $lines = $response instanceof Response
            ? $response->headerLines()
            : $this->genericLines($response);

        foreach ($lines as [$name, $value]) {
            // Swoole needs the multi-value form for repeated headers.
            $swooleResponse->header($name, $value, false);
        }

        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        $swooleResponse->end($body->getContents());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function genericLines(\Psr\Http\Message\ResponseInterface $response): array
    {
        $lines = [];

        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = [$name, $value];
            }
        }

        return $lines;
    }
}
