<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Kayra\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'serve', description: 'Run the development server')]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind', '127.0.0.1')
            ->addOption('port', 'p', InputOption::VALUE_REQUIRED, 'Port to bind', '8000')
            ->addOption('tries', null, InputOption::VALUE_REQUIRED, 'Ports to try if the first is busy', '10');
    }

    protected function run_(): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $tries = max(1, (int) $this->option('tries'));

        $port = $this->availablePort($host, $port, $tries);

        if ($port === null) {
            $this->io->error("No free port found starting at {$this->option('port')}.");

            return self::FAILURE;
        }

        $root = $this->app->publicPath();

        $this->io->writeln('');
        $this->io->writeln("  <fg=magenta;options=bold>KayraPHP</> <fg=gray>" . \Kayra\Foundation\Application::VERSION . "</>");
        $this->io->writeln('');
        $this->io->writeln("  <fg=green>➜</>  URL:      <href=http://{$host}:{$port}>http://{$host}:{$port}</>");
        $this->io->writeln("  <fg=green>➜</>  Root:     {$this->relative($root)}");
        $this->io->writeln("  <fg=green>➜</>  Env:      {$this->app->environment()}");
        $this->io->writeln("  <fg=green>➜</>  PHP:      " . PHP_VERSION);
        $this->io->writeln('');
        $this->io->writeln('  <fg=gray>Press Ctrl+C to stop.</>');
        $this->io->writeln('');

        // The router script re-serves existing static files itself; see public/index.php.
        $command = [
            PHP_BINARY,
            '-d', 'variables_order=EGPCS',
            '-S', "{$host}:{$port}",
            '-t', $root,
            $root . DIRECTORY_SEPARATOR . 'index.php',
        ];

        $process = proc_open(
            $command,
            [0 => STDIN, 1 => STDOUT, 2 => STDERR],
            $pipes,
            $this->app->basePath(),
        );

        if (!is_resource($process)) {
            $this->io->error('Unable to start the PHP development server.');

            return self::FAILURE;
        }

        return proc_close($process) === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Find a port that is not already in use.
     */
    private function availablePort(string $host, int $start, int $tries): ?int
    {
        for ($port = $start; $port < $start + $tries; $port++) {
            $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);

            if ($socket === false) {
                return $port;
            }

            fclose($socket);

            if ($port === $start) {
                $this->io->warning("Port {$port} is in use, trying the next one.");
            }
        }

        return null;
    }
}
