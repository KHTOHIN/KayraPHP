<?php

declare(strict_types=1);

namespace Kayra\Pipeline;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adapts a closure to PSR-15's RequestHandlerInterface — the endpoint at the
 * bottom of a {@see Pipeline}.
 */
final class CallableHandler implements RequestHandlerInterface
{
    public function __construct(private readonly Closure $callback)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->callback)($request);
    }
}
