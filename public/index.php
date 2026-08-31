<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| KayraPHP — HTTP entry point
|--------------------------------------------------------------------------
|
| Every web request enters here. The work is:
|
|   autoload -> build the application -> hand the kernel to the runtime
|
| The runtime decides how requests arrive (one per process under php-fpm, many
| per process under Swoole), so this file does not change between them.
|
*/

use Kayra\Http\Kernel;
use Kayra\Runtime\RuntimeInterface;

// The built-in PHP development server has no rewrite rules of its own; serve
// existing files directly and let everything else fall through to the router.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Kayra\Foundation\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$app->get(RuntimeInterface::class)->run($app->get(Kernel::class));
