<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Kayra\Console\Command;
use Kayra\Foundation\Application;
use Kayra\Routing\Router;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'about', description: 'Show a summary of the application')]
final class AboutCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setAliases(['env'])
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function run_(): int
    {
        $router = $this->app->get(Router::class);
        $router->compile();

        $data = [
            'Application' => [
                'Name'        => $this->app->config()->string('app.name', 'KayraPHP'),
                'Version'     => Application::VERSION,
                'Environment' => $this->app->environment(),
                'Debug'       => $this->app->isDebug() ? 'ENABLED' : 'OFF',
                'URL'         => $this->app->config()->string('app.url', ''),
                'Timezone'    => date_default_timezone_get(),
            ],
            'Runtime' => [
                'PHP'       => PHP_VERSION,
                'SAPI'      => PHP_SAPI,
                'Runtime'   => $this->app->get(\Kayra\Runtime\RuntimeInterface::class)->name(),
                'OPcache'   => function_exists('opcache_get_status') ? 'available' : 'missing',
                'Native URI' => \Kayra\Http\Uri::usingNativeParser() ? 'ext/uri' : 'parse_url',
            ],
            'Routing' => [
                'Routes'       => (string) count($router->routes()->all()),
                'Named routes' => (string) count($router->routes()->named()),
                'Cached'       => $this->app->routesAreCached() ? 'yes' : 'no',
            ],
            'Cache' => [
                'Config cached' => $this->app->configIsCached() ? 'yes' : 'no',
                'Providers'     => (string) count($this->app->providers()),
            ],
        ];

        if ($this->option('json') === true) {
            $this->io->writeln(json_encode($data, JSON_PRETTY_PRINT) ?: '{}');

            return self::SUCCESS;
        }

        $this->io->newLine();

        foreach ($data as $section => $rows) {
            $this->io->writeln("  <options=bold>{$section}</>");

            foreach ($rows as $label => $value) {
                $this->io->writeln(sprintf('  <fg=gray>%-16s</> %s', $label, $value));
            }

            $this->io->newLine();
        }

        return self::SUCCESS;
    }
}
