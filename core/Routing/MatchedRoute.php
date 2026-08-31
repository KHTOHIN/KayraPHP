<?php

declare(strict_types=1);

namespace Kayra\Routing;

/**
 * The result of a successful match: the route plus its captured parameters.
 */
final class MatchedRoute
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $parameters = [],
    ) {
    }

    public function parameter(string $name, mixed $default = null): mixed
    {
        return $this->parameters[$name] ?? $default;
    }
}
