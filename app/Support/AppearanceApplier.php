<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one writer of look keys: themes, Complete looks, the factory reset, publishes and restores. Every call writes
 * the appearance group in one transaction, records a SettingsRevision (when the table exists), clears the settings
 * caches and returns ['changed' => keys, 'revision' => id|null].
 *
 * Colour settings equal to the active colour style's are deleted rather than stored: the style supplies them, and a
 * stored colour always means a custom colour. When the style changes, stored colours equal to the previous style's go
 * too, so the new style shows.
 */
final class AppearanceApplier
{
    private const GROUP = 'appearance';

    /** Old look ids that still work. */
    private const MASTER_ALIASES = [
        'default_factory' => 'youtube_spotlight_pro',
        'youtube_2026_pro' => 'youtube_spotlight_pro',
        'cyberpunk_hud_2077' => 'cyberpunk_hud',
    ];

    /**
     * Apply a theme (v2 id or old id) in one of its colour styles. Custom colours are replaced by the style; unless
     * $keepLayout the theme's recommended look (layouts, header, cards, fonts, mode) is applied too.
     *
     * @return array{changed: array<int, string>, revision: ?int}
     */
    public static function applyTheme(string $themeId, ?string $flavorId = null, bool $keepLayout = false, ?int $userId = null): array
    {
        $resolved = ThemeCatalog::resolve($themeId, $flavorId);

        if ($resolved === null) {
            throw new InvalidArgumentException("Unknown theme [{$themeId}].");
        }

        $values = $keepLayout ? [] : array_intersect_key(
            AppearanceSettings::themeValues($resolved['theme'], $resolved['flavor']),
            array_flip(AppearanceSettings::lookKeys()),
        );

        $values = array_replace($values, [
            'theme_preset' => $resolved['theme'],
            'theme_flavor' => $resolved['flavor'],
            'active_master_experience' => '',
            'daily_design_auto_enabled' => '0',
        ]);

        return self::write(
            array_diff_key($values, array_flip(ThemeCatalog::COLOUR_KEYS)),
            ThemeCatalog::COLOUR_KEYS,
            'apply_theme',
            $userId,
            ['theme' => $resolved['theme'], 'flavor' => $resolved['flavor'], 'keep_layout' => $keepLayout, 'requested' => $themeId],
        );
    }

    /**
     * Apply a Complete look (12 v2 looks + default_factory / youtube_2026_pro / cyberpunk_hud_2077).
     *
     * @return array{changed: array<int, string>, revision: ?int}
     */
    public static function applyMaster(string $id, ?int $userId = null): array
    {
        $canonical = self::MASTER_ALIASES[$id] ?? $id;
        $master = MasterExperienceCatalog::find($canonical);

        if (! is_array($master)) {
            throw new InvalidArgumentException("Unknown look [{$id}].");
        }

        if (is_array($master['apply_settings'] ?? null)) {
            $values = array_map('strval', array_filter($master['apply_settings'], 'is_scalar'));
        } else {
            // v1 look: its theme preset's complete contract, then the look's layout keys.
            $values = AppearanceSettings::presetValues((string) ($master['theme_preset'] ?? ''));

            foreach (AppearanceSettings::lookKeys() as $key) {
                if (array_key_exists($key, $master) && is_scalar($master[$key]) && $key !== 'theme_preset') {
                    $values[$key] = (string) $master[$key];
                }
            }
        }

        $values['active_master_experience'] = (string) ($master['id'] ?? $canonical);
        $values['daily_design_auto_enabled'] = '0';
        $values['suggested_master_upgrade'] = '';

        $replaced = array_values(array_diff(ThemeCatalog::COLOUR_KEYS, array_keys($values)));

        return self::write(
            array_intersect_key($values, array_flip(AppearanceSettings::lookKeys())),
            $replaced,
            'apply_master',
            $userId,
            ['master' => $values['active_master_experience'], 'requested' => $id],
        );
    }

    /**
     * Back to the factory look (YouTube Spotlight Pro on Midnight Spotlight). Only look keys: logo, texts, menus,
     * ads, custom code and the effect switches stay as they are. Settings the site never stored take the v2 defaults
     * from now on, also after "Restore my previous look" brought back the pre-upgrade rows.
     *
     * @return array{changed: array<int, string>, revision: ?int}
     */
    public static function resetToFactory(?int $userId = null): array
    {
        $values = array_intersect_key(AppearanceSettings::defaults(), array_flip(AppearanceSettings::lookKeys()));
        $values['active_master_experience'] = 'youtube_spotlight_pro';
        $values['daily_design_auto_enabled'] = '0';
        $values['suggested_master_upgrade'] = '';
        $values['appearance_schema_version'] = AppearanceSettings::SCHEMA_VERSION;

        return self::write(
            array_diff_key($values, array_flip(ThemeCatalog::COLOUR_KEYS)),
            ThemeCatalog::COLOUR_KEYS,
            'reset',
            $userId,
        );
    }

