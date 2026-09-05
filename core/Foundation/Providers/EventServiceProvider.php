<?php

declare(strict_types=1);

namespace Kayra\Foundation\Providers;

use Kayra\Database\Model;
use Kayra\Events\Dispatcher;
use Kayra\Events\ListenerProvider;
use Kayra\Foundation\Application;
use Kayra\Foundation\ServiceProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use RuntimeException;

/**
 * Registers the PSR-14 event layer.
 */
final class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singletons: registrations are configuration, not request state. A
        // listener that needs something from the current request resolves it
        // when it runs, which is after the scoped bindings exist.
        $this->app->singleton(
            ListenerProvider::class,
            static fn (Application $app): ListenerProvider => new ListenerProvider($app),
        );

        $this->app->singleton(
            Dispatcher::class,
            static fn (Application $app): Dispatcher => new Dispatcher($app->get(ListenerProvider::class)),
        );

        $this->app->alias(EventDispatcherInterface::class, Dispatcher::class);
        $this->app->alias(ListenerProviderInterface::class, ListenerProvider::class);
        $this->app->alias('events', Dispatcher::class);
    }

    public function boot(): void
    {
        $provider = $this->app->get(ListenerProvider::class);

        foreach ($this->app->config()->array('events.listeners', []) as $event => $listeners) {
            if (!is_string($event)) {
                continue;
            }

            if (!class_exists($event) && !interface_exists($event)) {
                throw new RuntimeException(
                    "events.listeners is keyed on [{$event}], which is neither a class nor an interface.",
                );
            }

            foreach (is_array($listeners) ? $listeners : [$listeners] as $listener) {
                if (is_string($listener) || is_array($listener) || is_callable($listener)) {
                    /** @var callable|class-string|array{0: string, 1: string} $listener */
                    $provider->listen($event, $listener);
                }
            }
        }

        // Models announce their lifecycle only once something is listening for
        // it. Handing them the dispatcher unconditionally would make every
        // insert allocate an event object nobody reads.
        if ($this->app->config()->bool('events.model_events', true)) {
            Model::setEventDispatcher($this->app->get(Dispatcher::class));
        }
    }
}
