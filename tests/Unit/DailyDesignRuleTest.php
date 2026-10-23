<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Support\AppearanceSettings;
use App\Support\DailyDesignCatalog;
use App\Support\ThemeCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use ReflectionProperty;
use Tests\Support\CreatesAdminSettingsSchema;
use Tests\TestCase;

/**
 * The Daily Design rule against the redesign's reference implementation (spec/theme/scripts/daily_rule.py)
 * and its golden year (spec/theme/work/daily_2026.json).
 */
class DailyDesignRuleTest extends TestCase
{
    use CreatesAdminSettingsSchema;

    // daily_2026.json, base theme tokyo_neon: per scope the days per theme, the 12 most used looks, the
    // holidays (date => [holiday, theme, colour style]) and the first 14 days.
    private const GOLDEN_2026 = [
            'palette_only' => [
                'theme_counts' => ['tokyo_neon' => 365],
                'flavor_counts' => ['tokyo_neon/emerald' => 54, 'tokyo_neon/signature' => 54, 'tokyo_neon/coral' => 54, 'tokyo_neon/volt' => 51, 'tokyo_neon/electric' => 51, 'tokyo_neon/ultraviolet' => 51, 'tokyo_neon/sunset' => 50],
                'holidays' => [
                    '2026-01-01' => ["New Year's Day", 'tokyo_neon', 'volt'],
                    '2026-01-19' => ['Martin Luther King Jr. Day', 'tokyo_neon', 'electric'],
                    '2026-02-14' => ["Valentine's Day", 'tokyo_neon', 'signature'],
                    '2026-03-08' => ["International Women's Day", 'tokyo_neon', 'ultraviolet'],
                    '2026-03-17' => ["St. Patrick's Day", 'tokyo_neon', 'emerald'],
                    '2026-03-20' => ['Spring Equinox', 'tokyo_neon', 'signature'],
                    '2026-04-01' => ["April Fools' Day", 'tokyo_neon', 'signature'],
                    '2026-04-22' => ['Earth Day', 'tokyo_neon', 'emerald'],
                    '2026-05-04' => ['Star Wars Day', 'tokyo_neon', 'electric'],
                    '2026-05-25' => ['Memorial Day', 'tokyo_neon', 'electric'],
                    '2026-06-21' => ['Summer Solstice', 'tokyo_neon', 'sunset'],
                    '2026-07-04' => ['Independence Day', 'tokyo_neon', 'coral'],
                    '2026-08-12' => ['Perseid Meteor Shower', 'tokyo_neon', 'ultraviolet'],
                    '2026-09-01' => ['Autumn Preview', 'tokyo_neon', 'sunset'],
                    '2026-09-22' => ['Autumn Equinox', 'tokyo_neon', 'sunset'],
                    '2026-10-31' => ['Halloween', 'tokyo_neon', 'sunset'],
                    '2026-11-11' => ['Veterans Day', 'tokyo_neon', 'volt'],
                    '2026-11-26' => ['Thanksgiving', 'tokyo_neon', 'sunset'],
                    '2026-11-27' => ['Black Friday', 'tokyo_neon', 'coral'],
                    '2026-12-24' => ['Christmas Eve', 'tokyo_neon', 'emerald'],
                    '2026-12-25' => ['Christmas Day', 'tokyo_neon', 'coral'],
                    '2026-12-31' => ["New Year's Eve", 'tokyo_neon', 'volt'],
                ],
                'first_14_days' => ['tokyo_neon/volt', 'tokyo_neon/emerald', 'tokyo_neon/signature', 'tokyo_neon/coral', 'tokyo_neon/electric', 'tokyo_neon/sunset', 'tokyo_neon/ultraviolet', 'tokyo_neon/volt', 'tokyo_neon/emerald', 'tokyo_neon/signature', 'tokyo_neon/coral', 'tokyo_neon/electric', 'tokyo_neon/sunset', 'tokyo_neon/ultraviolet'],
            ],
            'full' => [
                'theme_counts' => ['tokyo_neon' => 42, 'matcha_zen' => 25, 'sahara_dune' => 25, 'amazon_rainforest' => 24, 'silver_screen' => 22, 'interstellar' => 14, 'broadsheet' => 14, 'pure_oled_black' => 13, 'synthwave_sunset' => 13, 'bubblegum_pop' => 12, 'neo_pop' => 12, 'vaporwave_mall' => 12, 'deep_trench' => 12, 'obsidian_gold' => 11, 'daylight_paper' => 11, 'lavender_cloud' => 11, 'shibuya_rain' => 11, 'cyber_gladiator' => 11, 'ivory_salon' => 11, 'aurora_borealis' => 10, 'rose_gold_velvet' => 10, 'holo_pearl' => 10, 'overwatch_pulse' => 10, 'nordic_minimal' => 9, 'fairy_floss' => 9, 'swiss_monolith' => 1],
                'flavor_counts' => ['vaporwave_mall/signature' => 12, 'synthwave_sunset/signature' => 12, 'holo_pearl/aqua' => 10, 'tokyo_neon/volt' => 7, 'tokyo_neon/signature' => 7, 'amazon_rainforest/signature' => 7, 'matcha_zen/indigo' => 6, 'tokyo_neon/emerald' => 6, 'matcha_zen/sakura' => 6, 'tokyo_neon/sunset' => 6, 'tokyo_neon/ultraviolet' => 6, 'sahara_dune/ochre' => 6],
                'holidays' => [
                    '2026-01-01' => ["New Year's Day", 'obsidian_gold', 'signature'],
                    '2026-01-19' => ['Martin Luther King Jr. Day', 'broadsheet', 'navy'],
                    '2026-02-14' => ["Valentine's Day", 'rose_gold_velvet', 'crimson'],
                    '2026-03-08' => ["International Women's Day", 'lavender_cloud', 'signature'],
                    '2026-03-17' => ["St. Patrick's Day", 'amazon_rainforest', 'signature'],
                    '2026-03-20' => ['Spring Equinox', 'matcha_zen', 'sakura'],
                    '2026-04-01' => ["April Fools' Day", 'neo_pop', 'signature'],
                    '2026-04-22' => ['Earth Day', 'amazon_rainforest', 'jade'],
                    '2026-05-04' => ['Star Wars Day', 'interstellar', 'signature'],
                    '2026-05-25' => ['Memorial Day', 'broadsheet', 'navy'],
                    '2026-06-21' => ['Summer Solstice', 'synthwave_sunset', 'signature'],
                    '2026-07-04' => ['Independence Day', 'swiss_monolith', 'bauhaus'],
                    '2026-08-12' => ['Perseid Meteor Shower', 'interstellar', 'event_horizon'],
                    '2026-09-01' => ['Autumn Preview', 'sahara_dune', 'signature'],
                    '2026-09-22' => ['Autumn Equinox', 'matcha_zen', 'kin'],
                    '2026-10-31' => ['Halloween', 'synthwave_sunset', 'tangerine'],
                    '2026-11-11' => ['Veterans Day', 'silver_screen', 'signature'],
                    '2026-11-26' => ['Thanksgiving', 'sahara_dune', 'ochre'],
                    '2026-11-27' => ['Black Friday', 'pure_oled_black', 'signature'],
                    '2026-12-24' => ['Christmas Eve', 'amazon_rainforest', 'signature'],
                    '2026-12-25' => ['Christmas Day', 'pure_oled_black', 'signature'],
                    '2026-12-31' => ["New Year's Eve", 'obsidian_gold', 'signature'],
                ],
                'first_14_days' => ['obsidian_gold/signature', 'interstellar/event_horizon', 'pure_oled_black/crimson', 'silver_screen/signature', 'nordic_minimal/frost', 'obsidian_gold/rose', 'aurora_borealis/arctic', 'matcha_zen/kin', 'tokyo_neon/volt', 'interstellar/mars', 'pure_oled_black/violet', 'silver_screen/curtain', 'nordic_minimal/moss', 'obsidian_gold/emerald'],
            ],
    ];

