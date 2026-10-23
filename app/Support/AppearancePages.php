<?php

namespace App\Support;

use App\Http\Controllers\Admin\SettingsController;
use App\Models\Setting;
use Illuminate\Support\Facades\Validator;

/**
 * What the Appearance pages are made of: which settings each page edits (config/admin_settings.php), the visual
 * pickers (plain names, one sentence and a wireframe drawn with the admin's own colours), the effects levels with the
 * speed estimate for a weak phone, the published value of every key, and what a Complete look or a theme changes.
 * AppearanceController draws the pages from it; AppearanceDraft keeps the changes until they are published.
 */
final class AppearancePages
{
    /** Address segment => page id in config/admin_settings.php (the Themes page is /admin/appearance itself). */
    public const PAGES = [
        'brand' => 'appearance.brand',
        'homepage' => 'appearance.homepage',
        'video-page' => 'appearance.video_page',
        'cards' => 'appearance.cards',
        'menus' => 'appearance.menus',
        'effects' => 'appearance.effects',
        'other-pages' => 'appearance.other_pages',
        'channels' => 'appearance.channels',
        'code' => 'appearance.custom_code',
    ];

    /** The old settings tab that holds a page's settings (admins who use the old admin). */
    public const OLD_TABS = [
        'appearance.themes' => 'appearance',
        'appearance.homepage' => 'discovery',
        'appearance.channels' => 'users',
    ];

    /** Set by the server (a look, the bottom bar's twin), never typed in. */
    public const SERVER_KEYS = ['active_master_experience', 'mobile_bottom_dock_enabled', 'suggested_master_upgrade'];

    /**
     * The admin page that manages the rest of a card's subject, for a link under the card's title: ['page' => nav page
     * id, 'text', 'label'] or null. The announcement bar (Header, menus & footer) points to the dated notices of
     * Community > Announcements.
     *
     * @return array{page: string, text: string, label: string}|null
     */
    public static function cardLink(string $pageId, string $cardId): ?array
    {
        return match ($pageId.'/'.$cardId) {
            'appearance.menus/announcement' => [
                'page' => 'community.announcements',
                'text' => __('Notices with a title and a colour, at the top or bottom of the page:'),
                'label' => __('Community > Announcements'),
            ],
            default => null,
        };
    }

    /** What "Use this theme" leaves alone unless the admin also takes the theme's layouts. */
    public const LAYOUT_KEYS = [
        'home_layout_mode', 'hero_layout_mode', 'hero_slider_autoplay_enabled', 'hero_slider_autoplay_seconds',
        'watch_layout_mode', 'video_card_layout_style', 'grid_columns', 'home_feed_columns', 'mobile_video_columns',
        'mobile_video_card_density', 'content_density', 'mobile_bottom_nav_enabled', 'mobile_bottom_dock_enabled',
    ];

    /** Effects levels (admin spec appearance.effects_levels): one choice writes these keys. */
    public const LEVELS = [
        'off' => [
            'label' => 'Off',
            'help' => 'Fastest. Nothing moves or glows.',
            'writes' => ['effects_enabled' => '0', 'motion_level' => 'off', 'motion_engine_level' => 'off', 'background_atmosphere_effect' => 'clean_none'],
        ],
        'calm' => [
            'label' => 'Calm',
            'help' => 'Smooth colour changes and gentle hover. No glows or moving backgrounds.',
            'writes' => [
                'effects_enabled' => '1', 'motion_level' => 'reduced', 'motion_engine_level' => 'subtle_elegant',
                'background_atmosphere_effect' => 'clean_none', 'css_blur_effects_enabled' => '0', 'surface_glassmorphism_enabled' => '0',
                'surface_noise_grain_enabled' => '0', 'cursor_glow_trail_enabled' => '0', 'css_animations_enabled' => '0',
                'button_liquid_shimmer_enabled' => '0', 'border_beam_glow_enabled' => '0', 'interactive_3d_card_tilt_enabled' => '0',
                'card_spotlight_glow_enabled' => '0', 'cinema_ambient_light_diffusion_enabled' => '0', 'video_player_ambilight_glow_enabled' => '0',
            ],
        ],
        'standard' => [
            'label' => 'Standard',
            'help' => 'Hover, light animation and soft glows. Recommended.',
            'writes' => [
                'effects_enabled' => '1', 'motion_level' => 'full', 'motion_engine_level' => 'smooth_fluid', 'css_blur_effects_enabled' => '1',
                'surface_glassmorphism_enabled' => '0', 'surface_noise_grain_enabled' => '0', 'cursor_glow_trail_enabled' => '0',
                'css_animations_enabled' => '1', 'interactive_3d_card_tilt_enabled' => '0', 'card_spotlight_glow_enabled' => '0',
            ],
        ],
        'maximum' => [
            'label' => 'Maximum',
            'help' => 'Every effect, including 3D tilt and moving backgrounds. Weak phones still get a lighter version.',
            'writes' => [
                'effects_enabled' => '1', 'motion_level' => 'full', 'motion_engine_level' => 'hyper_dynamic', 'css_blur_effects_enabled' => '1',
                'surface_glassmorphism_enabled' => '1', 'css_animations_enabled' => '1', 'interactive_3d_card_tilt_enabled' => '1',
                'card_spotlight_glow_enabled' => '1', 'border_beam_glow_enabled' => '1', 'button_liquid_shimmer_enabled' => '1',
            ],
        ],
    ];

    /**
     * The effects levels (LEVELS) for views and the translation extractor (scripts/i18n/sources.json catalogs:
     * label, help).
     *
     * @return array<string, array{label: string, help: string, writes: array<string, string>}>
     */
    public static function levels(): array
    {
        return self::LEVELS;
    }

    /** Speed points of one effect that still runs on a weak phone (admin spec effects_page.speed_summary). */
    private const COST_POINTS = ['none' => 0, 'low' => 1, 'mid' => 3, 'high' => 6];

    /** Backgrounds that stand still (they cost nothing). */
    private const STILL_BACKGROUNDS = ['clean_none', 'cyber_grid'];

    /**
     * The settings of an Appearance page in card order: [card id => [title, description, level, keys]].
     *
     * @return array<string, array{title: string, description: string, level: string, fields: list<string>}>
     */
    public static function cards(string $pageId): array
    {
        return (array) (config('admin_settings.pages', [])[$pageId]['cards'] ?? []);
    }