    /**
     * Write appearance settings (e.g. a published draft). Unknown keys are ignored; null or $delete removes a stored
     * key (a colour then comes from the colour style again, any other key from its default).
     *
     * @return array{changed: array<int, string>, revision: ?int}
     */
    public static function applyValues(array $values, string $kind = 'publish', ?int $userId = null, array $delete = [], array $meta = []): array
    {
        $known = AppearanceSettings::defaults();
        $clean = [];

        foreach ($values as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $known)) {
                continue;
            }

            if ($value === null) {
                $delete[] = $key;
            } elseif (is_scalar($value)) {
                $clean[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return self::write($clean, array_values(array_intersect($delete, array_keys($known))), $kind, $userId, $meta);
    }

    /**
     * Undo a revision of the appearance settings (SettingsRevision::restore() says which ones can be restored).
     *
     * @return array{changed: array<int, string>, revision: ?int}|null
     */
    public static function restoreRevision(int $revisionId, ?int $userId = null): ?array
    {
        return SettingsRevision::restore($revisionId, $userId ?? self::userId());
    }

    /**
     * Run the theme catalogue v2 upgrade of the stored appearance now (the data migration's own code), e.g. after
     * "Restore my previous look". Null when the settings are already upgraded or the revisions table is missing.
     *
     * @return array{class: string, theme: string, flavor: string, snapshot: ?int, revision: ?int}|null
     */
    public static function upgrade(?int $userId = null): ?array
    {
        $path = glob(database_path('migrations/*_migrate_appearance_to_theme_catalog_v2.php'))[0] ?? null;

        if ($path === null) {
            return null;
        }

        return (require $path)->run($userId ?? self::userId());
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<int, string>  $delete
     * @return array{changed: array<int, string>, revision: ?int}
     */
    private static function write(array $values, array $delete, string $kind, ?int $userId, array $meta = []): array
    {
        $userId ??= self::userId();

        $result = DB::transaction(function () use ($values, $delete, $kind, $userId, $meta): array {
            $before = SettingsRevision::rows(self::GROUP);
            $target = array_diff_key(array_replace($before, $values), array_flip($delete));
            $previous = AppearanceSettings::resolveThemeFlavor($before);
            $active = AppearanceSettings::resolveThemeFlavor($target);
            $colours = ThemeCatalog::flavorColours($active['theme'], $active['flavor']);
            $styleChanged = [$previous['theme'], $previous['flavor']] !== [$active['theme'], $active['flavor']];

            foreach ($values as $key => $value) {
                // A colour equal to the colour style's is not a custom colour: let the style supply it.
                if (isset($colours[$key]) && strtolower((string) $value) === $colours[$key]) {
                    unset($values[$key]);
                    $delete[] = $key;
                }
            }

            if ($styleChanged) {
                // A stored colour equal to the previous style's (e.g. saved by the old settings page) was never a
                // custom colour either: the new style supplies it.
                foreach (ThemeCatalog::flavorColours($previous['theme'], $previous['flavor']) as $key => $colour) {
                    if (! array_key_exists($key, $values) && strtolower((string) ($target[$key] ?? '')) === $colour) {
                        $delete[] = $key;
                    }
                }
            }

            $now = now();
            $foreign = DB::table('settings')
                ->whereIn('key', array_keys($values))
                ->where('group', '!=', self::GROUP)
                ->pluck('key')
                ->all();

            foreach ($values as $key => $value) {
                if (in_array($key, $foreign, true)) {
                    continue; // never move a key out of another settings group
                }

                if (! array_key_exists($key, $before)) {
                    DB::table('settings')->insert(['key' => $key, 'value' => $value, 'group' => self::GROUP, 'created_at' => $now, 'updated_at' => $now]);
                } elseif ($before[$key] !== $value) {
                    DB::table('settings')->where('key', $key)->where('group', self::GROUP)->update(['value' => $value, 'updated_at' => $now]);
                }
            }

            $delete = array_values(array_unique(array_intersect($delete, array_keys($before))));

            if ($delete !== []) {
                DB::table('settings')->where('group', self::GROUP)->whereIn('key', $delete)->delete();
            }

            $after = SettingsRevision::rows(self::GROUP);
            $changed = SettingsRevision::changedKeys($before, $after);

            return [
                'changed' => $changed,
                'revision' => $changed === [] ? null : SettingsRevision::record($kind, self::GROUP, $before, $after, $userId, null, $meta),
            ];
        });

        SettingsRevision::flushSettingsCache();

        return $result;
    }

    private static function userId(): ?int
    {
        $id = auth()->id();

        return $id === null ? null : (int) $id;
    }
}
