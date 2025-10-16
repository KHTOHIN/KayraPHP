<?php

namespace Kayra\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends Command
{
    protected static $defaultName = 'migrate';
    protected static $defaultDescription = 'Run database migrations';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = require __DIR__ . '/../../../bootstrap/app.php';
        $db = $app->make('db')->getConnection();
        $migration = new \Kayra\Database\Migration($db);
        $migration->run();
        $output->writeln('<info>Migrations run successfully.</info>');
        return Command::SUCCESS;
    }
}