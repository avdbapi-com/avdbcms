<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\AppearanceSettings;
use App\Support\PendingMigrations;
use App\Support\SettingsRevision;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Uploading the new version over an older install (I13): the site works before the update is finished, Home shows
 * "Finish update", one click runs the pending migrations under a lock (idempotent, never a 500), then Home offers the
 * previous look back (revision #0, 30 days) and the new look again.
 */
class AdminFinishUpdateTest extends TestCase
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

        // Home is a page of the new admin, whatever ADMIN_UI the test environment sets.
        config(['admin.ui' => 'v2']);
    }

    public function test_an_old_install_opens_home_with_the_finish_update_banner(): void
    {
        $admin = $this->admin();
        $this->makeOldInstall();

        $this->assertSame(self::REDESIGN, PendingMigrations::pending());

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Finish the update')
            ->assertSee('3 database changes are waiting')
            ->assertSee('Your look moves to the new theme collection')
            ->assertSee('action="'.route('admin.update.finish').'"', false)
            // The pages around it work too (no new table or column is read before the update).
            ->assertSee('Get your site ready');

        foreach (['admin.videos.index', 'admin.users.index', 'admin.comments.index', 'admin.storage.index', 'admin.settings.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    public function test_one_click_runs_the_pending_migrations_once(): void
    {
        $admin = $this->admin();
        $this->makeOldInstall();
        $otherGroups = $this->otherSettings();

        $this->actingAs($admin)
            ->post(route('admin.update.finish'))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHas('success', 'The update is finished.');

        $this->assertSame([], PendingMigrations::pending());
        $this->assertSame(3, DB::table('migrations')->whereIn('migration', self::REDESIGN)->count());
        $this->assertTrue(Schema::hasTable('admin_setting_revisions'));
        $this->assertTrue(Schema::hasColumn('users', 'admin_preferences'));
        $this->assertTrue(AppearanceSettings::isUpgraded(SettingsRevision::rows('appearance')));
        $this->assertSame($otherGroups, $this->otherSettings(), 'only the appearance group changes');

        // A second click (two tabs, two administrators) finds nothing to do.
        $this->actingAs($admin)
            ->postJson(route('admin.update.finish'))
            ->assertOk()
            ->assertJson(['ok' => true, 'ran' => [], 'message' => 'Your site is up to date.']);

        $this->assertSame(3, DB::table('migrations')->whereIn('migration', self::REDESIGN)->count());
        $this->actingAs($admin)->get(route('admin.index'))->assertOk()->assertDontSee('Finish the update');
    }

    public function test_the_update_never_runs_twice_at_once(): void
    {
        $admin = $this->admin();
        $this->makeOldInstall();
        $lock = Cache::lock(PendingMigrations::LOCK, 300);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($admin)
                ->postJson(route('admin.update.finish'))
                ->assertStatus(409)
                ->assertJson(['ok' => false, 'busy' => true]);
        } finally {
            $lock->release();
        }

        $this->assertSame(self::REDESIGN, PendingMigrations::pending());
    }

    public function test_a_failing_migration_is_reported_on_home_and_never_a_500(): void
    {
        $admin = $this->admin();
        $this->makeOldInstall();

        Artisan::shouldReceive('call')->with('migrate', Mockery::any())->andThrow(new RuntimeException('Disk full'));
        Artisan::shouldReceive('call')->withAnyArgs()->andReturn(0);

        $this->actingAs($admin)
            ->postJson(route('admin.update.finish'))
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'busy' => false, 'ran' => []])
            ->assertJsonFragment(['message' => 'The update could not finish: RuntimeException: Disk full Your site keeps working. Try again, or ask your host to run "php artisan migrate".']);

        $this->actingAs($admin)
            ->post(route('admin.update.finish'))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHasErrors('update');
    }

    public function test_the_previous_look_comes_back_and_the_new_look_again(): void
    {
        $admin = $this->admin();
        $this->makeOldInstall();
        $oldLook = SettingsRevision::rows('appearance');

        $this->actingAs($admin)->post(route('admin.update.finish'))->assertRedirect(route('admin.index'));
        $newLook = SettingsRevision::rows('appearance');
        $this->assertNotSame($oldLook, $newLook);

        $snapshot = SettingsRevision::upgradeSnapshot();
        $this->assertNotNull($snapshot);
        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertSee('Your look moved to the new theme collection')
            ->assertSee('action="'.route('admin.update.restore-look').'"', false)
            // Compare: the design history shows the update next to the look before it.
            ->assertSee('href="'.route('admin.appearance.history').'"', false)
            ->assertSee('Restore your previous look?');

        // Restore my previous look: the appearance rows as they were before the update, byte for byte.
        $this->actingAs($admin)
            ->post(route('admin.update.restore-look'))
            ->assertRedirect(route('admin.index'))
            ->assertSessionHas('success', 'Your previous look is back.');
        $this->assertSame($oldLook, SettingsRevision::rows('appearance'));
        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertSee('Your site uses its previous look')
            ->assertSee('action="'.route('admin.update.new-look').'"', false);

        // Use the new look again: the same upgrade, with a new copy of the previous look.
        $this->actingAs($admin)
            ->post(route('admin.update.new-look'))
            ->assertSessionHas('success', 'Your site uses the new look again.');
        $this->assertSame($newLook, SettingsRevision::rows('appearance'));
        $this->assertNotSame($snapshot['id'], SettingsRevision::upgradeSnapshot()['id']);

        // Keep the new look: the notice goes away for this administrator.
        $this->actingAs($admin)->get(route('admin.index'))->assertSee('Your look moved to the new theme collection');
        $this->actingAs($admin)->post(route('admin.update.keep-look'))->assertSessionHas('success', 'The new look stays.');
        $this->actingAs($admin->fresh())->get(route('admin.index'))->assertDontSee('Your look moved to the new theme collection');
        $this->assertTrue(AdminPreferences::dismissed($admin->fresh(), 'look-moved-'.SettingsRevision::upgradeSnapshot()['id']));
    }

    public function test_keep_my_previous_look_answers_the_offer_of_the_new_look(): void
    {
        $admin = $this->admin();
        $this->makeOldInstall();
        $this->actingAs($admin)->post(route('admin.update.finish'))->assertRedirect(route('admin.index'));
        $this->actingAs($admin)->post(route('admin.update.restore-look'))->assertSessionHas('success', 'Your previous look is back.');
        $look = SettingsRevision::rows('appearance');

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertSee('Your site uses its previous look')
            ->assertSee('Keep my previous look')
            ->assertSee('name="look" value="previous"', false);

        $this->actingAs($admin)
            ->post(route('admin.update.keep-look'), ['look' => 'previous'])
            ->assertSessionHas('success', 'Your previous look stays.');

        // The notice is answered for this upgrade snapshot; the look itself does not change.
        $this->actingAs($admin->fresh())->get(route('admin.index'))->assertOk()->assertDontSee('Your site uses its previous look');
        $this->assertTrue(AdminPreferences::dismissed($admin->fresh(), 'look-previous-'.SettingsRevision::upgradeSnapshot()['id']));
        $this->assertSame($look, SettingsRevision::rows('appearance'));
    }

    public function test_the_old_dashboard_offers_finish_update_too(): void
    {
        $admin = $this->admin();
        $this->makeOldInstall();
        config(['admin.ui' => 'v1']);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Most Reported contents')
            ->assertSee('3 database changes are waiting')
            ->assertSee('action="'.route('admin.update.finish').'"', false);

        $this->actingAs($admin)->post(route('admin.update.finish'))->assertRedirect(route('admin.index'));

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.update.finish').'"', false);
    }

    public function test_restoring_without_a_snapshot_says_why(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('admin.update.restore-look'))
            ->assertStatus(409)
            ->assertJson(['ok' => false, 'message' => 'Your previous look can no longer be restored. It is kept for 30 days after the update.']);
    }

    public function test_the_getting_started_list_can_be_hidden(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.index'))->assertSee('Get your site ready')->assertSee('Hide this list');
        $this->actingAs($admin)->post(route('admin.home.dismiss'), ['notice' => 'home-checklist'])->assertRedirect(route('admin.index'));
        $this->actingAs($admin->fresh())->get(route('admin.index'))->assertOk()->assertDontSee('Get your site ready');

        $this->actingAs($admin)->post(route('admin.home.dismiss'), ['notice' => 'something-else'])->assertSessionHasErrors('notice');

        // A link under "Needs you" brings it back.
        $this->actingAs($admin->fresh())->get(route('admin.index'))->assertSee('Show the getting-started list');
        $this->actingAs($admin->fresh())
            ->post(route('admin.home.dismiss'), ['notice' => 'home-checklist', 'show' => 1])
            ->assertRedirect(route('admin.index'));
        $this->assertFalse(AdminPreferences::dismissed($admin->fresh(), 'home-checklist'));
        $this->actingAs($admin->fresh())->get(route('admin.index'))->assertSee('Get your site ready')->assertDontSee('Show the getting-started list');
    }

    public function test_failed_jobs_are_listed_only_where_their_page_opens(): void
    {
        $admin = $this->admin();
        DB::table('failed_jobs')->insert(['uuid' => 'f-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'Boom', 'failed_at' => now()]);

        config(['admin.operational_tools_enabled' => true]);
        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertSee('1 background job failed this week')
            ->assertSee('href="'.route('admin.failed-jobs.index').'"', false);

        // The operational tools switched off (the production default): no item that leads to a page that answers 404.
        config(['admin.operational_tools_enabled' => false]);
        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertDontSee('background job failed')
            ->assertDontSee('href="'.route('admin.failed-jobs.index').'"', false);
    }

    public function test_home_lists_what_needs_the_administrator(): void
    {
        $admin = $this->admin();
        DB::table('contact_messages')->insert(['name' => 'Ann', 'email' => 'ann@example.test', 'subject' => 'Hi', 'message' => 'Hello', 'is_read' => false, 'created_at' => now(), 'updated_at' => now()]);
        config(['site.robots_noindex' => true]);

        $this->actingAs($admin)
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('1 unread message')
            ->assertSee('Hidden from Google')
            ->assertSee('Needs you')
            ->assertSee('This week')
            ->assertSee('class="ad-spark"', false);
    }

    public function test_the_old_admin_keeps_the_old_dashboard(): void
    {
        config(['admin.ui' => 'v1']);

        $this->actingAs($this->admin())
            ->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Most Reported contents')
            ->assertDontSee('Get your site ready');
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** The database of an install from before the redesign: its three migrations wait for "Finish update". */
    private function makeOldInstall(): void
    {
        Schema::dropIfExists('admin_setting_revisions');

        if (Schema::hasColumn('users', 'admin_preferences')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('admin_preferences'));
        }

        DB::table('migrations')->whereIn('migration', self::REDESIGN)->delete();
        DB::table('settings')->where('group', 'appearance')->where('key', 'appearance_schema_version')->delete();
        Setting::query()->updateOrCreate(['key' => 'theme_preset'], ['value' => 'cyberpunk_neon', 'group' => 'appearance']);

        app()->forgetInstance(SettingsRevision::class.'.table');
        Cache::flush();
        PendingMigrations::forget();
    }

    private function otherSettings(): array
    {
        return DB::table('settings')->where('group', '!=', 'appearance')->orderBy('key')->pluck('value', 'key')->all();
    }
}
