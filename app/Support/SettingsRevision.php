<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\SettingRevision;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * History of settings changes (admin_setting_revisions). A revision keeps rows (key => value) from before and after a
 * change in one of two scopes:
 * - 'group': one group's complete stored rows (AppearanceApplier, the upgrade), so restore() reproduces that state
 *   byte for byte;
 * - 'keys': only the keys a save wrote, nested by group (null = the key was not stored), so restore() puts back
 *   exactly those keys.
 * Only appearance revisions without masked values are restored; the others record who changed what. Every call
 * tolerates a missing table (an upload over an old install before "Finish update"): record()/restore() return null,
 * latest() [].
 */
final class SettingsRevision
{
    public const KINDS = [
        'upgrade_snapshot',
        'upgrade_result',
        'publish',
        'apply_theme',
        'apply_master',
        'reset',
        'restore',
        'settings_save',
    ];

    /** What a settings save records instead of a password or key; a revision holding it is never restored. */
    public const MASK = '(hidden)';

    /** Revisions kept per group (History lists the last 20); the upgrade snapshot stays until it expires. */
    public const KEEP = 50;

    private const TABLE = 'admin_setting_revisions';

    private const RESTORABLE_GROUP = 'appearance';

    public static function available(): bool
    {
        $key = self::class . '.table';

        if (app()->bound($key)) {
            return true;
        }

        try {
            $exists = Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }

        if ($exists) {
            app()->instance($key, true);
        }

        return $exists;
    }

    /**
     * Store a revision. Rows are key => scalar|null, either flat for one group or nested by group
     * ([group => [key => value]], $group = 'seo,general'). Flat rows that equal the group's stored rows after the
     * change are a complete snapshot (scope 'group'); anything else is a save of chosen keys (scope 'keys'). Expired
     * revisions are deleted and each group keeps its newest KEEP.
     *
     * @throws InvalidArgumentException when a value is not a scalar or null, flat rows name several groups, or nested
     *                                  rows name a group $group does not
     */
    public static function record(
        string $kind,
        string $group,
        array $before,
        array $after,
        ?int $userId = null,
        DateTimeInterface|string|null $expiresAt = null,
        array $meta = [],
    ): ?int {
        if (! self::available()) {
            return null;
        }

        if (self::isNested($before) || self::isNested($after)) {
            $before = self::normaliseGroups($before);
            $after = self::normaliseGroups($after);
            $scope = 'keys';

            if (array_diff(array_keys($before + $after), explode(',', $group)) !== []) {
                throw new InvalidArgumentException("Nested settings revision rows are keyed by the groups named in [{$group}].");
            }
        } elseif (str_contains($group, ',')) {
            throw new InvalidArgumentException('Rows of several settings groups must be nested by group.');
        } else {
            $before = self::normalise($before);
            $after = self::normalise($after);
            $scope = $after === self::rows($group) ? 'group' : 'keys';

            if ($scope === 'keys') {
                [$before, $after] = [[$group => $before], [$group => $after]];
            }
        }

        $masked = in_array(self::MASK, self::values($before), true) || in_array(self::MASK, self::values($after), true);

        $revision = SettingRevision::create([
            'kind' => $kind,
            'group' => $group,
            'before' => $before,
            'after' => $after,
            'changed' => $scope === 'group' ? self::changedKeys($before, $after) : self::changedGroupKeys($before, $after),
            'meta' => array_replace($meta, ['scope' => $scope], $masked ? ['masked' => true] : []),
            'user_id' => $userId,
            'expires_at' => $expiresAt,
        ]);

        self::prune($group);

        return (int) $revision->getKey();
    }

    /**
     * Undo an appearance revision and record the restore: scope 'group' makes the group's stored rows exactly what
     * they were before it (missing keys inserted again, keys added since deleted); scope 'keys' gives those keys their
     * old values back (null: deleted) and leaves every other key alone. Null when the revision is missing, expired,
     * not restorable (another group, a masked password or key) or the table is missing.
     *
     * @return array{changed: array<int, string>, revision: ?int}|null
     */
    public static function restore(int $id, ?int $userId = null): ?array
    {
        if (! self::available()) {
            return null;
        }

        $revision = SettingRevision::query()->find($id);

        if ($revision === null || ! self::restorable($revision) || ! is_array($revision->before)
            || in_array(self::MASK, self::values($revision->before), true)) {
            return null;
        }

        $result = self::scope($revision) === 'group'
            ? self::restoreGroup((string) $revision->group, self::normalise($revision->before), $id, $userId)
            : self::restoreKeys((string) $revision->group, self::normaliseGroups($revision->before), $id, $userId);

        self::flushSettingsCache();

        return $result;
    }

