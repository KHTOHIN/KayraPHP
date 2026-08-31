<?php

declare(strict_types=1);

namespace Kayra\Http;

use Kayra\Exceptions\Handler as ExceptionHandler;
use Kayra\Foundation\Application;
use Kayra\Pipeline\CallableHandler;
use Kayra\Pipeline\Pipeline;
use Kayra\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The HTTP kernel: turns a request into a response.
 *
 * The pipeline is built in two stages so that route middleware only runs for
 * requests that actually matched a route:
 *
 *     global middleware -> route matching -> route middleware -> action
 *
 * Every exception is funnelled through the {@see ExceptionHandler}, so no
 * failure path can escape as a raw PHP error.
 */
final class Kernel implements RequestHandlerInterface
{
    /**
     * @param list<class-string> $middleware Global middleware, in order.
     * @param array<string, class-string|list<class-string>> $aliases Route middleware names.
     */
    public function __construct(
        private readonly Application $app,
        private readonly Router $router,
        private readonly ExceptionHandler $exceptions,
        private array $middleware = [],
        private array $aliases = [],
    ) {
    }

    /**
     * @param list<class-string> $middleware
     */
    public function setMiddleware(array $middleware): void
    {
        $this->middleware = $middleware;
    }

    /**
     * @param array<string, class-string|list<class-string>> $aliases
     */
    public function setAliases(array $aliases): void
    {
        $this->aliases = $aliases;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->shareRequest($request);

            $pipeline = new Pipeline(
                $this->middleware,
                new CallableHandler($this->dispatch(...)),
                $this->app,
            );

            return $this->finalise($pipeline->handle($request), $request);
        } catch (Throwable $e) {
            return $this->finalise($this->exceptions->render($e, $request), $request);
        }
    }

    /**
     * Match the route, then run its middleware around the action.
     */
    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $matched = $this->router->resolve($request);

        $request = $request->withAttribute('route', $matched->route)
            ->withAttribute('route.parameters', $matched->parameters);

        foreach ($matched->parameters as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        // Re-share: middleware and the action must see the enriched request.
        $this->shareRequest($request);

        $middleware = $this->expandAliases($matched->route->getMiddleware());

        $action = new ActionDispatcher($this->app, $matched);

        return (new Pipeline($middleware, $action, $this->app))->handle($request);
    }

    /**
     * Bind the current request into the container for this request only.
     */
    private function shareRequest(ServerRequestInterface $request): void
    {
        $this->app->scopedInstance(ServerRequestInterface::class, $request);

        if ($request instanceof Request) {
            $this->app->scopedInstance(Request::class, $request);
        }
    }

    /**
     * Turn middleware aliases into class names.
     *
     * An alias may map to a list, which is how middleware groups such as 'web'
     * and 'api' are expressed.
     *
     * @param list<string> $middleware
     * @return list<string|MiddlewareParameters>
     */
    private function expandAliases(array $middleware): array
    {
        $resolved = [];

        foreach ($middleware as $item) {
            // 'throttle:60,1' — parameters after the colon are passed through
            // to the middleware constructor by the container.
            $separator = strpos($item, ':');
            $name = $separator === false ? $item : substr($item, 0, $separator);
            $parameters = $separator === false ? null : substr($item, $separator + 1);

            $target = $this->aliases[$name] ?? $name;

            foreach ((array) $target as $class) {
                $resolved[] = $parameters === null
                    ? $class
                    : new MiddlewareParameters($class, explode(',', $parameters));
            }
        }

        return $resolved;
    }

    /**
     * Last-mile response fixes that must apply to every response.
     */
    private function finalise(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $status = $response->getStatusCode();

        // 204/304 and 1xx must not carry a body (RFC 9110).
        if ($status === 204 || $status === 304 || ($status >= 100 && $status < 200)) {
            return $response->withoutHeader('Content-Type')
                ->withoutHeader('Content-Length')
                ->withBody(Stream::of(''));
        }

        // A HEAD response carries the headers of the GET but no body.
        if (strcasecmp($request->getMethod(), 'HEAD') === 0) {
            $size = $response->getBody()->getSize();

            $response = $size === null
                ? $response
                : $response->withHeader('Content-Length', (string) $size);

            return $response->withBody(Stream::of(''));
        }

        if (!$response->hasHeader('Content-Length')) {
            $size = $response->getBody()->getSize();

            if ($size !== null) {
                $response = $response->withHeader('Content-Length', (string) $size);
            }
        }

        return $response;
    }
}
