<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\UserService;
use Kayra\Foundation\Application;
use Kayra\Http\Controller;
use Kayra\Routing\Router;
use Psr\Http\Message\ResponseInterface;

final class HomeController extends Controller
{
    public function __construct(
        private readonly Application $app,
        private readonly Router $router,
        private readonly UserService $users,
    ) {
    }

    public function index(): ResponseInterface
    {
        return $this->view('home', [
            'version'   => Application::VERSION,
            'php'       => PHP_VERSION,
            'env'       => $this->app->environment(),
            'debug'     => $this->app->isDebug(),
            'routes'    => count($this->router->routes()->all()),
            'userCount' => $this->users->count(),
            'optimised' => [
                'config' => $this->app->configIsCached(),
                'routes' => $this->app->routesAreCached(),
                'opcache' => function_exists('opcache_get_status'),
                'nativeUri' => \Kayra\Http\Uri::usingNativeParser(),
            ],
        ]);
    }

    public function docs(): ResponseInterface
    {
        return $this->view('documentation', ['version' => Application::VERSION]);
    }

    /**
     * Liveness endpoint for orchestrators.
     */
    public function health(): ResponseInterface
    {
        return $this->json([
            'status'  => 'ok',
            'version' => Application::VERSION,
            'env'     => $this->app->environment(),
        ]);
    }
}
