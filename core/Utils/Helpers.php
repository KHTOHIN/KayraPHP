<?php

if (!function_exists('env')) {
    function env(string $key, $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (str_starts_with($value ?? '', 'base64:')) {
            $value = base64_decode(substr($value, 7));
        }
        return $value ?? $default;
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return __DIR__ . '/../../' . $path;
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage/' . $path);
    }
}

if (!function_exists('database_path')) {
    function database_path(string $path = ''): string
    {
        return base_path('database/' . $path);
    }
}

if (!function_exists('container')) {
    function container(?string $id = null)
    {
        $app = require base_path('bootstrap/app.php');
        return $id ? $app->make($id) : $app;
    }
}

if (!function_exists('app_env')) {
    function app_env(): string
    {
        return $_ENV['APP_ENV'] ?? 'production';
    }
}

if (!function_exists('is_dev')) {
    function is_dev(): bool
    {
        return app_env() === 'development';
    }
}

if (!function_exists('is_prod')) {
    function is_prod(): bool
    {
        return app_env() === 'production';
    }
}