    /**
     * Every key the Appearance pages may change for this admin (expert keys only in Developer mode), plus the keys
     * the server sets itself.
     *
     * @return list<string>
     */
    public static function editableKeys(bool $developer): array
    {
        $keys = [];

        foreach ((array) config('admin_settings.settings', []) as $key => $setting) {
            if (str_starts_with((string) ($setting['page'] ?? ''), 'appearance.')
                && ($setting['level'] ?? '') !== 'hidden'
                && (($setting['level'] ?? '') !== 'expert' || $developer)) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique(array_merge($keys, self::SERVER_KEYS)));
    }

    /**
     * The page that shows a key (for the list of unpublished changes), e.g. 'appearance.homepage'.
     */
    public static function pageOf(string $key): ?string
    {
        $page = config("admin_settings.settings.{$key}.page");

        return is_string($page) ? $page : null;
    }

    public static function label(string $key): string
    {
        $label = config("admin_settings.settings.{$key}.label");

        return is_string($label) ? __($label) : str_replace('_', ' ', $key);
    }

    public static function isBool(string $key): bool
    {
        if (config("admin_settings.settings.{$key}.type") === 'bool') {
            return true;
        }

        return in_array($key, array_merge(AppearanceSettings::booleanKeys(), AppearanceSettings::v2BooleanKeys()), true);
    }

