<?php

declare(strict_types=1);

namespace Kayra\Http;

use Kayra\Foundation\Application;
use Kayra\Routing\UrlGenerator;
use Kayra\View\Factory as ViewFactory;
use LogicException;
use Psr\Http\Message\ResponseInterface;

/**
 * Optional base class for controllers.
 *
 * Extending this is a convenience, not a requirement: any callable can be a
 * route action, and a controller that prefers constructor injection can ignore
 * this class entirely.
 *
 * Controllers should stay thin. Business logic belongs in services; this class
 * deliberately offers response helpers and nothing else.
 */
abstract class Controller
{
    private ?Application $app = null;

    /**
     * @internal Called by {@see ActionDispatcher} after construction.
     */
    final public function setApplication(Application $app): void
    {
        $this->app = $app;
    }

    final protected function app(): Application
    {
        return $this->app ?? throw new LogicException(
            static::class . ' was constructed outside the framework, so response helpers are unavailable. '
            . 'Inject the services you need through the constructor instead.',
        );
    }

    /**
     * Render a template.
     *
     * @param array<string, mixed> $data
     */
    protected function view(string $view, array $data = [], int $status = 200): ResponseInterface
    {
        return Response::html($this->app()->get(ViewFactory::class)->render($view, $data), $status);
    }

    /**
     * Return a JSON response.
     */
    protected function json(mixed $data, int $status = 200): ResponseInterface
    {
        return Response::json($data, $status);
    }

    protected function html(string $html, int $status = 200): ResponseInterface
    {
        return Response::html($html, $status);
    }

    protected function text(string $text, int $status = 200): ResponseInterface
    {
        return Response::text($text, $status);
    }

    protected function noContent(): ResponseInterface
    {
        return Response::noContent();
    }

    /**
     * Redirect to a path or absolute URL.
     */
    protected function redirect(string $to, int $status = 302): ResponseInterface
    {
        return Response::redirect($to, $status);
    }

    /**
     * Redirect to a named route.
     *
     * @param array<string, mixed> $parameters
     */
    protected function redirectToRoute(string $name, array $parameters = [], int $status = 302): ResponseInterface
    {
        return Response::redirect(
            $this->app()->get(UrlGenerator::class)->route($name, $parameters),
            $status,
        );
    }

    protected function download(string $path, ?string $filename = null): ResponseInterface
    {
        return Response::download($path, $filename);
    }

    protected function file(string $path, ?string $contentType = null): ResponseInterface
    {
        return Response::file($path, $contentType);
    }

    /**
     * The current request.
     */
    protected function request(): Request
    {
        return $this->app()->get(Request::class);
    }
}
