<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Auth\Access\Gate;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Requires an ability before the route runs.
 *
 * Registered as `can:update,post` — the first parameter is the ability, and
 * any further parameters name route parameters whose *resolved* values are
 * passed to the policy.
 *
 * Checking here rather than inside the action means the action can assume it
 * is allowed to run, and a forgotten check is visible in the route definition
 * rather than buried in a method body.
 */
final class Authorize implements MiddlewareInterface
{
    private readonly string $ability;

    /** @var list<string> */
    private readonly array $parameters;

    /**
     * @param list<string> $parameters [ability, ...routeParameterNames]
     */
    public function __construct(
        private readonly Gate $gate,
        array $parameters = [],
    ) {
        $this->ability = $parameters[0] ?? '';
        $this->parameters = array_values(array_slice($parameters, 1));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Throws AuthorizationException, which carries the status the policy
        // chose — 403, or 404 where existence itself is privileged.
        $this->gate->authorize($this->ability, ...$this->argumentsFrom($request));

        return $handler->handle($request);
    }

    /**
     * @return list<mixed>
     */
    private function argumentsFrom(ServerRequestInterface $request): array
    {
        $arguments = [];

        foreach ($this->parameters as $name) {
            // A bound model wins over the raw route value, so a policy receives
            // the record rather than its id.
            $arguments[] = $request->getAttribute('route.model.' . $name)
                ?? $request->getAttribute($name);
        }

        return $arguments;
    }
}
