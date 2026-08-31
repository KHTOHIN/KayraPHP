<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Create the application
|--------------------------------------------------------------------------
|
| This file builds the application and returns it. It is deliberately the only
| place that knows the project root, so both the HTTP entry point and the CLI
| can share exactly the same wiring.
|
| Nothing here is request-specific: on a long-running runtime this runs once
| per worker, not once per request.
|
*/

use Kayra\Container\Container;
use Kayra\Foundation\Application;

$app = new Application(dirname(__DIR__));

// Make the container reachable from the global helper functions. Framework
// internals never use this; it exists for app(), config(), route() and friends.
Container::setInstance($app);

$app->bootstrap();
$app->boot();

return $app;
