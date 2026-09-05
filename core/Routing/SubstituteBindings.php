<?php

declare(strict_types=1);

namespace Kayra\Routing;

use Closure;
use Kayra\Database\Model;
use Kayra\Database\ModelNotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Turns route parameters into the models they name.
 *
 * A route of `/posts/{post}` whose action reads
 *
 *     public function edit(Post $post)
 *
 * gets the record, not the string "17". Nothing has to be declared: the type
 * hint is the declaration, and a parameter with no model type hint is left
 * exactly as it was.
 *
 * The lookup happens here, in middleware, rather than in the action — which is
 * the whole point. `can:update,post` runs before the controller is built, so a
 * policy can only be given the record if something upstream has already
 * fetched it. Doing it in the action would mean the authorization layer sees an
 * id and the action sees a model, and a policy handed an id cannot answer
 * "does this belong to you".
 *
 * A parameter that matches no row raises ModelNotFoundException, which the
 * exception handler renders as 404. That is deliberate: the alternative is an
 * action defensively re-checking every binding it was promised.
 */
final class SubstituteBindings implements MiddlewareInterface
{
    /**
     * Reflection is not free, and a route's signature never changes within a
     * process. Keyed by handler identity.
     *
     * @var array<string, array<string, class-string<Model>>>
     */
    private static array $signatures = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute('route');
        $parameters = $request->getAttribute('route.parameters');

        if (!$route instanceof Route || !is_array($parameters) || $parameters === []) {
            return $handler->handle($request);
        }

        foreach ($this->modelParameters($route) as $name => $class) {
            $value = $parameters[$name] ?? null;

            if (!is_string($value) && !is_int($value)) {
                continue;
            }

            $model = $this->resolve($class, (string) $value);

            // Both names are set on purpose. `route.model.post` is what the
            // authorization middleware looks for; the bare `post` is what an
            // action or a view reads.
            $request = $request
                ->withAttribute('route.model.' . $name, $model)
                ->withAttribute($name, $model);
        }

        return $handler->handle($request);
    }

    /**
     * Route parameter name => model class, for the parameters worth binding.
     *
     * @return array<string, class-string<Model>>
     */
    private function modelParameters(Route $route): array
    {
        $key = $this->signatureKey($route);

        if (isset(self::$signatures[$key])) {
            return self::$signatures[$key];
        }

        $reflector = $this->reflect($route->handler);

        if ($reflector === null) {
            return self::$signatures[$key] = [];
        }

        $bindings = [];

        foreach ($reflector->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (is_subclass_of($class, Model::class)) {
                $bindings[$parameter->getName()] = $class;
            }
        }

        return self::$signatures[$key] = $bindings;
    }

    /**
     * Fetch one record by its route key.
     *
     * @param class-string<Model> $class
     */
    private function resolve(string $class, string $value): Model
    {
        $prototype = new $class();
        $key = $prototype->getRouteKeyName();

        return $class::query()->where($key, $value)->first() ?? throw new ModelNotFoundException(
            'No ' . $class . ' matches ' . $key . ' ' . var_export($value, true) . '.',
        );
    }

    /**
     * A stable identity for the route's handler, for the reflection cache.
     */
    private function signatureKey(Route $route): string
    {
        $handler = $route->handler;

        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $class = is_object($class) ? $class::class : $class;

            if (is_string($class) && is_string($method)) {
                return $class . '@' . $method;
            }
        }

        // A closure route: the URI identifies it well enough, and closures are
        // not shared between routes.
        return implode(',', $route->methods) . ' ' . $route->uri;
    }

    private function reflect(mixed $handler): ?ReflectionFunctionAbstract
    {
        if ($handler instanceof Closure) {
            return new ReflectionFunction($handler);
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            $handler = explode('@', $handler, 2);
        }

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $class = is_object($class) ? $class::class : $class;

            return is_string($class) && is_string($method) && method_exists($class, $method)
                ? new ReflectionMethod($class, $method)
                : null;
        }

        if (is_string($handler) && method_exists($handler, '__invoke')) {
            return new ReflectionMethod($handler, '__invoke');
        }

        return null;
    }

    /**
     * @internal Test seam: drop the reflection cache.
     */
    public static function flushSignatures(): void
    {
        self::$signatures = [];
    }
}
