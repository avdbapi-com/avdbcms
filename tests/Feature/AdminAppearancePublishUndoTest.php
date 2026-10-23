<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AppearanceDraft;
use App\Support\AppearanceSettings;
use App\Support\SettingsRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Publish / History / Undo of the Appearance pages: publishing writes the draft through AppearanceApplier (one revision
 * for the look keys) and the other settings of these pages in the same transaction; Undo restores exactly; History
 * lists the upgrade snapshot (#0) while it lives.
 */
class AdminAppearancePublishUndoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.ui' => 'v2']);
        Setting::set('appearance_schema_version', '2', 'appearance');
        Setting::set('theme_preset', 'tokyo_neon', 'appearance');
        Setting::set('theme_flavor', 'signature', 'appearance');
        Setting::set('home_intro_title', 'Published welcome', 'appearance');
    }

    public function test_publish_writes_the_draft_through_the_applier_and_keeps_a_revision(): void
    {
        $admin = $this->admin();
        $this->draft($admin, ['set' => ['home_intro_title' => 'New welcome', 'home_layout_mode' => 'bento_mosaic']])->assertOk();
        $before = $this->rows('appearance');

        $response = $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->assertJson(['ok' => true, 'count' => 0]);
        $revision = $response->json('revision');

        $this->assertSame('New welcome', Setting::getGroup('appearance')['home_intro_title']);
        $this->assertSame('bento_mosaic', Setting::getGroup('appearance')['home_layout_mode']);
        $this->assertSame([], AppearanceDraft::get($admin->id)['values'], 'the draft is gone once published');

        $record = SettingsRevision::find($revision);
        $this->assertSame('publish', $record['kind']);
        $this->assertSame('appearance', $record['group']);
        $this->assertTrue($record['restorable']);
        $this->assertSame('appearance', $record['meta']['source']);
        $this->assertSame(route('admin.appearance.restore', ['revision' => $revision]), $response->json('undo'));

        // Undo: the appearance rows are exactly what they were.
        $this->actingAs($admin)->postJson(route('admin.appearance.restore', ['revision' => $revision]))->assertOk()->assertJson(['ok' => true]);
        $this->assertSame($before, $this->rows('appearance'));
    }

    public function test_a_publish_that_also_changes_other_groups_is_one_history_line_and_one_undo(): void
    {
        Setting::set('smart_discovery_rail_enabled', '1', 'discovery');
        Setting::set('per_page_videos', '24', 'general');
        $admin = $this->admin();
        $beforeAppearance = $this->rows('appearance');
        $beforeDiscovery = $this->rows('discovery');
        $beforeGeneral = $this->rows('general');

        $this->draft($admin, ['set' => ['home_intro_title' => 'New welcome', 'smart_discovery_rail_enabled' => '0', 'per_page_videos' => '36']])->assertOk()->assertJson(['count' => 3]);
        $revision = $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->json('revision');

        $this->assertSame('0', Setting::getGroup('discovery')['smart_discovery_rail_enabled']);
        $this->assertSame('36', Setting::getGroup('general')['per_page_videos']);
        $this->assertSame('New welcome', Setting::getGroup('appearance')['home_intro_title']);

        $pair = SettingsRevision::find($revision)['meta']['pair'] ?? null;
        $this->assertNotNull($pair, 'the appearance revision names the revision of the other groups');
        $this->assertSame($revision, SettingsRevision::find($pair)['meta']['pair']);

        $history = $this->actingAs($admin)->getJson(route('admin.appearance.history'))->assertOk()->json('items');
        $ids = array_column($history, 'id');
        $this->assertContains($revision, $ids);
        $this->assertNotContains($pair, $ids, 'one line per publish');

        $this->actingAs($admin)->postJson(route('admin.appearance.restore', ['revision' => $revision]))->assertOk();

        $this->assertSame($beforeAppearance, $this->rows('appearance'));
        $this->assertSame($beforeDiscovery, $this->rows('discovery'));
        $this->assertSame($beforeGeneral, $this->rows('general'));
    }

    public function test_undo_of_the_other_groups_alone_and_a_key_that_was_not_stored(): void
    {
        $admin = $this->admin();
        DB::table('settings')->where('key', 'profile_videos_limit')->delete();
        Setting::clearCache();

        $this->draft($admin, ['set' => ['profile_videos_limit' => '20']])->assertOk();
        $revision = $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->json('revision');
        $this->assertSame('20', Setting::getGroup('general')['profile_videos_limit']);
        $this->assertSame('general', SettingsRevision::find($revision)['group']);

        $this->actingAs($admin)->postJson(route('admin.appearance.restore', ['revision' => $revision]))->assertOk();
        $this->assertFalse(DB::table('settings')->where('key', 'profile_videos_limit')->exists(), 'not stored before, not stored after');
    }

    public function test_a_complete_look_publishes_its_settings_and_undo_restores_exactly(): void
    {
        $admin = $this->admin();
        $before = $this->rows('appearance');

        $this->draft($admin, ['look' => 'editorial_magazine'])->assertOk();
        $revision = $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->json('revision');

        $stored = Setting::getGroup('appearance');
        $this->assertSame('broadsheet', $stored['theme_preset']);
        $this->assertSame('editorial_magazine', $stored['active_master_experience']);
        $this->assertSame('Published welcome', $stored['home_intro_title'], 'texts stay');
        $this->assertStringContainsString(__('Published: :what', ['what' => 'Magazine']), json_encode(
            $this->actingAs($admin)->getJson(route('admin.appearance.history'))->json('items'),
            JSON_UNESCAPED_UNICODE,
        ));

        $this->actingAs($admin)->postJson(route('admin.appearance.restore', ['revision' => $revision]))->assertOk();
        $this->assertSame($before, $this->rows('appearance'));
    }

    public function test_history_lists_the_upgrade_snapshot_for_30_days(): void
    {
        $admin = $this->admin();
        $snapshot = SettingsRevision::record('upgrade_snapshot', 'appearance', SettingsRevision::rows('appearance'), SettingsRevision::rows('appearance'), null, now()->addDays(30));

        $items = $this->actingAs($admin)->getJson(route('admin.appearance.history'))->assertOk()->json('items');
        $row = collect($items)->firstWhere('id', $snapshot);
        $this->assertNotNull($row);
        $this->assertTrue($row['snapshot']);
        $this->assertTrue($row['restorable']);

        // The Themes page offers "Restore my previous look" while it lives.
        $this->actingAs($admin)->get(route('admin.appearance.index'))->assertOk()
            ->assertSee(route('admin.appearance.restore', ['revision' => $snapshot], false), false)
            ->assertSee('Restore my previous look');

        $this->travel(31)->days();
        $this->assertNull(collect($this->actingAs($admin)->getJson(route('admin.appearance.history'))->json('items'))->firstWhere('id', $snapshot));
        $this->actingAs($admin)->postJson(route('admin.appearance.restore', ['revision' => $snapshot]))->assertStatus(422);
    }

    public function test_the_snapshot_counts_its_days_across_a_clock_change(): void
    {
        // Finish update on 21 October in Paris: the 30 days include the end of summer time (721 hours).
        config(['app.timezone' => 'Europe/Paris']);
        date_default_timezone_set('Europe/Paris');
        $this->travelTo(Carbon::parse('2026-10-21 19:22:55', 'Europe/Paris'));
        $admin = $this->admin();
        SettingsRevision::record('upgrade_snapshot', 'appearance', SettingsRevision::rows('appearance'), SettingsRevision::rows('appearance'), null, now()->addDays(30));

        $this->actingAs($admin)->get(route('admin.appearance.index'))->assertOk()
            ->assertSee('(saved before the update, 30 days left)')
            ->assertDontSee('31 days left');

        // The last day still counts as one.
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'Europe/Paris'));
        $this->actingAs($admin)->get(route('admin.appearance.index'))->assertOk()
            ->assertSee('(saved before the update, 1 day left)');
    }

    public function test_history_keeps_the_look_s_changes_after_many_saves_on_other_settings_pages(): void
    {
        $admin = $this->admin();
        $snapshot = SettingsRevision::record('upgrade_snapshot', 'appearance', SettingsRevision::rows('appearance'), SettingsRevision::rows('appearance'), null, now()->addDays(30));
        $this->draft($admin, ['set' => ['home_intro_title' => 'New welcome']])->assertOk();
        $publish = $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->json('revision');
        Setting::set('per_page_videos', '24', 'general');
        $this->draft($admin, ['set' => ['per_page_videos' => '36']])->assertOk();
        $otherOnly = $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->json('revision');

        // An active admin then saves other settings pages: the settings pages record a revision per save, any group.
        foreach (['general', 'seo', 'email', 'users', 'comments'] as $group) {
            for ($n = 0; $n < 25; $n++) {
                SettingsRevision::record('settings_save', $group, ['k'.$n => 'a'], ['k'.$n => 'b'], $admin->id);
            }
        }

        $items = $this->actingAs($admin)->getJson(route('admin.appearance.history'))->assertOk()->json('items');
        $ids = array_column($items, 'id');

        $this->assertContains($snapshot, $ids, 'the upgrade snapshot stays listed');
        $this->assertContains($publish, $ids, 'the publish stays listed');
        $this->assertContains($otherOnly, $ids, 'a publish of other groups only stays listed');
        $this->assertSame([], array_values(array_filter($items, fn (array $item): bool => $item['kind'] === 'settings_save')), 'other pages\' saves are not the design\'s history');

        $line = collect($items)->firstWhere('id', $snapshot);
        $this->assertSame(__('Your look before the update'), $line['title']);
        $this->assertStringContainsString(now()->addDays(30)->isoFormat('ll'), $line['detail'], 'the snapshot line says until when it can be restored');
        $this->assertStringNotContainsString('0 settings', $line['detail']);
    }

    public function test_the_undo_of_a_publish_of_several_groups_is_one_line_and_undoing_it_redoes_both(): void
    {
        Setting::set('per_page_videos', '24', 'general');
        $admin = $this->admin();
        $this->draft($admin, ['set' => ['home_intro_title' => 'New welcome', 'per_page_videos' => '36']])->assertOk();
        $publish = $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->json('revision');
        $published = [$this->rows('appearance'), $this->rows('general')];

        $items = $this->actingAs($admin)->getJson(route('admin.appearance.history'))->json('items');
        $this->assertCount(1, $items);
        $this->assertSame(__('Published: :what', ['what' => 'Homepage, Video cards']), $items[0]['title']);
        $this->assertSame(trans_choice(':count setting changed|:count settings changed', 2, ['count' => 2]), $items[0]['detail'], 'both halves are counted');

        $undo = $this->actingAs($admin)->postJson(route('admin.appearance.restore', ['revision' => $publish]))->assertOk()->json('revision');
        $this->assertSame('24', Setting::getGroup('general')['per_page_videos']);

        $items = $this->actingAs($admin)->getJson(route('admin.appearance.history'))->json('items');
        $this->assertCount(2, $items, 'one line for the publish, one for its undo');
        $this->assertSame($undo, $items[0]['id']);
        $this->assertSame('restore', $items[0]['kind']);
        $this->assertSame(__('Undo: :what', ['what' => __('Published: :what', ['what' => 'Homepage, Video cards'])]), $items[0]['title']);
        $this->assertSame(trans_choice(':count setting changed|:count settings changed', 2, ['count' => 2]), $items[0]['detail']);
        $this->assertTrue($items[0]['restorable']);

        // Undoing the undo puts both halves of the publish back.
        $this->actingAs($admin)->postJson(route('admin.appearance.restore', ['revision' => $undo]))->assertOk();
        $this->assertSame($published, [$this->rows('appearance'), $this->rows('general')]);
    }

    public function test_restoring_the_upgrade_snapshot_is_named_in_history(): void
    {
        $admin = $this->admin();
        $snapshot = SettingsRevision::record('upgrade_snapshot', 'appearance', SettingsRevision::rows('appearance'), SettingsRevision::rows('appearance'), null, now()->addDays(30));
        Setting::set('home_intro_title', 'Changed since', 'appearance');

        $this->actingAs($admin)->postJson(route('admin.appearance.restore', ['revision' => $snapshot]))->assertOk();

        $first = $this->actingAs($admin)->getJson(route('admin.appearance.history'))->json('items.0');
        $this->assertSame('restore', $first['kind']);
        $this->assertSame(__('Restored your look from before the update'), $first['title']);
        $this->assertSame('Published welcome', Setting::getGroup('appearance')['home_intro_title']);
    }

    public function test_the_announcement_bar_is_published_with_the_header(): void
    {
        $admin = $this->admin();
        Setting::set('announcement_banner_enabled', '0', 'appearance');

        $this->draft($admin, ['set' => ['announcement_banner_enabled' => '1', 'announcement_banner' => 'Big news today']])
            ->assertOk()
            ->assertJson(['count' => 2])
            ->assertJsonPath('changes.0.page', 'appearance.menus');
        $revision = $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk()->json('revision');

        $stored = Setting::getGroup('appearance');
        $this->assertSame('1', $stored['announcement_banner_enabled']);
        $this->assertSame('Big news today', $stored['announcement_banner']);
        $this->assertSame(['appearance.menus'], SettingsRevision::find($revision)['meta']['pages']);

        $this->actingAs($admin)->postJson(route('admin.appearance.restore', ['revision' => $revision]))->assertOk();
        $this->assertSame('0', Setting::getGroup('appearance')['announcement_banner_enabled']);
    }

    public function test_a_page_posted_without_javascript_publishes_its_fields(): void
    {
        $admin = $this->admin();
        Setting::set('home_intro_enabled', '1', 'appearance');

        $this->actingAs($admin)
            ->from(route('admin.appearance.page', ['page' => 'homepage']))
            ->post(route('admin.appearance.publish'), [
                'groups' => ['appearance' => ['home_intro_title' => 'Posted welcome']],
                'fields' => ['appearance' => 'home_intro_title,home_intro_enabled'],
            ])
            ->assertRedirect(route('admin.appearance.page', ['page' => 'homepage']));

        $stored = Setting::getGroup('appearance');
        $this->assertSame('Posted welcome', $stored['home_intro_title']);
        $this->assertSame('0', $stored['home_intro_enabled'], 'a listed switch that is absent is off');
    }

    public function test_publish_checks_the_draft_again_and_writes_nothing_when_a_value_is_wrong(): void
    {
        $admin = $this->admin();
        AppearanceDraft::put($admin->id, ['values' => ['home_intro_title' => 'New welcome', 'grid_columns' => '42'], 'action' => null]);
        $before = $this->rows('appearance');

        $this->actingAs($admin)->postJson(route('admin.appearance.publish'))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['appearance.grid_columns']]);

        $this->assertSame($before, $this->rows('appearance'));
    }

    public function test_reset_puts_a_custom_colour_back_to_the_colour_style(): void
    {
        $admin = $this->admin();
        Setting::set('primary_color', '#123456', 'appearance');

        $this->draft($admin, ['reset' => ['primary_color']])->assertOk()->assertJson(['count' => 1]);
        $this->actingAs($admin)->postJson(route('admin.appearance.publish'))->assertOk();

        $this->assertArrayNotHasKey('primary_color', Setting::getGroup('appearance'));
        $this->assertSame('#ff0055', AppearanceSettings::formValues(Setting::getGroup('appearance'))['primary_color']);
    }

    public function test_nothing_to_publish_answers_ok_without_a_revision(): void
    {
        $this->actingAs($this->admin())->postJson(route('admin.appearance.publish'))->assertOk()->assertJson(['ok' => true, 'revision' => null]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function draft(User $admin, array $edits): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($admin)->postJson(route('admin.appearance.draft'), $edits);
    }

    /** @return array<string, ?string> the group's stored rows */
    private function rows(string $group): array
    {
        $rows = DB::table('settings')->where('group', $group)->pluck('value', 'key')->all();
        ksort($rows);

        return $rows;
    }
}
