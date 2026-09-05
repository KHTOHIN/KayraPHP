<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Config\Repository;
use Kayra\Container\Container;
use Kayra\Http\Response;
use Kayra\Session\Session;
use Kayra\Session\SessionHandler;
use Kayra\View\Factory as ViewFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Loads the session for the request and writes it back on the way out.
 *
 * The session object is bound into the container's *scoped* bucket, so it is
 * per-request by construction and cannot leak into a concurrent request on a
 * long-running worker.
 */
final class StartSession implements MiddlewareInterface
{
    public function __construct(
        private readonly Container $container,
        private readonly SessionHandler $handler,
        private readonly Repository $config,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $name = $this->config->string('session.cookie', 'kayra_session');
        $cookies = $request->getCookieParams();
        $incoming = $cookies[$name] ?? null;

        $session = new Session($this->handler, is_string($incoming) ? $incoming : '');
        $session->start();

        // Scoped: discarded with the request, invisible to any other in flight.
        $this->container->scopedInstance(Session::class, $session);

        // Make the CSRF token available to @csrf without the view layer
        // reaching into the session itself.
        $this->container->get(ViewFactory::class)->shareForRequest('csrf_token', $session->token());

        $request = $request->withAttribute('session', $session);

        try {
            $response = $handler->handle($request);
        } finally {
            // Persist even when the action threw: a regenerated id or a flashed
            // error message must survive the failure that produced it.
            $session->save();
        }

        return $this->attachCookie($response, $session->id(), $name, $request);
    }

    private function attachCookie(
        ResponseInterface $response,
        string $id,
        string $name,
        ServerRequestInterface $request,
    ): ResponseInterface {
        $lifetime = $this->config->int('session.lifetime', 7200);

        // Secure is forced on over TLS and left off on plain http, so local
        // development works without silently shipping an insecure default.
        $secure = $this->config->get('session.secure', null);
        $secure = is_bool($secure) ? $secure : $request->getUri()->getScheme() === 'https';

        if (!$response instanceof Response) {
            return $response;
        }

        return $response->withCookie(
            name: $name,
            value: $id,
            expiresAt: $lifetime > 0 ? time() + $lifetime : 0,
            path: $this->config->string('session.path', '/'),
            domain: $this->config->string('session.domain', ''),
            secure: $secure,
            httpOnly: true,
            sameSite: $this->config->string('session.same_site', 'Lax'),
        );
    }
}