    // daily_rule.py's whole years: md5 of one "Y-m-d theme/flavor holiday" line per day ('-' without a
    // holiday) joined by "\n"; "all 32 themes" is the md5 of the 32 palette_only digests in catalogue order.
    private const REFERENCE_DIGESTS = [
        '2026 full' => '0773d78b2a03ede55c099cd2008b8858',
        '2026 palette_only tokyo_neon' => 'f29b7cd2058712bacb7cc658c54e2e93',
        '2026 palette_only all 32 themes' => 'bc647e73f90eb2fb1e4b6ac79529d57d',
        '2028 full' => 'b51457cb49cfaf44ddab6fe75cc4a981',
        '2028 palette_only tokyo_neon' => '6606aa350146b9532ef3ec4a898a2a52',
        '2028 palette_only all 32 themes' => '1872fdc9367c90d58783414170caa0c0',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshAdminSettingsSchema();
        DailyDesignCatalog::flush();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropAdminSettingsSchema();
        Cache::flush();

        parent::tearDown();
    }

    public function test_2026_reproduces_the_golden_year_in_both_scopes(): void
    {
        foreach (self::GOLDEN_2026 as $scope => $golden) {
            $year = DailyDesignCatalog::schedule(2026, $scope, 'tokyo_neon');
            $themes = [];
            $looks = [];
            $holidays = [];

            foreach ($year as $i => $pick) {
                $look = $pick['theme'] . '/' . $pick['flavor'];
                $themes[$pick['theme']] = ($themes[$pick['theme']] ?? 0) + 1;
                $looks[$look] = ($looks[$look] ?? 0) + 1;

                if ($pick['holiday'] !== null) {
                    $holidays[$this->date(2026, $i)] = [$pick['holiday'], $pick['theme'], $pick['flavor']];
                }
            }

            ksort($themes);
            $goldenThemes = $golden['theme_counts'];
            ksort($goldenThemes);

            $this->assertCount(365, $year, $scope);
            $this->assertSame($goldenThemes, $themes, $scope);
            $this->assertEquals($golden['flavor_counts'], array_intersect_key($looks, $golden['flavor_counts']), $scope);
            $this->assertSame($golden['holidays'], $holidays, $scope);
            $this->assertSame($golden['first_14_days'], array_map(fn (array $pick): string => $pick['theme'] . '/' . $pick['flavor'], array_slice($year, 0, 14)), $scope);
        }
    }

