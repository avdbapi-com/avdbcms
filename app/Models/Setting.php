<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

class Setting extends Model
{
    protected $guarded = ['id'];

    /**
     * Get a setting value by key
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::rememberForever("setting.{$key}", function () use ($key, $default) {
                $setting = static::where('key', $key)->first();
                return $setting ? $setting->value : $default;
            });
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group]
        );

        Cache::forget("setting.{$key}");
        Cache::forget("settings.{$group}");
        Cache::forget('settings.__all_grouped');
    }

    /**
     * Get all settings for a group
     */
    public static function getGroup(string $group): array
    {
        try {
            return Cache::rememberForever("settings.{$group}", function () use ($group) {
                return static::where('group', $group)
                    ->pluck('value', 'key')
                    ->toArray();
            });
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Load ALL settings in a single query, grouped by group name.
     * Returns ['general' => [...], 'uploads' => [...], ...].
     * Used by AppServiceProvider to avoid 16+ individual getGroup() calls.
     */
    public static function getAllGrouped(): array
    {
        try {
            return Cache::rememberForever('settings.__all_grouped', function () {
                return static::all(['key', 'value', 'group'])
                    ->groupBy('group')
                    ->map(fn($items) => $items->pluck('value', 'key')->toArray())
                    ->toArray();
            });
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Clear all settings cache
     */
    public static function clearCache(array $keys = [], array $groups = []): void
    {
        Cache::forget('settings.__all_grouped');

        $groups = collect($groups)
            ->merge(static::distinct('group')->pluck('group'))
            ->filter()
            ->unique();

        foreach ($groups as $group) {
            Cache::forget("settings.{$group}");
        }

        $keys = collect($keys)
            ->merge(static::pluck('key'))
            ->filter()
            ->unique();

        foreach ($keys as $key) {
            Cache::forget("setting.{$key}");
        }
    }
}
