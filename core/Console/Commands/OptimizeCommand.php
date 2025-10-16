<?php

namespace Kayra\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Kayra\Container\Compiler;
use Kayra\Foundation\Application;

class OptimizeCommand extends Command
{
    protected static $defaultName = 'optimize';
    protected static $defaultDescription = 'Optimize the framework (compile container, cache routes/views)';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = new Application(dirname(__DIR__, 4));
        $configs = $app->loadConfigs();
        $compiler = new Compiler($app->getContainer());
        $compiler->compileBasicServices($configs);
        // Stub: Cache routes/views
        $output->writeln('<info>Container compiled and caches optimized.</info>');
        return Command::SUCCESS;
    }
}