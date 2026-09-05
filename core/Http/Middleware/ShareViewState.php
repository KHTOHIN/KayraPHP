<?php

declare(strict_types=1);

namespace Kayra\Http\Middleware;

use Kayra\Auth\AuthManager;
use Kayra\Container\Container;
use Kayra\Session\Session;
use Kayra\View\Factory as ViewFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Publishes the four things almost every page template reaches for.
 *
 *  $errors      validation messages flashed by the previous request
 *  $old         the input that failed, so a form can be redrawn filled in
 *  $status      a one-shot success message
 *  $currentUser the signed-in user, or unset for a guest
 *
 * Without this a controller has to pass those to every single view, and the
 * one place it forgets is the page that silently drops the user's typing.
 *
 * $errors is always defined, as an empty array for a clean request, so
 * templates can write `@if($errors)` without an isset() dance. The other three
 * are only defined when they exist, which is what makes `@isset($currentUser)`
 * the natural way to ask whether anyone is signed in.
 */
final class ShareViewState implements MiddlewareInterface
{
    public function __construct(
        private readonly Container $container,
        private readonly ViewFactory $views,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->container->hasInstance(Session::class)
            ? $this->container->get(Session::class)
            : null;

        // Read, don't pull: the session's own flash bookkeeping ages these out
        // after this request, and pulling here would also drop them from the
        // storage the response still has to write back.
        $errors = $session?->get('errors');
        $old = $session?->get('old');
        $status = $session?->get('status');

        $this->views->shareForRequest('errors', is_array($errors) ? $errors : []);
        $this->views->shareForRequest('old', is_array($old) ? $old : []);

        if (is_string($status) && $status !== '') {
            $this->views->shareForRequest('status', $status);
        }

        $user = $this->currentUser();

        if ($user !== null) {
            $this->views->shareForRequest('currentUser', $user);
        }

        return $handler->handle($request);
    }

    /**
     * The signed-in user, or null.
     *
     * For a guest this costs nothing: the guard sees no id in the session and
     * never queries. For a signed-in user it is the one lookup the page was
     * going to need anyway, and the guard memoises it for the rest of the
     * request.
     */
    private function currentUser(): ?object
    {
        if (!$this->container->bound(AuthManager::class)) {
            return null;
        }

        try {
            return $this->container->get(AuthManager::class)->user();
        } catch (Throwable) {
            // An application with no auth configuration still renders.
            return null;
        }
    }
}
