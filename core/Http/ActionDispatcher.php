<?php

declare(strict_types=1);

namespace Kayra\Http;

use Closure;
use JsonSerializable;
use Kayra\Foundation\Application;
use Kayra\Routing\MatchedRoute;
use Kayra\Routing\RedirectRoute;
use Kayra\Routing\ViewRoute;
use Kayra\View\Factory as ViewFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Stringable;

/**
 * Invokes a route's action and normalises whatever it returns into a response.
 *
 * Action parameters are resolved by the container, so a controller method can
 * type-hint services and name route parameters in any order.
 */
final class ActionDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private readonly Application $app,
        private readonly MatchedRoute $matched,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Rebind the request as it exists *here*, at the bottom of the pipeline.
        // Middleware returns modified copies (PSR-7 messages are immutable), so
        // without this an action that type-hints Request would receive the
        // pre-middleware version and miss every attribute they added.
        $this->app->scopedInstance(ServerRequestInterface::class, $request);

        if ($request instanceof Request) {
            $this->app->scopedInstance(Request::class, $request);
        }

        $handler = $this->matched->route->handler;

        return $this->toResponse(match (true) {
            $handler instanceof ViewRoute     => $this->renderView($handler),
            $handler instanceof RedirectRoute => Response::redirect($handler->to, $handler->status),
            default                           => $this->invoke($handler, $this->parameters($request)),
        });
    }

    private function renderView(ViewRoute $route): ResponseInterface
    {
        return Response::html($this->app->get(ViewFactory::class)->render($route->view, $route->data));
    }

    /**
     * Route parameters as the action should receive them.
     *
     * The matched route carries the raw strings from the URL. Where
     * {@see \Kayra\Routing\SubstituteBindings} has since turned one into a
     * model, the model is what the action asked for.
     *
     * @return array<string, mixed>
     */
    private function parameters(ServerRequestInterface $request): array
    {
        $parameters = $this->matched->parameters;

        foreach (array_keys($parameters) as $name) {
            $bound = $request->getAttribute('route.model.' . $name);

            if ($bound !== null) {
                $parameters[$name] = $bound;
            }
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function invoke(mixed $handler, array $parameters): mixed
    {
        if ($handler instanceof Closure) {
            return $this->app->call($handler, $parameters);
        }

        // 'Class@method' and [Class::class, 'method'] both name a controller.
        if (is_string($handler) && str_contains($handler, '@')) {
            $handler = explode('@', $handler, 2);
        }

        if (is_array($handler)) {
            [$class, $method] = $handler;

            $controller = is_string($class) ? $this->app->make($class) : $class;

            // Hand the base Controller its application so its helpers work.
            if ($controller instanceof Controller) {
                $controller->setApplication($this->app);
            }

            return $this->app->call([$controller, $method], $parameters);
        }

        if (is_string($handler) && class_exists($handler)) {
            $controller = $this->app->make($handler);

            if ($controller instanceof Controller) {
                $controller->setApplication($this->app);
            }

            return $this->app->call([$controller, '__invoke'], $parameters);
        }

        if (is_callable($handler)) {
            return $this->app->call($handler, $parameters);
        }

        throw new RuntimeException(
            'Route [' . $this->matched->route->uri . '] has an unusable handler of type '
            . get_debug_type($handler) . '.',
        );
    }

    /**
     * Normalise an action's return value.
     *
     * Returning a response is always allowed; the shortcuts exist so simple
     * actions do not have to build one.
     */
    private function toResponse(mixed $result): ResponseInterface
    {
        return match (true) {
            $result instanceof ResponseInterface => $result,
            $result === null                     => Response::noContent(),
            is_string($result)                   => Response::html($result),
            $result instanceof Stringable        => Response::html((string) $result),
            is_array($result),
            $result instanceof JsonSerializable  => Response::json($result),
            is_bool($result) || is_int($result) || is_float($result) => Response::json($result),
            is_object($result)                   => Response::json($result),
            default => throw new RuntimeException(
                'Route [' . $this->matched->route->uri . '] returned an unsupported type: '
                . get_debug_type($result) . '.',
            ),
        };
    }
}
