<?php

namespace Kayra\Http;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewareDispatcher implements RequestHandlerInterface
{
    public function __construct(
        private array $middlewares,
        private RequestHandlerInterface $handler
    ) {}

    public function process(Request $request): Response
    {
        return $this->handle($request);
    }

    public function handle(Request $request): Response
    {
        $middleware = array_shift($this->middlewares);
        if (!$middleware) {
            return $this->handler->handle($request);
        }

        if (is_callable($middleware)) {
            return $middleware($request, $this);
        }

        if (is_string($middleware)) {
            $instance = container('app')->make($middleware); // Precompiled
            return $instance->process($request, $this);
        }

        throw new \Exception('Invalid middleware');
    }
}