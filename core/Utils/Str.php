<?php

declare(strict_types=1);

namespace Kayra\Utils;

/**
 * String helpers used by the framework.
 *
 * Kept deliberately small: these exist because the framework needs them, not as
 * a general-purpose string library.
 */
final class Str
{
    /** @var array<string, string> */
    private static array $cache = [];

    /**
     * "user profile" / "user_profile" / "user-profile" -> "UserProfile"
     */
    public static function studly(string $value): string
    {
        return self::$cache['studly' . $value] ??= str_replace(
            ' ',
            '',
            ucwords(str_replace(['-', '_', '.', '/', '\\'], ' ', $value)),
        );
    }

    /**
     * "UserProfile" -> "userProfile"
     */
    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    /**
     * "UserProfile" -> "user_profile"
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        return self::$cache['snake' . $delimiter . $value] ??= strtolower(
            (string) preg_replace('/(?<!^)[A-Z]/', $delimiter . '$0', str_replace(['-', ' '], $delimiter, $value)),
        );
    }

    /**
     * "UserProfile" -> "user-profile"
     */
    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    /**
     * "user_profile.settings" -> "User Profile Settings"
     */
    public static function headline(string $value): string
    {
        return ucwords(trim((string) preg_replace(
            '/(?<!^)[A-Z]/',
            ' $0',
            str_replace(['-', '_', '.', '/'], ' ', $value),
        )));
    }

    /**
     * Naive English singularisation, good enough for route parameter names.
     */
    public static function singular(string $value): string
    {
        return match (true) {
            str_ends_with($value, 'ies') => substr($value, 0, -3) . 'y',
            str_ends_with($value, 'sses'),
            str_ends_with($value, 'shes'),
            str_ends_with($value, 'ches') => substr($value, 0, -2),
            str_ends_with($value, 'ss')   => $value,
            str_ends_with($value, 's')    => substr($value, 0, -1),
            default                       => $value,
        };
    }

    /**
     * A URL-safe slug.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', $separator, $value);

        return trim(strtolower($value), $separator);
    }

    /**
     * Cryptographically secure random string, hex-encoded.
     */
    public static function random(int $length = 32): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        return mb_strlen($value) <= $limit ? $value : rtrim(mb_substr($value, 0, $limit)) . $end;
    }
}
