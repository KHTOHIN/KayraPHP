<?php

use Kayra\Foundation\Application;

/*
|--------------------------------------------------------------------------
| Load Environment Variables
|--------------------------------------------------------------------------
*/
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#')) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[$key] = trim($value);
        $_SERVER[$key] = trim($value);
    }
}

/*
|--------------------------------------------------------------------------
| Create Application
|--------------------------------------------------------------------------
*/
$app = new Application(__DIR__ . '/..');

/*
|--------------------------------------------------------------------------
| Bind Core Environment Settings
|--------------------------------------------------------------------------
*/
$app->singleton('env', fn() => $_ENV['APP_ENV'] ?? 'production');
$app->singleton('debug', fn() => ($_ENV['APP_DEBUG'] ?? 'false') === 'true');

return $app;