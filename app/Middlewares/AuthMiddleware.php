<?php

declare(strict_types=1);

namespace App\Middlewares;

use Kayra\Http\Request;
use Kayra\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Example bearer-token gate.
 *
 * The parameter types are the PSR-15 ones, not the KayraPHP subclasses: PHP
 * requires a parameter type to be the same as, or wider than, the interface's.
 * Narrowing it to Kayra\Http\Request is a fatal error at class-declaration time.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $request instanceof Request
            ? $request->bearerToken()
            : null;

        if ($token === null) {
            return Response::json(['message' => 'Unauthenticated.'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        // Replace with a real lookup; the resolved user travels as an attribute
        // rather than in a property, so nothing leaks between requests.
        return $handler->handle($request->withAttribute('auth.token', $token));
    }
}
