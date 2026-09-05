<?php

declare(strict_types=1);

namespace Kayra\Console\Commands;

use Kayra\Console\Command;
use Kayra\Http\Uri;
use Kayra\Runtime\RuntimeInterface;
use Kayra\Runtime\SwooleRuntime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Checks the environment and reports which fast paths are active.
 */
#[AsCommand(name: 'doctor', description: 'Check the environment and active optimisations')]
final class DoctorCommand extends Command
{
    /** Checks that failed, for the --security exit code. */
    private int $failures = 0;

    protected function configure(): void
    {
        $this->addOption(
            'security',
            null,
            InputOption::VALUE_NONE,
            'Exit non-zero if any security check fails — for deployment pipelines',
        );
    }

    protected function run_(): int
    {
        $production = $this->app->isProduction();
        $securityMode = $this->option('security') === true;

        $this->io->newLine();
        $this->io->writeln('  <options=bold>Environment</>');
        $this->line('PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.5.0', '>='), 'requires 8.5+');
        $this->line('SAPI', PHP_SAPI, true);
        $this->line('Runtime', $this->app->get(RuntimeInterface::class)->name(), true);
        $this->line('APP_ENV', $this->app->environment(), true);
        $this->line('Debug mode', $this->app->isDebug() ? 'on' : 'off', !($production && $this->app->isDebug()), 'must be off in production');

        $this->io->newLine();
        $this->io->writeln('  <options=bold>PHP 8.5 fast paths</>');
        $this->line('ext/uri native parser', Uri::usingNativeParser() ? 'in use' : 'not available', Uri::usingNativeParser(), 'lenient fallback in use');
        $this->line('#[\NoDiscard] enforcement', class_exists('NoDiscard') ? 'active' : 'inactive', class_exists('NoDiscard'), 'PHP 8.5 only');
        // Guaranteed by the 8.5 floor in composer.json; probing it would be
        // a check that can never fail.
        $this->line('Lazy objects', 'available', true, 'native since 8.4');

        $this->io->newLine();
        $this->io->writeln('  <options=bold>Extensions</>');

        foreach (['mbstring' => true, 'json' => true, 'pdo' => false, 'openssl' => true, 'opcache' => false, 'redis' => false, 'swoole' => false] as $extension => $required) {
            $loaded = extension_loaded($extension) || ($extension === 'opcache' && extension_loaded('Zend OPcache'));
            $this->line($extension, $loaded ? 'loaded' : 'missing', $loaded, $required ? 'required' : 'optional', optional: !$required);
        }

        $this->io->newLine();
        $this->io->writeln('  <options=bold>Optimisation</>');
        // Outside production these are informational, so a "no" is not a failure.
        $this->line('Config cached', $this->app->configIsCached() ? 'yes' : 'no', $this->app->configIsCached(), 'run `kayra optimize`', optional: !$production);
        $this->line('Routes cached', $this->app->routesAreCached() ? 'yes' : 'no', $this->app->routesAreCached(), 'run `kayra optimize`', optional: !$production);
        $this->line(
            'Services compiled',
            $this->app->servicesAreCached() ? $this->app->compiledCount() . ' class(es)' : 'no',
            $this->app->servicesAreCached(),
            'run `kayra optimize`',
            optional: !$production,
        );

        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $opcacheOn = is_array($opcache) && ($opcache['opcache_enabled'] ?? false);
        $this->line('OPcache enabled', $opcacheOn ? 'yes' : 'no', $opcacheOn, 'largest single production win', optional: !$production);

        $this->io->newLine();
        $this->io->writeln('  <options=bold>Writable paths</>');

        foreach ([$this->app->storagePath('logs'), $this->app->storagePath('framework/views'), $this->app->bootstrapCachePath()] as $path) {
            $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));
            $this->line($this->relative($path), $writable ? 'writable' : 'NOT writable', $writable, 'chmod required');
        }

        $this->io->newLine();
        $this->io->writeln('  <options=bold>Security</>');

        $config = $this->app->config();

        $key = (string) $config->get('app.key', '');
        $this->line('APP_KEY set', $key !== '' ? 'yes' : 'no', $key !== '', 'run `kayra key:generate`');

        // Encryption is only genuinely working if the key round-trips.
        $encryptionOk = false;

        if ($key !== '') {
            try {
                $encrypter = $this->app->get(\Kayra\Encryption\Encrypter::class);
                $encryptionOk = $encrypter->decrypt($encrypter->encrypt('probe')) === 'probe';
            } catch (\Throwable) {
                $encryptionOk = false;
            }
        }

        $this->line('Encryption works', $encryptionOk ? 'yes' : 'no', $encryptionOk, 'APP_KEY is not a valid 32-byte key');

        $hosts = $config->array('security.trusted_hosts', []);
        $this->line(
            'Trusted hosts',
            $hosts === [] ? 'not configured (any Host accepted)' : implode(', ', array_map(strval(...), $hosts)),
            $hosts !== [],
            'set TRUSTED_HOSTS to prevent host-header poisoning',
            optional: !$production,
        );

        $proxies = $config->array('security.trusted_proxies', []);
        $this->line(
            'Trusted proxies',
            $proxies === [] ? 'none (X-Forwarded-* ignored)' : implode(', ', array_map(strval(...), $proxies)),
            !in_array('*', $proxies, true),
            "'*' trusts every source",
            optional: true,
        );

        $hsts = (string) $config->get('security.hsts', '');
        $this->line(
            'HSTS',
            $hsts === '' ? 'not set' : $hsts,
            $hsts !== '',
            'set HSTS_HEADER once TLS is confirmed',
            optional: !$production,
        );

        $csp = $config->get('security.headers.Content-Security-Policy');
        $this->line(
            'Content-Security-Policy',
            is_string($csp) && $csp !== '' ? 'configured' : 'not set',
            is_string($csp) && $csp !== '',
            'the strongest single defence against XSS',
            optional: !$production,
        );

        $sameSite = $config->string('session.same_site', 'Lax');
        $this->line(
            'Session SameSite',
            $sameSite,
            in_array($sameSite, ['Lax', 'Strict'], true),
            "'None' requires Secure and weakens CSRF defence",
        );

        $csrfExcept = $config->array('session.csrf_except', []);
        $this->line(
            'CSRF exemptions',
            $csrfExcept === [] ? 'none' : implode(', ', array_map(strval(...), $csrfExcept)),
            true,
            '',
            optional: true,
        );

        if (SwooleRuntime::isAvailable()) {
            $this->io->newLine();
            $this->io->note('Swoole detected. Scoped container bindings are per-coroutine and cleared after each request.');
        }

        $this->io->newLine();

        if ($securityMode && $this->failures > 0) {
            $this->io->error("{$this->failures} check(s) failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param bool $ok       Whether the check genuinely passed.
     * @param bool $optional Whether failing it is acceptable here.
     */
    private function line(string $label, string $value, bool $ok, string $hint = '', bool $optional = false): void
    {
        if (!$ok && !$optional) {
            $this->failures++;
        }

        $icon = match (true) {
            $ok       => '<fg=green>✓</>',
            $optional => '<fg=gray>·</>',
            default   => '<fg=red>✗</>',
        };

        $suffix = $ok || $hint === '' ? '' : "  <fg=gray>({$hint})</>";

        $this->io->writeln(sprintf('  %s %-28s <fg=gray>%s</>%s', $icon, $label, $value, $suffix));
    }
}
