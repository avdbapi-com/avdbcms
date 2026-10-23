<?php

namespace App\Support;

/**
 * Theme catalogue v2: 32 themes in 13 families, 174 colour styles ("flavors") and the 120 theme ids of the old
 * catalogue, each of which now resolves to a theme + flavor. The data is generated into ThemeCatalogData from the
 * redesign theme spec (scripts/themes/build_catalog.py).
 *
 * all(), find(), get(), random(), categories(), byCategory(), categoryDefaults() and themeCustomizations() keep the
 * v1 contract (one flat preset per accepted id) for the v1 settings page, ?theme= previews and fleet scripts.
 */
class ThemeCatalog
{
    public const DEFAULT_THEME = 'tokyo_neon';
    public const DEFAULT_FLAVOR = 'signature';

    /** The colour settings a flavor supplies when the owner stored no custom colour. */
    public const COLOUR_KEYS = [
        'primary_color',
        'brand_secondary_color',
        'accent_color',
        'dark_bg_color',
        'dark_surface_color',
        'light_bg_color',
        'light_surface_color',
    ];

    /** Keys of a v1 preset entry besides id/label/category/description/primary/secondary/accent. */
    private const V1_PRESET_KEYS = [
        'theme',
        'theme_oled_black_enabled',
        'surface_style',
        'radius_scale',
        'font_family',
        'header_style',
        'category_card_style',
        'creator_avatar_ring_style',
        'video_card_duration_style',
        'watch_ambient_glow_intensity',
        'scrollbar_style',
        'hero_layout_mode',
        'back_to_top_style',
        'mobile_video_columns',
        'mobile_video_card_density',
        'mobile_bottom_nav_enabled',
        'mobile_bottom_dock_enabled',
        'mobile_tap_preview_enabled',
        'hero_slider_autoplay_enabled',
        'hero_slider_autoplay_seconds',
        'video_card_title_lines',
        'home_layout_mode',
        'watch_layout_mode',
        'video_card_layout_style',
        'background_atmosphere_effect',
        'typography_pairing',
        'modal_presentation_style',
        'motion_engine_level',
        'interactive_3d_card_tilt_enabled',
        'cinema_ambient_light_diffusion_enabled',
        'dark_bg_color',
        'dark_surface_color',
        'light_bg_color',
        'light_surface_color',
        'sidebar_style',
        'grid_columns',
        'home_feed_columns',
        'content_density',
    ];

    /** @var array<string, array<string, array>> locale => all() (the labels carry a translated "formerly ...") */
    private static array $presets = [];

    /** @var array<int, array>|null */
    private static ?array $searchIndex = null;

    /**
     * The 32 v2 themes in spec order: id => theme (palette, typography, shape, layout, recommended_settings,
     * theme_owned_recipe, flavors keyed by id with the signature flavor first, ...).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function themes(): array
    {
        return ThemeCatalogData::themes();
    }

    /** @return array<int, string> */
    public static function themeIds(): array
    {
        return array_keys(ThemeCatalogData::themes());
    }

    /**
     * A v2 theme by its id or by one of the 120 old ids.
     */
    public static function theme(?string $id): ?array
    {
        $resolved = self::resolve($id);

        return $resolved === null ? null : ThemeCatalogData::themes()[$resolved['theme']];
    }

    public static function isThemeId(?string $id): bool
    {
        return $id !== null && isset(ThemeCatalogData::themes()[$id]);
    }

    /**
     * One of the 93 old ids that is not also a v2 theme id.
     */
    public static function isLegacyId(?string $id): bool
    {
        return $id !== null && isset(ThemeCatalogData::aliases()[$id]) && ! self::isThemeId($id);
    }

    public static function isKnownId(?string $id): bool
    {
        return self::isThemeId($id) || ($id !== null && isset(ThemeCatalogData::aliases()[$id]));
    }

    /**
     * Flavors of a theme (v2 or old id): flavor id => flavor (signature first).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function flavors(?string $themeId): array
    {
        return self::theme($themeId)['flavors'] ?? [];
    }

    public static function flavor(?string $themeId, ?string $flavorId): ?array
    {
        $theme = self::theme($themeId);

        if ($theme === null) {
            return null;
        }

        return $theme['flavors'][$flavorId ?: self::DEFAULT_FLAVOR] ?? null;
    }

    /**
     * Families in display order: id => [id, name, promise, themes].
     *
     * @return array<string, array{id: string, name: string, promise: string, themes: array<int, string>}>
     */
    public static function families(): array
    {
        return ThemeCatalogData::families();
    }

