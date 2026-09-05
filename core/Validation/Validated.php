<?php

declare(strict_types=1);

namespace Kayra\Validation;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use RuntimeException;
use Traversable;

/**
 * Validated input, readable as the types it was validated as.
 *
 * `validate()` returns a plain array, which is honest -- its values really are
 * mixed as far as the type system knows -- but it pushes the problem into every
 * controller:
 *
 *     $user->name = (string) $data['name'];   // static analysis: cast of mixed
 *
 * The rules already said `name` is a string. This object is where that
 * knowledge is cashed in:
 *
 *     $safe = Validator::make($input, $rules)->safe();
 *     $user->name = $safe->string('name');
 *
 * Each accessor checks rather than casts, and throws when the value is not what
 * was asked for. That should be unreachable when the key had a matching rule --
 * and if it is reachable, the rules and the reader disagree, which is worth an
 * exception rather than a silent "0".
 *
 * @implements ArrayAccess<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
final class Validated implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /* --------------------------------------------------------------------
     | Typed reads
     * -------------------------------------------------------------------- */

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->data[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        // An int or float that reached a string field is not a type error worth
        // raising -- "42" is what a form would have sent anyway.
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default ?? throw $this->missing($key, 'string', $value);
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->data[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return $default ?? throw $this->missing($key, 'int', $value);
    }

    public function float(string $key, ?float $default = null): float
    {
        $value = $this->data[$key] ?? null;

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default ?? throw $this->missing($key, 'float', $value);
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = $this->data[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [1, 0, '1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
            return in_array($value, [1, '1', 'true', 'yes', 'on'], true);
        }

        return $default ?? throw $this->missing($key, 'bool', $value);
    }

    /**
     * @param array<array-key, mixed>|null $default
     *
     * @return array<array-key, mixed>
     */
    public function array(string $key, ?array $default = null): array
    {
        $value = $this->data[$key] ?? null;

        if (is_array($value)) {
            return $value;
        }

        return $default ?? throw $this->missing($key, 'array', $value);
    }

    /**
     * A value that is allowed to be absent, with no type promise.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /* --------------------------------------------------------------------
     | The whole set
     * -------------------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->data);
    }

    /* --------------------------------------------------------------------
     | ArrayAccess: read-only on purpose
     * -------------------------------------------------------------------- */

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? ($this->data[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new RuntimeException(
            'Validated input is read-only. Writing to it would put a value into the set that '
            . 'no rule ever checked, which is the thing this class exists to prevent.',
        );
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new RuntimeException('Validated input is read-only.');
    }

    private function missing(string $key, string $expected, mixed $actual): RuntimeException
    {
        return new RuntimeException(sprintf(
            'Validated field [%s] was read as %s but holds %s. Either the rule for it does not '
            . 'match how it is being read, or the field has no rule at all -- only validated '
            . 'fields are present here.',
            $key,
            $expected,
            get_debug_type($actual),
        ));
    }
}
