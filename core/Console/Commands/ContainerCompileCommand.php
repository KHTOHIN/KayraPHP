<?php

namespace Kayra\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Kayra\Container\Container;
use Kayra\Container\Compiler;

class ContainerCompileCommand extends Command
{
    protected static $defaultName = 'container:compile';
    protected static $defaultDescription = 'Compile the container for performance optimization';

    protected function configure(): void
    {
        $this->setDescription('Compiles the DI container to avoid runtime reflection');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Compiling container...</info>');
        
        // Load config files
        $configs = [];
        $basePath = dirname(__DIR__, 4); // Go up 4 levels from core/Console/Commands
        
        foreach (['app', 'cache', 'database', 'storage'] as $config) {
            $configFile = "{$basePath}/config/{$config}.php";
            if (file_exists($configFile)) {
                $configs[$config] = require $configFile;
                $output->writeln("  <comment>Loaded config:</comment> {$config}");
            } else {
                $output->writeln("  <error>Missing config:</error> {$config}");
            }
        }
        
        // Create container and compiler
        $container = new Container();
        $compiler = new Compiler($container);
        
        // Compile basic services
        $compiler->compileBasicServices($configs);
        
        $cachePath = storage_path('cache/container.php');
        $output->writeln("<info>Container compiled successfully to:</info> {$cachePath}");
        
        return Command::SUCCESS;
    }
}