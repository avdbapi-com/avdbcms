<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChannelCustomizationSettings
{
    /** The accent every install before 1.2.3 stored as its default (database/install/example.sql). */
    public const SEED_ACCENT = 'coral';

    /** The cache key of accentIsInstallerSeed() (under the admin cache's version: every settings save renews it). */
    public const SEED_CACHE_KEY = 'channel_default_accent.installer_seed';

    /**
     * The mark of an accent a member picked (QA channel-11 on channels saved before 1.2.3): 1.2.0 to 1.2.2 stored every
     * field of the channel form with each save, the default accent "coral" too, so a channel's stored "coral" without
     * this mark is that old default, not a pick: the channel follows the site's default accent (the look's colour unless
     * the owner chose another). The Channel section of Account settings writes the mark with the accent a member saves
     * there (normalizeForStorage()), so a member who picks Coral since 1.2.3 keeps it. Nothing stored is rewritten.
     */
    public const ACCENT_CHOSEN = 'accent_chosen';

    /**
     * The settings revisions an admin's own save writes (App\Support\SettingsRevision): a settings page
     * (settings_save), an Appearance page such as Appearance > Channels (publish) and an Undo of either (restore).
     * The automatic kinds (upgrade snapshots, look applies) never count as a choice.
     */
    public const SEED_CHOICE_KINDS = ['settings_save', 'publish', 'restore'];

    public static function defaults(): array
    {
        return [
            'channel_customization_enabled' => '1',
            'channel_customization_layout_enabled' => '1',
            'channel_customization_theme_enabled' => '1',
            'channel_customization_accent_enabled' => '1',
            'channel_customization_custom_accent_enabled' => '1',
            'channel_customization_surface_enabled' => '1',
            'channel_customization_banner_enabled' => '1',
            'channel_customization_avatar_enabled' => '1',
            'channel_customization_motion_enabled' => '1',
            'channel_customization_effects_enabled' => '1',
            'channel_customization_pattern_enabled' => '1',
            'channel_customization_shape_enabled' => '1',
            'channel_customization_video_layout_enabled' => '1',
            'channel_customization_featured_video_enabled' => '1',
            'channel_customization_featured_layout_enabled' => '1',
            'channel_customization_featured_sizing_enabled' => '1',
            'channel_customization_latest_uploads_enabled' => '1',
            'channel_customization_latest_layout_enabled' => '1',
            'channel_customization_latest_sizing_enabled' => '1',
            'channel_customization_playlists_enabled' => '1',
            'channel_customization_playlists_layout_enabled' => '1',
            'channel_customization_playlists_sizing_enabled' => '1',
            'channel_customization_sections_enabled' => '1',
            'channel_customization_cta_enabled' => '1',
            'channel_customization_stats_enabled' => '1',
            'channel_customization_socials_enabled' => '1',
            'channel_customization_upload_rhythm_enabled' => '1',
            'channel_default_layout' => 'spotlight',
            'channel_default_theme' => 'midnight',
            // 1.2.3 (member design 6.19, QA channel-11): a channel that has not chosen an accent follows the look's own
            // colour. A site that stored another default keeps it (an owner's stored setting is never changed).
            'channel_default_accent' => 'look',
            'channel_default_custom_accent' => '#18c8d8',
            'channel_default_surface' => 'glass',
            'channel_default_banner_treatment' => 'cinematic',
            'channel_default_avatar_frame' => 'ring',
            'channel_default_motion' => 'gentle',
            'channel_default_pattern' => 'signal',
            'channel_default_header_shape' => 'soft-arc',
            'channel_default_stats_style' => 'pills',
            'channel_default_video_layout' => 'grid',
            'channel_default_featured_mode' => 'pinned',
            'channel_default_featured_layout' => 'grid',
            'channel_default_featured_columns' => '3',
            'channel_default_featured_rows' => '1',
            'channel_default_latest_uploads_layout' => 'grid',
            'channel_default_latest_uploads_columns' => '3',
            'channel_default_latest_uploads_rows' => '1',
            'channel_default_playlists_layout' => 'stacked',
            'channel_default_playlists_columns' => '1',
            'channel_default_playlists_rows' => '3',
            'channel_default_playlist_video_columns' => '3',
            'channel_default_playlist_video_rows' => '1',
            'channel_default_density' => 'comfortable',
            'channel_tagline_max_length' => '120',
            'channel_cta_label_max_length' => '42',
        ];
    }

    public static function userDefaults(): array
    {
        return [
            'layout' => config('site.channel_default_layout', 'spotlight'),
            'theme' => config('site.channel_default_theme', 'midnight'),
            'accent' => config('site.channel_default_accent', 'look'),
            'custom_accent' => config('site.channel_default_custom_accent', '#18c8d8'),
            'surface' => config('site.channel_default_surface', 'glass'),
            'banner_treatment' => config('site.channel_default_banner_treatment', 'cinematic'),
            'avatar_frame' => config('site.channel_default_avatar_frame', 'ring'),
            'motion' => config('site.channel_default_motion', 'gentle'),
            'pattern' => config('site.channel_default_pattern', 'signal'),
            'header_shape' => config('site.channel_default_header_shape', 'soft-arc'),
            'stats_style' => config('site.channel_default_stats_style', 'pills'),
            'video_layout' => config('site.channel_default_video_layout', 'grid'),
            'featured_mode' => config('site.channel_default_featured_mode', 'pinned'),
            'featured_layout' => config('site.channel_default_featured_layout', 'grid'),
            'featured_columns' => config('site.channel_default_featured_columns', '3'),
            'featured_rows' => config('site.channel_default_featured_rows', '1'),
            'latest_uploads_layout' => config('site.channel_default_latest_uploads_layout', 'grid'),
            'latest_uploads_columns' => config('site.channel_default_latest_uploads_columns', '3'),
            'latest_uploads_rows' => config('site.channel_default_latest_uploads_rows', '1'),
            'playlists_layout' => config('site.channel_default_playlists_layout', 'stacked'),
            'playlists_columns' => config('site.channel_default_playlists_columns', '1'),
            'playlists_rows' => config('site.channel_default_playlists_rows', '3'),
            'playlist_video_columns' => config('site.channel_default_playlist_video_columns', '3'),
            'playlist_video_rows' => config('site.channel_default_playlist_video_rows', '1'),
            'density' => config('site.channel_default_density', 'comfortable'),
            'tagline' => '',
            'cta_label' => '',
            'cta_url' => '',
            'show_stats' => '1',
            'show_featured_video' => '1',
            'show_latest_uploads' => '1',
            'show_playlists' => '1',
            'show_about_panel' => '1',
            'show_social_links' => '1',
            'show_upload_rhythm' => '1',
            'show_subscribe_cta' => '1',
            'dedupe_home_sections' => '1',
        ];
    }

    public static function booleanKeys(): array
    {
        return [
            'channel_customization_enabled',
            'channel_customization_layout_enabled',
            'channel_customization_theme_enabled',
            'channel_customization_accent_enabled',
            'channel_customization_custom_accent_enabled',
            'channel_customization_surface_enabled',
            'channel_customization_banner_enabled',
            'channel_customization_avatar_enabled',
            'channel_customization_motion_enabled',
            'channel_customization_effects_enabled',
            'channel_customization_pattern_enabled',
            'channel_customization_shape_enabled',
            'channel_customization_video_layout_enabled',
            'channel_customization_featured_video_enabled',
            'channel_customization_featured_layout_enabled',
            'channel_customization_featured_sizing_enabled',
            'channel_customization_latest_uploads_enabled',
            'channel_customization_latest_layout_enabled',
            'channel_customization_latest_sizing_enabled',
            'channel_customization_playlists_enabled',
            'channel_customization_playlists_layout_enabled',
            'channel_customization_playlists_sizing_enabled',
            'channel_customization_sections_enabled',
            'channel_customization_cta_enabled',
            'channel_customization_stats_enabled',
            'channel_customization_socials_enabled',
            'channel_customization_upload_rhythm_enabled',
        ];
    }

    public static function textKeys(): array
    {
        return array_values(array_diff(array_keys(self::defaults()), self::booleanKeys()));
    }

    public static function options(): array
    {
        return [
            'layout' => [
                'spotlight' => 'Spotlight',
                'studio' => 'Studio',
                'poster' => 'Poster',
                'split' => 'Split',
                'compact' => 'Compact',
            ],
            'theme' => [
                // 1.2.3 (OD-M14): the band takes no colour of its own and follows the look's surfaces. The site's default
                // (channel_default_theme, midnight) is unchanged.
                'look' => 'Follow the look',
                'midnight' => 'Midnight',
                'sunrise' => 'Sunrise',
                'ocean' => 'Ocean',
                'forest' => 'Forest',
                'candy' => 'Candy',
                'graphite' => 'Graphite',
            ],
            'accent' => [
                // 1.2.3 (QA channel-11): the look's own colour in light and dark (App\Support\ChannelAccent::LOOK).
                'look' => 'Look colour',
                'coral' => 'Coral',
                'cyan' => 'Cyan',
                'violet' => 'Violet',
                'amber' => 'Amber',
                'emerald' => 'Emerald',
                'rose' => 'Rose',
                // British spelling, as the rest of the admin writes it (R3-078: it said Custom color, the American way, next to Look colour).
                'custom' => 'Custom colour',
            ],
            'surface' => [
                'glass' => 'Glass',
                'solid' => 'Solid',
                'outlined' => 'Outlined',
                'soft' => 'Soft',
            ],
            'banner_treatment' => [
                'cinematic' => 'Cinematic',
                'poster' => 'Poster crop',
                'split' => 'Split focus',
                'mosaic' => 'Mosaic',
                'minimal' => 'Minimal',
            ],
            'avatar_frame' => [
                'ring' => 'Accent ring',
                'glow' => 'Soft glow',
                'square' => 'Rounded square',
                'badge' => 'Creator badge',
                'none' => 'No frame',
            ],
            'motion' => [
                'none' => 'None',
                'gentle' => 'Gentle',
                'float' => 'Float',
                'pulse' => 'Pulse',
                'sweep' => 'Light sweep',
            ],
            'pattern' => [
                'clean' => 'Clean',
                'signal' => 'Signal',
                'grid' => 'Grid',
                'diagonal' => 'Diagonal',
                'waves' => 'Waves',
            ],
            'header_shape' => [
                'flat' => 'Flat',
                'soft-arc' => 'Soft arc',
                'angled' => 'Angled',
                'framed' => 'Framed',
            ],
            'stats_style' => [
                'pills' => 'Pills',
                'bar' => 'Bar',
                'minimal' => 'Minimal',
            ],
            'video_layout' => [
                'grid' => 'Grid',
                'compact' => 'Compact grid',
                'list' => 'List',
                'showcase' => 'Showcase',
            ],
            'featured_mode' => [
                'pinned' => 'Pinned video',
                'latest' => 'Latest video',
                'popular' => 'Most viewed video',
            ],
            'featured_layout' => [
                'grid' => 'Video cards',
                'hero' => 'Lead story',
                'rail' => 'Horizontal rail',
                'compact' => 'Compact cards',
            ],
            'latest_uploads_layout' => [
                'grid' => 'Video cards',
                'showcase' => 'Lead card',
                'rail' => 'Horizontal rail',
                'compact' => 'Compact cards',
            ],
            'playlists_layout' => [
                'stacked' => 'Stacked rows',
                'grid' => 'Playlist grid',
                'rail' => 'Horizontal rail',
                'compact' => 'Compact panels',
            ],
            'density' => [
                'compact' => 'Compact',
                'comfortable' => 'Comfortable',
                'spacious' => 'Spacious',
            ],
        ];
    }

    public static function merge(?array $customization): array
    {
        $customization = is_array($customization) ? $customization : [];

        // the old default accent of a channel saved before 1.2.3 is no pick: the site's default applies (ACCENT_CHOSEN)
        if (self::isOldDefaultAccent($customization)) {
            unset($customization['accent']);
        }

        $merged = array_merge(self::userDefaults(), Arr::only($customization, array_keys(self::userDefaults())));

        foreach (self::options() as $key => $options) {
            $default = self::userDefaults()[$key] ?? array_key_first($options);
            if (! array_key_exists((string) ($merged[$key] ?? ''), $options)) {
                $merged[$key] = $default;
            }
        }

        foreach (['show_stats', 'show_featured_video', 'show_latest_uploads', 'show_playlists', 'show_about_panel', 'show_social_links', 'show_upload_rhythm', 'show_subscribe_cta', 'dedupe_home_sections'] as $key) {
            $merged[$key] = in_array((string) ($merged[$key] ?? '0'), ['1', 'true', 'on'], true) ? '1' : '0';
        }

        $merged['custom_accent'] = self::sanitizeColor((string) ($merged['custom_accent'] ?? '#18c8d8'));
        $merged['tagline'] = self::trimToLimit((string) ($merged['tagline'] ?? ''), (int) config('site.channel_tagline_max_length', 120));
        $merged['cta_label'] = self::trimToLimit((string) ($merged['cta_label'] ?? ''), (int) config('site.channel_cta_label_max_length', 42));
        $merged['cta_url'] = trim((string) ($merged['cta_url'] ?? ''));
        $merged['featured_columns'] = (string) self::clampInt($merged['featured_columns'] ?? 3, 1, 6);
        $merged['featured_rows'] = (string) self::clampInt($merged['featured_rows'] ?? 1, 1, 4);
        $merged['latest_uploads_columns'] = (string) self::clampInt($merged['latest_uploads_columns'] ?? 3, 1, 6);
        $merged['latest_uploads_rows'] = (string) self::clampInt($merged['latest_uploads_rows'] ?? 1, 1, 6);
        $merged['playlists_columns'] = (string) self::clampInt($merged['playlists_columns'] ?? 1, 1, 4);
        $merged['playlists_rows'] = (string) self::clampInt($merged['playlists_rows'] ?? 3, 1, 6);
        $merged['playlist_video_columns'] = (string) self::clampInt($merged['playlist_video_columns'] ?? 3, 1, 6);
        $merged['playlist_video_rows'] = (string) self::clampInt($merged['playlist_video_rows'] ?? 1, 1, 4);

        return $merged;
    }

    public static function normalizeForStorage(array $input, ?array $current = null): array
    {
        // a save of the form never takes the mark from what the request sends: it picks an accent by changing it
        // (chosenDefaults()); without $current, $input is what is stored (User::channelCustomization()) and keeps it
        if ($current !== null) {
            unset($input[self::ACCENT_CHOSEN]);
        }
        $combined = array_merge($current ?? [], $input);
        $accentOn = (bool) config('site.channel_customization_accent_enabled', true);

        // a save of the member's channel form ($current: what is stored): the defaults the member chose (R2A-017)
        $chosen = $current !== null ? self::chosenDefaults($input, $current) : null;

        if ($chosen !== null && isset($chosen['accent']) && $accentOn) {
            $combined[self::ACCENT_CHOSEN] = '1';
        }

        $merged = self::merge($combined);

        if (! config('site.channel_customization_layout_enabled', true)) {
            $merged['layout'] = self::userDefaults()['layout'];
        }
        if (! config('site.channel_customization_theme_enabled', true)) {
            $merged['theme'] = self::userDefaults()['theme'];
        }
        if (! config('site.channel_customization_accent_enabled', true)) {
            $merged['accent'] = self::userDefaults()['accent'];
        }
        if (! config('site.channel_customization_custom_accent_enabled', true) || $merged['accent'] !== 'custom') {
            $merged['custom_accent'] = self::userDefaults()['custom_accent'];
        }
        if (! config('site.channel_customization_surface_enabled', true)) {
            $merged['surface'] = self::userDefaults()['surface'];
        }
        if (! config('site.channel_customization_banner_enabled', true)) {
            $merged['banner_treatment'] = self::userDefaults()['banner_treatment'];
        }
        if (! config('site.channel_customization_avatar_enabled', true)) {
            $merged['avatar_frame'] = self::userDefaults()['avatar_frame'];
        }
        if (! config('site.channel_customization_motion_enabled', true)) {
            $merged['motion'] = 'none';
        }
        if (! config('site.channel_customization_pattern_enabled', true)) {
            $merged['pattern'] = self::userDefaults()['pattern'];
        }
        if (! config('site.channel_customization_shape_enabled', true)) {
            $merged['header_shape'] = self::userDefaults()['header_shape'];
        }
        if (! config('site.channel_customization_video_layout_enabled', true)) {
            $merged['video_layout'] = self::userDefaults()['video_layout'];
        }
        if (! config('site.channel_customization_featured_video_enabled', true)) {
            $merged['show_featured_video'] = '0';
        }
        if (! config('site.channel_customization_featured_layout_enabled', true)) {
            $merged['featured_layout'] = self::userDefaults()['featured_layout'];
        }
        if (! config('site.channel_customization_featured_sizing_enabled', true)) {
            $merged['featured_columns'] = self::userDefaults()['featured_columns'];
            $merged['featured_rows'] = self::userDefaults()['featured_rows'];
        }
        if (! config('site.channel_customization_latest_uploads_enabled', true)) {
            $merged['show_latest_uploads'] = '0';
        }
        if (! config('site.channel_customization_latest_layout_enabled', true)) {
            $merged['latest_uploads_layout'] = self::userDefaults()['latest_uploads_layout'];
        }
        if (! config('site.channel_customization_latest_sizing_enabled', true)) {
            $merged['latest_uploads_columns'] = self::userDefaults()['latest_uploads_columns'];
            $merged['latest_uploads_rows'] = self::userDefaults()['latest_uploads_rows'];
        }
        if (! config('site.channel_customization_playlists_enabled', true)) {
            $merged['show_playlists'] = '0';
        }
        if (! config('site.channel_customization_playlists_layout_enabled', true)) {
            $merged['playlists_layout'] = self::userDefaults()['playlists_layout'];
        }
        if (! config('site.channel_customization_playlists_sizing_enabled', true)) {
            $merged['playlists_columns'] = self::userDefaults()['playlists_columns'];
            $merged['playlists_rows'] = self::userDefaults()['playlists_rows'];
            $merged['playlist_video_columns'] = self::userDefaults()['playlist_video_columns'];
            $merged['playlist_video_rows'] = self::userDefaults()['playlist_video_rows'];
        }
        if (! config('site.channel_customization_sections_enabled', true)) {
            $merged['show_about_panel'] = '0';
        }
        if (! config('site.channel_customization_cta_enabled', true)) {
            $merged['cta_label'] = '';
            $merged['cta_url'] = '';
            $merged['show_subscribe_cta'] = '0';
        }
        if (! config('site.channel_customization_stats_enabled', true)) {
            $merged['show_stats'] = '0';
        }
        if (! config('site.channel_customization_socials_enabled', true)) {
            $merged['show_social_links'] = '0';
        }
        if (! config('site.channel_customization_upload_rhythm_enabled', true)) {
            $merged['show_upload_rhythm'] = '0';
        }

        // the mark stays with an accent the member picked (also "look" or a preset: the next default changes none of them)
        if ($accentOn && (string) ($combined[self::ACCENT_CHOSEN] ?? '') === '1') {
            $merged[self::ACCENT_CHOSEN] = '1';
        }

        // what the member did not choose is not stored: it keeps following the site's defaults (Appearance > Channels,
        // "Used on channels that have not chosen their own"), also after the admin changes them
        if ($chosen !== null) {
            foreach (self::DEFAULTED as $key) {
                if (! isset($chosen[$key])) {
                    unset($merged[$key]);
                }
            }

            if (! isset($chosen['accent'])) {
                unset($merged[self::ACCENT_CHOSEN]);
            }
        }

        return $merged;
    }

    /**
     * The fields of a channel that follow one of the site's defaults (channel_default_*, Appearance > Channels: "Used on
     * channels that have not chosen their own").
     */
    public const DEFAULTED = [
        'layout', 'theme', 'accent', 'custom_accent', 'surface', 'banner_treatment', 'avatar_frame', 'motion', 'pattern',
        'header_shape', 'stats_style', 'video_layout', 'featured_mode', 'featured_layout', 'featured_columns',
        'featured_rows', 'latest_uploads_layout', 'latest_uploads_columns', 'latest_uploads_rows', 'playlists_layout',
        'playlists_columns', 'playlists_rows', 'playlist_video_columns', 'playlist_video_rows', 'density',
    ];

    /**
     * Which of DEFAULTED a save of the member's Channel form keeps as the member's own (judge R2A-017): what the member
     * chose before (stored; an accent only as a pick, isOldDefaultAccent()), and what this save changes from what the
     * form showed (the channel's values as merge() gives them when the save starts). The form sends every field of its
     * open groups with the value it shows, so a member who only changed the tagline sent the site's defaults of that
     * moment; stored as choices they locked the channel against every later default (the accent, the layout ...). The
     * own colour belongs to the accent: chosen with an accent "Your own colour".
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current
     * @return array<string, true>
     */
    private static function chosenDefaults(array $input, array $current): array
    {
        $shown = self::merge($current);
        $chosen = [];
        $changed = static fn (string $key): bool => array_key_exists($key, $input) && (string) $input[$key] !== (string) ($shown[$key] ?? '');
        $storedBefore = static fn (string $key): bool => array_key_exists($key, $current)
            && ! ($key === 'accent' && self::isOldDefaultAccent($current));

        foreach (self::DEFAULTED as $key) {
            if ($key !== 'custom_accent' && ($storedBefore($key) || $changed($key))) {
                $chosen[$key] = true;
            }
        }

        $accent = (string) ($input['accent'] ?? $shown['accent'] ?? '');

        if ($accent === 'custom' && $changed('custom_accent')) {
            $chosen['accent'] = true;
        }

        if (isset($chosen['accent']) && $accent === 'custom') {
            $chosen['custom_accent'] = true;
        }

        return $chosen;
    }

    /**
     * Whether a channel's stored customization holds the old default accent rather than a pick: "coral" (the default
     * of 1.2.0 to 1.2.2, stored with every save of the channel form) without the mark of a pick (ACCENT_CHOSEN).
     *
     * @param  array<string, mixed>  $customization
     */
    public static function isOldDefaultAccent(array $customization): bool
    {
        return ($customization['accent'] ?? null) === self::SEED_ACCENT
            && (string) ($customization[self::ACCENT_CHOSEN] ?? '') !== '1';
    }

    /**
     * The value the admin's "Accent colour" field (Appearance > Channels, the old settings page) shows for the stored
     * default accent: what channels use. The installer's untouched "coral" of a site installed before 1.2.3 reads as
     * the look's colour (text()), so the field shows that, and a save that leaves the field alone keeps it (QA channel-11:
     * the field said "Coral" while every channel used the look's colour, and a page posted whole stored that "Coral").
     */
    public static function adminAccentValue(string $stored): string
    {
        return $stored === self::SEED_ACCENT && self::accentIsInstallerSeed() ? ChannelAccent::LOOK : $stored;
    }

    /**
     * Coral picked in the admin over the untouched installer "coral" (R1-039): the field shows that seed as the look's
     * colour (adminAccentValue()), so a save that sends "coral" for it is the owner's pick. The stored value stays the
     * same, so no save recorded anything and the pick read as the seed again (Appearance > Channels answered "Published"
     * and the old settings page saved, while every channel kept the look's colour). The row gets the save's time
     * (updated_at): accentIsInstallerSeed() reads it as a choice from then on. Called by the admin's saves before they
     * write the value, inside their transaction; their cache flush renews the seed check. True when it marked a pick.
     */
    public static function keepAccentPick(mixed $sent): bool
    {
        if (! is_string($sent) || $sent !== self::SEED_ACCENT || ! self::accentIsInstallerSeed()) {
            return false;
        }

        try {
            return DB::table('settings')
                ->where('key', 'channel_default_accent')
                ->where('value', self::SEED_ACCENT)
                ->update(['updated_at' => now()]) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public static function bool(array $settings, string $key): bool
    {
        $defaults = self::defaults();

        return (string) ($settings[$key] ?? $defaults[$key] ?? '0') === '1';
    }

    public static function text(array $settings, string $key): string
    {
        $defaults = self::defaults();
        $value = (string) ($settings[$key] ?? $defaults[$key] ?? '');

        // QA channel-11 on sites installed before 1.2.3: their installer stored the old default accent; nobody chose it
        if ($key === 'channel_default_accent' && $value === self::SEED_ACCENT && self::accentIsInstallerSeed()) {
            return ChannelAccent::LOOK;
        }

        return $value;
    }

    /**
     * Whether the stored default accent is still the one the installer wrote (member design 6.19, QA channel-11:
     * the coral accent of the old default clashed with every look that is not pink; since 1.2.3 a channel that has not
     * chosen its accent follows the look). The stored setting is never changed; an untouched seed just counts as "no
     * choice", so it reads as the look's colour:
     * - the settings row still has its installer time (updated_at = created_at: no save ever changed its value), and
     * - no save of the admin ever wrote the key (admin_setting_revisions of SEED_CHOICE_KINDS: a settings page or the
     *   Appearance > Channels page): an owner who saves Coral keeps Coral, also when the stored value did not change.
     * Read once per admin cache version (App\Support\AdminCache: a settings save starts a new one), never fails a page.
     */
    public static function accentIsInstallerSeed(): bool
    {
        return (bool) AdminCache::rememberForever(self::SEED_CACHE_KEY, static function (): bool {
            try {
                $row = DB::table('settings')->where('key', 'channel_default_accent')->first(['value', 'created_at', 'updated_at']);

                if ($row === null || (string) $row->value !== self::SEED_ACCENT) {
                    return false;
                }

                if ($row->created_at !== null && $row->updated_at !== null && (string) $row->created_at !== (string) $row->updated_at) {
                    return false;
                }

                return ! (SettingsRevision::available() && DB::table('admin_setting_revisions')
                    ->whereIn('kind', self::SEED_CHOICE_KINDS)
                    ->where('after', 'like', '%"channel_default_accent"%')
                    ->exists());
            } catch (Throwable) {
                return false;
            }
        });
    }

    public static function sanitizeColor(string $value): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtolower($value) : '#18c8d8';
    }

    private static function trimToLimit(string $value, int $limit): string
    {
        $limit = max(1, $limit);
        $value = trim($value);

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) : $value;
    }

    private static function clampInt(mixed $value, int $min, int $max): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['default' => $min]]);

        return min($max, max($min, (int) $value));
    }
}
