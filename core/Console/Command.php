<?php

declare(strict_types=1);

namespace Kayra\Console;

use Kayra\Foundation\Application;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Base class for `kayra` commands.
 *
 * Subclasses implement {@see run()} and get the application, a styled output
 * helper, and the input already wired up.
 */
abstract class Command extends SymfonyCommand
{
    protected InputInterface $input;

    protected SymfonyStyle $io;

    public function __construct(protected readonly Application $app)
    {
        parent::__construct();
    }

    /**
     * @return int One of self::SUCCESS, self::FAILURE, self::INVALID.
     */
    abstract protected function run_(): int;

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->io = new SymfonyStyle($input, $output);

        return $this->run_();
    }

    protected function argument(string $name): mixed
    {
        return $this->input->getArgument($name);
    }

    protected function option(string $name): mixed
    {
        return $this->input->getOption($name);
    }

    /**
     * Write a file, creating parent directories, refusing to clobber.
     */
    protected function writeFile(string $path, string $contents, bool $force = false): bool
    {
        if (is_file($path) && !$force) {
            $this->io->error(sprintf('%s already exists. Use --force to overwrite.', $this->relative($path)));

            return false;
        }

        $this->app->ensureDirectory(dirname($path));

        if (file_put_contents($path, $contents) === false) {
            $this->io->error("Unable to write {$path}.");

            return false;
        }

        $this->io->success('Created ' . $this->relative($path));

        return true;
    }

    protected function relative(string $path): string
    {
        $base = $this->app->basePath();
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/') : $path;
    }
}
