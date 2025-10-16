<?php

namespace Kayra\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SeedCommand extends Command
{
    protected static $defaultName = 'seed';
    protected static $defaultDescription = 'Run database seeders';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = require __DIR__ . '/../../../bootstrap/app.php';
        $db = $app->make('db')->getConnection();
        $seeder = new \Kayra\Database\Seeder($db);
        $seeder->run();
        $output->writeln('<info>Seeders run successfully.</info>');
        return Command::SUCCESS;
    }
}