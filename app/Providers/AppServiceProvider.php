<?php

namespace App\Providers;

use Kayra\Foundation\Application;

class AppServiceProvider
{
    public function __construct(protected Application $app) {}

    public function register(): void
    {
        $this->app->instance('userService', new \App\Services\UserService());
        // Bind models, etc., to container (precompiled)
    }

    public function boot(): void
    {
        // Event listeners, etc.
    }
}