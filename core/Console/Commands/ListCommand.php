<?php

namespace Kayra\Console\Commands;

use Kayra\Foundation\Application;

class ListCommand
{
    public static function run(Application $app, array $args): void
    {
        echo "Kayra CLI Commands\n";
        echo "--------------------\n";
        echo "list              List all available commands\n";
        echo "key:generate      Generate a new APP_KEY in .env\n";
        echo "cache:clear       Clear framework cache\n";
        echo "route:list        Display all registered routes\n";
        echo "serve             Run local development server\n";
    }
}