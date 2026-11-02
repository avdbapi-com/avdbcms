<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration 2026_10_25_120000_add_homepage_feed_list_ad: the homepage feed's list ad of an existing site. A site whose
 * Classic grid homepage list ("New videos") has an ad code gets the same code and shape for the homepage feed, once:
 * only into an empty field nobody changed. The owner's own code stays, a code the owner cleared stays cleared (also
 * when the migration runs again), and a site without a New videos code gets the empty field.
 */
class HomepageFeedListAdMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_25_120000_add_homepage_feed_list_ad';

    private const CODE = 'ad_code_grid_home_feed';

    private const SHAPE = 'ad_grid_layout_home_feed';

    private const AUDIT = 'update.homepage_feed_list_ad';

    private const SITE_AD = '<a class="site-own-ad" href="https://ads.example.com/zone/42">Sponsor</a>';

    private const OWNER_AD = '<div class="owner-homepage-ad">Owner</div>';

    private static ?object $migration = null;

    private function migration(): object
    {
        return self::$migration ??= require database_path('migrations/'.self::MIGRATION.'.php');
    }

    /** A 1.2.2 site: no homepage feed rows yet, New videos as given (null = no rows), written two days ago. */
    private function site122(?string $newVideosCode, ?string $newVideosShape = 'inherit'): void
    {
        DB::table('settings')->whereIn('key', [self::CODE, self::SHAPE, 'ad_code_grid_new_videos', 'ad_grid_layout_new_videos'])->delete();
        DB::table('admin_audit_logs')->where('action', self::AUDIT)->delete();
        $then = now()->subDays(2);

        foreach (['ad_code_grid_new_videos' => $newVideosCode, 'ad_grid_layout_new_videos' => $newVideosShape] as $key => $value) {
            if ($value !== null) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'group' => 'monetization', 'created_at' => $then, 'updated_at' => $then]);
            }
        }

        Setting::clearCache();
    }

    /** @return array{0: ?string, 1: ?string} the stored homepage feed code and shape (null = no row) */
    private function homepageFeed(): array
    {
        $rows = DB::table('settings')->whereIn('key', [self::CODE, self::SHAPE])->pluck('value', 'key');

        return [$rows[self::CODE] ?? null, $rows[self::SHAPE] ?? null];
    }

    private function audits(): int
    {
        return DB::table('admin_audit_logs')->where('action', self::AUDIT)->count();
    }

    public function test_a_site_built_from_the_migrations_alone_has_the_demo_ad_on_the_homepage_feed(): void
    {
        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());

        $newVideos = (string) DB::table('settings')->where('key', 'ad_code_grid_new_videos')->value('value');
        $this->assertStringContainsString('avd-demo-ad', $newVideos, 'the demo ad of the migrations');
        $this->assertSame([$newVideos, (string) DB::table('settings')->where('key', 'ad_grid_layout_new_videos')->value('value')], $this->homepageFeed());
        $this->assertSame('monetization', DB::table('settings')->where('key', self::CODE)->value('group'));
        $this->assertSame(0, $this->audits(), 'the bundled demo card is no news for the owner of a new site');
        $this->assertSame(0, DB::table('admin_audit_logs')->count(), 'a new site starts with an empty Admin actions list');
    }

    public function test_the_demo_card_is_the_one_the_migrations_store(): void
    {
        $seed = (string) file_get_contents(database_path('migrations/2026_05_05_100000_add_feed_specific_ad_settings.php'));
        $this->assertSame(1, preg_match("/'ad_code_grid_new_videos' => <<<'HTML'\n(.*?)\nHTML,/s", str_replace("\r\n", "\n", $seed), $sample));
        $this->assertSame($sample[1], $this->migration()::DEMO_CODE);
    }

    public function test_the_bundled_demo_card_is_copied_without_an_admin_actions_row(): void
    {
        $demo = $this->migration()::DEMO_CODE;
        $old = str_replace('avd-demo-ad', 'avd-test-ad', $demo);

        foreach ([$demo, $old, "\r\n".$old."\r\n"] as $code) {
            $this->site122($code, 'inherit');
            $this->migration()->up();

            $this->assertSame([$code, 'inherit'], $this->homepageFeed(), 'the demo ad shows on the homepage feed too');
            $this->assertSame(0, $this->audits());
        }

        // A demo card the owner changed is their own ad now: the copy is written down.
        $this->site122(str_replace('New Videos sponsor', 'Our premium plans', $old), 'grid_card');
        $this->migration()->up();
        $this->assertSame(1, $this->audits());
    }

    public function test_a_site_with_a_new_videos_code_gets_it_on_the_homepage_feed(): void
    {
        $this->site122(self::SITE_AD, 'wide_row');
        $this->assertNull(Setting::getGroup('monetization')[self::CODE] ?? null, 'cached before the update');

        $this->migration()->up();

        $this->assertSame([self::SITE_AD, 'wide_row'], $this->homepageFeed());
        $this->assertSame(self::SITE_AD, Setting::getGroup('monetization')[self::CODE] ?? null, 'the settings cache was cleared');
        $this->assertSame(1, $this->audits());
        $this->assertSame(
            'Money > Ads: the homepage feed got the ad code and shape of the Classic grid homepage list (New videos)',
            DB::table('admin_audit_logs')->where('action', self::AUDIT)->value('details')
        );
        $this->assertLessThanOrEqual(120, strlen((string) DB::table('admin_audit_logs')->where('action', self::AUDIT)->value('details')), 'fits the old admin list');
    }

    public function test_a_site_without_a_new_videos_code_gets_the_empty_field(): void
    {
        foreach (['' => 'inherit', '   ' => 'wide_row'] as $code => $shape) {
            $this->site122($code, $shape);
            $this->migration()->up();

            $this->assertSame(['', 'inherit'], $this->homepageFeed(), 'no code to copy: the field is empty, its shape "Same as above"');
            $this->assertSame(0, $this->audits());
        }

        $this->site122(null, null);
        $this->migration()->up();
        $this->assertSame(['', 'inherit'], $this->homepageFeed(), 'a site that never had New videos rows');
        $this->assertSame(0, $this->audits());
    }

    public function test_the_owner_code_on_the_homepage_feed_stays(): void
    {
        $this->site122(self::SITE_AD, 'wide_row');
        $then = now()->subDay();
        DB::table('settings')->insert([
            ['key' => self::CODE, 'value' => self::OWNER_AD, 'group' => 'monetization', 'created_at' => $then, 'updated_at' => $then],
            ['key' => self::SHAPE, 'value' => 'grid_card', 'group' => 'monetization', 'created_at' => $then, 'updated_at' => $then],
        ]);
        $before = DB::table('settings')->whereIn('key', [self::CODE, self::SHAPE])->orderBy('key')->get()->map(fn ($row) => (array) $row)->all();

        $this->migration()->up();

        $this->assertSame($before, DB::table('settings')->whereIn('key', [self::CODE, self::SHAPE])->orderBy('key')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(0, $this->audits());
    }

    public function test_a_code_the_owner_cleared_stays_cleared_when_this_runs_again(): void
    {
        $this->site122(self::SITE_AD, 'wide_row');
        $this->migration()->up();
        $this->assertSame([self::SITE_AD, 'wide_row'], $this->homepageFeed());

        // Later the owner empties the field and picks a card on Money > Ads (Setting::set, as the save does).
        $this->travel(2)->hours();
        Setting::set(self::CODE, '', 'monetization');
        Setting::set(self::SHAPE, 'grid_card', 'monetization');

        $this->travel(1)->days();
        $this->migration()->up();

        $this->assertSame(['', 'grid_card'], $this->homepageFeed());
        $this->assertSame(1, $this->audits(), 'copied once');
    }

    public function test_an_empty_field_nobody_changed_gets_the_copy(): void
    {
        // Money > Ads saved between the file swap and "Finish update": the page wrote the new fields as they were.
        $this->site122(self::SITE_AD, 'grid_card');
        Setting::set(self::CODE, '', 'monetization');
        Setting::set(self::SHAPE, 'inherit', 'monetization');
        $this->travel(5)->minutes();

        $this->migration()->up();

        $this->assertSame([self::SITE_AD, 'grid_card'], $this->homepageFeed());
        $this->assertSame(1, $this->audits());
    }

    public function test_a_shape_the_owner_chose_stays_when_the_code_is_copied(): void
    {
        $this->site122(self::SITE_AD, 'wide_row');
        Setting::set(self::CODE, '', 'monetization');
        Setting::set(self::SHAPE, 'inherit', 'monetization');
        $this->travel(1)->hours();
        Setting::set(self::SHAPE, 'grid_card', 'monetization');
        $this->travel(1)->hours();

        $this->migration()->up();

        $this->assertSame([self::SITE_AD, 'grid_card'], $this->homepageFeed());

        // Saved once with "Full-width row" before "Finish update" (written, never changed since): somebody chose it.
        $this->site122(self::SITE_AD, 'grid_card');
        Setting::set(self::CODE, '', 'monetization');
        Setting::set(self::SHAPE, 'wide_row', 'monetization');
        $this->travel(1)->hours();

        $this->migration()->up();

        $this->assertSame([self::SITE_AD, 'wide_row'], $this->homepageFeed());
    }

    public function test_running_twice_changes_nothing_more(): void
    {
        $this->site122(self::SITE_AD, 'wide_row');
        $this->migration()->up();
        $after = DB::table('settings')->whereIn('key', [self::CODE, self::SHAPE])->orderBy('key')->get()->map(fn ($row) => (array) $row)->all();

        $this->travel(3)->days();
        $this->migration()->up();

        $this->assertSame($after, DB::table('settings')->whereIn('key', [self::CODE, self::SHAPE])->orderBy('key')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(1, DB::table('settings')->where('key', self::CODE)->count(), 'one row per key');
        $this->assertSame(1, DB::table('settings')->where('key', self::SHAPE)->count());
        $this->assertSame(1, $this->audits());

        // Run on a site whose field stayed empty and whose New videos got a code since: the copy happens then.
        $this->site122('', 'inherit');
        $this->migration()->up();
        DB::table('settings')->where('key', 'ad_code_grid_new_videos')->update(['value' => self::SITE_AD, 'updated_at' => now()]);
        $this->travel(1)->days();
        $this->migration()->up();
        $this->assertSame([self::SITE_AD, 'inherit'], $this->homepageFeed());
    }

    public function test_a_shape_the_list_cannot_have_becomes_same_as_above(): void
    {
        $this->site122(self::SITE_AD, 'sideways');
        $this->migration()->up();

        $this->assertSame([self::SITE_AD, 'inherit'], $this->homepageFeed());
    }

    public function test_down_keeps_everything(): void
    {
        $this->site122(self::SITE_AD, 'wide_row');
        $this->migration()->up();
        $rows = DB::table('settings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->migration()->down();

        $this->assertSame($rows, DB::table('settings')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    public function test_a_database_without_settings_is_left_alone(): void
    {
        Schema::rename('settings', 'settings_away');

        try {
            $this->migration()->up();
            $this->assertFalse(Schema::hasTable('settings'));
        } finally {
            Schema::rename('settings_away', 'settings');
        }
    }
}