    /**
     * The published (stored, else default) value of each key as the forms show it: appearance keys through
     * AppearanceSettings::formValues() (the fallback defaults and the colour style's colours), other groups stored
     * or their default. Switches are '1' / '0'.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public static function published(array $keys): array
    {
        $grouped = Setting::getAllGrouped();
        $appearance = AppearanceSettings::formValues($grouped['appearance'] ?? []);
        $values = [];

        foreach ($keys as $key) {
            $group = AppearanceDraft::groupOf($key);
            $value = $group === 'appearance'
                ? ($appearance[$key] ?? null)
                : ($grouped[$group][$key] ?? self::defaultOf($key));

            $values[$key] = self::normalize($key, $value);
        }

        return $values;
    }

    /**
     * The value a key is stored with: switches '1' / '0', everything else a string.
     */
    public static function normalize(string $key, mixed $value): string
    {
        if (self::isBool($key)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The default of a setting that is not an appearance key (config/admin_settings.php 'default').
     */
    public static function defaultOf(string $key): string
    {
        $default = config("admin_settings.settings.{$key}.default");

        if (is_array($default) && isset($default['config'])) {
            $default = config($default['config'], $default['fallback'] ?? null);
        } elseif (is_array($default) && isset($default['translate'])) {
            $default = __($default['translate']);
        }

        return is_scalar($default) ? (is_bool($default) ? ($default ? '1' : '0') : (string) $default) : '';
    }

    /**
     * Check one value with the rules the settings save uses (interface I7's schema). Null when it is fine, else the
     * first error.
     */
    public static function invalid(string $key, string $value, ?array $schema = null): ?string
    {
        $group = AppearanceDraft::groupOf($key);
        $schema ??= app(SettingsController::class)->saveSchema();
        $rules = $schema[$group][$key]['rules'] ?? null;

        if ($group === null || self::isBool($key) || ! is_array($rules)) {
            return null;
        }

        $validator = Validator::make(['value' => $value], ['value' => $rules], [
            'required' => __('This cannot be empty.'),
            'integer' => __('Use a whole number.'),
            'numeric' => __('Use a number.'),
            'min' => ['numeric' => __('Use :min or more.'), 'string' => __('Use at least :min characters.')],
            'max' => ['numeric' => __('Use :max or less.'), 'string' => __('Use :max characters or fewer.')],
            'url' => __('Use a full address that starts with https://'),
            'in' => __('Choose one of the options.'),
            'regex' => __('This is not in the expected format.'),
        ]);

        return $validator->fails() ? (string) $validator->errors()->first('value') : null;
    }

    /**
     * What publishing a Complete look writes (null for an unknown look): its 37 settings, the look's id, daily designs
     * off.
     *
     * @return array<string, ?string>|null
     */
    public static function lookValues(string $id): ?array
    {
        $look = MasterExperienceCatalog::find($id);

        if (! is_array($look) || ! is_array($look['apply_settings'] ?? null)) {
            return null;
        }

        $values = array_map('strval', array_filter($look['apply_settings'], 'is_scalar'));
        $values['active_master_experience'] = (string) $look['id'];
        $values['daily_design_auto_enabled'] = '0';
        $values['suggested_master_upgrade'] = '';

        return array_intersect_key($values, array_flip(AppearanceSettings::lookKeys()));
    }

    /**
     * What publishing a theme in one of its colour styles writes (null for an unknown theme), like
     * AppearanceApplier::applyTheme(): the colour style instead of custom colours (null = back to the style), the
     * theme's recommended look; with $layouts false the homepage, video page and card layouts stay.
     *
     * @return array<string, ?string>|null
     */
    public static function themeValues(string $themeId, ?string $flavorId, bool $layouts): ?array
    {
        $resolved = ThemeCatalog::resolve($themeId, $flavorId);

        if ($resolved === null) {
            return null;
        }

        $values = array_intersect_key(
            AppearanceSettings::themeValues($resolved['theme'], $resolved['flavor']),
            array_flip(AppearanceSettings::lookKeys()),
        );

        if (! $layouts) {
            $values = array_diff_key($values, array_flip(self::LAYOUT_KEYS));
        }

        $values = array_replace($values, [
            'theme_preset' => $resolved['theme'],
            'theme_flavor' => $resolved['flavor'],
            'active_master_experience' => '',
            'daily_design_auto_enabled' => '0',
        ]);

        foreach (ThemeCatalog::COLOUR_KEYS as $key) {
            $values[$key] = null;
        }

        return $values;
    }

    /**
     * The effects level these values match, or 'custom'.
     *
     * @param  array<string, string>  $values
     */
    public static function levelOf(array $values): string
    {
        foreach (self::LEVELS as $id => $level) {
            foreach ($level['writes'] as $key => $value) {
                if ((string) ($values[$key] ?? '') !== $value) {
                    continue 2;
                }
            }

            return $id;
        }

        return 'custom';
    }

    /**
     * What an effect costs on a weak phone: cost none|low|mid|high, where all|not-weak|strong, and why.
     *
     * @return array{cost: string, where: string, why: string}|null
     */
    public static function impact(string $key): ?array
    {
        $impact = config("admin_settings.settings.{$key}.impact");

        if (! is_array($impact)) {
            return null;
        }

        return [
            'cost' => (string) ($impact['cost'] ?? 'low'),
            'where' => match ((string) ($impact['runs_on'] ?? 'all')) {
                'rich' => 'strong',
                'balanced+' => 'not-weak',
                default => 'all',
            },
            'why' => (string) ($impact['why'] ?? ''),
        ];
    }

    /**
     * "Speed on a weak phone", an estimate: the cost points of every effect that is on and still runs on a weak phone
     * under the device rule (none 0, low 1, medium 3, heavy 6; a moving background counts as heavy). 0-6 Fast, 7-14 OK,
     * 15+ Slower. Also the heavy effects that are on (wherever they run) and how many bars of 5 the meter shows.
     *
     * @param  array<string, string>  $values  effective values of the Effects & speed keys
     * @return array{score: int, verdict: string, label: string, bars: int, heavy: list<string>, background: bool, tier: string}
     */
    public static function speed(array $values): array
    {
        $tier = (string) ($values['performance_tier_mode'] ?? 'auto');
        // Under Auto (and "Always light") a weak phone gets the light tier; "Always all effects" gives it everything.
        $weakTier = match ($tier) {
            'rich' => 'rich',
            'balanced' => 'balanced',
            default => 'lite',
        };
        $score = 0;
        $heavy = [];
        $background = false;
        $effectsOn = ($values['effects_enabled'] ?? '1') === '1';

        foreach ((array) config('admin_settings.settings', []) as $key => $setting) {
            if (($setting['page'] ?? '') !== 'appearance.effects' || ! is_array($setting['impact'] ?? null)) {
                continue;
            }

            $value = (string) ($values[$key] ?? '');
            $on = match ($key) {
                'effects_enabled', 'motion_level', 'performance_tier_mode', 'border_beam_speed' => false,
                'motion_engine_level' => $value === 'hyper_dynamic',
                'background_atmosphere_effect' => $value !== '' && ! in_array($value, self::STILL_BACKGROUNDS, true),
                'watch_ambient_glow_intensity' => $value !== '' && $value !== 'off',
                'video_player_cinema_filter' => $value !== '' && $value !== 'none',
                default => $value === '1',
            };

            // The main switch turns every decoration off; the background is drawn by the same layer.
            if (! $on || (! $effectsOn && $key !== 'js_lazy_comments_enabled')) {
                continue;
            }

            $cost = (string) $setting['impact']['cost'];
            $runsOn = (string) ($setting['impact']['runs_on'] ?? 'all');

            if ($key === 'background_atmosphere_effect') {
                $background = true;
            }

            if ($cost === 'high') {
                $heavy[] = $key;
            }

            $runsOnWeak = $runsOn === 'all'
                || $weakTier === 'rich'
                || ($weakTier === 'balanced' && $runsOn === 'balanced+');

            if ($runsOnWeak) {
                $score += self::COST_POINTS[$cost] ?? 1;
            }
        }

        [$verdict, $label] = match (true) {
            $score <= 6 => ['fast', __('Fast')],
            $score <= 14 => ['ok', __('OK')],
            default => ['slow', __('Slower')],
        };

        $bars = match (true) {
            $score === 0 => 5,
            $score <= 6 => 4,
            $score <= 10 => 3,
            $score <= 14 => 2,
            default => 1,
        };

        return ['score' => $score, 'verdict' => $verdict, 'label' => $label, 'bars' => $bars, 'heavy' => $heavy, 'background' => $background, 'tier' => $tier];
    }

    /**
     * Choices of a setting the settings form leaves without a list (pickers without a picture, the effects level
     * keys, number ranges such as videos per row): plain names where the admin spec gives them, else the values the
     * save accepts.
     *
     * @return array<string, string> value => English label
     */
    public static function options(string $key, ?array $rule): array
    {
        $plain = [
            'motion_level' => ['full' => 'Full', 'reduced' => 'Reduced', 'off' => 'Off'],
            'motion_engine_level' => ['off' => 'Off', 'subtle_elegant' => 'Subtle', 'smooth_fluid' => 'Smooth motion', 'hyper_dynamic' => 'Lively'],
            'back_to_top_style' => ['floating_pill' => 'Pill with a label', 'round_icon' => 'Round button', 'minimal_corner' => 'Small corner button'],
        ][$key] ?? null;

        if ($plain !== null) {
            return $plain;
        }

        $channel = str_starts_with($key, 'channel_default_') ? (ChannelCustomizationSettings::options()[substr($key, 16)] ?? null) : null;
        $values = [];
        $min = null;
        $max = null;

        foreach ((array) ($rule['rules'] ?? []) as $part) {
            if ($part instanceof \Illuminate\Validation\Rules\In) {
                foreach (str_getcsv(substr((string) $part, 3)) as $value) {
                    $values[$value] = is_array($channel) && isset($channel[$value]) ? (string) $channel[$value] : ($value === '' ? 'None' : \Illuminate\Support\Str::headline($value));
                }
            } elseif (is_string($part) && preg_match('/^(min|max):(\d+)$/', $part, $m) === 1) {
                $m[1] === 'min' ? $min = (int) $m[2] : $max = (int) $m[2];
            }
        }

        if ($values === [] && $min !== null && $max !== null && $max - $min <= 12) {
            foreach (range($min, $max) as $number) {
                $values[(string) $number] = (string) $number;
            }
        }

        return $values;
    }

    /**
     * The visual pickers: value => [label, help, svg (trusted markup) | img]. Only the values the setting accepts.
     *
     * @return array<string, array{label: string, help: string, svg?: string, img?: string}>
     */
    public static function picker(string $key): array
    {
        $options = match ($key) {
            'home_layout_mode' => [
                'grid_modern' => [__('Classic grid'), __('Banner, then a simple grid.')],
                'youtube_spotlight' => [__('Spotlight'), __('Two ranked lists, chips, then a grid.')],
                'cinema_carousel' => [__('Cinema rows'), __('Big banner and rows you scroll sideways.')],
                'bento_mosaic' => [__('Mosaic'), __('Mixed-size tiles, lots of space.')],
                'tiktok_shorts' => [__('Shorts'), __('Tall cards for short clips.')],
                'cyberpunk_hud' => [__('Control room'), __('Framed video with a stats panel.')],
            ],
            'hero_layout_mode' => [
                'snap_slider_carousel' => [__('Sliding banner'), __('Large slides that change by themselves.')],
                'bento_spotlight' => [__('Video + 3 tiles'), __('One big video and three smaller ones.')],
                'live_ticker_stream' => [__('Two ranked lists'), __('Most viewed and fastest rising.')],
                'classic_banner' => [__('Title and chips'), __('A heading and category buttons.')],
                'editorial_lead' => [__('Lead story'), __('A lead video and a "Most watched" column.')],
                'floating_pill' => [__('Small label'), __('Just a label above the videos.')],
                'off' => [__('No banner'), __('Videos start right away.')],
            ],
            'watch_layout_mode' => [
                'classic_two_column' => [__('Player left, Up next right'), __('Title and buttons under the player.')],
                'theater_cinema' => [__('Wide player on top'), __('Everything else under the player.')],
                'floating_focus' => [__('Centred, no distractions'), __('Player in the middle, buttons in a bar.')],
                'studio_dashboard' => [__('Player with stats'), __('A side panel with views and likes.')],
            ],
            'video_card_layout_style' => [
                'card_standard' => [__('Thumbnail with text below'), __('The classic card.')],
                'card_hover_expand' => [__('Grows when pointed at'), __('Shows a play button on hover.')],
                'card_glass_overlay' => [__('Text over the picture'), __('Title on a shaded strip.')],
                'card_horizontal_strip' => [__('Thumbnail left, text right'), __('A compact list.')],
                'card_bento_modern' => [__('Rounded tile'), __('Soft tile with rounded corners.')],
            ],
            'header_style' => [
                'solid_dark' => [__('Solid bar'), __('A plain bar in the theme colour.')],
                'minimal_slim' => [__('Slim bar'), __('A thin bar that leaves room for videos.')],
                'glass_blur' => [__('Translucent bar'), __('The page shows faintly through it.')],
                'glass_island' => [__('Floating island'), __('A rounded bar that floats above the page.')],
                'full_megabar' => [__('Two-row bar'), __('Search on top, categories below.')],
                'transparent_overlay' => [__('Over the banner'), __('Sits on the top banner; a solid bar on other pages.')],
            ],
            'sidebar_style' => [
                'full' => [__('Full side menu'), __('Icons and names on the left.')],
                'rail' => [__('Icon rail'), __('A slim column of icons.')],
                'floating' => [__('Floating panel'), __('A rounded panel beside the page.')],
                'hidden' => [__('No side menu'), __('The main links move into the header.')],
            ],
            'background_atmosphere_effect' => [
                'clean_none' => [__('Plain'), __('No cost.')],
                'cyber_grid' => [__('Grid lines'), __('Still, no cost.')],
                'aurora_glow' => [__('Aurora'), __('Moving, heavy.')],
                'cosmic_particles' => [__('Stars'), __('Moving, heavy.')],
                'mesh_nebula' => [__('Nebula'), __('Moving, heavy.')],
                'solar_flare' => [__('Solar dust'), __('Moving, heavy.')],
                'quantum_matrix' => [__('Digital rain'), __('Moving, heavy.')],
            ],
            'modal_presentation_style' => [
                'modal_glassmorphic_center' => [__('Centred window'), __('Opens in the middle of the page.')],
                'modal_slide_sheet' => [__('Sliding sheet'), __('Slides in from the side (from the bottom on phones).')],
                'modal_fullscreen_cinema' => [__('Full screen'), __('Covers the whole page.')],
                'modal_brutalist_sharp' => [__('Sharp box'), __('Square corners and a strong outline.')],
            ],
            'watch_player_frame_style' => [
                'cinematic' => [__('Cinematic'), __('A dark frame with a soft edge.')],
                'soft' => [__('Soft'), __('Rounded corners, no frame.')],
                'minimal' => [__('Minimal'), __('Square edges, nothing around it.')],
            ],
            'surface_style' => [
                'glass' => [__('Glass'), __('See-through panels.')],
                'solid' => [__('Solid'), __('Plain filled panels.')],
                'contrast' => [__('High contrast'), __('Strong outlines.')],
            ],
            'radius_scale' => [
                'compact' => [__('Sharp'), __('Small corners.')],
                'balanced' => [__('Balanced'), __('Medium corners.')],
                'rounded' => [__('Round'), __('Big soft corners.')],
            ],
            'video_card_duration_style' => [
                'pill' => [__('Pill'), __('A rounded label.')],
                'badge_solid' => [__('Dark badge'), __('A solid dark label.')],
                'subtle' => [__('Subtle'), __('Small text only.')],
            ],
            'category_card_style' => [
                'gradient_mesh' => [__('Colour blend'), __('Soft colour gradient.')],
                'glass_card' => [__('Glass'), __('See-through tile.')],
                'minimal_flat' => [__('Outline'), __('A plain outlined tile.')],
                'photo_overlay' => [__('Photo'), __('A picture with a dark shade.')],
            ],
            'creator_avatar_ring_style' => [
                'neon_pulse' => [__('Pulsing ring'), __('A coloured ring that pulses.')],
                'gradient_glow' => [__('Colour ring'), __('A soft gradient ring.')],
                'static_ring' => [__('Plain ring'), __('A thin ring in your colour.')],
                'off' => [__('No ring'), __('A clean circle.')],
            ],
            'typography_pairing' => self::fontOptions(),
            default => [],
        };

        $allowed = AppearanceSettings::optionLists()[$key] ?? null;
        $picker = [];

        foreach ($options as $value => $option) {
            if (is_array($allowed) && ! in_array((string) $value, $allowed, true) && $key !== 'watch_player_frame_style') {
                continue;
            }

            // The labels are translated where they are written (font pairings keep their product names).
            $entry = ['label' => (string) $option[0], 'help' => (string) $option[1]];

            if ($key === 'typography_pairing') {
                $entry['img'] = $option[2];
            } else {
                $entry['svg'] = self::wireframe($key, (string) $value);
            }

            $picker[(string) $value] = $entry;
        }

        return $picker;
    }

    /**
     * A 160 x 100 wireframe of one choice, drawn with the admin's tokens (classes of components/_ui.scss: bg, blk,
     * soft, acc, ln), ported from the admin mockups (spec/admin/mockups/src/mock_lib.py).
     */
    public static function wireframe(string $key, string $value): string
    {
        $header = '<rect class="soft" x="0" y="0" width="160" height="8"/><rect class="acc" x="6" y="2.5" width="12" height="3" rx="1.5"/><rect class="blk" x="56" y="2" width="48" height="4" rx="2"/>';
        $body = match ($key.':'.$value) {
            'home_layout_mode:grid_modern' => $header.'<rect class="blk" x="6" y="12" width="148" height="30" rx="2"/><rect class="acc" x="12" y="33" width="20" height="4" rx="2"/>'.self::grid(6, 47, 4, 2, 34.75),
            'home_layout_mode:youtube_spotlight' => $header.self::rankedLists(12, 32, 9.5).self::chips(6, 48, 7, 20, 17, 4).self::grid(6, 56, 5, 2, 27.2, 3.5),
            'home_layout_mode:cinema_carousel' => $header.'<rect class="blk" x="6" y="12" width="98" height="40" rx="2"/><rect class="acc" x="12" y="43" width="16" height="4" rx="2"/>'
                .self::repeat(3, fn (int $i) => '<rect class="soft" x="108" y="'.self::n(12 + $i * 13.6).'" width="46" height="12" rx="1.5"/>')
                .self::repeat(5, fn (int $i) => '<rect class="blk" x="'.(6 + $i * 39).'" y="58" width="36" height="20" rx="1.5"/><rect class="soft" x="'.(6 + $i * 39).'" y="82" width="36" height="14" rx="1.5"/>'),
            'home_layout_mode:bento_mosaic' => $header.'<rect class="blk" x="6" y="12" width="74" height="46" rx="3"/><rect class="soft" x="84" y="12" width="34" height="21" rx="3"/><rect class="blk" x="122" y="12" width="32" height="21" rx="3"/><rect class="blk" x="84" y="37" width="70" height="21" rx="3"/>'.self::grid(6, 63, 5, 1, 27.2, 3.5).self::grid(6, 88, 5, 1, 27.2, 3.5, 6),
            'home_layout_mode:tiktok_shorts' => $header.self::repeat(6, fn (int $i) => '<rect class="blk" x="'.(6 + $i * 25).'" y="12" width="22" height="40" rx="3"/><rect class="ln" x="'.(8 + $i * 25).'" y="46" width="14" height="1.8" rx=".9"/>').self::grid(6, 58, 5, 2, 27.2, 3.5, 12),
            'home_layout_mode:cyberpunk_hud' => $header.'<rect class="blk" x="6" y="12" width="96" height="46"/><path d="M6 18V12h6M96 12h6v6M6 52v6h6M102 52v6h-6" fill="none" stroke="var(--accent)" stroke-width="1.4"/><rect class="soft" x="106" y="12" width="48" height="46"/>'
                .self::repeat(5, fn (int $i) => '<rect class="ln" x="110" y="'.(17 + $i * 7).'" width="'.(36 - ($i % 3) * 8).'" height="1.8"/>').'<rect class="acc" x="110" y="51" width="40" height="4"/>'.self::grid(6, 63, 5, 1, 27.2, 3.5).self::grid(6, 88, 5, 1, 27.2, 3.5, 6),
            'hero_layout_mode:snap_slider_carousel' => $header.'<rect class="blk" x="6" y="12" width="148" height="44" rx="2"/><circle cx="14" cy="34" r="4" class="soft"/><circle cx="146" cy="34" r="4" class="soft"/>'
                .self::repeat(4, fn (int $i) => '<rect class="'.($i === 1 ? 'acc' : 'soft').'" x="'.(66 + $i * 7).'" y="50" width="5" height="2" rx="1"/>').self::grid(6, 62, 5, 1, 27.2, 3.5),
            'hero_layout_mode:bento_spotlight' => $header.'<rect class="blk" x="6" y="12" width="96" height="44" rx="2"/><rect class="soft" x="106" y="12" width="48" height="13" rx="2"/><rect class="soft" x="106" y="27.5" width="48" height="13" rx="2"/><rect class="soft" x="106" y="43" width="48" height="13" rx="2"/>'.self::grid(6, 62, 5, 1, 27.2, 3.5),
            'hero_layout_mode:live_ticker_stream' => $header.self::rankedLists(12, 44, 13, true).self::grid(6, 62, 5, 1, 27.2, 3.5),
            'hero_layout_mode:classic_banner' => $header.'<rect class="ln" x="6" y="15" width="70" height="4" rx="2"/><rect class="ln" x="6" y="22" width="110" height="2" rx="1"/>'.self::chips(6, 30, 6, 22, 19, 5).self::grid(6, 42, 5, 2, 27.2, 3.5),
            'hero_layout_mode:floating_pill' => $header.'<rect class="acc" x="6" y="13" width="44" height="6" rx="3"/>'.self::grid(6, 25, 5, 3, 27.2, 3.5, 14),
            'hero_layout_mode:off' => $header.self::grid(6, 13, 5, 3, 27.2, 3.5, 15),
            'hero_layout_mode:editorial_lead' => $header.'<rect class="blk" x="6" y="12" width="92" height="40" rx="2"/><rect class="ln" x="6" y="56" width="84" height="4" rx="2"/><rect class="ln" x="6" y="63" width="60" height="2" rx="1"/>'
                .self::repeat(5, fn (int $i) => '<rect class="acc" x="104" y="'.(13 + $i * 11).'" width="5" height="5" rx="1"/><rect class="ln" x="112" y="'.self::n(13.5 + $i * 11).'" width="40" height="2"/><rect class="ln" x="112" y="'.self::n(17.5 + $i * 11).'" width="26" height="1.5"/>').self::grid(6, 70, 5, 1, 27.2, 3.5, 14),
            'watch_layout_mode:classic_two_column' => $header.'<rect class="blk" x="6" y="12" width="104" height="58" rx="2"/><rect class="ln" x="6" y="74" width="80" height="3"/><rect class="soft" x="6" y="81" width="104" height="13" rx="2"/>'
                .self::repeat(8, fn (int $i) => '<rect class="blk" x="114" y="'.(12 + $i * 11).'" width="16" height="9" rx="1"/><rect class="ln" x="133" y="'.self::n(13.5 + $i * 11).'" width="21" height="1.8"/>'),
            'watch_layout_mode:theater_cinema' => $header.'<rect class="blk" x="0" y="10" width="160" height="62"/><rect class="ln" x="6" y="76" width="80" height="3"/>'.self::repeat(5, fn (int $i) => '<rect class="soft" x="'.(6 + $i * 30).'" y="83" width="27" height="13" rx="1.5"/>'),
            'watch_layout_mode:floating_focus' => $header.'<rect class="blk" x="34" y="14" width="92" height="52" rx="3"/><rect class="soft" x="52" y="70" width="56" height="7" rx="3.5"/><rect class="ln" x="34" y="82" width="70" height="3"/><rect class="ln" x="34" y="88" width="46" height="2"/>',
            'watch_layout_mode:studio_dashboard' => $header.'<rect class="blk" x="6" y="12" width="98" height="54" rx="2"/><rect class="soft" x="108" y="12" width="46" height="26" rx="2"/>'
                .self::repeat(3, fn (int $i) => '<rect class="acc" x="112" y="'.(16 + $i * 6).'" width="'.(30 - $i * 6).'" height="2.5"/>').'<rect class="soft" x="108" y="42" width="46" height="52" rx="2"/><rect class="ln" x="6" y="70" width="70" height="3"/><rect class="soft" x="6" y="77" width="98" height="17" rx="2"/>',
            'video_card_layout_style:card_standard' => '<rect class="blk" x="40" y="12" width="80" height="45" rx="3"/><circle class="soft" cx="46" cy="66" r="5"/><rect class="ln" x="55" y="62" width="62" height="3" rx="1.5"/><rect class="ln" x="55" y="68" width="40" height="2" rx="1"/><rect class="ln" x="55" y="73" width="30" height="2" rx="1"/>',
            'video_card_layout_style:card_hover_expand' => '<rect class="blk" x="34" y="8" width="92" height="52" rx="3"/><rect class="soft" x="34" y="60" width="92" height="22"/><rect class="acc" x="40" y="66" width="18" height="5" rx="2.5"/><rect class="ln" x="62" y="67" width="50" height="2.5"/><path d="M28 34h-5M132 34h5" stroke="var(--text-3)" stroke-width="1.2"/>',
            'video_card_layout_style:card_glass_overlay' => '<rect class="blk" x="40" y="12" width="80" height="60" rx="3"/><rect class="soft" x="40" y="48" width="80" height="24"/><rect class="ln" x="46" y="54" width="60" height="3"/><rect class="ln" x="46" y="61" width="36" height="2"/>',
            'video_card_layout_style:card_horizontal_strip' => '<rect class="blk" x="20" y="30" width="56" height="32" rx="3"/><rect class="ln" x="82" y="33" width="58" height="3.5"/><rect class="ln" x="82" y="41" width="40" height="2"/><rect class="ln" x="82" y="47" width="30" height="2"/>',
            'video_card_layout_style:card_bento_modern' => '<rect class="soft" x="34" y="8" width="92" height="76" rx="9"/><rect class="blk" x="40" y="14" width="80" height="44" rx="6"/><rect class="ln" x="42" y="64" width="62" height="3"/><rect class="ln" x="42" y="71" width="40" height="2"/>',
            'header_style:solid_dark' => '<rect class="blk" x="0" y="0" width="160" height="12"/><rect class="acc" x="6" y="4" width="14" height="4" rx="2"/><rect class="soft" x="52" y="3.5" width="56" height="5" rx="2.5"/>'.self::grid(6, 18, 4, 2, 34.75),
            'header_style:minimal_slim' => '<rect class="soft" x="0" y="0" width="160" height="6"/><rect class="acc" x="6" y="1.5" width="10" height="3" rx="1.5"/><rect class="blk" x="60" y="1.5" width="40" height="3" rx="1.5"/>'.self::grid(6, 12, 4, 2, 34.75),
            'header_style:glass_blur' => self::grid(6, 6, 4, 2, 34.75).'<rect class="soft" x="0" y="0" width="160" height="12" opacity=".75"/><rect class="acc" x="6" y="4" width="14" height="4" rx="2"/><rect class="blk" x="52" y="3.5" width="56" height="5" rx="2.5" opacity=".7"/>',
            'header_style:glass_island' => self::grid(6, 20, 4, 2, 34.75).'<rect class="soft" x="22" y="4" width="116" height="11" rx="5.5"/><rect class="acc" x="28" y="7.5" width="12" height="4" rx="2"/><rect class="blk" x="62" y="7.5" width="44" height="4" rx="2"/>',
            'header_style:full_megabar' => '<rect class="blk" x="0" y="0" width="160" height="11"/><rect class="acc" x="6" y="3.5" width="14" height="4" rx="2"/><rect class="soft" x="40" y="3" width="80" height="5" rx="2.5"/><rect class="soft" x="0" y="11" width="160" height="8"/>'.self::chips(6, 13, 7, 21, 17, 4).self::grid(6, 24, 4, 2, 34.75),
            'header_style:transparent_overlay' => '<rect class="blk" x="0" y="0" width="160" height="52"/><rect class="acc" x="6" y="4" width="14" height="4" rx="2"/><rect class="ln" x="52" y="5" width="56" height="2" rx="1"/><rect class="ln" x="12" y="34" width="70" height="4" rx="2"/><rect class="ln" x="12" y="41" width="46" height="2" rx="1"/>'.self::grid(6, 58, 5, 1, 27.2, 3.5),
            'sidebar_style:full' => $header.'<rect class="soft" x="0" y="8" width="34" height="92"/>'.self::repeat(6, fn (int $i) => '<rect class="'.($i === 0 ? 'acc' : 'ln').'" x="5" y="'.(14 + $i * 9).'" width="5" height="4" rx="1"/><rect class="ln" x="13" y="'.(15 + $i * 9).'" width="16" height="2" rx="1"/>').self::grid(40, 14, 3, 3, 36, 3.5, 17),
            'sidebar_style:rail' => $header.'<rect class="soft" x="0" y="8" width="14" height="92"/>'.self::repeat(6, fn (int $i) => '<rect class="'.($i === 0 ? 'acc' : 'ln').'" x="4.5" y="'.(14 + $i * 9).'" width="5" height="5" rx="1.2"/>').self::grid(20, 14, 4, 3, 31.5, 3.5, 17),
            'sidebar_style:floating' => $header.'<rect class="soft" x="5" y="13" width="30" height="80" rx="5"/>'.self::repeat(6, fn (int $i) => '<rect class="'.($i === 0 ? 'acc' : 'ln').'" x="10" y="'.(19 + $i * 9).'" width="20" height="2.5" rx="1.2"/>').self::grid(41, 14, 3, 3, 35.5, 3.5, 17),
            'sidebar_style:hidden' => '<rect class="soft" x="0" y="0" width="160" height="8"/><rect class="ln" x="5" y="2" width="6" height="1.2"/><rect class="ln" x="5" y="4" width="6" height="1.2"/><rect class="ln" x="5" y="6" width="6" height="1.2"/><rect class="acc" x="15" y="2.5" width="12" height="3" rx="1.5"/>'
                .self::repeat(4, fn (int $i) => '<rect class="ln" x="'.(60 + $i * 18).'" y="3" width="14" height="2" rx="1"/>').self::grid(6, 14, 5, 3, 27.2, 3.5, 17),
            'background_atmosphere_effect:clean_none' => $header.self::grid(6, 14, 4, 2, 34.75),
            'background_atmosphere_effect:cyber_grid' => $header.self::repeat(8, fn (int $i) => '<path d="M'.(10 + $i * 20).' 8V100" stroke="var(--border-strong)" stroke-width=".6"/>').self::repeat(5, fn (int $i) => '<path d="M0 '.(20 + $i * 20).'H160" stroke="var(--border-strong)" stroke-width=".6"/>').self::grid(6, 14, 4, 2, 34.75),
            'background_atmosphere_effect:aurora_glow' => $header.'<path d="M0 40C40 20 80 60 160 30V8H0Z" class="acc" opacity=".25"/><path d="M0 60C50 40 100 80 160 50V40C100 70 50 30 0 50Z" class="soft"/>'.self::grid(6, 62, 4, 1, 34.75),
            'background_atmosphere_effect:cosmic_particles' => $header.self::repeat(18, fn (int $i) => '<circle class="'.($i % 4 === 0 ? 'acc' : 'ln').'" cx="'.((($i * 37) % 150) + 5).'" cy="'.((($i * 23) % 84) + 12).'" r="'.($i % 3 === 0 ? 1.3 : .8).'"/>').self::grid(6, 62, 4, 1, 34.75),
            'background_atmosphere_effect:mesh_nebula' => $header.'<circle class="acc" cx="40" cy="40" r="26" opacity=".2"/><circle class="soft" cx="110" cy="30" r="30"/><circle class="acc" cx="128" cy="62" r="18" opacity=".15"/>'.self::grid(6, 62, 4, 1, 34.75),
            'background_atmosphere_effect:solar_flare' => $header.'<circle class="acc" cx="140" cy="18" r="34" opacity=".18"/><circle class="acc" cx="140" cy="18" r="16" opacity=".25"/>'.self::repeat(10, fn (int $i) => '<circle class="ln" cx="'.((($i * 41) % 140) + 8).'" cy="'.((($i * 29) % 40) + 14).'" r=".9"/>').self::grid(6, 62, 4, 1, 34.75),
            'background_atmosphere_effect:quantum_matrix' => $header.self::repeat(12, fn (int $i) => '<rect class="'.($i % 3 === 0 ? 'acc' : 'ln').'" x="'.(6 + $i * 13).'" y="'.(10 + ($i * 7) % 22).'" width="1.6" height="'.(18 + ($i * 11) % 24).'" opacity=".6"/>').self::grid(6, 62, 4, 1, 34.75),
            'modal_presentation_style:modal_glassmorphic_center' => $header.self::grid(6, 14, 4, 2, 34.75).'<rect width="160" height="100" fill="var(--text)" opacity=".18"/><rect class="soft" x="40" y="22" width="80" height="56" rx="6"/><rect class="ln" x="48" y="30" width="44" height="3" rx="1.5"/><rect class="ln" x="48" y="37" width="60" height="2" rx="1"/><rect class="acc" x="94" y="66" width="20" height="6" rx="3"/>',
            'modal_presentation_style:modal_slide_sheet' => $header.self::grid(6, 14, 4, 2, 34.75).'<rect width="160" height="100" fill="var(--text)" opacity=".18"/><rect class="soft" x="98" y="0" width="62" height="100"/><rect class="ln" x="105" y="10" width="40" height="3" rx="1.5"/><rect class="ln" x="105" y="17" width="48" height="2" rx="1"/><rect class="acc" x="128" y="86" width="24" height="6" rx="3"/>',
            'modal_presentation_style:modal_fullscreen_cinema' => '<rect class="blk" width="160" height="100"/><rect class="soft" x="20" y="14" width="120" height="62" rx="2"/><rect class="ln" x="20" y="82" width="70" height="3" rx="1.5"/><rect class="acc" x="146" y="5" width="8" height="8" rx="4"/>',
            'modal_presentation_style:modal_brutalist_sharp' => $header.self::grid(6, 14, 4, 2, 34.75).'<rect class="blk" x="44" y="26" width="80" height="56"/><rect class="soft" x="40" y="22" width="80" height="56" stroke="var(--text)" stroke-width="1.5"/><rect class="ln" x="48" y="30" width="44" height="3"/><rect class="acc" x="94" y="66" width="20" height="6"/>',
            'watch_player_frame_style:cinematic' => '<rect class="soft" x="16" y="12" width="128" height="76" rx="4"/><rect class="blk" x="22" y="18" width="116" height="64" rx="2"/><path d="M76 42l12 8-12 8z" class="acc"/>',
            'watch_player_frame_style:soft' => '<rect class="blk" x="16" y="14" width="128" height="72" rx="10"/><path d="M76 42l12 8-12 8z" class="acc"/>',
            'watch_player_frame_style:minimal' => '<rect class="blk" x="16" y="14" width="128" height="72"/><path d="M76 42l12 8-12 8z" class="acc"/>',
            'surface_style:glass' => '<rect class="acc" x="10" y="14" width="60" height="40" rx="20" opacity=".35"/><rect class="soft" x="34" y="28" width="96" height="56" rx="6" opacity=".7"/><rect class="ln" x="42" y="38" width="50" height="3" rx="1.5"/>',
            'surface_style:solid' => '<rect class="soft" x="34" y="28" width="96" height="56" rx="6"/><rect class="ln" x="42" y="38" width="50" height="3" rx="1.5"/>',
            'surface_style:contrast' => '<rect class="soft" x="34" y="28" width="96" height="56" rx="6" stroke="var(--text)" stroke-width="2"/><rect class="ln" x="42" y="38" width="50" height="3" rx="1.5"/>',
            'radius_scale:compact' => '<rect class="blk" x="24" y="22" width="112" height="56" rx="2"/>',
            'radius_scale:balanced' => '<rect class="blk" x="24" y="22" width="112" height="56" rx="8"/>',
            'radius_scale:rounded' => '<rect class="blk" x="24" y="22" width="112" height="56" rx="18"/>',
            'video_card_duration_style:pill' => '<rect class="blk" x="24" y="14" width="112" height="64" rx="4"/><rect class="soft" x="104" y="64" width="26" height="9" rx="4.5"/><rect class="ln" x="109" y="67.5" width="16" height="2"/>',
            'video_card_duration_style:badge_solid' => '<rect class="blk" x="24" y="14" width="112" height="64" rx="4"/><rect x="104" y="64" width="26" height="9" rx="1.5" fill="var(--text)"/><rect class="bg" x="109" y="67.5" width="16" height="2"/>',
            'video_card_duration_style:subtle' => '<rect class="blk" x="24" y="14" width="112" height="64" rx="4"/><rect class="ln" x="112" y="68" width="16" height="2"/>',
            'category_card_style:gradient_mesh' => '<rect class="acc" x="24" y="22" width="112" height="56" rx="8" opacity=".55"/><circle class="soft" cx="112" cy="40" r="22" opacity=".6"/><rect class="ln" x="34" y="62" width="40" height="4" rx="2"/>',
            'category_card_style:glass_card' => '<rect class="soft" x="24" y="22" width="112" height="56" rx="8" opacity=".75"/><rect class="ln" x="34" y="62" width="40" height="4" rx="2"/>',
            'category_card_style:minimal_flat' => '<rect x="24" y="22" width="112" height="56" rx="8" fill="none" stroke="var(--border-strong)" stroke-width="1.4"/><rect class="ln" x="34" y="62" width="40" height="4" rx="2"/>',
            'category_card_style:photo_overlay' => '<rect class="blk" x="24" y="22" width="112" height="56" rx="8"/><rect x="24" y="52" width="112" height="26" fill="var(--text)" opacity=".35"/><rect class="bg" x="34" y="62" width="40" height="4" rx="2"/>',
            'creator_avatar_ring_style:neon_pulse' => '<circle cx="80" cy="50" r="30" fill="none" stroke="var(--accent)" stroke-width="3"/><circle cx="80" cy="50" r="36" fill="none" stroke="var(--accent)" stroke-width="1" opacity=".4"/><circle class="blk" cx="80" cy="50" r="25"/>',
            'creator_avatar_ring_style:gradient_glow' => '<circle cx="80" cy="50" r="30" fill="none" stroke="var(--accent)" stroke-width="4" opacity=".6"/><circle class="blk" cx="80" cy="50" r="25"/>',
            'creator_avatar_ring_style:static_ring' => '<circle cx="80" cy="50" r="28" fill="none" stroke="var(--accent)" stroke-width="2"/><circle class="blk" cx="80" cy="50" r="25"/>',
            'creator_avatar_ring_style:off' => '<circle class="blk" cx="80" cy="50" r="25"/>',
            default => '',
        };

        return $body === '' ? '' : '<svg class="wf" viewBox="0 0 160 100" aria-hidden="true" focusable="false"><rect class="bg" width="160" height="100"/>'.self::compact($body).'</svg>';
    }

    /**
     * Neighbouring plain rectangles of one colour class become one path (same drawing order, square corners - at
     * this size nobody sees the 1px rounding): a few KB less per wireframe.
     */
    private static function compact(string $svg): string
    {
        return (string) preg_replace_callback(
            '/(?:<rect class="(blk|soft|acc|ln)" x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"(?: rx="[\d.]+")?\/>)+/',
            function (array $run): string {
                preg_match_all('/<rect class="(blk|soft|acc|ln)" x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"(?: rx="[\d.]+")?\/>/', $run[0], $rects, PREG_SET_ORDER);
                $out = '';
                $class = null;
                $d = '';

                foreach ($rects as [, $cls, $x, $y, $w, $h]) {
                    if ($cls !== $class && $d !== '') {
                        $out .= '<path class="'.$class.'" d="'.$d.'"/>';
                        $d = '';
                    }

                    $class = $cls;
                    $d .= 'M'.$x.' '.$y.'h'.$w.'v'.$h.'h-'.$w.'z';
                }

                return $out.($d !== '' ? '<path class="'.$class.'" d="'.$d.'"/>' : '');
            },
            $svg,
        );
    }

    /**
     * Rows of video tiles with a title line under each, as two paths (one per colour class): a page carries a dozen
     * of these wireframes, so they stay short.
     */
    private static function grid(float $x, float $y, int $cols, int $rows, float $w, float $gap = 3, ?float $h = null): string
    {
        $h ??= $w * 9 / 16;
        $tiles = '';
        $lines = '';

        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $left = self::n($x + $c * ($w + $gap));
                $top = $y + $r * ($h + 7);
                $tiles .= 'M'.$left.' '.self::n($top).'h'.self::n($w).'v'.self::n($h).'h-'.self::n($w).'z';
                $lines .= 'M'.$left.' '.self::n($top + $h + 1.6).'h'.self::n($w * .8).'v1.8h-'.self::n($w * .8).'z';
            }
        }

        return '<path class="blk" d="'.$tiles.'"/><path class="ln" d="'.$lines.'"/>';
    }

