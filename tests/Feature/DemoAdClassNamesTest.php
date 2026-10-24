<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The demo ad cards (1.2.1): a new install stores them with the class avd-demo-ad. Sites installed before keep their
 * stored codes with the old name avd-test-ad (the seed migrations already ran there and only ever fill empty slots),
 * so the stylesheet styles both names, rule for rule.
 */
class DemoAdClassNamesTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_MIGRATIONS = [
        '2026_05_05_090000_seed_responsive_test_ads',
        '2026_05_05_100000_add_feed_specific_ad_settings',
        '2026_05_05_130000_expand_feed_specific_ad_slots',
    ];

    /** The 18 demo ad slots a new install fills (ad_code_grid is replaced by the per-feed slots). */
    private const DEMO_SLOTS = [
        'ad_code_header', 'ad_code_sidebar', 'ad_code_video', 'ad_code_footer', 'ad_code_mobile',
        'ad_code_grid_smart_discovery', 'ad_code_grid_creator_spotlight', 'ad_code_grid_trending_actors', 'ad_code_grid_new_videos',
        'ad_code_grid_trend_page', 'ad_code_grid_category_page', 'ad_code_grid_subscriptions', 'ad_code_grid_channel_videos',
        'ad_code_grid_search_results', 'ad_code_grid_playlist_videos', 'ad_code_grid_watch_related',
        'ad_code_grid_channel_home_featured', 'ad_code_grid_channel_home_latest',
    ];

    public function test_a_new_install_stores_every_demo_ad_with_the_demo_class_only(): void
    {
        $codes = DB::table('settings')->where('key', 'like', 'ad_code_%')->pluck('value', 'key')->map(fn ($value) => (string) $value);

        foreach (self::DEMO_SLOTS as $key) {
            $this->assertStringContainsString('class="avd-demo-ad avd-demo-ad--', $codes[$key] ?? '', $key);
        }

        foreach ($codes as $key => $value) {
            $this->assertStringNotContainsString('avd-test-ad', $value, "{$key} still uses the old demo class name");
        }

        foreach (self::SEED_MIGRATIONS as $name) {
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*avd-test-ad/', $this->migrationSource($name), $name);
        }
    }

    public function test_the_stylesheet_styles_the_old_and_the_new_class_name_in_the_same_rules(): void
    {
        $pairs = 0;

        foreach ($this->selectorLists($this->appScss()) as $list) {
            foreach ($list as $selector) {
                if (str_contains($selector, '.avd-test-ad')) {
                    $this->assertContains(str_replace('.avd-test-ad', '.avd-demo-ad', $selector), $list, "{$selector} has no avd-demo-ad twin in its rule");
                    $pairs++;
                }

                if (str_contains($selector, '.avd-demo-ad')) {
                    $this->assertContains(str_replace('.avd-demo-ad', '.avd-test-ad', $selector), $list, "{$selector} has no avd-test-ad twin in its rule (sites installed before 1.2.1)");
                }
            }
        }

        $this->assertGreaterThanOrEqual(40, $pairs, 'The demo ad rules of resources/sass/app.scss were not found.');

        // Every part of the demo markup the migrations store is styled under both names.
        preg_match_all('/avd-demo-ad(?:__[a-z]+|--[a-z]+)?/', implode("\n", array_map(fn (string $name): string => $this->migrationSource($name), self::SEED_MIGRATIONS)), $classes);
        $styled = implode("\n", array_merge(...$this->selectorLists($this->appScss())));

        foreach (array_unique($classes[0]) as $class) {
            $legacy = str_replace('avd-demo-ad', 'avd-test-ad', $class);

            if (preg_match('/\.'.preg_quote($legacy, '/').'(?![\w-])/', $styled)) {
                $this->assertMatchesRegularExpression('/\.'.preg_quote($class, '/').'(?![\w-])/', $styled, "{$class} is not styled");
            }
        }
    }

    public function test_the_seed_migrations_keep_the_stored_codes_of_an_existing_site(): void
    {
        $legacy = [];

        foreach (self::DEMO_SLOTS as $key) {
            $legacy[$key] = str_replace('avd-demo-ad', 'avd-test-ad', $this->value($key));
        }

        DB::table('settings')->whereIn('key', array_keys($legacy))->get()->each(function ($row) use ($legacy): void {
            DB::table('settings')->where('id', $row->id)->update(['value' => $legacy[$row->key]]);
        });
        DB::table('settings')->where('key', 'ad_code_footer')->update(['value' => '<div class="my-own-ad">Mine</div>']);
        DB::table('settings')->where('key', 'ad_code_mobile')->update(['value' => '']);

        foreach (self::SEED_MIGRATIONS as $name) {
            $this->migration($name)->up();
        }

        foreach (self::DEMO_SLOTS as $key) {
            match ($key) {
                'ad_code_footer' => $this->assertSame('<div class="my-own-ad">Mine</div>', $this->value($key)),
                // An empty slot is a slot the seed may fill: it gets the new name.
                'ad_code_mobile' => $this->assertStringContainsString('class="avd-demo-ad avd-demo-ad--mobile"', $this->value($key)),
                default => $this->assertSame($legacy[$key], $this->value($key), "{$key} was rewritten"),
            };
        }
    }

    public function test_rolling_the_seed_migrations_back_also_removes_the_old_demo_codes(): void
    {
        foreach (['ad_code_header', 'ad_code_sidebar', 'ad_code_grid_smart_discovery', 'ad_code_grid_new_videos'] as $key) {
            DB::table('settings')->where('key', $key)->update(['value' => str_replace('avd-demo-ad', 'avd-test-ad', $this->value($key))]);
        }
        DB::table('settings')->where('key', 'ad_code_video')->update(['value' => '<div class="my-own-ad">Mine</div>']);

        $this->migration('2026_05_05_100000_add_feed_specific_ad_settings')->down();
        $this->migration('2026_05_05_090000_seed_responsive_test_ads')->down();

        $this->assertSame('', $this->value('ad_code_header'));
        $this->assertSame('', $this->value('ad_code_sidebar'));
        $this->assertNull(DB::table('settings')->where('key', 'ad_code_grid_smart_discovery')->value('id'));
        $this->assertNull(DB::table('settings')->where('key', 'ad_code_grid_new_videos')->value('id'));
        $this->assertSame('<div class="my-own-ad">Mine</div>', $this->value('ad_code_video'));
        // Demo codes with the new name roll back the same way.
        $this->assertSame('', $this->value('ad_code_footer'));
    }

    private function value(string $key): string
    {
        return (string) DB::table('settings')->where('key', $key)->value('value');
    }

    private function migration(string $name): Migration
    {
        return require database_path("migrations/{$name}.php");
    }

    private function migrationSource(string $name): string
    {
        return (string) file_get_contents(database_path("migrations/{$name}.php"));
    }

    private function appScss(): string
    {
        return (string) file_get_contents(resource_path('sass/app.scss'));
    }

    /**
     * The selector list of every rule (comments removed, nesting not resolved: the demo ad rules are flat).
     *
     * @return list<list<string>>
     */
    private function selectorLists(string $scss): array
    {
        $scss = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m', '/#\{[^}]*\}/'], ['', '', 'x'], $scss);
        preg_match_all('/([^{};]+)\{/', $scss, $matches);

        return array_values(array_filter(array_map(
            fn (string $prelude): array => array_values(array_filter(array_map('trim', explode(',', $prelude)), fn (string $one): bool => $one !== '')),
            $matches[1]
        ), fn (array $list): bool => $list !== [] && ! str_starts_with($list[0], '@')));
    }
}
