<?php

namespace Kayra\Utils;

class Str
{
    public static function slug(string $string): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string), '-'));
    }

    public static function random(int $length = 16): string
    {
        return bin2hex(random_bytes($length / 2));
    }

    public static function plural(string $string): string
    {
        return $string . 's'; // Minimal stub
    }

    public static function singular(string $string): string
    {
        return substr($string, 0, -1); // Minimal stub
    }

    // Performance: No regex at runtime; precompile if needed
}