    /**
     * Newest revisions first (expired ones left out), without their row payloads. A group also matches the saves
     * that covered it together with other groups.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function latest(int $n = 20, ?string $group = null): array
    {
        if (! self::available()) {
            return [];
        }

        return SettingRevision::query()
            ->when($group !== null, fn ($query) => $query->where(fn ($query) => $query->where('group', $group)->orWhere('group', 'like', '%' . $group . '%')))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')
            ->get(['id', 'kind', 'group', 'changed', 'meta', 'user_id', 'expires_at', 'created_at'])
            ->filter(fn (SettingRevision $revision): bool => $group === null || in_array($group, explode(',', (string) $revision->group), true))
            ->take(max(1, $n))
            ->map(fn (SettingRevision $revision): array => self::summary($revision))
            ->values()
            ->all();
    }

    /**
     * One revision with its before/after rows (nested by group for scope 'keys'), or null.
     */
    public static function find(int $id): ?array
    {
        if (! self::available()) {
            return null;
        }

        $revision = SettingRevision::query()->find($id);

        if ($revision === null) {
            return null;
        }

        $group = self::scope($revision) === 'group';

        return self::summary($revision) + [
            'before' => $group ? self::normalise((array) $revision->before) : self::normaliseGroups((array) $revision->before),
            'after' => $group ? self::normalise((array) $revision->after) : self::normaliseGroups((array) $revision->after),
        ];
    }

    /**
     * The live upgrade snapshot (#0) behind "Restore my previous look", or null once it expired.
     */
    public static function upgradeSnapshot(): ?array
    {
        if (! self::available()) {
            return null;
        }

        $revision = SettingRevision::query()
            ->where('kind', 'upgrade_snapshot')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')
            ->first(['id', 'kind', 'group', 'changed', 'meta', 'user_id', 'expires_at', 'created_at']);

        return $revision === null ? null : self::summary($revision);
    }

    /**
     * The group's stored rows: key => value (value as stored, null kept).
     *
     * @return array<string, ?string>
     */
    public static function rows(string $group): array
    {
        return self::normalise(DB::table('settings')->where('group', $group)->pluck('value', 'key')->all());
    }

    /**
     * @return array<int, string>
     */
    public static function changedKeys(array $before, array $after): array
    {
        $keys = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (! array_key_exists($key, $before) || ! array_key_exists($key, $after) || $before[$key] !== $after[$key]) {
                $keys[] = (string) $key;
            }
        }

        sort($keys);

