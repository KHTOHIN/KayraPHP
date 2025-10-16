<?php

use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Http\Router;
use Kayra\Foundation\Application;

function handleRequest(Request $request, Application $app): Response
{
    $router = new Router($app);

    // Load all route files
    $router->loadRoutes([
        __DIR__ . '/../routes/web.php',
        __DIR__ . '/../routes/api.php',
    ]);

    // Bind core dependencies
    $app->instance(Request::class, $request);
    $app->instance(Response::class, new Response());

    return $router->dispatch($request);
}