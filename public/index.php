<?php

/*
|--------------------------------------------------------------------------
| KayraPHP — HTTP entry point
|--------------------------------------------------------------------------
|
| Every web request enters here. The work is:
|
|   preflight -> autoload -> build the application -> hand it to the runtime
|
| The runtime decides how requests arrive (one per process under php-fpm, many
| per process under Swoole or FrankenPHP), so this file does not change between
| them.
|
| declare(strict_types=1) is deliberately absent: this file must parse on
| whatever PHP the web server is configured with, so that preflight.php can
| report a version mismatch instead of the request dying on a parse error.
|
*/

// The built-in PHP development server has no rewrite rules of its own; serve
// existing files directly and let everything else fall through to the router.
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
    $file = __DIR__ . $requested;

    if (is_file($file) && substr($file, -4) !== '.php') {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap/preflight.php';

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Kayra\Foundation\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$app->get(Kayra\Runtime\RuntimeInterface::class)->run(
    $app->get(Kayra\Http\Kernel::class),
);
