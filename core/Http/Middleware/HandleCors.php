<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Config\Repository;
use Kayra\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cross-origin resource sharing.
 *
 * The wildcard origin is deliberately incompatible with credentialed requests:
 * a browser rejects `Access-Control-Allow-Origin: *` when credentials are
 * allowed, and echoing an arbitrary origin back while allowing credentials is a
 * well-known account-takeover vector. When credentials are enabled, only an
 * explicitly listed origin is echoed.
 */
final class HandleCors implements MiddlewareInterface
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '') {
            return $handler->handle($request);
        }

        $allowed = $this->resolveOrigin($origin);

        // Preflight is answered here; it never reaches the application.
        if (strcasecmp($request->getMethod(), 'OPTIONS') === 0 && $request->hasHeader('Access-Control-Request-Method')) {
            return $this->decorate(Response::noContent(), $allowed, true);
        }

        return $this->decorate($handler->handle($request), $allowed, false);
    }

    private function resolveOrigin(string $origin): ?string
    {
        /** @var list<string> $allowed */
        $allowed = $this->config->array('cors.allowed_origins', []);
        $credentials = $this->config->bool('cors.supports_credentials', false);

        if (in_array($origin, $allowed, true)) {
            return $origin;
        }

        if (in_array('*', $allowed, true)) {
            // With credentials on, '*' is not usable; require an explicit list.
            return $credentials ? null : '*';
        }

        foreach ($this->config->array('cors.allowed_origin_patterns', []) as $pattern) {
            if (is_string($pattern) && preg_match($pattern, $origin) === 1) {
                return $origin;
            }
        }

        return null;
    }

    private function decorate(ResponseInterface $response, ?string $origin, bool $preflight): ResponseInterface
    {
        if ($origin === null) {
            return $response;
        }

        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);

        // Any response that varies by Origin must say so, or a shared cache will
        // serve one origin's response to another.
        if ($origin !== '*') {
            $response = $response->withAddedHeader('Vary', 'Origin');
        }

        if ($this->config->bool('cors.supports_credentials', false)) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $exposed = $this->config->array('cors.exposed_headers', []);

        if ($exposed !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $exposed));
        }

        if (!$preflight) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->config->array(
                'cors.allowed_methods',
                ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            )))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->config->array(
                'cors.allowed_headers',
                ['Content-Type', 'Authorization', 'X-Requested-With'],
            )))
            ->withHeader('Access-Control-Max-Age', (string) $this->config->int('cors.max_age', 86400));
    }
}
