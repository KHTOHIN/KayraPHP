<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Closure;
use Kayra\Console\Command;
use Kayra\Routing\RedirectRoute;
use Kayra\Routing\Route;
use Kayra\Routing\Router;
use Kayra\Routing\ViewRoute;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'route:list', description: 'List every registered route')]
final class RouteListCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('method', 'm', InputOption::VALUE_REQUIRED, 'Filter by HTTP method')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Filter by URI substring')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Filter by route-name substring')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function run_(): int
    {
        $router = $this->app->get(Router::class);
        $router->compile();

        $routes = $router->routes()->all();

        $rows = [];

        foreach ($routes as $route) {
            if (!$this->matchesFilters($route)) {
                continue;
            }

            $rows[] = [
                // HEAD is implied by GET; listing it doubles the table for nothing.
                'method'     => implode('|', array_diff($route->methods, ['HEAD'])),
                'uri'        => $route->uri,
                'name'       => $route->getName() ?? '',
                'action'     => $this->describeAction($route),
                'middleware' => implode(', ', $route->getMiddleware()),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['uri'] <=> $b['uri']);

        if ($this->option('json') === true) {
            $this->io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]');

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->io->warning('No routes matched.');

            return self::SUCCESS;
        }

        $this->io->newLine();
        $this->io->table(
            ['Method', 'URI', 'Name', 'Action', 'Middleware'],
            array_map(array_values(...), $rows),
        );
        $this->io->writeln(sprintf('  <fg=gray>%d route(s)</>', count($rows)));
        $this->io->newLine();

        return self::SUCCESS;
    }

    private function matchesFilters(Route $route): bool
    {
        $method = $this->option('method');

        if (is_string($method) && !in_array(strtoupper($method), $route->methods, true)) {
            return false;
        }

        $path = $this->option('path');

        if (is_string($path) && !str_contains($route->uri, $path)) {
            return false;
        }

        $name = $this->option('name');

        if (is_string($name) && !str_contains($route->getName() ?? '', $name)) {
            return false;
        }

        return true;
    }

    private function describeAction(Route $route): string
    {
        $handler = $route->handler;

        return match (true) {
            $handler instanceof Closure       => 'Closure',
            $handler instanceof ViewRoute     => "view: {$handler->view}",
            $handler instanceof RedirectRoute => "redirect: {$handler->to}",
            is_array($handler)                => $this->shortClass((string) $handler[0]) . '@' . (string) ($handler[1] ?? '?'),
            is_string($handler)               => $this->shortClass($handler),
            default                           => get_debug_type($handler),
        };
    }

    private function shortClass(string $class): string
    {
        return str_contains($class, '\\') ? substr(strrchr($class, '\\') ?: $class, 1) : $class;
    }
}
