<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Kayra\Console\Command;
use Kayra\Foundation\Optimizer;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'optimize:clear', description: 'Remove every compiled artefact')]
final class ClearCommand extends Command
{
    protected function configure(): void
    {
        $this->setAliases(['cache:clear', 'config:clear', 'route:clear', 'view:clear']);
    }

    protected function run_(): int
    {
        $removed = (new Optimizer($this->app))->clear();

        $this->io->newLine();

        foreach ($removed as $name => $count) {
            $this->io->writeln(sprintf('  <fg=green>✓</> cleared %-8s <fg=gray>%d file(s)</>', $name, $count));
        }

        $this->io->newLine();

        return self::SUCCESS;
    }
}
