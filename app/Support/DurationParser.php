<?php

namespace App\Support;

class DurationParser
{
    public static function toSeconds(mixed $duration): ?int
    {
        if ($duration === null || $duration === '') {
            return null;
        }

        if (is_numeric($duration)) {
            $seconds = (int) round((float) $duration);

            return $seconds > 0 ? $seconds : null;
        }

        $value = trim(html_entity_decode(strip_tags((string) $duration), ENT_QUOTES, 'UTF-8'));
        $value = str_replace(['：', '﹕'], ':', $value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ':')) {
            $parts = array_map('intval', explode(':', $value));

            if (count($parts) === 3) {
                $seconds = ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
            } elseif (count($parts) === 2) {
                $seconds = ($parts[0] * 60) + $parts[1];
            } else {
                return null;
            }

            return $seconds > 0 ? $seconds : null;
        }

        $seconds = 0;
        $matched = false;

        $patterns = [
            '/(\d+(?:\.\d+)?)\s*(?:h|hr|hrs|hour|hours|小時|小时|時間)/iu' => 3600,
            '/(\d+(?:\.\d+)?)\s*(?:m|min|mins|minute|minutes|分)/iu' => 60,
            '/(\d+(?:\.\d+)?)\s*(?:s|sec|secs|second|seconds|秒)/iu' => 1,
        ];

        foreach ($patterns as $pattern => $multiplier) {
            if (preg_match_all($pattern, $value, $matches)) {
                foreach ($matches[1] as $number) {
                    $seconds += (int) round((float) $number * $multiplier);
                    $matched = true;
                }
            }
        }

        return $matched && $seconds > 0 ? $seconds : null;
    }
}
