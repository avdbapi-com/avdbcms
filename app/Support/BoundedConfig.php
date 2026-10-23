<?php

namespace App\Support;

class BoundedConfig
{
    public static function integer(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) config($key, $default)));
    }
}
