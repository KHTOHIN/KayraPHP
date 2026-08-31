<?php

declare(strict_types=1);

namespace Kayra\Config;

use ArrayAccess;
use InvalidArgumentException;

/**
 * Configuration store with dot-notation access and typed accessors.
 *
 * The typed accessors exist so that configuration mistakes surface at boot with
 * a clear message, instead of as a TypeError deep inside a service.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class Repository implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $items;

    /**
     * Flat cache of resolved dot-paths. Configuration is read far more often
     * than it is written, so memoising the walk is worth the memory.
     *
     * @var array<string, mixed>
     */
    private array $resolved = [];

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Build a repository by requiring every .php file in a directory.
     *
     * Each file returns an array and becomes a top-level key named after the
     * file, so config/app.php is reachable as 'app.*'.
     */
    public static function fromDirectory(string $directory): self
    {
        $items = [];

        foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            $key = basename($file, '.php');
            $value = require $file;

            if (is_array($value)) {
                $items[$key] = $value;
            }
        }

        return new self($items);
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        // Whole-file fast path: 'app' with no dot.
        if (array_key_exists($key, $this->items)) {
            return $this->resolved[$key] = $this->items[$key];
        }

        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $this->resolved[$key] = $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target = &$this->items;

        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                break;
            }

            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target[end($segments)] = $value;

        // A write can invalidate any memoised prefix, so drop the whole cache.
        $this->resolved = [];
    }

    /**
     * @param array<string, mixed> $items
     */
    public function merge(array $items): void
    {
        $this->items = array_replace_recursive($this->items, $items);
        $this->resolved = [];
    }

    /* --------------------------------------------------------------------
     | Typed accessors
     * -------------------------------------------------------------------- */

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->get($key, $default);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw $this->typeError($key, 'string', $value);
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->get($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw $this->typeError($key, 'int', $value);
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalised = strtolower($value);

            if (in_array($normalised, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalised, ['false', '0', 'no', 'off'], true)) {
                return false;
            }
        }

        if ($value === 1 || $value === 0) {
            return $value === 1;
        }

        throw $this->typeError($key, 'bool', $value);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(string $key, ?array $default = null): array
    {
        $value = $this->get($key, $default);

        if (is_array($value)) {
            return $value;
        }

        throw $this->typeError($key, 'array', $value);
    }

    private function typeError(string $key, string $expected, mixed $actual): InvalidArgumentException
    {
        $got = get_debug_type($actual);

        return new InvalidArgumentException(
            "Configuration key [{$key}] must be of type {$expected}, {$got} given.",
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    /* --------------------------------------------------------------------
     | ArrayAccess
     * -------------------------------------------------------------------- */

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new InvalidArgumentException('Configuration keys cannot be appended without a name.');
        }

        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->set((string) $offset, null);
    }
}
