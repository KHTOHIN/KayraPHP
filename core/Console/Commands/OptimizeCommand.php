<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Kayra\Console\Command;
use Kayra\Foundation\Optimizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

#[AsCommand(name: 'optimize', description: 'Compile config, routes and templates for production')]
final class OptimizeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setAliases(['config:cache', 'route:cache', 'view:cache', 'service:cache', 'build'])
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Environment to build for')
            ->addOption('show-skipped', null, InputOption::VALUE_NONE, 'List classes that stay dynamic');
    }

    protected function run_(): int
    {
        $invoked = (string) $this->input->getFirstArgument();
        $optimizer = new Optimizer($this->app);

        $only = match ($invoked) {
            'config:cache'  => ['config'],
            'route:cache'   => ['routes'],
            'view:cache'    => ['views'],
            'service:cache' => ['services'],
            // Order matters: the service graph is discovered from the route
            // table, so routes must be built first.
            default         => ['config', 'packages', 'routes', 'services', 'views', 'preload'],
        };

        $skipped = [];

        $this->io->newLine();

        foreach ($only as $step) {
            try {
                if ($step === 'services') {
                    $result = $optimizer->cacheServices();
                    $skipped = $result['skipped'];
                    $detail = $result['compiled'] . ' class(es), ' . count($skipped) . ' left dynamic';
                } elseif ($step === 'packages') {
                    $detail = $optimizer->cachePackages() . ' package(s) discovered';
                } elseif ($step === 'preload') {
                    $preload = $optimizer->cachePreload();
                    $detail = $preload['files'] . ' file(s) → ' . $this->relative($preload['path']);
                } else {
                    $detail = match ($step) {
                        'config' => $this->relative($optimizer->cacheConfig()),
                        'routes' => $this->relative($optimizer->cacheRoutes()),
                        'views'  => $optimizer->cacheViews() . ' template(s)',
                    };
                }

                $this->io->writeln(sprintf('  <fg=green>✓</> %-9s <fg=gray>%s</>', $step, $detail));
            } catch (Throwable $e) {
                $this->io->writeln(sprintf('  <fg=red>✗</> %-9s <fg=red>%s</>', $step, $e->getMessage()));

                return self::FAILURE;
            }
        }

        $this->io->newLine();

        if ($skipped !== [] && $this->option('show-skipped') === true) {
            $this->io->writeln('  <fg=gray>Left dynamic (resolved by reflection at run time):</>');

            foreach ($skipped as $entry) {
                $this->io->writeln("  <fg=gray>  · {$entry}</>");
            }

            $this->io->newLine();
        }

        if (!$this->app->isProduction()) {
            $this->io->note(
                'Built while APP_ENV=' . $this->app->environment() . '. '
                . 'The cached config captures the values from this environment, so build on the target environment or run `kayra optimize:clear` afterwards.',
            );
        }

        if (!function_exists('opcache_get_status')) {
            $this->io->warning('OPcache is not available. Enable it in production; it is the single largest win.');
        }

        return self::SUCCESS;
    }
}
