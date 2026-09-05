<?php

declare(strict_types=1);

namespace Kayra\Foundation\Providers;

use Kayra\Auth\Access\Gate;
use Kayra\Auth\AuthManager;
use Kayra\Auth\Guard;
use Kayra\Auth\Hasher;
use Kayra\Auth\PasswordBroker;
use Kayra\Auth\UserProvider;
use Kayra\Database\Connection;
use Kayra\Database\DatabaseManager;
use Kayra\Database\Model;
use Kayra\Foundation\Application;
use Kayra\Foundation\ServiceProvider;
use Kayra\View\Factory as ViewFactory;
use RuntimeException;

/**
 * Registers authentication.
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stateless and cheap to share.
        $this->app->singleton(
            Hasher::class,
            static fn (Application $app): Hasher => Hasher::fromConfig($app->config()->array('auth.hashing', [])),
        );

        // Scoped, not singleton: a guard holds the current user, and caching
        // that across requests in a long-running worker would hand one user's
        // identity to the next request.
        $this->app->scoped(
            AuthManager::class,
            static fn (Application $app): AuthManager => new AuthManager($app, $app->config()),
        );

        $this->app->scoped(
            Guard::class,
            static fn (Application $app): Guard => $app->get(AuthManager::class)->guard(),
        );

        $this->app->scoped(
            UserProvider::class,
            static function (Application $app): UserProvider {
                $default = $app->config()->string('auth.default', 'web');
                $provider = (string) $app->config()->get("auth.guards.{$default}.provider", 'users');

                return new \Kayra\Auth\ModelUserProvider(
                    (string) $app->config()->get("auth.providers.{$provider}.model", ''),
                    $app->get(Hasher::class),
                );
            },
        );

        // Scoped: the gate is bound to the current user, so it must not
        // outlive the request that established who that is.
        $this->app->scoped(Gate::class, static function (Application $app): Gate {
            $gate = new Gate($app, $app->get(AuthManager::class)->user());

            // Policies declared by the application.
            foreach ($app->config()->array('auth.policies', []) as $model => $policy) {
                // Both halves have to be real classes. A typo in config would
                // otherwise register a policy that can never be instantiated,
                // and the failure would surface as a denial rather than as the
                // configuration mistake it is.
                if (!is_string($model) || !is_string($policy)) {
                    continue;
                }

                if (!class_exists($model) || !class_exists($policy)) {
                    throw new RuntimeException(
                        "auth.policies maps [{$model}] to [{$policy}], and one of them does not exist.",
                    );
                }

                $gate->policy($model, $policy);
            }

            return $gate;
        });

        $this->app->scoped(PasswordBroker::class, static function (Application $app): PasswordBroker {
            return new PasswordBroker(
                $app->get(UserProvider::class),
                $app->get(Connection::class),
                $app->get(Hasher::class),
                $app->config()->string('auth.passwords.table', 'password_resets'),
                $app->config()->int('auth.passwords.expires', 3600),
            );
        });
    }

    public function boot(): void
    {
        // Models resolve their own connections; hand them the manager once so
        // they never need to reach into the container themselves.
        Model::setConnectionResolver($this->app->get(DatabaseManager::class));

        // Views need the gate for @can. Resolved lazily on first render so a
        // console command never builds an auth stack it will not use.
        $this->app->extend(ViewFactory::class, function (mixed $views): mixed {
            if ($views instanceof ViewFactory) {
                $views->setGateResolver(fn (): Gate => $this->app->get(Gate::class));
            }

            return $views;
        });
    }
}
