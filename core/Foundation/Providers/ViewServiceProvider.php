<?php

declare(strict_types=1);

namespace Kayra\Foundation\Providers;

use Kayra\Foundation\Application;
use Kayra\Foundation\ServiceProvider;
use Kayra\View\Compiler;
use Kayra\View\Factory;

/**
 * Registers the template engine.
 */
final class ViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Compiler::class, static fn (): Compiler => new Compiler());

        $this->app->singleton(Factory::class, static function (Application $app): Factory {
            $paths = $app->config()->array('view.paths', [
                $app->appPath('Views'),
                $app->resourcePath('views'),
            ]);

            return new Factory(
                array_values(array_filter(array_map(strval(...), $paths), is_dir(...))),
                $app->ensureDirectory(
                    $app->config()->string('view.cache', $app->storagePath('framework/views')),
                ),
                $app->get(Compiler::class),
                // In production a compiled template is trusted without a stat
                // call. `kayra optimize` is what refreshes it.
                alwaysRecompile: !$app->isProduction(),
            );
        }, lazy: true);

        $this->app->alias('view', Factory::class);
    }
}