        return $keys;
    }

    public static function flushSettingsCache(): void
    {
        try {
            Setting::clearCache();
            AdminCache::bumpVersion();
        } catch (Throwable) {
        }
    }

    /**
     * @param  array<string, ?string>  $target
     * @return array{changed: array<int, string>, revision: ?int}
     */
    private static function restoreGroup(string $group, array $target, int $id, ?int $userId): array
    {
        return DB::transaction(function () use ($group, $target, $id, $userId): array {
            $current = self::rows($group);

            foreach ($target as $key => $value) {
                if (! array_key_exists($key, $current) || $current[$key] !== $value) {
                    self::put($group, $key, $value);
                }
            }

            $extra = array_diff_key($current, $target);

            if ($extra !== []) {
                DB::table('settings')->where('group', $group)->whereIn('key', array_keys($extra))->delete();
            }

            $after = self::rows($group);
            $changed = self::changedKeys($current, $after);

            return [
                'changed' => $changed,
                'revision' => $changed === [] ? null : self::record('restore', $group, $current, $after, $userId, null, ['restored_revision' => $id]),
            ];
        });
    }

    /**
     * @param  array<string, array<string, ?string>>  $target
     * @return array{changed: array<int, string>, revision: ?int}
     */
    private static function restoreKeys(string $groups, array $target, int $id, ?int $userId): array
    {
        return DB::transaction(function () use ($groups, $target, $id, $userId): array {
            $before = [];
            $after = [];

            foreach ($target as $group => $rows) {
                $current = self::rows($group);

                foreach ($rows as $key => $value) {
                    $before[$group][$key] = $current[$key] ?? null;

                    if ($value === null) {
                        DB::table('settings')->where('group', $group)->where('key', $key)->delete();
                    } elseif ($before[$group][$key] !== $value) {
                        self::put($group, $key, $value);
                    }
                }

                $now = self::rows($group);

                foreach (array_keys($rows) as $key) {
                    $after[$group][$key] = $now[$key] ?? null;
                }
            }

            $changed = self::changedGroupKeys($before, $after);

            return [
                'changed' => $changed,
                'revision' => $changed === [] ? null : self::record('restore', $groups, $before, $after, $userId, null, ['restored_revision' => $id]),
            ];
        });
    }

    private static function put(string $group, string $key, ?string $value): void
    {
        $now = now();

        if (DB::table('settings')->where('key', $key)->exists()) {
            DB::table('settings')->where('key', $key)->update(['value' => $value, 'group' => $group, 'updated_at' => $now]);
        } else {
            DB::table('settings')->insert(['key' => $key, 'value' => $value, 'group' => $group, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * Expired revisions go; each group keeps its newest KEEP (the upgrade snapshot lives until it expires).
     */
    private static function prune(string $group): void
    {
        SettingRevision::query()->whereNotNull('expires_at')->where('expires_at', '<=', now())->delete();

        $oldestDropped = SettingRevision::query()
            ->where('group', $group)
            ->where('kind', '!=', 'upgrade_snapshot')
            ->orderByDesc('id')
            ->skip(self::KEEP)
            ->value('id');

        if ($oldestDropped !== null) {
            SettingRevision::query()
                ->where('group', $group)
                ->where('kind', '!=', 'upgrade_snapshot')
                ->where('id', '<=', $oldestDropped)
                ->delete();
        }
    }

    private static function restorable(SettingRevision $revision): bool
    {
        return ! $revision->isExpired()
            && array_diff(explode(',', (string) $revision->group), [self::RESTORABLE_GROUP]) === []
            && empty(((array) ($revision->meta ?? []))['masked']);
    }

    /** Revisions recorded without a scope hold complete group rows. */
    private static function scope(SettingRevision $revision): string
    {
        return ((array) ($revision->meta ?? []))['scope'] ?? 'group';
    }

    /**
     * Changed keys of nested rows: plain keys for one group, group.key for several.
     *
     * @return array<int, string>
     */
    private static function changedGroupKeys(array $before, array $after): array
    {
        $groups = array_unique(array_merge(array_keys($before), array_keys($after)));
        $keys = [];

        foreach ($groups as $group) {
            foreach (self::changedKeys($before[$group] ?? [], $after[$group] ?? []) as $key) {
                $keys[] = count($groups) > 1 ? $group . '.' . $key : $key;
            }
        }

        sort($keys);

        return $keys;
    }

    private static function isNested(array $rows): bool
    {
        foreach ($rows as $value) {
            if (is_array($value)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, ?string> every value of flat or nested rows */
    private static function values(array $rows): array
    {
        $values = [];

        foreach ($rows as $value) {
            array_push($values, ...(is_array($value) ? array_values($value) : [$value]));
        }

        return $values;
    }

    /** @return array<string, ?string> */
    private static function normalise(array $rows): array
    {
        $normalised = [];

        foreach ($rows as $key => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException("Settings revision rows hold scalar values; [{$key}] does not.");
            }

            $normalised[(string) $key] = $value === null ? null : (string) $value;
        }

        ksort($normalised, SORT_STRING);

        return $normalised;
    }

    /** @return array<string, array<string, ?string>> */
    private static function normaliseGroups(array $groups): array
    {
        $normalised = [];

        foreach ($groups as $group => $rows) {
            if (! is_array($rows)) {
                throw new InvalidArgumentException("Nested settings revision rows are [group => [key => value]]; [{$group}] is not.");
            }

            $normalised[(string) $group] = self::normalise($rows);
        }

        ksort($normalised, SORT_STRING);

        return $normalised;
    }

    private static function summary(SettingRevision $revision): array
    {
        return [
            'id' => (int) $revision->getKey(),
            'kind' => (string) $revision->kind,
            'group' => (string) $revision->group,
            'scope' => self::scope($revision),
            'changed' => (array) ($revision->changed ?? []),
            'meta' => (array) ($revision->meta ?? []),
            'restorable' => self::restorable($revision),
            'user_id' => $revision->user_id,
            'expires_at' => $revision->expires_at?->toIso8601String(),
            'created_at' => $revision->created_at?->toIso8601String(),
        ];
    }
}
