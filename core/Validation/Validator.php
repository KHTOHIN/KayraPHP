<?php

declare(strict_types=1);

namespace Kayra\Validation;

use Closure;
use InvalidArgumentException;
use Kayra\Exceptions\ValidationException;

/**
 * Rule-based input validation.
 *
 * Returns only the fields that were actually validated. That is deliberate:
 * passing the raw input onward after validating a subset of it is how
 * mass-assignment bugs happen, so {@see validated()} cannot return a key that
 * had no rule.
 *
 * <code>
 * $data = Validator::make($request->all(), [
 *     'email'    => 'required|email|max:255',
 *     'age'      => 'nullable|integer|between:13,120',
 *     'password' => 'required|string|min:12|confirmed',
 * ])->validate();
 * </code>
 */
final class Validator
{
    /** @var array<string, list<string>> field => messages */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    /** @var array<string, Closure(mixed, list<string>, array<string, mixed>): (bool|string)> */
    private array $extensions = [];

    /** @var array<string, string> */
    private array $messages = [];

    private bool $ran = false;

    /**
     * @param array<string, mixed>          $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string>         $messages Custom "field.rule" messages.
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        array $messages = [],
    ) {
        $this->messages = $messages;
    }

    /**
     * @param array<string, mixed>               $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string>              $messages
     */
    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    /**
     * Register a custom rule.
     *
     * The callback returns true, false, or an error message string.
     *
     * @param Closure(mixed, list<string>, array<string, mixed>): (bool|string) $callback
     */
    public function extend(string $rule, Closure $callback): self
    {
        $this->extensions[$rule] = $callback;

        return $this;
    }

