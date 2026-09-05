<?php

declare(strict_types=1);

namespace Kayra\Runtime\Swoole;

/**
 * Shapes for the Swoole objects this framework touches.
 *
 * Swoole's classes only exist when the extension is loaded, so code that must
 * compile without it can only type them as `object` — which silences static
 * analysis on every call and hides real typos until runtime.
 *
 * These interfaces describe just the surface used here. They are never
 * implemented: they exist so `@var` annotations can name a real type, so that
 * `$server->on(...)` is checked rather than waved through.
 *
 * @see https://wiki.swoole.com/en/#/http_server
 */
interface SwooleHttpServer
{
    /**
     * @param array<string, mixed> $settings
     */
    public function set(array $settings): void;

    public function on(string $event, callable $callback): void;

    public function start(): bool;
}

interface SwooleHttpResponse
{
    public function status(int $code): void;

    public function header(string $key, string $value, bool $format = true): void;

    public function end(string $content = ''): void;
}

interface SwooleHttpRequest
{
    public function rawContent(): string|false;
}