    /** Two ranked lists side by side (Spotlight, or the ticker with rank numbers). */
    private static function rankedLists(float $y, float $height, float $step, bool $ranks = false): string
    {
        $svg = '';

        foreach ([6, 82] as $x) {
            $svg .= '<rect class="soft" x="'.$x.'" y="'.self::n($y).'" width="72" height="'.self::n($height).'" rx="2"/>';

            for ($i = 0; $i < 3; $i++) {
                $top = $y + 3 + $i * $step;
                $thumb = $ranks ? $x + 11 : $x + 4;
                $text = $thumb + ($ranks ? 18 : 16);
                $svg .= $ranks ? '<rect class="acc" x="'.($x + 4).'" y="'.self::n($top + 1).'" width="4" height="4" rx="1"/>' : '';
                $svg .= '<rect class="blk" x="'.$thumb.'" y="'.self::n($top).'" width="'.($ranks ? 15 : 13).'" height="'.($ranks ? 9 : 7.3).'" rx="1"/>';
                $svg .= '<rect class="ln" x="'.$text.'" y="'.self::n($top + 1.5).'" width="'.($ranks ? 36 : 40).'" height="1.8" rx=".9"/>';
                $svg .= '<rect class="ln" x="'.$text.'" y="'.self::n($top + 4.5).'" width="'.($ranks ? 22 : 26).'" height="1.4" rx=".7"/>';
            }
        }

        return $svg;
    }

