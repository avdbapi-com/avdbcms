<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppearanceApplier;
use App\Support\AppearancePages;
use App\Support\AppearanceSettings;
use App\Support\PendingMigrations;
use App\Support\SettingsRevision;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * An upload of the new version over an old install: the Appearance pages work before "Finish update", so an owner may
 * publish a look, a theme or a colour style on the OLD database first. Finish update (the theme catalogue v2 data
 * migration) must keep that choice: the look (active_master_experience), every key the publish wrote and the colour
 * style - instead of classifying the rows as an untouched v1 site and moving them on. Running it twice changes nothing.
 */
class AdminAppearancePublishBeforeUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const REDESIGN = [
        '2026_10_20_000001_create_admin_setting_revisions_table',
        '2026_10_20_000002_migrate_appearance_to_theme_catalog_v2',
        '2026_10_20_000003_add_admin_preferences_to_users_table',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
    }

    /** Looks new in v2, and looks whose id the old admin had too (with other settings then). */
    public static function looks(): array
    {
        return array_map(fn (string $look): array => [$look], [
            'velvet_lounge' => 'velvet_lounge',
            'editorial_magazine' => 'editorial_magazine',
            'glass_lounge' => 'glass_lounge',
            'daylight_creator' => 'daylight_creator',
            'netflix_cinema' => 'netflix_cinema',
            'cosmic_astronomy_theater' => 'cosmic_astronomy_theater',
            'cyberpunk_hud' => 'cyberpunk_hud',
            'youtube_spotlight_pro' => 'youtube_spotlight_pro',
        ]);
    }

    #[DataProvider('looks')]
    public function test_a_look_published_before_finish_update_survives_it(string $look): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeOldInstall();
        $old = $this->rows();

        $this->actingAs($admin)->postJson(route('admin.appearance.draft'), ['look' => $look])->assertOk();
        $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->assertJson(['ok' => true]);
        $published = $this->rows();
        $written = $this->changes($old, $published);

        $this->assertSame($look, $published['active_master_experience']);
        $this->assertSame(self::REDESIGN, PendingMigrations::pending(), 'still an old install');

        $this->finishUpdate($admin);
        $after = $this->rows();

        $this->assertSame($look, $after['active_master_experience'] ?? null, 'the look stays');
        foreach ($written as $key => $value) {
            $this->assertSame($value, $after[$key] ?? null, "{$look}: {$key} as published");
        }
        $this->assertSame(
            array_intersect_key(AppearanceSettings::resolveThemeFlavor($published), ['theme' => 1, 'flavor' => 1]),
            array_intersect_key(AppearanceSettings::resolveThemeFlavor($after), ['theme' => 1, 'flavor' => 1]),
            "{$look}: the same theme and colour style"
        );
        $this->assertTrue(AppearanceSettings::isUpgraded($after));
        $this->assertNotNull(SettingsRevision::upgradeSnapshot(), 'the previous look can still come back');
        $this->assertSame($published, SettingsRevision::find(SettingsRevision::upgradeSnapshot()['id'])['before']);

        $this->assertTwiceChangesNothing($admin, $after);
    }

    public function test_a_colour_style_and_single_cards_published_before_finish_update_survive_it(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeOldInstall();
        $old = $this->rows();

        // A colour style of another theme without its layouts, then the header, the side menu and the announcement bar.
        $this->actingAs($admin)->postJson(route('admin.appearance.draft'), ['theme' => ['id' => 'shibuya_rain', 'flavor' => array_keys(\App\Support\ThemeCatalog::flavors('shibuya_rain'))[1], 'layouts' => false]])->assertOk();
        $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.appearance.draft'), ['set' => [
            'header_search_enabled' => '0',
            'sidebar_style' => 'floating',
            'announcement_banner_enabled' => '1',
            'announcement_banner' => 'Published on the old database',
        ]])->assertOk();
        $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk();
        $published = $this->rows();
        $written = $this->changes($old, $published);

        $this->assertArrayHasKey('theme_flavor', $written);
        $this->assertSame('floating', $written['sidebar_style']);

        $this->finishUpdate($admin);
        $after = $this->rows();

        foreach ($written as $key => $value) {
            $this->assertSame($value, $after[$key] ?? null, "{$key} as published");
        }
        $this->assertSame(['shibuya_rain', $published['theme_flavor']], [$after['theme_preset'], $after['theme_flavor']]);

        $this->assertTwiceChangesNothing($admin, $after);
    }

    public function test_a_side_menu_style_published_on_a_factory_site_survives_the_new_default_look(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeOldInstall();

        // Only keys the old admin never stored: the site still counts as untouched and gets the new default look.
        $this->actingAs($admin)->postJson(route('admin.appearance.draft'), ['set' => ['sidebar_style' => 'floating', 'brand_contrast_autofix' => '0']])->assertOk();
        $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk();

        $this->finishUpdate($admin);
        $after = $this->rows();

        $this->assertSame('FACTORY', $this->upgradeClass());
        $this->assertSame(['tokyo_neon', 'youtube_spotlight_pro'], [$after['theme_preset'], $after['active_master_experience']]);
        $this->assertSame(['floating', '0'], [$after['sidebar_style'], $after['brand_contrast_autofix']], 'published in the new admin');

        $this->assertTwiceChangesNothing($admin, $after);
    }

    /** Look keys the classification does not check, each published alone on a site whose look still moves on. */
    public static function singleLookKeys(): array
    {
        $sites = [
            'factory' => [[], 'FACTORY'],
            'v1 look' => [['active_master_experience' => 'apple_bento_studio'], 'UNTOUCHED_MASTER'],
            'v1 preset' => [['theme_preset' => 'acid_overdrive'], 'UNTOUCHED_PRESET'],
        ];
        $cases = [];

        foreach ($sites as $site => [$rows, $class]) {
            foreach (['grid_columns' => '3', 'home_feed_columns' => '3', 'content_density' => 'relaxed'] as $key => $value) {
                $cases["{$site}: {$key} {$value}"] = [$rows, $class, $key, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('singleLookKeys')]
    public function test_a_look_key_published_alone_survives_the_look_finish_update_applies(array $oldRows, string $class, string $key, string $value): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeOldInstall($oldRows);
        $old = $this->rows();

        $this->actingAs($admin)->postJson(route('admin.appearance.draft'), ['set' => [$key => $value]])->assertOk();
        $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->assertJson(['ok' => true]);
        $published = $this->rows();
        $this->assertSame([$key => $value], $this->changes($old, $published), 'the publish wrote only that key');

        // What Finish update gives this site without the publish: the look writes another value for the key.
        $expected = $this->migration()->plan($old)['target'];
        $this->assertNotSame($value, $expected[$key]);

        $this->finishUpdate($admin);
        $after = $this->rows();

        $this->assertSame($class, $this->upgradeClass(), 'the site still gets its new look');
        $this->assertSame($value, $after[$key] ?? null, "{$class}: {$key} as published");
        $this->assertSame(array_replace($expected, [$key => $value]), $after, 'everything else as without the publish');

        $this->assertTwiceChangesNothing($admin, $after);
    }

    public function test_videos_per_row_set_in_the_old_admin_stay_on_a_factory_site(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeOldInstall(['grid_columns' => '3', 'home_feed_columns' => '6']);

        $this->finishUpdate($admin);
        $after = $this->rows();

        $this->assertSame('FACTORY', $this->upgradeClass());
        $this->assertSame(['tokyo_neon', 'youtube_spotlight_pro'], [$after['theme_preset'], $after['active_master_experience']]);
        $this->assertSame(['3', '6', 'comfortable'], [$after['grid_columns'], $after['home_feed_columns'], $after['content_density']]);
    }

    public function test_the_retired_rail_header_keeps_a_side_menu_style_published_next_to_it(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeOldInstall(['header_style' => 'sidebar_rail']);

        $this->actingAs($admin)->postJson(route('admin.appearance.draft'), ['set' => ['sidebar_style' => 'floating']])->assertOk();
        $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk();

        $this->finishUpdate($admin);
        $after = $this->rows();

        // As the site rendered it before the update: the retired header value shows the rail only for a full side menu.
        $this->assertSame(['glass_blur', 'floating'], [$after['header_style'], $after['sidebar_style']]);
        $this->assertTwiceChangesNothing($admin, $after);
    }

    public function test_an_old_install_that_published_nothing_still_gets_the_new_default_look(): void
    {
        $admin = User::factory()->admin()->create();
        $this->makeOldInstall();

        $this->finishUpdate($admin);
        $after = $this->rows();

        $this->assertSame(['tokyo_neon', 'signature', 'youtube_spotlight_pro'], [$after['theme_preset'], $after['theme_flavor'], $after['active_master_experience']]);
        $this->assertSame('FACTORY', $this->upgradeClass());
    }

    /** The database of an install from before the redesign: v1 appearance rows (example.sql), its three migrations pending. */
    private function makeOldInstall(array $rows = []): void
    {
        $sources = json_decode((new ReflectionClassConstant($this->migration(), 'FACTORY_SOURCES'))->getValue(), true);
        $sources['example_sql_appearance_rows'] = array_replace($sources['example_sql_appearance_rows'], $rows);

        Schema::dropIfExists('admin_setting_revisions');

        if (Schema::hasColumn('users', 'admin_preferences')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('admin_preferences'));
        }

        DB::table('migrations')->whereIn('migration', self::REDESIGN)->delete();
        DB::table('settings')->where('group', 'appearance')->delete();
        $now = now();
        DB::table('settings')->insert(array_map(
            fn (string $key, $value): array => ['key' => $key, 'value' => (string) $value, 'group' => 'appearance', 'created_at' => $now, 'updated_at' => $now],
            array_keys($sources['example_sql_appearance_rows']),
            $sources['example_sql_appearance_rows'],
        ));

        app()->forgetInstance(SettingsRevision::class.'.table');
        Cache::flush();
        PendingMigrations::forget();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_20_000002_migrate_appearance_to_theme_catalog_v2.php');
    }

    /** The class the upgrade gave the site (FACTORY, UNTOUCHED_MASTER, ...), from its result revision. */
    private function upgradeClass(): ?string
    {
        return json_decode((string) DB::table('admin_setting_revisions')->where('kind', 'upgrade_result')->value('meta'), true)['class'] ?? null;
    }

    private function finishUpdate(User $admin): void
    {
        $this->actingAs($admin)->post(route('admin.update.finish'))->assertRedirect(route('admin.index'))->assertSessionHas('success', 'The update is finished.');
        $this->assertSame([], PendingMigrations::pending());
    }

    private function assertTwiceChangesNothing(User $admin, array $after): void
    {
        $this->assertNull(AppearanceApplier::upgrade(), 'an upgraded site is not upgraded twice');
        $this->actingAs($admin)->postJson(route('admin.update.finish'))->assertOk()->assertJson(['ok' => true, 'ran' => []]);
        $this->assertSame($after, $this->rows(), 'a second run changes nothing');
        $this->assertSame(1, DB::table('admin_setting_revisions')->where('kind', 'upgrade_snapshot')->count());
    }

    /** @return array<string, ?string> keys the publish wrote (null = deleted) */
    private function changes(array $before, array $after): array
    {
        $changes = [];

        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[$key] = $after[$key] ?? null;
            }
        }

        return $changes;
    }

    private function rows(): array
    {
        $rows = DB::table('settings')->where('group', 'appearance')->pluck('value', 'key')->all();
        ksort($rows, SORT_STRING);

        return $rows;
    }
}
