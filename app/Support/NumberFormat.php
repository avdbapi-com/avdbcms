<?php

namespace App\Support;

final class NumberFormat
{
    public static function fileSize(mixed $bytes): string
    {
        if ($bytes === null || $bytes === '') {
            return '';
        }

        $size = max(0.0, (float) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        $precision = $unitIndex === 0 ? 0 : ($size >= 100 ? 0 : ($size >= 10 ? 1 : 2));

        return self::formatDecimal($size, $precision).' '.$units[$unitIndex];
    }

    public static function currency(mixed $amount, string $in = 'EUR', ?string $locale = null, int $precision = 2): string
    {
        $currency = strtoupper($in ?: 'EUR');

        return $currency.' '.number_format((float) $amount, $precision, '.', ',');
    }

    public static function abbreviate(mixed $number, int $maxPrecision = 1): string
    {
        $value = (float) $number;
        $absolute = abs($value);

        foreach ([1000000000000 => 'T', 1000000000 => 'B', 1000000 => 'M', 1000 => 'K'] as $threshold => $suffix) {
            if ($absolute >= $threshold) {
                return self::formatDecimal($value / $threshold, $maxPrecision).$suffix;
            }
        }

        return number_format($value, 0, '.', ',');
    }

    private static function formatDecimal(float $value, int $precision): string
    {
        $formatted = number_format($value, $precision, '.', ',');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