    /** A row of category chips, the first one active. */
    private static function chips(float $x, float $y, int $count, float $step, float $w, float $h): string
    {
        return self::repeat($count, fn (int $i) => '<rect class="'.($i === 0 ? 'acc' : 'soft').'" x="'.self::n($x + $i * $step).'" y="'.self::n($y).'" width="'.self::n($w).'" height="'.self::n($h).'" rx="'.self::n($h / 2).'"/>');
    }

    private static function repeat(int $count, \Closure $part): string
    {
        $svg = '';

        for ($i = 0; $i < $count; $i++) {
            $svg .= $part($i);
        }

        return $svg;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    /**
     * The 34 font pairings: [label, the families, preview image]. The admin never loads theme fonts: each pairing is a
     * picture of its heading and text faces (scripts/theme_thumbs.js renders them).
     *
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    private static function fontOptions(): array
    {
        $options = [];

        foreach (AppearanceSettings::typographyPairings() as $id => $pairing) {
            $label = (string) ($pairing['label'] ?? $id);
            $families = array_values(array_unique(array_filter([(string) ($pairing['display'] ?? ''), (string) ($pairing['body'] ?? '')])));
            $file = 'images/admin/looks/fonts/'.$id.'.webp';
            $options[$id] = [
                trim(explode(':', $label)[0]),
                implode(' + ', $families),
                is_file(public_path($file)) ? asset($file) : null,
            ];
        }

        return $options;
    }
}
