<?php

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 2); // go up from core/Support → project root
        return $path ? $base . DIRECTORY_SEPARATOR . $path : $base;
    }
}