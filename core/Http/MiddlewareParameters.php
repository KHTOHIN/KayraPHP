<?php

declare(strict_types=1);

namespace Kayra\Http;

/**
 * A middleware reference carrying inline parameters, as in `throttle:60,1`.
 *
 * The {@see \Kayra\Pipeline\Pipeline} builds the middleware with the parameter
 * list passed to a `$parameters` constructor argument.
 */
final class MiddlewareParameters
{
    /**
     * @param list<string> $parameters
     */
    public function __construct(
        public readonly string $middleware,
        public readonly array $parameters = [],
    ) {
    }
}