    /**
     * Validate and return the validated subset.
     *
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    public function passes(): bool
    {
        $this->run();

        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $this->run();

        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $this->run();

        return $this->validated;
    }

    private function run(): void
    {
        if ($this->ran) {
            return;
        }

        $this->ran = true;

        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);
            $value = $this->data[$field] ?? null;
            $present = array_key_exists($field, $this->data);

            // 'nullable' and a missing optional field short-circuit the rest:
            // running 'email' against null would produce a nonsense message.
            if ($this->skips($rules, $value, $present)) {
                if ($present) {
                    $this->validated[$field] = $value;
                }

                continue;
            }

            $failed = false;

            foreach ($rules as $rule) {
                $rule = trim((string) $rule);

                if ($rule === '' || $rule === 'nullable' || $rule === 'sometimes') {
                    continue;
                }

                [$name, $parameters] = $this->parseRule($rule);

                if (!$this->check($name, $parameters, $field, $value)) {
                    $failed = true;

                    // One message per field: a list of six complaints about the
                    // same input is noise, not help.
                    break;
                }
            }

            if (!$failed) {
                $this->validated[$field] = $this->cast($rules, $value);
            }
        }
    }

    /**
     * @param list<string> $rules
     */
    private function skips(array $rules, mixed $value, bool $present): bool
    {
        $normalised = array_map(static fn ($r): string => trim((string) $r), $rules);

        if (in_array('sometimes', $normalised, true) && !$present) {
            return true;
        }

        if (in_array('nullable', $normalised, true) && ($value === null || $value === '')) {
            return true;
        }

        return false;
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function parseRule(string $rule): array
    {
        $separator = strpos($rule, ':');

        if ($separator === false) {
            return [$rule, []];
        }

        return [
            substr($rule, 0, $separator),
            array_map(trim(...), explode(',', substr($rule, $separator + 1))),
        ];
    }

    /**
     * @param list<string> $parameters
     */
    private function check(string $rule, array $parameters, string $field, mixed $value): bool
    {
        if (isset($this->extensions[$rule])) {
            $result = ($this->extensions[$rule])($value, $parameters, $this->data);

            if ($result === true) {
                return true;
            }

            $this->addError($field, $rule, is_string($result) ? $result : "The {$field} field is invalid.");

            return false;
        }

        $passed = match ($rule) {
            'required'  => $this->notEmpty($value),
            'string'    => is_string($value),
            'integer'   => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'numeric'   => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'boolean'   => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
            'array'     => is_array($value),
            'email'     => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'       => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip'        => is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false,
            'uuid'      => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
            'alpha'     => is_string($value) && preg_match('/^[\p{L}]+$/u', $value) === 1,
            'alpha_num' => is_string($value) && preg_match('/^[\p{L}\p{N}]+$/u', $value) === 1,
            'alpha_dash' => is_string($value) && preg_match('/^[\p{L}\p{N}_-]+$/u', $value) === 1,
            'date'      => is_string($value) && strtotime($value) !== false,
            'min'       => $this->compareSize($value, (float) ($parameters[0] ?? 0), '>='),
            'max'       => $this->compareSize($value, (float) ($parameters[0] ?? 0), '<='),
            'between'   => $this->compareSize($value, (float) ($parameters[0] ?? 0), '>=')
                            && $this->compareSize($value, (float) ($parameters[1] ?? 0), '<='),
            'size'      => $this->compareSize($value, (float) ($parameters[0] ?? 0), '=='),
            'in'        => in_array((string) (is_scalar($value) ? $value : ''), $parameters, true),
            'not_in'    => !in_array((string) (is_scalar($value) ? $value : ''), $parameters, true),
            'regex'     => is_string($value) && @preg_match($parameters[0] ?? '//', $value) === 1,
            'confirmed' => ($this->data[$field . '_confirmation'] ?? null) === $value,
            'same'      => ($this->data[$parameters[0] ?? ''] ?? null) === $value,
            'different' => ($this->data[$parameters[0] ?? ''] ?? null) !== $value,
            'accepted'  => in_array($value, [true, 1, '1', 'yes', 'on', 'true'], true),
            default     => throw new InvalidArgumentException("Unknown validation rule [{$rule}]."),
        };

        if (!$passed) {
            $this->addError($field, $rule, $this->message($field, $rule, $parameters));
        }

        return $passed;
    }

    private function notEmpty(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * Size means length for strings, count for arrays, and value for numbers —
     * so `min:8` reads correctly for a password and for an age.
     */
    private function compareSize(mixed $value, float $limit, string $operator): bool
    {
        // A numeric *string* is measured by length, not value: `max:8` on a
        // password means eight characters, not the number eight. The earlier
        // arms already cover every case, so a trailing is_numeric() check would
        // be unreachable.
        $size = match (true) {
            is_string($value) => (float) mb_strlen($value),
            is_array($value)  => (float) count($value),
            is_int($value), is_float($value) => (float) $value,
            default => null,
        };

        if ($size === null) {
            return false;
        }

        return match ($operator) {
            '>='    => $size >= $limit,
            '<='    => $size <= $limit,
            default => abs($size - $limit) < PHP_FLOAT_EPSILON,
        };
    }

    /**
     * Cast a validated value to the type its rules imply.
     *
     * @param list<string> $rules
     */
    private function cast(array $rules, mixed $value): mixed
    {
        $names = array_map(fn (string $r): string => $this->parseRule(trim($r))[0], array_map(strval(...), $rules));

        if (in_array('integer', $names, true) && is_string($value)) {
            return (int) $value;
        }

        if (in_array('numeric', $names, true) && is_string($value) && is_numeric($value)) {
            // int when it is one, float otherwise — `+ 0` would be a TypeError
            // on a non-numeric string, which the is_numeric() guard rules out.
            return str_contains($value, '.') || str_contains(strtolower($value), 'e')
                ? (float) $value
                : (int) $value;
        }

        if (in_array('boolean', $names, true) && !is_bool($value)) {
            return in_array($value, [1, '1', 'true'], true);
        }

        return $value;
    }

    /**
     * @param list<string> $parameters
     */
    private function message(string $field, string $rule, array $parameters): string
    {
        if (isset($this->messages["{$field}.{$rule}"])) {
            return $this->messages["{$field}.{$rule}"];
        }

        if (isset($this->messages[$rule])) {
            return $this->messages[$rule];
        }

        $label = str_replace('_', ' ', $field);
        $first = $parameters[0] ?? '';

        return match ($rule) {
            'required'  => "The {$label} field is required.",
            'email'     => "The {$label} field must be a valid email address.",
            'url'       => "The {$label} field must be a valid URL.",
            'integer'   => "The {$label} field must be an integer.",
            'numeric'   => "The {$label} field must be a number.",
            'boolean'   => "The {$label} field must be true or false.",
            'array'     => "The {$label} field must be an array.",
            'string'    => "The {$label} field must be a string.",
            'min'       => "The {$label} field must be at least {$first}.",
            'max'       => "The {$label} field must not be greater than {$first}.",
            'between'   => "The {$label} field must be between {$first} and " . ($parameters[1] ?? '') . '.',
            'size'      => "The {$label} field must be {$first}.",
            'in'        => "The selected {$label} is invalid.",
            'confirmed' => "The {$label} field confirmation does not match.",
            'same'      => "The {$label} field must match {$first}.",
            'accepted'  => "The {$label} field must be accepted.",
            default     => "The {$label} field is invalid.",
        };
    }

    private function addError(string $field, string $rule, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
