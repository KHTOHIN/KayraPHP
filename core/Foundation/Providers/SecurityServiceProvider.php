<?php

declare(strict_types=1);

namespace Kayra\Foundation\Providers;

use Kayra\Encryption\Encrypter;
use Kayra\Foundation\Application;
use Kayra\Foundation\ServiceProvider;
use Kayra\RateLimiter\FileRateLimiter;
use Kayra\RateLimiter\RateLimiter;
use Kayra\Session\FileSessionHandler;
use Kayra\Session\SessionHandler;

/**
 * Registers encryption, sessions and rate limiting.
 */
final class SecurityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Lazy: an application that never encrypts anything should not be
        // forced to have an APP_KEY just to boot.
        $this->app->singleton(
            Encrypter::class,
            static fn (Application $app): Encrypter => Encrypter::fromKey(
                (string) $app->config()->get('app.key', ''),
            ),
            lazy: true,
        );

        $this->app->singleton(SessionHandler::class, static function (Application $app): SessionHandler {
            return new FileSessionHandler(
                $app->ensureDirectory(
                    $app->config()->string('session.files', $app->storagePath('framework/sessions')),
                ),
                $app->get(Encrypter::class),
                $app->config()->int('session.lifetime', 7200),
            );
        }, lazy: true);

        $this->app->singleton(RateLimiter::class, static function (Application $app): RateLimiter {
            return new FileRateLimiter(
                $app->ensureDirectory($app->storagePath('framework/limiter')),
            );
        }, lazy: true);
    }
}