    public function test_whole_years_match_the_reference_implementation(): void
    {
        foreach ([2026, 2028] as $year) {
            $this->assertSame(self::REFERENCE_DIGESTS["$year full"], $this->digest($year, 'full', 'tokyo_neon'));
            $this->assertSame(self::REFERENCE_DIGESTS["$year palette_only tokyo_neon"], $this->digest($year, 'palette_only', 'tokyo_neon'));

            $themes = array_map(fn (string $theme): string => $this->digest($year, 'palette_only', $theme), DailyDesignCatalog::themeIds());
            $this->assertSame(self::REFERENCE_DIGESTS["$year palette_only all 32 themes"], md5(implode('', $themes)));
        }
    }

    public function test_every_day_uses_catalogue_ids_and_repeats_a_look_only_on_adjacent_holidays(): void
    {
        $catalogue = $this->catalogueFlavors();
        $this->assertCount(32, $catalogue);
        $problems = [];

        foreach ([2026, 2027, 2028] as $year) {
            foreach ([DailyDesignCatalog::SCOPE_PALETTE => DailyDesignCatalog::themeIds(), DailyDesignCatalog::SCOPE_FULL => ['tokyo_neon']] as $scope => $bases) {
                foreach ($bases as $base) {
                    $previous = null;

                    foreach (DailyDesignCatalog::schedule($year, $scope, $base) as $i => $pick) {
                        $where = "$year $scope $base " . $this->date($year, $i);

                        if (!in_array($pick['flavor'], $catalogue[$pick['theme']] ?? [], true)) {
                            $problems[] = "$where: unknown look {$pick['theme']}/{$pick['flavor']}";
                        }

                        if ($scope === DailyDesignCatalog::SCOPE_PALETTE && $pick['theme'] !== $base) {
                            $problems[] = "$where: the owner's theme changed to {$pick['theme']}";
                        }

                        $same = $previous !== null && $previous['theme'] === $pick['theme']
                            && ($scope === DailyDesignCatalog::SCOPE_FULL || $previous['flavor'] === $pick['flavor']);

                        if ($same && ($previous['holiday'] === null || $pick['holiday'] === null)) {
                            $problems[] = "$where: same look as the day before ({$pick['theme']}/{$pick['flavor']})";
                        }

                        $previous = $pick;
                    }
                }
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_palette_only_keeps_the_owners_theme_and_layout(): void
    {
        $settings = [
            'daily_design_scope' => 'palette_only',
            'daily_design_layouts_enabled' => '1',
            'theme_preset' => 'daylight_paper',
            'home_layout_mode' => 'bento_mosaic',
            'surface_style' => 'solid',
        ];

        $halloween = DailyDesignCatalog::forDay(304, null, $settings, 2026);

        $this->assertSame(['2026-10-31', 'Halloween', 'daylight_paper'], [$halloween['date'], $halloween['holiday'], $halloween['theme']]);
        $this->assertSame(DailyDesignCatalog::schedule(2026, 'palette_only', 'daylight_paper')[303]['flavor'], $halloween['flavor']);
        $this->assertNull($halloween['layout']);
        $this->assertSame(['bento_mosaic', 'solid'], [$halloween['home_layout_mode'], $halloween['surface_style']]);
    }

    public function test_full_scope_changes_the_homepage_layout_only_with_daily_layouts(): void
    {
        $settings = ['daily_design_scope' => 'full', 'theme_preset' => 'tokyo_neon', 'home_layout_mode' => 'grid_modern'];

        $newYear = DailyDesignCatalog::forDay(1, null, $settings, 2026);
        $this->assertSame(['obsidian_gold', 'signature', "New Year's Day"], [$newYear['theme'], $newYear['flavor'], $newYear['holiday']]);
        $this->assertNull($newYear['layout']);
        $this->assertSame('grid_modern', $newYear['home_layout_mode']);

        $withLayouts = DailyDesignCatalog::forDay(1, null, ['daily_design_layouts_enabled' => '1'] + $settings, 2026);
        $this->assertSame(['bento_mosaic', 'bento_mosaic'], [$withLayouts['layout'], $withLayouts['home_layout_mode']]);

        // The scope argument wins over the settings.
        $this->assertSame('tokyo_neon', DailyDesignCatalog::forDay(1, 'palette_only', $settings, 2026)['theme']);
        $this->assertSame('palette_only', DailyDesignCatalog::forDay(1, 'anything else', $settings, 2026)['scope']);
    }

    public function test_day_numbers_months_and_the_v1_entry_shape(): void
    {
        $settings = ['daily_design_scope' => 'palette_only', 'theme_preset' => 'tokyo_neon'];
        $first = DailyDesignCatalog::forDay(1, null, $settings, 2026);

        foreach (['theme', 'flavor', 'layout', 'label', 'holiday', 'date', 'year', 'scope', 'reason', 'theme_name', 'flavor_name',
            'day', 'month', 'day_of_month', 'date_label', 'name', 'season_mood', 'primary', 'secondary', 'accent', 'home_layout_mode',
            'surface_style', 'radius_scale', 'font_family', 'header_style', 'category_card_style', 'creator_avatar_ring_style',
            'video_card_duration_style', 'watch_ambient_glow_intensity', 'scrollbar_style'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        $this->assertSame(['2026-01-01', 1, 1, 1, 'Jan 01', 'Winter'], [$first['date'], $first['day'], $first['month'], $first['day_of_month'], $first['date_label'], $first['season_mood']]);
        $this->assertSame(['Midnight Spotlight · Volt', 'Midnight Spotlight · Volt'], [$first['label'], $first['name']]);
        $this->assertSame(['#b8f400', '#00c2a8', '#7cf9ff'], [$first['primary'], $first['secondary'], $first['accent']]);

        // Day 366 of a common year is the next 1 January; out-of-range days are clamped.
        $last = DailyDesignCatalog::forDay(366, null, $settings, 2026);
        $this->assertSame(['2027-01-01', 366, 2027], [$last['date'], $last['day'], $last['year']]);
        $this->assertSame('2028-12-31', DailyDesignCatalog::forDay(999, null, $settings, 2028)['date']);
        $this->assertSame(1, DailyDesignCatalog::forDay(0, null, $settings, 2026)['day']);

        $this->assertSame(range(1, 365), array_keys(DailyDesignCatalog::all(null, $settings, 2026)));
        $this->assertSame(range(1, 366), array_keys(DailyDesignCatalog::all(null, $settings, 2028)));

        $february = DailyDesignCatalog::forMonth(2, null, $settings, 2028);
        $this->assertSame(range(32, 60), array_keys($february));
        $this->assertSame('Leap Day Special', $february[60]['holiday']);
        $this->assertSame(28, DailyDesignCatalog::months(2026)[2]['days_count']);
        $this->assertSame(['number' => 2, 'name' => 'February', 'short' => 'Feb', 'days_count' => 29], DailyDesignCatalog::months(2028)[2]);
    }

    public function test_old_and_unknown_theme_ids_keep_a_catalogue_theme(): void
    {
        $this->assertSame('shibuya_rain', DailyDesignCatalog::baseTheme('shibuya_rain'));
        $this->assertSame('tokyo_neon', DailyDesignCatalog::baseTheme(null));
        $this->assertSame('tokyo_neon', DailyDesignCatalog::baseTheme('cyberpunk_neon'));
        $this->assertSame('tokyo_neon', DailyDesignCatalog::baseTheme('no_such_theme'));

        if (method_exists(ThemeCatalog::class, 'resolve')) {
            $this->assertSame('fairy_floss', DailyDesignCatalog::baseTheme('sakura_blossom'));
        }

        $this->assertSame(DailyDesignCatalog::schedule(2026), DailyDesignCatalog::schedule(2026, 'palette_only', 'no_such_theme'));
    }

    public function test_today_the_override_day_and_the_preview_parameter_reach_the_active_design(): void
    {
        // Noon of the real date, so a resolver counting days with date() and the catalogue's now() agree.
        Carbon::setTestNow(Carbon::today()->setTime(12, 0));
        $year = now()->year;
        $stored = [
            'theme_preset' => 'tokyo_neon',
            'daily_design_scope' => 'palette_only',
            'daily_design_auto_enabled' => '1',
            'daily_design_override_day' => '0',
        ];

        foreach ($stored as $key => $value) {
            Setting::set($key, $value, 'appearance');
        }

        $settings = array_replace(AppearanceSettings::defaults(), $stored);
        $rule = fn (array $day): array => array_intersect_key($day, array_flip(['date', 'day', 'theme', 'flavor', 'label', 'holiday']));

        $this->app->instance('request', Request::create('/'));
        $today = AppearanceSettings::resolveActiveDesign($settings)['_active_daily_design'];
        $this->assertSame([now()->toDateString(), now()->dayOfYear], [$today['date'], $today['day']]);
        $this->assertSame($rule(DailyDesignCatalog::today(null, $settings)), $rule($today));

        $christmas = (string) Carbon::create($year, 12, 25)->dayOfYear;
        $override = AppearanceSettings::resolveActiveDesign(['daily_design_override_day' => $christmas] + $settings)['_active_daily_design'];
        $this->assertSame(["$year-12-25", 'Christmas Day'], [$override['date'], $override['holiday']]);

        $this->app->instance('request', Request::create('/', 'GET', ['daily_design' => (string) Carbon::create($year, 10, 31)->dayOfYear]));
        $preview = AppearanceSettings::resolveActiveDesign(['daily_design_auto_enabled' => '0'] + $settings)['_active_daily_design'];
        $this->assertSame(["$year-10-31", 'Halloween'], [$preview['date'], $preview['holiday']]);
    }

    public function test_tomorrow_follows_the_calendar_across_the_year_end(): void
    {
        $date = fn (array $entry): string => (string) $entry['date'];
        $after = fn (int $day, int $year): string => $date(DailyDesignCatalog::tomorrow(DailyDesignCatalog::forDay($day, 'palette_only', 'tokyo_neon', $year), 'palette_only', 'tokyo_neon'));

        // 31 December of a leap year is day 366: the day after is 1 January of the next year, not of the same year.
        $this->assertSame('2029-01-01', $after(366, 2028));
        $this->assertSame('2028-12-31', $after(365, 2028));
        $this->assertSame('2027-01-01', $after(365, 2026));
        $this->assertSame('2026-04-11', $after(100, 2026));
        $this->assertSame([365, 366], [DailyDesignCatalog::daysInYear(2026), DailyDesignCatalog::daysInYear(2028)]);

        Carbon::setTestNow(Carbon::create(2028, 12, 31, 12));
        $this->assertSame('2029-01-01', $date(DailyDesignCatalog::tomorrow(null, 'palette_only', 'tokyo_neon')));
        $this->assertSame(366, DailyDesignCatalog::daysInYear());
        Carbon::setTestNow();
    }

    public function test_the_daily_design_api_follows_the_calendar_year(): void
    {
        // routes/api.php: the /today teaser is DailyDesignCatalog::tomorrow() (after the last day of a leap year comes
        // 1 January of the next year, not day 1 of the same year) and /catalog counts the days of this year.
        Carbon::setTestNow(Carbon::create(2028, 12, 31, 12));
        $today = $this->getJson('/api/daily-design/today')->assertOk()->json();
        $this->assertSame([366, 1, 'Jan 01'], [$today['today']['day'], $today['tomorrow_teaser']['day'], $today['tomorrow_teaser']['date_label']]);
        $this->assertSame(366, $this->getJson('/api/daily-design/catalog')->assertOk()->json('total_days'));

        Carbon::setTestNow(Carbon::create(2026, 6, 1, 12));
        $catalog = $this->getJson('/api/daily-design/catalog')->assertOk()->json();
        $this->assertSame(365, $catalog['total_days']);
        $this->assertCount(365, $catalog['designs']);
    }

    public function test_the_owners_theme_id_alone_works_as_the_context(): void
    {
        // AppearanceSettings v2 passes the owner's resolved theme id instead of the settings array.
        $byId = DailyDesignCatalog::forDay(304, 'palette_only', 'daylight_paper', 2026);
        $bySettings = DailyDesignCatalog::forDay(304, 'palette_only', ['theme_preset' => 'daylight_paper'], 2026);

        $this->assertSame([$bySettings['theme'], $bySettings['flavor'], $bySettings['label']], [$byId['theme'], $byId['flavor'], $byId['label']]);
        $this->assertSame('tokyo_neon', DailyDesignCatalog::forDay(304, 'palette_only', 'cyberpunk_neon', 2026)['theme']);
        $this->assertSame('obsidian_gold', DailyDesignCatalog::forDay(1, 'full', 'daylight_paper', 2026)['theme']);
        $this->assertSame(DailyDesignCatalog::forDay(1, 'full', 'daylight_paper', 2026)['flavor'], DailyDesignCatalog::forMonth(1, 'full', 'daylight_paper', 2026)[1]['flavor']);
    }

    public function test_a_day_is_cached_and_computed_once_per_year(): void
    {
        $settings = ['daily_design_scope' => 'full', 'theme_preset' => 'tokyo_neon'];
        $day = DailyDesignCatalog::forDay(45, null, $settings, 2026);

        $this->assertSame(
            ['theme' => $day['theme'], 'flavor' => $day['flavor'], 'holiday' => $day['holiday'], 'reason' => $day['reason']],
            Cache::get('daily_design.v2.2026-02-14.full.tokyo_neon')
        );

        // A cached day is answered without computing its year again.
        DailyDesignCatalog::flush();
        $this->assertSame($day, DailyDesignCatalog::forDay(45, null, $settings, 2026));
        $this->assertSame([], (new ReflectionProperty(DailyDesignCatalog::class, 'schedules'))->getValue());

        // A cached pick the catalogue does not know (an older release, a broken value) is computed again.
        Cache::put('daily_design.v2.2026-02-14.full.tokyo_neon', ['theme' => 'retired_theme', 'flavor' => 'gone'], 60);
        $this->assertSame($day, DailyDesignCatalog::forDay(45, null, $settings, 2026));
        Cache::put('daily_design.v2.2026-02-14.full.tokyo_neon', 'broken', 60);
        $this->assertSame($day, DailyDesignCatalog::forDay(45, null, $settings, 2026));
    }

    private function digest(int $year, string $scope, string $theme): string
    {
        $lines = [];

        foreach (DailyDesignCatalog::schedule($year, $scope, $theme) as $i => $pick) {
            $lines[] = sprintf('%s %s/%s %s', $this->date($year, $i), $pick['theme'], $pick['flavor'], $pick['holiday'] ?? '-');
        }

        return md5(implode("\n", $lines));
    }

    private function date(int $year, int $index): string
    {
        return gmdate('Y-m-d', gmmktime(0, 0, 0, 1, 1 + $index, $year));
    }

    /**
     * Theme => colour style ids of the theme catalogue when it lists them (v2), else the rule's own table.
     *
     * @return array<string, list<string>>
     */
    private function catalogueFlavors(): array
    {
        $catalogue = [];

        foreach (DailyDesignCatalog::themeIds() as $theme) {
            $catalogue[$theme] = DailyDesignCatalog::flavorIds($theme);

            if (method_exists(ThemeCatalog::class, 'flavors')) {
                $listed = (array) ThemeCatalog::flavors($theme);
                $catalogue[$theme] = array_map(
                    fn ($flavor, $key): string => is_array($flavor) ? (string) ($flavor['id'] ?? $key) : (is_int($key) ? (string) $flavor : (string) $key),
                    $listed,
                    array_keys($listed)
                );
            }
        }

        return $catalogue;
    }
}