    public static function family(?string $themeId): ?array
    {
        $theme = self::theme($themeId);

        return $theme === null ? null : (ThemeCatalogData::families()[$theme['family']] ?? null);
    }

    /**
     * The theme-owned structure recipe (not settings): header_deco, hero_deco, card_frame, chip_style, chip_active,
     * button_style, section_style, backdrop, icon_style, avatar_shape, motion_personality, sidebar_deco, rank_shelf.
     * [] for an unknown id.
     */
    public static function recipe(?string $themeId): array
    {
        return self::theme($themeId)['theme_owned_recipe'] ?? [];
    }

    /**
     * Resolve a v2 theme id or one of the 120 old ids to [theme, flavor]. An old id brings its own flavor; an explicit
     * $flavor wins when the theme has it. Null for an unknown id.
     *
     * @return array{theme: string, flavor: string, legacy: bool, id: string}|null
     */
    public static function resolve(?string $id, ?string $flavor = null): ?array
    {
        $id = trim((string) $id);

        if ($id === '') {
            return null;
        }

        $themes = ThemeCatalogData::themes();

        if (isset($themes[$id])) {
            $theme = $id;
            $resolvedFlavor = self::DEFAULT_FLAVOR;
            $legacy = false;
        } else {
            $alias = ThemeCatalogData::aliases()[$id] ?? null;

            if ($alias === null) {
                return null;
            }

            $theme = $alias['theme'];
            $resolvedFlavor = $alias['flavor'];
            $legacy = true;
        }

        if ($flavor !== null && $flavor !== '' && isset($themes[$theme]['flavors'][$flavor])) {
            $resolvedFlavor = $flavor;
        }

        return ['theme' => $theme, 'flavor' => $resolvedFlavor, 'legacy' => $legacy, 'id' => $id];
    }

    /**
     * Like resolve(), but an unknown id falls back to the default theme.
     *
     * @return array{theme: string, flavor: string, legacy: bool, id: string}
     */
    public static function resolveOrDefault(?string $id, ?string $flavor = null): array
    {
        return self::resolve($id, $flavor) ?? self::resolve(self::DEFAULT_THEME, $flavor);
    }

    /**
     * The 120 old ids (old catalogue order): old id => [old_id, old_label, old_category, old_mode, old_home,
     * old_primary, theme, flavor, reused_as_flagship].
     *
     * @return array<string, array<string, mixed>>
     */
    public static function aliases(): array
    {
        return ThemeCatalogData::aliases();
    }

    /**
     * Old theme names that now map to this theme (and flavor), e.g. ['Tokyo Neon Horizon', 'Laser Grid Arcade'].
     *
     * @return array<int, string>
     */
    public static function legacyLabels(?string $themeId, ?string $flavorId = null): array
    {
        $resolved = self::resolve($themeId);

        if ($resolved === null) {
            return [];
        }

        $labels = [];

        foreach (ThemeCatalogData::aliases() as $alias) {
            if ($alias['theme'] === $resolved['theme'] && ($flavorId === null || $alias['flavor'] === $flavorId)) {
                $labels[] = $alias['old_label'];
            }
        }

        return $labels;
    }

    /**
     * "formerly Tokyo Neon Horizon, Laser Grid Arcade" for the admin ('' when the theme has no old names).
     */
    public static function formerlyLabel(?string $themeId, ?string $flavorId = null): string
    {
        $labels = self::legacyLabels($themeId, $flavorId);

        return $labels === [] ? '' : __('formerly :names', ['names' => implode(', ', $labels)]);
    }

    /**
     * Everything the theme gallery can be searched by: theme names and ids, family names, flavor names, and the old
     * names and ids (D2: retired presets stay findable).
     *
     * @return array<int, array{theme: string, flavor: ?string, label: string, formerly: ?string, terms: array<string, array<int, string>>}>
     */
    public static function searchIndex(): array
    {
        if (self::$searchIndex !== null) {
            return self::$searchIndex;
        }

        $themes = ThemeCatalogData::themes();
        $families = ThemeCatalogData::families();
        $index = [];

        foreach ($themes as $id => $theme) {
            $index[] = [
                'theme' => $id,
                'flavor' => null,
                'label' => $theme['name'],
                'formerly' => null,
                'terms' => [
                    'name' => [$theme['name'], $id],
                    'family' => [$families[$theme['family']]['name'] ?? $theme['family']],
                ],
            ];

            foreach ($theme['flavors'] as $flavorId => $flavor) {
                $index[] = [
                    'theme' => $id,
                    'flavor' => $flavorId,
                    'label' => $theme['name'] . ' - ' . $flavor['name'],
                    'formerly' => null,
                    'terms' => ['flavor' => [$flavor['name']]],
                ];
            }
        }

        foreach (ThemeCatalogData::aliases() as $oldId => $alias) {
            $theme = $themes[$alias['theme']];
            $index[] = [
                'theme' => $alias['theme'],
                'flavor' => $alias['flavor'],
                'label' => $theme['name'] . ' - ' . $theme['flavors'][$alias['flavor']]['name'],
                'formerly' => $alias['old_label'],
                'terms' => ['formerly' => [$alias['old_label']], 'id' => [$oldId]],
            ];
        }

        return self::$searchIndex = $index;
    }

