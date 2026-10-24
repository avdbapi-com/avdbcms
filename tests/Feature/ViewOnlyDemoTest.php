<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\Category;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Models\Transaction;
use App\Support\AdminPreferences;
use App\Support\DatabaseBackup;
use App\Support\Updater\Downloader;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\UpdatePaths;
use App\Support\Updater\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.2, lane V: the built-in view-only demo account (users.is_view_only, App\Http\Middleware\ViewOnlyDemo).
 * Built from a site-only guard first used on a public demo site: a view-only account opens the admin and the site, but every change is
 * refused, the private pages are closed, what a page writes is rolled back and what it sees is masked. The owner,
 * members and visitors are untouched; only a normal administrator can switch the flag, never on their own account.
 */
class ViewOnlyDemoTest extends TestCase
{
    use RefreshDatabase;

    private const CHANGE = 'This is a view-only demo account: you can look at everything, but changes are switched off.';

    private const CLOSED = 'This page is not part of the view-only demo: it would show private files or server details.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
    }

    // ------------------------------------------------------------------ the column

    public function test_the_migration_adds_the_flag_off_for_everybody_and_is_safe_to_run_twice(): void
    {
        $existing = User::factory()->create();
        $migration = require database_path('migrations/2026_10_24_100000_add_is_view_only_to_users_table.php');

        $this->assertTrue(Schema::hasColumn('users', ViewOnlyDemo::COLUMN));
        $this->assertFalse((bool) $existing->fresh()->is_view_only);

        // twice in a row (a re-run "Finish update"), then down and up again
        $migration->up();
        $migration->up();
        $this->assertTrue(Schema::hasColumn('users', ViewOnlyDemo::COLUMN));
        $migration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', ViewOnlyDemo::COLUMN));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('users', ViewOnlyDemo::COLUMN));
        $this->assertSame(0, (int) DB::table('users')->where(ViewOnlyDemo::COLUMN, true)->count());
    }

    public function test_the_flag_is_cast_to_a_boolean_and_never_mass_assignable(): void
    {
        $user = User::factory()->create();

        $user->fill(['is_view_only' => true, 'description' => 'filled']);
        $this->assertNull($user->getAttributes()['is_view_only'] ?? null);
        $user->save();
        $this->assertFalse((bool) $user->fresh()->is_view_only);

        $user->update(['is_view_only' => 1]);
        $this->assertFalse((bool) $user->fresh()->is_view_only);

        $user->forceFill(['is_view_only' => 1])->save();
        $this->assertTrue($user->fresh()->is_view_only);
        $this->assertTrue(ViewOnlyDemo::isViewOnlyUser($user->fresh()));
    }

    // ------------------------------------------------------------------ the owner, members and visitors

    public function test_the_owner_members_and_visitors_are_untouched(): void
    {
        $owner = $this->admin();
        $member = User::factory()->create();

        $page = $this->actingAs($owner)->get('/admin/users')->assertOk();
        $page->assertHeaderMissing(ViewOnlyDemo::HEADER);
        $this->assertStringNotContainsString(ViewOnlyDemo::NOTE_CLASS, (string) $page->getContent());
        $this->assertStringContainsString($member->email, (string) $page->getContent());

        // the owner's changes work
        $this->actingAs($owner)->post(route('admin.users.ban', $member))->assertRedirect();
        $this->assertNotNull($member->fresh()->banned_at);
        $this->actingAs($owner)->get('/admin/backups')->assertOk()->assertHeaderMissing(ViewOnlyDemo::HEADER);

        $this->actingAs($member->fresh())->get('/api/me')->assertHeaderMissing(ViewOnlyDemo::HEADER);
        $this->app['auth']->forgetGuards();
        $this->get('/robots.txt')->assertOk()->assertHeaderMissing(ViewOnlyDemo::HEADER);
    }

    // ------------------------------------------------------------------ refused changes

    public function test_changes_in_the_admin_are_refused_with_the_message(): void
    {
        $demo = $this->viewOnlyAdmin();
        $owner = $this->admin();
        $member = User::factory()->create();
        Setting::set('site_name', 'Owner Site', 'general');

        // a script (the settings save bar): 403 JSON with the message, nothing saved
        $this->actingAs($demo)->putJson('/admin/settings/save', ['values' => ['site_name' => 'hacked']])
            ->assertForbidden()
            ->assertHeader(ViewOnlyDemo::HEADER, '1')
            ->assertJson(['ok' => false, 'success' => false, 'message' => self::CHANGE]);
        $this->assertSame('Owner Site', Setting::query()->where('key', 'site_name')->value('value'));

        // a form: back to the page with the message, nothing changed
        $this->actingAs($demo)->from('/admin/users')->post(route('admin.users.ban', $member))
            ->assertStatus(303)
            ->assertRedirect('/admin/users')
            ->assertHeader(ViewOnlyDemo::HEADER, '1')
            ->assertSessionHasErrors(['view_only_demo' => self::CHANGE]);
        $this->assertNull($member->fresh()->banned_at);

        $this->actingAs($demo)->delete(route('admin.users.delete', $owner))->assertStatus(303);
        $this->actingAs($demo)->post(route('admin.bulk.users'), ['action' => 'make_admin', 'ids' => [$member->id]])->assertStatus(303);
        $this->actingAs($demo)->post(route('admin.users.toggle-admin', $member))->assertStatus(303);
        $this->actingAs($demo)->put(route('admin.users.update', $demo), ['username' => 'demo2', 'email' => 'x@example.com', 'password' => 'Changed1234!'])->assertStatus(303);
        $this->actingAs($demo)->postJson('/admin/shell/preferences', ['key' => 'developer_mode', 'value' => true])->assertForbidden();
        $this->actingAs($demo)->postJson('/admin/updates/start')->assertForbidden();
        $this->actingAs($demo)->postJson('/admin/update/finish')->assertForbidden();

        $this->assertNotNull($owner->fresh());
        $this->assertFalse((bool) $member->fresh()->is_admin);
        $this->assertSame($demo->username, $demo->fresh()->username);
        $this->assertTrue(password_verify('Demo-pass-1234', (string) $demo->fresh()->password));
    }

    public function test_changes_on_the_site_are_refused_too(): void
    {
        $demo = $this->viewOnlyAdmin();
        $video = $this->publicVideo();

        $this->actingAs($demo)->from('/profile/edit')->put(route('user.update'), ['description' => 'demo was here'])
            ->assertStatus(303)
            ->assertSessionHasErrors('view_only_demo');
        $this->assertNotSame('demo was here', $demo->fresh()->description);

        $this->actingAs($demo)->postJson('/api/like', ['id' => $video->id, 'type' => 'video'])
            ->assertForbidden()
            ->assertJson(['message' => self::CHANGE]);
        $this->actingAs($demo)->postJson('/api/videos/'.$video->uuid.'/comments', ['content' => 'demo attempt'])->assertForbidden();
        $this->actingAs($demo)->post('/contact', ['name' => 'x', 'email' => 'x@example.com', 'message' => 'demo attempt'])->assertStatus(303);
        $this->actingAs($demo)->postJson('/api/videos/upload18/chunk', [])->assertForbidden();

        $this->assertSame(0, DB::table('interactions')->count());
        $this->assertSame(0, DB::table('comments')->count());
    }

    public function test_sign_out_still_works_and_really_ends_the_remember_token(): void
    {
        $demo = $this->viewOnlyAdmin();
        DB::table('users')->where('id', $demo->id)->update(['remember_token' => 'old-remember-token']);
        $demo = $demo->fresh();

        $this->actingAs($demo)->get('/logout')->assertRedirect()->assertSessionDoesntHaveErrors('view_only_demo');
        $this->assertGuest('web');
        // kept, not rolled back: a "remember me" cookie of this account stops working
        $this->assertNotSame('old-remember-token', DB::table('users')->where('id', $demo->id)->value('remember_token'));
    }

    public function test_signing_out_of_the_app_deletes_its_token(): void
    {
        $demo = $this->viewOnlyAdmin();
        $token = $demo->createToken('demo-app')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/me')->assertOk()->assertHeader(ViewOnlyDemo::HEADER, '1');
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/me/logout')->assertNoContent();
        $this->assertSame(0, $demo->tokens()->count());
    }

    public function test_the_language_switch_still_works(): void
    {
        $demo = $this->viewOnlyAdmin();

        $locale = $this->actingAs($demo)->post('/update-locale', ['locale' => 'de']);
        $this->assertLessThan(500, $locale->getStatusCode());
        $locale->assertSessionDoesntHaveErrors('view_only_demo');
    }

    public function test_the_player_gets_the_answer_for_nothing_stored(): void
    {
        $demo = $this->viewOnlyAdmin();
        $video = $this->publicVideo();

        $this->actingAs($demo)->post(route('videos.view', $video->uuid))
            ->assertNoContent()
            ->assertHeader(ViewOnlyDemo::HEADER, '1');
        $this->assertSame(0, (int) $video->fresh()->views);

        $this->actingAs($demo)->postJson(route('videos.progress', $video->uuid), ['position' => 42, 'duration' => 100])
            ->assertStatus(202)
            ->assertExactJson(['stored' => false])
            ->assertHeader(ViewOnlyDemo::HEADER, '1');
    }

    // ------------------------------------------------------------------ closed pages

    public function test_the_private_pages_are_closed(): void
    {
        $demo = $this->viewOnlyAdmin();
        $member = User::factory()->create();
        AdminPreferences::set($demo, 'developer_mode', true);

        $pages = [
            '/admin/backups', '/admin/backups/download/backup_x.sql.gz', '/admin/logs-viewer', '/admin/logs',
            '/admin/exports', '/admin/users/export', '/admin/languages/en/export', '/admin/env-editor', '/admin/database',
            '/admin/phpinfo', '/admin/system', '/admin/preflight', '/admin/preflight/json', '/admin/artisan', '/admin/queue',
            '/admin/cron', '/admin/failed-jobs', '/admin/horizon-link', '/admin/impersonate/take/'.$member->id, '/admin/audit',
        ];

        foreach ($pages as $page) {
            $this->actingAs($demo)->from('/admin/users')->get($page)
                ->assertStatus(303)
                ->assertHeader(ViewOnlyDemo::HEADER, '1')
                ->assertSessionHasErrors(['view_only_demo' => self::CLOSED]);

            $this->actingAs($demo)->getJson($page)
                ->assertForbidden()
                ->assertJson(['message' => self::CLOSED]);
        }

        // closed in every method: the maintenance secret, the .env editor
        $this->actingAs($demo)->postJson('/admin/settings/maintenance/reveal', ['password' => 'x'])
            ->assertForbidden()->assertJson(['message' => self::CLOSED]);
        $this->actingAs($demo)->putJson('/admin/settings/update-env', ['APP_DEBUG' => 'true'])
            ->assertForbidden()->assertJson(['message' => self::CLOSED]);

        // the upload's settings carry the Upload18 key: refused as a change
        $this->actingAs($demo)->getJson('/api/videos/upload18/config')->assertForbidden()->assertJson(['message' => self::CHANGE]);
        $this->actingAs($demo)->getJson('/api/videos/upload18/status')->assertForbidden();

        // impersonation never started
        $this->assertFalse(session()->has('impersonated_by'));
        $this->assertSame($demo->id, auth()->id());
    }

    public function test_an_account_taken_over_by_a_view_only_account_is_view_only_too(): void
    {
        $demo = $this->viewOnlyAdmin();
        $member = User::factory()->create();

        $this->actingAs($member)->withSession(['impersonated_by' => $demo->id])
            ->from('/profile/edit')->put(route('user.update'), ['description' => 'taken over'])
            ->assertStatus(303)
            ->assertHeader(ViewOnlyDemo::HEADER, '1')
            ->assertSessionHasErrors('view_only_demo');
        $this->assertNotSame('taken over', $member->fresh()->description);
    }

    public function test_an_admin_who_took_over_a_view_only_member_can_leave(): void
    {
        $owner = $this->admin();
        $member = $this->viewOnly(User::factory()->create());

        $response = $this->actingAs($member)->withSession(['impersonated_by' => $owner->id, 'impersonator_guard' => 'web', 'impersonator_guard_using' => 'web'])
            ->get('/admin/impersonate/leave');

        $this->assertLessThan(500, $response->getStatusCode());
        $response->assertSessionDoesntHaveErrors('view_only_demo');
    }

    // ------------------------------------------------------------------ keeping nothing

    public function test_a_real_page_that_writes_keeps_nothing(): void
    {
        $demo = $this->viewOnlyAdmin();
        $owner = $this->admin();
        $notice = function (User $user): Notification {
            $notification = (new Notification())->forceFill([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\ViewOnlyProbe',
                'notifiable_type' => $user->getMorphClass(),
                'notifiable_id' => $user->id,
                'data' => ['message' => 'Probe', 'url' => '/'],
            ]);
            $notification->save();

            return $notification;
        };
        $demoNotice = $notice($demo);
        $ownerNotice = $notice($owner);

        // opening a notification marks it read: not for the view-only account
        $this->actingAs($demo)->get(route('user.notifications.click', $demoNotice))->assertRedirect()->assertHeader(ViewOnlyDemo::HEADER, '1');
        $this->assertNull($demoNotice->fresh()->read_at);

        $this->actingAs($owner)->get(route('user.notifications.click', $ownerNotice))->assertRedirect()->assertHeaderMissing(ViewOnlyDemo::HEADER);
        $this->assertNotNull($ownerNotice->fresh()->read_at);
    }

    public function test_what_a_page_writes_is_rolled_back(): void
    {
        // a page that writes and reads back its write, matched before the site's catch-all video address
        $router = $this->app['router'];
        $probe = (new \Illuminate\Routing\Route(['GET', 'HEAD'], 'view-only-probe/write', function () {
            DB::table('users')->where('id', auth()->id())->update(['description' => 'written by a page']);

            return response('written: '.DB::table('users')->where('id', auth()->id())->value('description'));
        }))->middleware('web')->setRouter($router)->setContainer($this->app);
        $routes = new \Illuminate\Routing\RouteCollection();
        $routes->add($probe);
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $routes->add($route);
        }
        $router->setRoutes($routes);

        $demo = $this->viewOnlyAdmin();
        $owner = $this->admin();

        // the page saw its own write, the database kept nothing
        $this->actingAs($demo)->get('/view-only-probe/write')->assertOk()->assertSee('written: written by a page')->assertHeader(ViewOnlyDemo::HEADER, '1');
        $this->assertNotSame('written by a page', $demo->fresh()->description);

        // the owner's page writes as always
        $this->actingAs($owner)->get('/view-only-probe/write')->assertOk()->assertHeaderMissing(ViewOnlyDemo::HEADER);
        $this->assertSame('written by a page', $owner->fresh()->description);
    }

    // ------------------------------------------------------------------ masking

    public function test_a_settings_page_masks_secrets_emails_and_addresses(): void
    {
        $demo = $this->viewOnlyAdmin();
        $owner = $this->admin();
        Setting::set('smtp_username', 'owner-mailbox-7731', 'email');
        Setting::set('smtp_host', 'mail.private-relay-55.test', 'email');
        Setting::set('mail_from_address', 'owner.private@relay-mail.test', 'email');

        $ownerPage = (string) $this->actingAs($owner)->get('/admin/settings/email')->assertOk()->getContent();
        $this->assertStringContainsString('owner-mailbox-7731', $ownerPage);
        $this->assertStringContainsString('owner.private@relay-mail.test', $ownerPage);

        $demoPage = $this->actingAs($demo)->get('/admin/settings/email')->assertOk()->assertHeader(ViewOnlyDemo::HEADER, '1');
        $html = (string) $demoPage->getContent();
        foreach (['owner-mailbox-7731', 'mail.private-relay-55.test', 'owner.private@relay-mail.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }
        $this->assertStringContainsString('o•••@r•••.test', $html);
        $this->assertStringContainsString('no-store', (string) $demoPage->headers->get('Cache-Control'));
        // the note sits above the settings save bar while it shows
        $this->assertStringContainsString('body:has(.ad-savebar:not([hidden])) .'.ViewOnlyDemo::NOTE_CLASS, $html);
    }

    public function test_the_old_settings_page_masks_the_values_of_secrets(): void
    {
        config(['admin.ui' => 'v1']);
        $demo = $this->viewOnlyAdmin();
        Setting::set('smtp_password', 'Smtp-Secret-7f3a91', 'email');
        Setting::set('smtp_username', 'owner-mailbox-7731', 'email');

        $html = (string) $this->actingAs($demo)->get('/admin/settings?tab=email')->assertOk()->getContent();

        $this->assertStringNotContainsString('Smtp-Secret-7f3a91', $html);
        $this->assertStringNotContainsString('owner-mailbox-7731', $html);
        $this->assertSame(1, substr_count($html, 'class="'.ViewOnlyDemo::NOTE_CLASS.'"'));
    }

    public function test_json_answers_are_masked_and_stay_valid_json(): void
    {
        $demo = $this->viewOnlyAdmin();
        $owner = $this->admin();
        $css = "/* \"ask\" admin@owner-site.test from 203.0.113.77, files in /home/owner/public_html/x.css */\nbody{color:red}";
        Setting::set('custom_css', $css, 'appearance');

        $this->actingAs($owner)->getJson('/admin/settings/values?keys=custom_css')->assertOk()->assertJsonPath('values.custom_css', $css);

        $response = $this->actingAs($demo)->getJson('/admin/settings/values?keys=custom_css')->assertOk()->assertHeader(ViewOnlyDemo::HEADER, '1');
        $content = (string) $response->getContent();
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded, 'the masked answer is valid JSON');
        $value = $decoded['values']['custom_css'];
        $this->assertStringNotContainsString('admin@owner-site.test', $value);
        $this->assertStringNotContainsString('203.0.113.77', $value);
        $this->assertStringNotContainsString('/home/owner', $value);
        $this->assertStringContainsString('a•••@o•••.test', $value);
        $this->assertStringContainsString('203.0.x.x', $value);
        $this->assertStringContainsString('"ask"', $value);
        $this->assertStringContainsString('body{color:red}', $value);
        $this->assertStringNotContainsString(ViewOnlyDemo::NOTE_CLASS, $content);
    }

    public function test_members_emails_and_addresses_are_masked_but_the_sites_own_address_stays(): void
    {
        // a test server on an IP address (APP_URL http://10.20.30.40): every link carries the IP, which stays
        config(['app.url' => 'http://10.20.30.40']);
        $demo = $this->viewOnlyAdmin();
        $member = User::factory()->create(['email' => 'member.one@private-mail.test', 'last_login_ip' => '203.0.113.9']);

        $list = (string) $this->actingAs($demo)->get('http://10.20.30.40/admin/users')->assertOk()->getContent();
        $this->assertStringNotContainsString('member.one@private-mail.test', $list);
        $this->assertStringContainsString('m•••@p•••.test', $list);
        $this->assertStringContainsString('http://10.20.30.40/admin', $list);

        $record = (string) $this->actingAs($demo)->get('http://10.20.30.40/admin/users/'.$member->id.'/edit')->assertOk()->getContent();
        $this->assertStringNotContainsString('203.0.113.9', $record);
        $this->assertStringContainsString('203.0.x.x', $record);
        $this->assertStringContainsString('http://10.20.30.40/admin/users', $record);
    }

    // ------------------------------------------------------------------ searches

    public function test_searches_never_match_the_masked_email_addresses(): void
    {
        $demo = $this->viewOnlyAdmin();
        $owner = $this->admin();
        $target = User::factory()->create(['username' => 'ownerprobe', 'email' => 'secret.owner.box@private-owner.test', 'email_verified_at' => null]);
        DB::table('transactions')->insert([
            'payment_provider' => 'stripe', 'stripe_id' => 'ch_view_only_probe', 'amount' => 500, 'tax' => 0, 'fee' => 0, 'date' => now(),
            'user_id' => $target->id, 'name' => 'Probe', 'address' => 'Street 1', 'city' => 'Town', 'postal_code' => '10000', 'country' => 'US',
        ]);

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);

            // Members: a part of the address finds nobody; the name still finds the member. The old admin caches its
            // list for a minute by its search: the demo's list is never shared that way, in either direction.
            foreach (['secret.owner.b', 'private-owner', 'owner.box@'] as $guess) {
                $this->actingAs($demo)->get('/admin/users?search='.urlencode($guess))->assertOk()->assertDontSee('ownerprobe');
                $this->actingAs($owner)->get('/admin/users?search='.urlencode($guess))->assertOk()->assertSee('ownerprobe');
                $this->actingAs($demo)->get('/admin/users?search='.urlencode($guess))->assertOk()->assertDontSee('ownerprobe');
            }
            $this->actingAs($demo)->get('/admin/users?search=ownerprobe')->assertOk()->assertSee('ownerprobe')->assertDontSee('secret.owner.box');

            // e-mail confirmation and invoices search their members the same way
            $this->actingAs($demo)->get('/admin/verification?filter=all&search=private-owner')->assertOk()->assertDontSee('ownerprobe');
            $this->actingAs($owner)->get('/admin/verification?filter=all&search=private-owner')->assertOk()->assertSee('ownerprobe');
            $this->actingAs($demo)->get('/admin/verification?filter=all&search=ownerprobe')->assertOk()->assertSee('ownerprobe');

            $this->actingAs($demo)->get('/admin/invoice?user=private-owner')->assertOk()->assertDontSee('ownerprobe');
            $this->actingAs($owner)->get('/admin/invoice?user=private-owner')->assertOk()->assertSee('ownerprobe');
            $this->actingAs($demo)->get('/admin/invoice?user=ownerprobe')->assertOk()->assertSee('ownerprobe');
        }

        // the admin's search box
        config(['admin.ui' => 'v2']);
        $names = fn (User $user, string $q): array => array_column((array) $this->actingAs($user)->getJson('/admin/shell/search?q='.urlencode($q))->assertOk()->json('members'), 't');
        $this->assertNotContains('ownerprobe', $names($demo, 'private-owner'));
        $this->assertNotContains('ownerprobe', $names($demo, 'secret.owner'));
        $this->assertContains('ownerprobe', $names($owner, 'private-owner'));
        $this->assertContains('ownerprobe', $names($demo, 'ownerpro'));
        $this->assertStringNotContainsString('secret.owner.box', (string) $this->actingAs($demo)->getJson('/admin/shell/search?q=ownerpro')->getContent());

        // nothing of it stays for the next request of another account
        $this->assertSame(1, User::query()->where('email', 'like', '%private-owner%')->count());
    }

    public function test_the_email_search_rule_keeps_the_other_conditions_and_their_values(): void
    {
        $alpha = User::factory()->create(['username' => 'alpha-probe', 'email' => 'alpha@hidden-one.test']);
        $beta = User::factory()->create(['username' => 'beta-probe', 'email' => 'beta@hidden-two.test']);
        $both = [$alpha->id, $beta->id];
        $names = static fn ($query): array => $query->orderBy('username')->pluck('username')->all();

        DB::table('transactions')->insert([
            'payment_provider' => 'stripe', 'stripe_id' => 'ch_view_only_rule', 'amount' => 500, 'tax' => 0, 'fee' => 0, 'date' => now(),
            'user_id' => $alpha->id, 'name' => 'Probe', 'address' => 'Street 1', 'city' => 'Town', 'postal_code' => '10000', 'country' => 'US',
        ]);
        $this->assertSame(['alpha-probe'], $names(User::query()->where('email', 'like', '%hidden-one%')));
        $this->assertSame(1, Transaction::query()->whereHas('user', fn ($q) => $q->where('email', 'like', '%hidden%'))->count());

        ViewOnlyDemo::withoutEmailSearch(function () use ($names, $alpha, $beta, $both): void {
            // a match on the address finds nothing, the conditions around it keep their values in their places
            $this->assertSame([], $names(User::query()->where('email', 'like', '%hidden-one%')));
            $this->assertSame([], $names(User::query()->where('users.email', 'LIKE', '%hidden%')->whereIn('id', $both)));
            $this->assertSame([], $names(User::query()->whereLike('email', '%hidden%')));
            $this->assertSame(['beta-probe'], $names(User::query()
                ->where(fn ($q) => $q->where('username', 'like', '%beta%')->orWhere('email', 'like', '%hidden-one%'))
                ->where('id', '>=', $alpha->id)
                ->whereIn('id', $both)));
            $this->assertSame(['alpha-probe'], $names(User::query()
                ->where(fn ($q) => $q->where('email', 'like', '%hidden-two%')->orWhere('username', 'alpha-probe'))
                ->whereIn('id', $both)));

            // the NOT forms leave nothing out (they would tell the same)
            $this->assertSame(['alpha-probe', 'beta-probe'], $names(User::query()->whereNot('email', 'like', '%hidden-one%')->whereIn('id', $both)));
            $this->assertSame(['alpha-probe', 'beta-probe'], $names(User::query()->where('email', 'not like', '%hidden-one%')->whereIn('id', $both)));

            // through a relation: the invoices' member search (the name still finds the invoice)
            $this->assertSame(0, Transaction::query()->whereHas('user', fn ($q) => $q->where('email', 'like', '%hidden%'))->count());
            $this->assertSame(1, Transaction::query()->whereHas('user', fn ($q) => $q->where('username', 'like', '%alpha%')->orWhere('email', 'like', '%alpha%'))->count());

            // an exact address (signing in, a password reset) is not a search: it stays
            $this->assertSame(['alpha-probe'], $names(User::query()->where('email', 'alpha@hidden-one.test')));
            $this->assertSame([$beta->id], User::query()->whereKey($beta->id)->pluck('id')->all());
        });

        // and afterwards as always
        $this->assertSame(['alpha-probe'], $names(User::query()->where('email', 'like', '%hidden-one%')));
        $this->assertSame(['alpha-probe', 'beta-probe'], $names(User::query()->where('email', 'like', '%hidden%')));
    }

    public function test_every_email_search_of_the_site_is_one_the_rule_covers(): void
    {
        // Each runs as an App\Models\User query (directly or through whereHas('user')), which the rule covers. A new
        // e-mail search must be one too (not DB::table('users'), a join or raw SQL) and is then counted here.
        $known = [
            'app/Filters/UserFilters.php' => 1,
            'app/Http/Controllers/Admin/InvoiceController.php' => 2,
            'app/Http/Controllers/Admin/UserVerificationController.php' => 1,
            'app/Support/AdminSearchIndex.php' => 1,
        ];
        $pattern = '/email[\'"`]?\s*,\s*[\'"](?:not\s+)?(?:i?like|regexp|rlike)[\'"]|whereLike\(\s*[\'"][a-z_.]*email[\'"]|Raw\(\s*[\'"][^\'"]*email[^\'"]*\b(?:like|regexp|rlike)\b/i';
        $found = [];

        foreach (File::allFiles(app_path()) as $file) {
            $path = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()))), '/');

            // the commands run on the server, never for a visitor
            if (str_starts_with($path, 'app/Console/') || ! str_ends_with($path, '.php')) {
                continue;
            }

            if (($count = preg_match_all($pattern, $file->getContents())) > 0) {
                $found[$path] = $count;
            }
        }

        ksort($found);
        $this->assertSame($known, $found, 'a new e-mail search: make it an App\Models\User query (ViewOnlyDemo::withoutEmailSearch() covers those), then count it here');
    }

    // ------------------------------------------------------------------ the note

    public function test_pages_carry_one_note_but_the_player_embed_and_frames_do_not(): void
    {
        $demo = $this->viewOnlyAdmin();
        $video = $this->publicVideo();

        $html = (string) $this->actingAs($demo)->get('/admin/users')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'class="'.ViewOnlyDemo::NOTE_CLASS.'"'));
        $this->assertMatchesRegularExpression('/<body\b[^>]*><style>\.'.ViewOnlyDemo::NOTE_CLASS.'\{/', $html);
        $this->assertStringContainsString('View-only demo: look around freely, changes are switched off.', $html);

        $framed = (string) $this->actingAs($demo)->withHeaders(['Sec-Fetch-Dest' => 'iframe'])->get('/admin/users')->assertOk()->getContent();
        $this->assertStringNotContainsString(ViewOnlyDemo::NOTE_CLASS, $framed);

        $embed = $this->actingAs($demo)->withHeaders(['Sec-Fetch-Dest' => 'document'])->get(route('video.embed', $video));
        $this->assertLessThan(500, $embed->getStatusCode());
        $embed->assertHeader(ViewOnlyDemo::HEADER, '1');
        $this->assertStringNotContainsString(ViewOnlyDemo::NOTE_CLASS, (string) $embed->getContent());
    }

    // ------------------------------------------------------------------ the switch

    public function test_an_administrator_switches_the_flag_and_the_members_list_shows_a_badge(): void
    {
        $owner = $this->admin();
        $demo = User::factory()->admin()->create();

        $record = (string) $this->actingAs($owner)->get(route('admin.users.edit', $demo))->assertOk()->getContent();
        $this->assertStringContainsString('View-only demo account', $record);
        $this->assertStringContainsString('action="'.route('admin.users.view-only', $demo).'"', $record);
        $this->assertStringContainsString('data-view-only="0"', $record);

        $this->actingAs($owner)->from(route('admin.users.edit', $demo))
            ->post(route('admin.users.view-only', $demo), ['view_only' => '1'])
            ->assertRedirect(route('admin.users.edit', $demo))
            ->assertSessionHas('success');
        $this->assertTrue($demo->fresh()->is_view_only);

        // the same click again changes nothing
        $this->actingAs($owner)->post(route('admin.users.view-only', $demo), ['view_only' => '1'])->assertRedirect();
        $this->assertTrue($demo->fresh()->is_view_only);

        $list = (string) $this->actingAs($owner)->get('/admin/users')->assertOk()->getContent();
        $this->assertSame(1, substr_count($list, 'data-view-only-badge="1"'));
        $this->assertStringContainsString('View-only', $list);
        $this->assertStringContainsString('data-view-only="1"', (string) $this->actingAs($owner)->get(route('admin.users.edit', $demo))->getContent());

        // the old admin shows it too
        config(['admin.ui' => 'v1']);
        $this->assertStringContainsString('data-view-only-badge', (string) $this->actingAs($owner)->get('/admin/users')->assertOk()->getContent());
        $old = (string) $this->actingAs($owner)->get(route('admin.users.edit', $demo))->assertOk()->getContent();
        $this->assertStringContainsString('data-view-only="1"', $old);
        $this->assertStringContainsString('action="'.route('admin.users.view-only', $demo).'"', $old);

        $this->actingAs($owner)->post(route('admin.users.view-only', $demo), ['view_only' => '0'])->assertRedirect();
        $this->assertFalse($demo->fresh()->is_view_only);

        $this->actingAs($owner)->post(route('admin.users.view-only', $demo), [])->assertSessionHasErrors('view_only');
    }

    public function test_an_administrator_cannot_make_their_own_account_view_only(): void
    {
        $owner = $this->admin();

        $record = (string) $this->actingAs($owner)->get(route('admin.users.edit', $owner))->assertOk()->getContent();
        $this->assertStringNotContainsString('action="'.route('admin.users.view-only', $owner).'"', $record);

        $this->actingAs($owner)->post(route('admin.users.view-only', $owner), ['view_only' => '1'])
            ->assertRedirect()
            ->assertSessionHasErrors(['user' => 'You cannot make your own account view-only.']);
        $this->assertFalse((bool) $owner->fresh()->is_view_only);
    }

    public function test_a_view_only_account_or_a_member_can_never_switch_the_flag(): void
    {
        $demo = $this->viewOnlyAdmin();
        $other = $this->viewOnly(User::factory()->admin()->create());
        $member = User::factory()->create();

        // the guard refuses it
        $this->actingAs($demo)->post(route('admin.users.view-only', $other), ['view_only' => '0'])
            ->assertStatus(303)->assertSessionHasErrors('view_only_demo');
        $this->assertTrue($other->fresh()->is_view_only);

        // and so does the action itself, should the guard ever be skipped
        $this->withoutMiddleware(ViewOnlyDemo::class);
        $this->actingAs($demo)->post(route('admin.users.view-only', $other), ['view_only' => '0'])->assertForbidden();
        $this->actingAs($demo)->post(route('admin.users.view-only', $demo), ['view_only' => '0'])->assertForbidden();
        $this->assertTrue($other->fresh()->is_view_only);
        $this->assertTrue($demo->fresh()->is_view_only);

        // a member: no admin, and the profile form cannot write the flag
        $this->actingAs($member)->post(route('admin.users.view-only', $member), ['view_only' => '1'])->assertNotFound();
        $this->actingAs($member)->put(route('user.update'), ['description' => 'mine', 'is_view_only' => '1', 'is_admin' => '1']);
        $this->assertFalse((bool) $member->fresh()->is_view_only);
        $this->assertFalse((bool) $member->fresh()->is_admin);

        // nor can the admin's account form
        $owner = $this->admin();
        $this->actingAs($owner)->put(route('admin.users.update', $member), ['username' => $member->username, 'email' => $member->email, 'is_view_only' => '1']);
        $this->assertFalse((bool) $member->fresh()->is_view_only);
    }

    public function test_before_finish_update_added_the_column_nothing_breaks(): void
    {
        $owner = $this->admin();
        $member = User::factory()->create();
        $migration = require database_path('migrations/2026_10_24_100000_add_is_view_only_to_users_table.php');
        $migration->down();
        $owner = User::query()->find($owner->id);

        $this->assertFalse(ViewOnlyDemo::available());
        $this->assertFalse(ViewOnlyDemo::isViewOnlyUser($owner));

        $record = (string) $this->actingAs($owner)->get(route('admin.users.edit', $member))->assertOk()->getContent();
        $this->assertStringNotContainsString('View-only demo account', $record);
        $this->actingAs($owner)->get('/admin/users')->assertOk()->assertHeaderMissing(ViewOnlyDemo::HEADER);
        $this->actingAs($owner)->post(route('admin.users.view-only', $member), ['view_only' => '1'])
            ->assertRedirect()->assertSessionHasErrors('user');

        $migration->up();
    }

    public function test_the_switch_shows_the_safe_order_and_asks_before_an_administrator_gets_its_rights_back(): void
    {
        $owner = $this->admin();
        $member = User::factory()->create();
        $demo = $this->viewOnlyAdmin();
        $viewOnlyMember = $this->viewOnly(User::factory()->create());
        $first = 'To open the admin, it must be an administrator too: turn this on first, then make it an administrator.';
        $off = 'It can change everything in this admin again. If other people know its password, give it a new password first.';

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);

            // a member: view-only first, then administrator (never a full administrator in between)
            $page = (string) $this->actingAs($owner)->get(route('admin.users.edit', $member))->assertOk()->getContent();
            $this->assertStringContainsString(e($first), $page, $ui);
            $this->assertStringNotContainsString(e($off), $page, $ui);

            // a view-only member: only that it must be an administrator to open the admin
            $page = (string) $this->actingAs($owner)->get(route('admin.users.edit', $viewOnlyMember))->assertOk()->getContent();
            $this->assertStringContainsString(e('To open the admin, it must be an administrator too.'), $page, $ui);
            $this->assertStringNotContainsString(e($first), $page, $ui);
            $this->assertStringNotContainsString('If other people know its password', $page, $ui);

            // a view-only administrator: turning it off asks first
            $page = (string) $this->actingAs($owner)->get(route('admin.users.edit', $demo))->assertOk()->getContent();
            $this->assertStringContainsString('Turn off view-only?', $page, $ui);
            $this->assertStringContainsString('If other people know its password, give it a new password first.', $page, $ui);
            $this->assertStringNotContainsString(e($first), $page, $ui);
        }
    }

    // ------------------------------------------------------------------ undo back to an older version

    public function test_undo_back_to_a_version_without_view_only_accounts_waits_while_there_are_some(): void
    {
        $this->assertNull(ViewOnlyDemo::undoRefusal('1.2.1'), 'no view-only account: undo as always');

        $demo = $this->viewOnlyAdmin();

        foreach (['1.2.1', '1.2.0', '0.9.9', '', 'not a version'] as $older) {
            $this->assertStringStartsWith('Undo has to wait: this site has view-only demo accounts', (string) ViewOnlyDemo::undoRefusal($older), $older);
        }

        foreach ([ViewOnlyDemo::SINCE_VERSION, '1.2.3', '1.3.0', '2.0.0'] as $knows) {
            $this->assertNull(ViewOnlyDemo::undoRefusal($knows), $knows);
        }

        // a view-only member counts too: the older version would let it change things
        $demo->forceFill(['is_admin' => false])->save();
        $this->assertNotNull(ViewOnlyDemo::undoRefusal('1.2.1'));

        $demo->forceFill([ViewOnlyDemo::COLUMN => false])->save();
        $this->assertNull(ViewOnlyDemo::undoRefusal('1.2.1'));

        // before "Finish update" added the column there is no view-only account
        $migration = require database_path('migrations/2026_10_24_100000_add_is_view_only_to_users_table.php');
        $migration->down();
        $this->assertNull(ViewOnlyDemo::undoRefusal('1.2.1'));
        $migration->up();
    }

    public function test_the_updater_does_not_undo_to_an_older_version_while_view_only_accounts_exist(): void
    {
        $root = storage_path('framework/testing/view-only-undo-'.Str::lower(Str::random(10)));
        $owner = $this->admin();
        $demo = $this->viewOnlyAdmin();

        // the newest update, from $from to $to, done and undoable while $to runs
        $updater = function (string $from, string $to) use ($root): Updater {
            File::deleteDirectory($root);
            $id = $from.'-to-'.$to.'-20261024120000';
            File::ensureDirectoryExists($root.'/storage/app/updates/backups/'.$id.'/files/app');
            File::put($root.'/storage/app/updates/backups/'.$id.'/files/app/Old.php', '<?php // old');
            File::put($root.'/storage/app/updates/backups/'.$id.'/backup.json', json_encode([
                'format' => 1, 'from' => $from, 'to' => $to, 'created_at' => time() - 600, 'admin_id' => 1, 'status' => 'complete',
                'size' => 12, 'entries' => [['path' => 'app/Old.php', 'action' => 'replaced', 'old' => str_repeat('a', 64), 'new' => str_repeat('b', 64)]],
            ]));
            File::put($root.'/storage/app/updates/state.json', json_encode([
                'installed_version' => $to, 'previous_version' => $from, 'updated_at' => time() - 600, 'backup_id' => $id, 'admin_id' => 1,
            ]));

            return new Updater(new UpdatePaths($root, $root.'/storage'), new ReleaseCheck(), new DatabaseBackup(), new Downloader(), $to);
        };

        try {
            // back to 1.2.1 (no view-only accounts there): it waits, and nothing was started
            $answer = $updater('1.2.1', ViewOnlyDemo::SINCE_VERSION)->undo($owner);
            $this->assertFalse($answer['ok'], 'Updater::undo() asks ViewOnlyDemo::undoRefusal() before it plans');
            $this->assertStringStartsWith('Undo has to wait: this site has view-only demo accounts', (string) $answer['message']);
            $this->assertFileDoesNotExist($root.'/storage/app/updates/plan.json');

            // back to a version that knows them: as always
            $this->assertTrue($updater(ViewOnlyDemo::SINCE_VERSION, '1.2.3')->undo($owner)['ok']);

            // without view-only accounts: as always
            $demo->forceFill([ViewOnlyDemo::COLUMN => false])->save();
            $this->assertTrue($updater('1.2.1', ViewOnlyDemo::SINCE_VERSION)->undo($owner)['ok']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    // ------------------------------------------------------------------ every admin page, old and new admin

    #[\PHPUnit\Framework\Attributes\DataProvider('adminPages')]
    public function test_every_admin_page_opens_or_is_closed_for_the_view_only_account(string $routeName, bool $closed): void
    {
        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);
            $demo = $this->viewOnlyAdmin();
            AdminPreferences::set($demo, 'developer_mode', true);

            $response = $this->actingAs($demo)->from(url('/admin'))->get(route($routeName));
            $response->assertHeader(ViewOnlyDemo::HEADER, '1');

            if ($closed) {
                $response->assertStatus(303)->assertSessionHasErrors(['view_only_demo' => self::CLOSED]);

                continue;
            }

            // Money and its payment keys are pages of the new admin: the old one sends them to its settings tab.
            if ($ui === 'v1' && str_starts_with($routeName, 'admin.money.')) {
                $response->assertStatus(302);
                $this->assertStringContainsString('/admin/settings', (string) $response->headers->get('Location'));

                continue;
            }

            $response->assertOk();
            $html = (string) $response->getContent();
            $this->assertSame(1, substr_count($html, 'class="'.ViewOnlyDemo::NOTE_CLASS.'"'), "{$routeName} ({$ui}) has one note");
            $this->assertStringNotContainsString($demo->email, $html, "{$routeName} ({$ui}) masks e-mail addresses");
        }
    }

    public static function adminPages(): array
    {
        $open = ['admin.index', 'admin.activities.index', 'admin.announcements.index', 'admin.api-tokens.index',
            'admin.avdb-import.index', 'admin.broadcast.index', 'admin.categories.index', 'admin.categories.create', 'admin.comments.index',
            'admin.contacts.index', 'admin.devices.index', 'admin.email-test.index', 'admin.featured.index', 'admin.interactions.index',
            'admin.invoices.index', 'admin.ip-bans.index', 'admin.languages.index', 'admin.languages.create', 'admin.media.index',
            'admin.notifications.index', 'admin.pages.index', 'admin.pages.create', 'admin.password-resets.index', 'admin.plans.index',
            'admin.plans.create', 'admin.playlists.index', 'admin.premiums.index', 'admin.redirects.index', 'admin.reports.index',
            'admin.searches.index', 'admin.sessions.index', 'admin.settings.index', 'admin.spams.index', 'admin.storage.index',
            'admin.subscriptions.index', 'admin.tags.index', 'admin.thumbnails.index', 'admin.transactions.index', 'admin.users.index',
            'admin.verification.index', 'admin.videos.index', 'admin.views.index', 'admin.xvid-import.index', 'admin.updates.index',
            'admin.money.index', 'admin.money.setup', 'admin.appearance.index'];
        $closed = ['admin.artisan.index', 'admin.backups.index', 'admin.cron.index', 'admin.database.index', 'admin.env-editor.index',
            'admin.exports.index', 'admin.failed-jobs.index', 'admin.logs.index', 'admin.phpinfo.index', 'admin.preflight.index',
            'admin.queue.index', 'admin.system.index', 'admin.audit.index'];

        $pages = [];
        foreach ($open as $name) {
            $pages[$name] = [$name, false];
        }
        foreach ($closed as $name) {
            $pages[$name.' (closed)'] = [$name, true];
        }

        return $pages;
    }

    // ------------------------------------------------------------------ helpers

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function viewOnlyAdmin(): User
    {
        return $this->viewOnly(User::factory()->admin()->create(['password' => 'Demo-pass-1234']));
    }

    private function viewOnly(User $user): User
    {
        $user->forceFill([ViewOnlyDemo::COLUMN => true])->save();

        return $user->fresh();
    }

    private function publicVideo(): Video
    {
        $category = Category::query()->create(['title' => 'Demo', 'slug' => 'demo-'.uniqid(), 'icon' => 'film', 'in_menu' => true]);

        return Video::factory()
            ->for($category)
            ->for(User::factory()->create(), 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create(['published_at' => now()->subDay(), 'uploaded_at' => now()->subDay(), 'views' => 0]);
    }
}
