<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Auth\AuthManager;
use Kayra\Exceptions\HttpException;
use Kayra\Http\Request;
use Kayra\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Requires an authenticated user.
 *
 * Registered as `auth` or `auth:api` — the parameter names the guard.
 *
 * A browser gets a redirect to the login page and an API client gets 401,
 * decided by content negotiation rather than by the route, so one middleware
 * serves both without the application choosing in advance.
 */
final class Authenticate implements MiddlewareInterface
{
    private readonly ?string $guard;

    /**
     * @param list<string> $parameters [guardName]
     */
    public function __construct(
        private readonly AuthManager $auth,
        array $parameters = [],
    ) {
        $this->guard = isset($parameters[0]) && $parameters[0] !== '' ? $parameters[0] : null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $guard = $this->auth->guard($this->guard);

        if ($guard->guest()) {
            return $this->unauthenticated($request);
        }

        $user = $guard->user();

        // Published as attributes so downstream code — the rate limiter, an
        // audit log, a controller — can read the identity without depending on
        // the auth layer.
        return $handler->handle(
            $request
                ->withAttribute('auth.user', $user)
                ->withAttribute('auth.id', $user?->getAuthIdentifier()),
        );
    }

    private function unauthenticated(ServerRequestInterface $request): ResponseInterface
    {
        $wantsJson = $request instanceof Request
            ? $request->wantsJson()
            : str_contains(strtolower($request->getHeaderLine('Accept')), 'json');

        if ($wantsJson) {
            throw new HttpException(401, 'Unauthenticated.', ['WWW-Authenticate' => 'Bearer']);
        }

        return Response::redirect('/login');
    }
}
