<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets HTML forms reach PUT/PATCH/DELETE routes via a `_method` field.
 *
 * Only POST requests may be overridden, and only to a method on the allow-list;
 * otherwise a GET could be turned into a DELETE by a crafted link.
 */
final class MethodOverride implements MiddlewareInterface
{
    private const ALLOWED = ['PUT', 'PATCH', 'DELETE'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strcasecmp($request->getMethod(), 'POST') !== 0) {
            return $handler->handle($request);
        }

        $method = $this->override($request);

        if ($method !== null) {
            $request = $request->withMethod($method);
        }

        return $handler->handle($request);
    }

    private function override(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        $candidate = is_array($body) ? ($body['_method'] ?? null) : null;

        $candidate ??= $request->getHeaderLine('X-HTTP-Method-Override') ?: null;

        if (!is_string($candidate)) {
            return null;
        }

        $candidate = strtoupper($candidate);

        return in_array($candidate, self::ALLOWED, true) ? $candidate : null;
    }
}