    /**
     * Case-insensitive search over searchIndex(): each hit names the theme, the flavor (null for a theme hit), how it
     * matched (name, family, flavor, formerly, id) and the old name when it matched one.
     *
     * @return array<int, array{theme: string, flavor: ?string, label: string, match: string, formerly: ?string}>
     */
    public static function search(string $query): array
    {
        $query = mb_strtolower(trim($query));

        if ($query === '') {
            return [];
        }

        $hits = [];

        foreach (self::searchIndex() as $entry) {
            foreach ($entry['terms'] as $kind => $terms) {
                foreach ($terms as $term) {
                    if (str_contains(mb_strtolower($term), $query)) {
                        $hits[] = [
                            'theme' => $entry['theme'],
                            'flavor' => $entry['flavor'],
                            'label' => $entry['label'],
                            'match' => $kind,
                            'formerly' => $entry['formerly'],
                        ];

                        continue 3;
                    }
                }
            }
        }

        return $hits;
    }

    /**
     * The 7 colour settings the flavor supplies (primary/secondary/accent seeds, dark and light page + surface).
     *
     * @return array<string, string>
     */
    public static function flavorColours(?string $themeId, ?string $flavorId = null): array
    {
        $resolved = self::resolve($themeId, $flavorId);

        if ($resolved === null) {
            return [];
        }

        return ThemeCatalogData::themes()[$resolved['theme']]['flavors'][$resolved['flavor']]['colours'];
    }

    /**
     * The theme's recommended settings with the flavor's colours (setting keys; includes theme_preset = the v2 id,
     * theme_flavor and the theme's default mode).
     *
     * @return array<string, string>
     */
    public static function recommendedSettings(?string $themeId, ?string $flavorId = null): array
    {
        $resolved = self::resolve($themeId, $flavorId);

        if ($resolved === null) {
            return [];
        }

        $theme = ThemeCatalogData::themes()[$resolved['theme']];
        $values = array_map('strval', $theme['recommended_settings']);
        $values['theme_preset'] = $resolved['theme'];
        $values['theme_flavor'] = $resolved['flavor'];

        foreach ($theme['flavors'][$resolved['flavor']]['colours'] as $key => $colour) {
            $values[$key] = $colour;
        }

        return $values;
    }

    /**
     * Typography pairings (7 existing + 27 new): id => [label, display, body, label_font?, vi_display?, legacy,
     * existing, heading_font, body_font, label_font_stack].
     *
     * @return array<string, array<string, mixed>>
     */
    public static function pairings(): array
    {
        return ThemeCatalogData::pairings();
    }

    /**
     * Google Fonts css2 family specs, trimmed to the weights the themes use: family => spec.
     *
     * @return array<string, string>
     */
    public static function fontSpecs(): array
    {
        return ThemeCatalogData::fontSpecs();
    }

    /**
     * One flat v1-style preset per accepted id: the 120 old ids (old order) followed by the v2-only theme ids. Values
     * come from the v2 theme's recommended settings with the id's flavor; the v1 page, ?theme= previews and the
     * legacy endpoint's theme_preset validation read this.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $locale = app()->getLocale();

        if (isset(self::$presets[$locale])) {
            return self::$presets[$locale];
        }

        $presets = [];

        foreach (array_keys(ThemeCatalogData::aliases()) as $id) {
            $presets[$id] = self::presetEntry($id);
        }

        foreach (array_keys(ThemeCatalogData::themes()) as $id) {
            $presets[$id] ??= self::presetEntry($id);
        }

        return self::$presets[$locale] = $presets;
    }

    /**
     * Family names in display order (the v1 "categories").
     *
     * @return array<int, string>
     */
    public static function categories(): array
    {
        return array_values(array_map(fn (array $family): string => $family['name'], ThemeCatalogData::families()));
    }

    /**
     * Presets grouped by family name.
     *
     * @return array<string, array<string, array>>
     */
    public static function byCategory(): array
    {
        $grouped = array_fill_keys(self::categories(), []);

        foreach (self::all() as $key => $preset) {
            $grouped[$preset['category'] ?? 'Other'][$key] = $preset;
        }

        return $grouped;
    }

