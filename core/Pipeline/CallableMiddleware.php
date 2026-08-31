<?php

declare(strict_types=1);

namespace Kayra\Pipeline;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adapts a closure to PSR-15, so inline middleware stays possible without
 * weakening the interface everything else is written against.
 *
 * The closure receives ($request, $handler) and must return a response.
 */
final class CallableMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Closure $callback)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return ($this->callback)($request, $handler);
    }
}
