<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Kayra\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'key:generate', description: 'Generate the application key')]
final class KeyGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('show', null, InputOption::VALUE_NONE, 'Print the key instead of writing it')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing key');
    }

    protected function run_(): int
    {
        // 32 bytes: the key size for AES-256 and for modern MAC constructions.
        $key = 'base64:' . base64_encode(random_bytes(32));

        if ($this->option('show') === true) {
            $this->io->writeln($key);

            return self::SUCCESS;
        }

        $path = $this->app->basePath('.env');

        if (!is_file($path)) {
            $this->io->error('.env not found. Copy .env.example to .env first.');

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);

        // Replacing a live key invalidates every session and encrypted value.
        if (preg_match('/^APP_KEY=(.+)$/m', $contents, $m) === 1 && trim($m[1]) !== '' && $this->option('force') !== true) {
            $this->io->error('APP_KEY is already set. Use --force to replace it (this invalidates existing sessions and encrypted data).');

            return self::FAILURE;
        }

        $updated = preg_match('/^APP_KEY=.*$/m', $contents) === 1
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents)
            : rtrim($contents) . "\nAPP_KEY={$key}\n";

        if (file_put_contents($path, $updated) === false) {
            $this->io->error('Unable to write .env.');

            return self::FAILURE;
        }

        $this->io->success('Application key set.');

        return self::SUCCESS;
    }
}
