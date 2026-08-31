<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Kayra\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'test', description: 'Run the test suite')]
final class TestCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'args',
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'Arguments passed through to PHPUnit',
        );
    }

    protected function run_(): int
    {
        $phpunit = $this->app->basePath('vendor/bin/phpunit');

        if (!is_file($phpunit)) {
            $this->io->error('PHPUnit is not installed. Run `composer install`.');

            return self::FAILURE;
        }

        /** @var list<string> $args */
        $args = $this->argument('args') ?: [];

        $process = proc_open(
            [PHP_BINARY, $phpunit, '--colors=always', ...$args],
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            $this->app->basePath(),
            // The suite must not inherit the developer's APP_ENV.
            ['APP_ENV' => 'testing'] + getenv(),
        );

        if (!is_resource($process)) {
            $this->io->error('Unable to start PHPUnit.');

            return self::FAILURE;
        }

        return proc_close($process);
    }
}
