<?php

use Kayra\Http\Request;
use Kayra\Http\Response;
use Kayra\Http\Router;
use Kayra\Foundation\Application;

function handleRequest(Request $request, Application $app): Response
{
    // Bind core dependencies
    $app->instance(Request::class, $request);
    $app->instance(Response::class, new Response());

    // Use the architecture pattern to handle the request
    return $app->getArchitecturePattern()->handleRequest($request, $app);
}