    /**
     * Layout defaults of a family (by name or id): the recommended look of its first theme without colours.
     */
    public static function categoryDefaults(string $category): array
    {
        foreach (ThemeCatalogData::families() as $id => $family) {
            if ($id === $category || $family['name'] === $category) {
                $values = self::recommendedSettings($family['themes'][0] ?? null);
                unset($values['theme_preset'], $values['theme_flavor'], $values['theme']);

                return array_diff_key($values, array_flip(self::COLOUR_KEYS));
            }
        }

        return [];
    }

    /**
     * The look values of every preset id (what applying the preset writes besides its colours).
     *
     * @return array<string, array<string, string>>
     */
    public static function themeCustomizations(): array
    {
        $customizations = [];

        foreach (self::all() as $id => $preset) {
            $customizations[$id] = array_intersect_key($preset, array_flip(self::V1_PRESET_KEYS));
        }

        return $customizations;
    }

    public static function find(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * Alias for find().
     */
    public static function get(string $id): ?array
    {
        return self::find($id);
    }

    /**
     * A random v2 theme preset.
     */
    public static function random(): array
    {
        $ids = self::themeIds();

        return self::find($ids[array_rand($ids)]);
    }

    private static function presetEntry(string $id): array
    {
        $resolved = self::resolve($id);
        $theme = ThemeCatalogData::themes()[$resolved['theme']];
        $flavor = $theme['flavors'][$resolved['flavor']];
        $values = self::recommendedSettings($resolved['theme'], $resolved['flavor']) + self::presetBase();
        $values['font_family'] = self::fontFamilyFor($values['typography_pairing']);
        $formerly = $resolved['legacy']
            ? [ThemeCatalogData::aliases()[$id]['old_label']]
            : self::legacyLabels($id, self::DEFAULT_FLAVOR);
        $label = $resolved['legacy'] || $resolved['flavor'] !== self::DEFAULT_FLAVOR
            ? $theme['name'] . ' - ' . $flavor['name']
            : $theme['name'];

        $entry = [
            'id' => $id,
            'label' => $resolved['legacy'] ? $label . ' (' . __('formerly :names', ['names' => $formerly[0]]) . ')' : $label,
            'category' => $theme['family_name'],
            'description' => $theme['tagline'],
            'primary' => $values['primary_color'],
            'secondary' => $values['brand_secondary_color'],
            'accent' => $values['accent_color'],
        ];

        foreach (self::V1_PRESET_KEYS as $key) {
            $entry[$key] = (string) ($values[$key] ?? '');
        }

        return $entry + [
            'theme_id' => $resolved['theme'],
            'theme_flavor' => $resolved['flavor'],
            'flavor_name' => $flavor['name'],
            'family' => $theme['family'],
            'legacy' => $resolved['legacy'],
            'formerly' => $formerly,
        ];
    }

    /**
     * Values for preset keys a theme's recommended settings leave out (the v2 factory values).
     */
    private static function presetBase(): array
    {
        return array_map('strval', array_intersect_key(AppearanceSettings::defaults(), array_flip(self::V1_PRESET_KEYS)));
    }

    /**
     * The retired font_family preset that is closest to a pairing (font_family no longer drives the fonts; the v1
     * page and fleet scripts still read and post it).
     */
    private static function fontFamilyFor(string $pairing): string
    {
        $display = ThemeCatalogData::pairings()[$pairing]['display'] ?? '';

        return match (true) {
            in_array($display, ['JetBrains Mono', 'Space Mono', 'IBM Plex Mono', 'VT323', 'Silkscreen'], true) => 'mono',
            in_array($display, ['Playfair Display', 'Cinzel', 'Cormorant Garamond', 'Bodoni Moda', 'Newsreader', 'Fraunces', 'EB Garamond'], true) => 'serif',
            in_array($display, ['Outfit', 'Plus Jakarta Sans'], true) => 'modern',
            in_array($display, ['Inter', 'Geist', 'Onest', 'Manrope', 'Lexend', 'Sora'], true) => 'interface',
            in_array($display, ['Fredoka', 'Baloo 2', 'Nunito', 'Quicksand', 'Poppins'], true) => 'rounded',
            in_array($display, ['Orbitron', 'Tektur', 'Unbounded', 'Saira Condensed', 'Exo 2', 'Rajdhani'], true) => 'futuristic',
            in_array($display, ['Montserrat', 'Syne', 'Bricolage Grotesque', 'Archivo', 'Barlow Condensed', 'Josefin Sans', 'Space Grotesk'], true) => 'display',
            default => 'system',
        };
    }
}
