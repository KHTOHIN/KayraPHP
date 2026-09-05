<?php

declare(strict_types=1);

namespace Kayra\Foundation;

use RuntimeException;

/**
 * Discovers service providers declared by installed Composer packages.
 *
 * A package advertises itself in its own composer.json:
 *
 *     "extra": {
 *         "kayra": {
 *             "providers": ["Vendor\\Package\\PackageServiceProvider"],
 *             "aliases":   { "widget": "Vendor\\Package\\Widget" }
 *         }
 *     }
 *
 * The manifest is built once and cached, because scanning installed.json on
 * every boot would undo the work `kayra optimize` does elsewhere.
 *
 * Discovery is opt-out per package: an application can list a package under
 * `dont-discover` when it wants to register the provider itself, or not at all.
 */
final class PackageManifest
{
    /** @var array<string, array{providers: list<string>, aliases: array<string, string>}>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $vendorPath,
        private readonly string $manifestPath,
        /** @var list<string> */
        private readonly array $dontDiscover = [],
    ) {
    }

    /**
     * Service providers from every discovered package.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        $providers = [];

        foreach ($this->manifest() as $package) {
            foreach ($package['providers'] as $provider) {
                $providers[] = $provider;
            }
        }

        return array_values(array_unique($providers));
    }

    /**
     * @return array<string, string>
     */
    public function aliases(): array
    {
        $aliases = [];

        foreach ($this->manifest() as $package) {
            foreach ($package['aliases'] as $alias => $target) {
                $aliases[$alias] = $target;
            }
        }

        return $aliases;
    }

    /**
     * @return array<string, array{providers: list<string>, aliases: array<string, string>}>
     */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        if (is_file($this->manifestPath)) {
            /** @var array<string, array{providers: list<string>, aliases: array<string, string>}> $cached */
            $cached = require $this->manifestPath;

            return $this->manifest = $cached;
        }

        return $this->manifest = $this->build();
    }

    /**
     * Rebuild the manifest from installed.json and write it to the cache.
     *
     * @return array<string, array{providers: list<string>, aliases: array<string, string>}>
     */
    public function rebuild(): array
    {
        $manifest = $this->build();

        $this->manifest = $manifest;
        $this->write($manifest);

        return $manifest;
    }

    /**
     * @return array<string, array{providers: list<string>, aliases: array<string, string>}>
     */
    private function build(): array
    {
        $installed = $this->vendorPath . '/composer/installed.json';

        if (!is_file($installed)) {
            return [];
        }

        $contents = file_get_contents($installed);

        if ($contents === false) {
            return [];
        }

        /** @var array{packages?: list<array<string, mixed>>}|list<array<string, mixed>> $decoded */
        $decoded = json_decode($contents, true) ?: [];

        // Composer 2 nests under "packages"; Composer 1 was a bare list.
        $packages = $decoded['packages'] ?? $decoded;

        $manifest = [];

        foreach (is_array($packages) ? $packages : [] as $package) {
            if (!is_array($package)) {
                continue;
            }

            $name = (string) ($package['name'] ?? '');

            // `extra` is free-form: a package may set it to a scalar, and
            // indexing into that would be a fatal rather than a skipped package.
            $extraSection = $package['extra'] ?? null;
            $extra = is_array($extraSection) ? ($extraSection['kayra'] ?? null) : null;

            if ($name === '' || !is_array($extra) || in_array($name, $this->dontDiscover, true)) {
                continue;
            }

            // Only well-formed metadata is accepted. Coercing a bare string
            // into a one-element list would quietly turn a package's typo into
            // a provider name that fails much later, and further from the cause.
            $providers = $extra['providers'] ?? [];
            $aliases = $extra['aliases'] ?? [];

            $manifest[$name] = [
                'providers' => is_array($providers)
                    ? array_values(array_filter($providers, is_string(...)))
                    : [],
                'aliases' => is_array($aliases)
                    ? array_filter($aliases, is_string(...))
                    : [],
            ];
        }

        ksort($manifest);

        return $manifest;
    }

    /**
     * @param array<string, array{providers: list<string>, aliases: array<string, string>}> $manifest
     */
    private function write(array $manifest): void
    {
        $directory = dirname($this->manifestPath);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create [{$directory}].");
        }

        $contents = "<?php\n\n/* Package manifest generated by KayraPHP. Do not edit. */\n\nreturn "
            . var_export($manifest, true) . ";\n";

        if (file_put_contents($this->manifestPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write [{$this->manifestPath}].");
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->manifestPath, true);
        }
    }
}
