<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Preferences each admin sets for the admin itself (interface I8): light/dark, the accent colour,
 * advanced pages, Developer mode and the old/new admin. They never change the public site.
 *
 * Stored as a JSON object in users.admin_preferences (migration 2026_10_20_000003). Until that
 * migration has run ("Finish update"), every read returns the defaults and nothing is written.
 */
final class AdminPreferences
{
    public const COLUMN = 'admin_preferences';

    /** key => [default, allowed values | 'bool' | 'list'] */
    private const DEFINITIONS = [
        'admin_theme' => ['system', ['system', 'light', 'dark']],
        'accent_mode' => ['auto', ['auto', 'site', 'neutral', 'ink']],
        'developer_mode' => [false, 'bool'],
        'show_advanced' => [false, 'bool'],
        'admin_ui' => ['v2', ['v1', 'v2']],
        'dismissed' => [[], 'list'],
    ];

    private const MAX_DISMISSED = 50;

    public static function defaults(): array
    {
        return array_map(fn (array $definition) => $definition[0], self::DEFINITIONS);
    }

    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /**
     * Whether the column exists. A loaded user answers without a query (its attributes include the
     * column); otherwise the schema is asked.
     */
    public static function available(?User $user = null): bool
    {
        if ($user !== null && array_key_exists(self::COLUMN, $user->getAttributes())) {
            return true;
        }

        try {
            return Schema::hasTable('users') && Schema::hasColumn('users', self::COLUMN);
        } catch (Throwable) {
            return false;
        }
    }

    public static function all(?User $user): array
    {
        $stored = self::stored($user);
        $values = self::defaults();

        foreach (self::DEFINITIONS as $key => $definition) {
            if (! array_key_exists($key, $stored)) {
                continue;
            }

            try {
                $values[$key] = self::normalize($key, $stored[$key]);
            } catch (InvalidArgumentException) {
                // A value written by an older or newer release: keep the default.
            }
        }

        return $values;
    }

    public static function get(?User $user, string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::DEFINITIONS)) {
            return $default;
        }

        $stored = self::stored($user);

        if (! array_key_exists($key, $stored)) {
            return $default ?? self::DEFINITIONS[$key][0];
        }

        try {
            return self::normalize($key, $stored[$key]);
        } catch (InvalidArgumentException) {
            return $default ?? self::DEFINITIONS[$key][0];
        }
    }

    /**
     * @throws InvalidArgumentException for an unknown key or a value the key does not accept
     */
    public static function set(User $user, string $key, mixed $value): void
    {
        $value = self::normalize($key, $value);

        if (! self::available($user)) {
            return;
        }

        $stored = self::stored($user);
        $stored[$key] = $value;
        $json = json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // A plain update: no model events, no updated_at bump, no other dirty attribute saved by accident.
        User::query()->whereKey($user->getKey())->toBase()->update([self::COLUMN => $json]);

        $user->setRawAttributes(array_merge($user->getAttributes(), [self::COLUMN => $json]), true);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function normalize(string $key, mixed $value): mixed
    {
        if (! array_key_exists($key, self::DEFINITIONS)) {
            throw new InvalidArgumentException("Unknown admin preference [{$key}].");
        }

        $rule = self::DEFINITIONS[$key][1];

        if ($rule === 'bool') {
            if (is_bool($value)) {
                return $value;
            }

            $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($flag === null) {
                throw new InvalidArgumentException("Admin preference [{$key}] must be on or off.");
            }

            return $flag;
        }

        if ($rule === 'list') {
            if (! is_array($value)) {
                throw new InvalidArgumentException("Admin preference [{$key}] must be a list.");
            }

            $items = array_values(array_unique(array_filter(
                array_map(fn ($item) => is_scalar($item) ? substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) $item), 0, 80) : '', $value),
                fn (string $item) => $item !== '',
            )));

            return array_slice($items, -self::MAX_DISMISSED);
        }

        if (! is_string($value) || ! in_array($value, $rule, true)) {
            throw new InvalidArgumentException("Admin preference [{$key}] does not accept this value.");
        }

        return $value;
    }

    /** True when the redesigned admin renders for this admin (site switch v2 and not switched back). */
    public static function usesNewAdmin(?User $user = null): bool
    {
        if (config('admin.ui', 'v2') !== 'v2') {
            return false;
        }

        return self::get($user ?? self::currentUser(), 'admin_ui') !== 'v1';
    }

    public static function developerMode(?User $user = null): bool
    {
        return self::get($user ?? self::currentUser(), 'developer_mode') === true;
    }

    public static function showAdvanced(?User $user = null): bool
    {
        return self::get($user ?? self::currentUser(), 'show_advanced') === true;
    }

    public static function dismiss(User $user, string $notice): void
    {
        $dismissed = self::get($user, 'dismissed', []);
        $dismissed[] = $notice;

        self::set($user, 'dismissed', $dismissed);
    }

    public static function dismissed(?User $user, string $notice): bool
    {
        return in_array($notice, (array) self::get($user, 'dismissed', []), true);
    }

    private static function stored(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $raw = $user->getAttributes()[self::COLUMN] ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function currentUser(): ?User
    {
        try {
            $user = auth()->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }
}
