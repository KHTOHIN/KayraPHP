<?php

declare(strict_types=1);

namespace Kayra\Pipeline;

use Kayra\Container\Container;
use Kayra\Http\MiddlewareParameters;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

/**
 * A PSR-15 middleware pipeline.
 *
 * The pipeline is immutable at run time: {@see handle()} walks an index rather
 * than shifting an array, so the same instance can serve concurrent requests in
 * a coroutine runtime without middleware disappearing mid-flight.
 */
final class Pipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface|class-string|callable> $middleware
     */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $endpoint,
        private readonly ?Container $container = null,
        private readonly int $index = 0,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $current = $this->middleware[$this->index] ?? null;

        if ($current === null) {
            return $this->endpoint->handle($request);
        }

        $next = new self($this->middleware, $this->endpoint, $this->container, $this->index + 1);

        return $this->resolve($current)->process($request, $next);
    }

    private function resolve(mixed $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        // `throttle:60,1` — the parameter list is handed to a $parameters
        // constructor argument on the middleware.
        if ($middleware instanceof MiddlewareParameters) {
            return $this->instantiate(
                $middleware->middleware,
                ['parameters' => $middleware->parameters],
            );
        }

        if (is_string($middleware)) {
            return $this->instantiate($middleware, []);
        }

        if (is_callable($middleware)) {
            return new CallableMiddleware($middleware(...));
        }

        throw new RuntimeException(
            'Middleware must be a ' . MiddlewareInterface::class . ', a class name, or a callable; '
            . get_debug_type($middleware) . ' given.',
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function instantiate(string $class, array $parameters): MiddlewareInterface
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Middleware [{$class}] does not exist.");
        }

        $instance = $this->container?->make($class, $parameters) ?? new $class();

        if (!$instance instanceof MiddlewareInterface) {
            throw new RuntimeException(
                "Middleware [{$class}] must implement " . MiddlewareInterface::class . '.',
            );
        }

        return $instance;
    }
}
