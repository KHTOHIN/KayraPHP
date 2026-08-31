<?php

declare(strict_types=1);

namespace Kayra\Env;

use RuntimeException;

/**
 * A small, strict .env reader.
 *
 * Deliberately not a general-purpose dotenv library: it supports exactly what a
 * framework needs and nothing more, so the parsing rules stay predictable.
 *
 * Supported syntax:
 *   KEY=value                 unquoted, trailing comments stripped
 *   KEY="value with spaces"   double quotes, \n \t \" \\ escapes honoured
 *   KEY='raw value'           single quotes, no interpolation at all
 *   KEY="${OTHER}/path"       ${VAR} interpolation in double-quoted/bare values
 *   export KEY=value          the export prefix is ignored
 *   # comment                 whole-line comments
 *
 * Values are cast on read: "true"/"false" to bool, "null"/"" to null,
 * "base64:..." is decoded. Numeric strings are left as strings, because config
 * keys such as ports and version numbers are safer as strings.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $variables = [];

    private static bool $loaded = false;

    /**
     * Parse a .env file into the internal store.
     *
     * @param bool $override Replace values that are already present.
     */
    public static function load(string $path, bool $override = false): void
    {
        self::$loaded = true;

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read environment file [{$path}].");
        }

        foreach (self::parse($contents) as $key => $value) {
            if (!$override && array_key_exists($key, self::$variables)) {
                continue;
            }

            self::$variables[$key] = $value;
        }
    }

    /**
     * Keys in $_SERVER that describe the request, not the environment.
     *
     * Under a web SAPI, $_SERVER is a mixture of process environment and
     * request metadata. Importing it wholesale would turn attacker-controlled
     * request data into configuration input, so request keys are excluded.
     */
    private const REQUEST_KEYS = [
        'REQUEST_METHOD', 'REQUEST_URI', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT',
        'QUERY_STRING', 'CONTENT_TYPE', 'CONTENT_LENGTH', 'REQUEST_SCHEME',
        'REMOTE_ADDR', 'REMOTE_PORT', 'REMOTE_HOST', 'REMOTE_USER',
        'SERVER_NAME', 'SERVER_ADDR', 'SERVER_PORT', 'SERVER_PROTOCOL',
        'SERVER_SIGNATURE', 'SERVER_SOFTWARE', 'SERVER_ADMIN',
        'GATEWAY_INTERFACE', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'PHP_SELF',
        'DOCUMENT_ROOT', 'DOCUMENT_URI', 'PATH_INFO', 'PATH_TRANSLATED',
        'ORIG_PATH_INFO', 'AUTH_TYPE', 'PHP_AUTH_USER', 'PHP_AUTH_PW',
        'PHP_AUTH_DIGEST', 'HTTPS', 'argv', 'argc',
    ];

    /**
     * Seed the store from the real process environment.
     *
     * Real environment variables win over the .env file, which is what every
     * container orchestrator expects.
     */
    public static function loadFromProcess(): void
    {
        // getenv() is the actual process environment, free of request data.
        $environment = getenv();

        if (is_array($environment)) {
            foreach ($environment as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    self::$variables[$key] = $value;
                }
            }
        }

        // $_ENV and $_SERVER can still carry variables the SAPI injected
        // (fastcgi_param, SetEnv), so import them minus the request keys.
        foreach ([$_ENV, $_SERVER] as $source) {
            foreach ($source as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    continue;
                }

                if (self::isRequestKey($key)) {
                    continue;
                }

                self::$variables[$key] = $value;
            }
        }
    }

    private static function isRequestKey(string $key): bool
    {
        return str_starts_with($key, 'HTTP_')
            || str_starts_with($key, 'REDIRECT_')
            || str_starts_with($key, 'PHP_AUTH')
            || in_array($key, self::REQUEST_KEYS, true);
    }

    /**
     * Parse .env text into a flat key => raw-string map.
     *
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $result = [];
        $lines = preg_split('/\R/', $contents) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = rtrim(substr($line, 0, $separator));
            $raw = ltrim(substr($line, $separator + 1));

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key) !== 1) {
                continue;
            }

            $result[$key] = self::parseValue($raw, $result);
        }

        return $result;
    }

    /**
     * @param array<string, string> $sofar Already-parsed values, for ${VAR} lookups.
     */
    private static function parseValue(string $raw, array $sofar): string
    {
        if ($raw === '') {
            return '';
        }

        $quote = $raw[0];

        if ($quote === '"' || $quote === "'") {
            $closing = self::findClosingQuote($raw, $quote);

            if ($closing === null) {
                // Unterminated quote: treat the remainder literally.
                return substr($raw, 1);
            }

            $inner = substr($raw, 1, $closing - 1);

            if ($quote === "'") {
                return $inner;
            }

            return self::interpolate(self::unescape($inner), $sofar);
        }

        // Bare value: a # starts a comment only when preceded by whitespace.
        if (preg_match('/\s#/', $raw, $m, PREG_OFFSET_CAPTURE) === 1) {
            $raw = substr($raw, 0, $m[0][1]);
        }

        return self::interpolate(trim($raw), $sofar);
    }

    private static function findClosingQuote(string $raw, string $quote): ?int
    {
        $length = strlen($raw);

        for ($i = 1; $i < $length; $i++) {
            if ($raw[$i] === '\\' && $quote === '"') {
                $i++;

                continue;
            }

            if ($raw[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    private static function unescape(string $value): string
    {
        return strtr($value, [
            '\\n'  => "\n",
            '\\r'  => "\r",
            '\\t'  => "\t",
            '\\"'  => '"',
            '\\\\' => '\\',
        ]);
    }

    /**
     * @param array<string, string> $sofar
     */
    private static function interpolate(string $value, array $sofar): string
    {
        if (!str_contains($value, '${')) {
            return $value;
        }

        return preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_.]*)\}/',
            static fn (array $m): string => $sofar[$m[1]] ?? self::$variables[$m[1]] ?? '',
            $value,
        ) ?? $value;
    }

    /**
     * Read an environment value, cast to a PHP type.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$variables)) {
            return $default;
        }

        return self::cast(self::$variables[$key]) ?? $default;
    }

    /**
     * Read a value that must be present.
     */
    public static function require(string $key): mixed
    {
        $value = self::get($key);

        if ($value === null) {
            throw new RuntimeException("Required environment variable [{$key}] is not set.");
        }

        return $value;
    }

    private static function cast(string $value): mixed
    {
        return match (strtolower($value)) {
            'true', '(true)'     => true,
            'false', '(false)'   => false,
            'null', '(null)', '' => null,
            'empty', '(empty)'   => '',
            default              => str_starts_with($value, 'base64:')
                ? (base64_decode(substr($value, 7), true) ?: $value)
                : $value,
        };
    }

    public static function set(string $key, string $value): void
    {
        self::$variables[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$variables);
    }

    public static function loaded(): bool
    {
        return self::$loaded;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::$variables;
    }

    /**
     * Reset the store. Intended for tests and long-running worker reloads.
     */
    public static function flush(): void
    {
        self::$variables = [];
        self::$loaded = false;
    }
}
