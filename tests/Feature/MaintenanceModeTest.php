<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The owner's maintenance mode (Settings > Maintenance):
 *  - the API is closed too (it kept answering while the pages showed the maintenance card): visitors' and members'
 *    scripts and apps get 503 with the site's sentence and Retry-After; administrators (who browse the site during
 *    maintenance), the admin's own API, signing in, payment notifications from Stripe and PayPal, and an upload that is
 *    already running (the Upload18 routes, unchanged) still pass;
 *  - the maintenance page promises what it does: it checks again every few minutes (the meta refresh) and says that
 *    interval, instead of "a few minutes" while it waited for the 3600-second Retry-After meant for search engines;
 *  - whoever sees the site during maintenance (an administrator, the secret preview link, an allowed address) sees a
 *    strip that says maintenance is on, with the way to its settings for administrators.
 */
class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.maintenance_mode' => true,
            'site.maintenance_allow_admins' => true,
            'site.maintenance_retry_after' => 3600,
            'site.maintenance_message' => 'We are currently performing maintenance. Please check back soon.',
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
    }

    public function test_the_api_is_closed_for_visitors_and_members(): void
    {
        $this->getJson('/api/videos')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '3600')
            ->assertJson(['maintenance' => true, 'message' => __('We are updating the site. Try again soon.')]);

        $member = User::factory()->create();
        $this->actingAs($member, 'sanctum')->getJson('/api/me')->assertStatus(503);
        $this->actingAs($member, 'sanctum')->postJson('/api/like', ['video' => 1])->assertStatus(503);
    }

    public function test_the_owners_own_message_is_the_apis_message(): void
    {
        config(['site.maintenance_message' => 'Back at five with a new player.']);

        $this->getJson('/api/videos')->assertStatus(503)->assertJson(['message' => 'Back at five with a new player.']);
    }

    public function test_administrators_signing_in_payments_and_running_uploads_still_pass(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'sanctum')->getJson('/api/me')->assertOk();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?q=a')->assertStatus(200);

        $this->app['auth']->forgetGuards();
        // signing in stays open (an administrator has to be able to sign in)
        $this->assertNotSame(503, $this->postJson('/api/login', ['username' => 'nobody', 'password' => 'wrong-password'])->getStatusCode());
        // the payment providers' notifications are servers, not visitors
        $this->assertNotSame(503, $this->postJson('/paypal-webhook', [])->getStatusCode());
        $this->assertNotSame(503, $this->postJson('/stripe-webhook', [])->getStatusCode());
        // an upload that is already running is never cut by the maintenance switch (the Upload18 routes run as before)
        $member = User::factory()->create();
        $this->assertNotSame(503, $this->actingAs($member, 'sanctum')->getJson('/api/videos/upload18/status?uploadId=missing')->getStatusCode());

        // with "Admins can still browse the site" off, the admin's own API stays open like the admin pages
        config(['site.maintenance_allow_admins' => false]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?q=a')->assertStatus(200);
        $this->actingAs($admin, 'sanctum')->getJson('/api/me')->assertStatus(503);
    }

    public function test_the_page_says_how_often_it_checks_and_does_it(): void
    {
        $html = (string) $this->get('/trend')->assertStatus(503)->assertHeader('Retry-After', '3600')->getContent();

        $this->assertStringContainsString('<meta http-equiv="refresh" content="120">', $html);
        $this->assertStringContainsString(e(trans_choice('{1} This page checks every minute and shows the site again as soon as it is back.|[2,*] This page checks every :count minutes and shows the site again as soon as it is back.', 2, ['count' => 2])), $html);
        $this->assertStringNotContainsString('It takes a few minutes', $html);

        // a shorter "Tell Google to come back after" makes the page check sooner, and it says so
        config(['site.maintenance_retry_after' => 30]);
        $html = (string) $this->get('/trend')->assertStatus(503)->getContent();
        $this->assertStringContainsString('<meta http-equiv="refresh" content="30">', $html);
        $this->assertStringContainsString(e(__('This page checks every 30 seconds and shows the site again as soon as it is back.')), $html);

        // the owner's own message comes first, the promise follows
        config(['site.maintenance_retry_after' => 60, 'site.maintenance_message' => 'Back at five, with a new player.']);
        $html = (string) $this->get('/trend')->assertStatus(503)->getContent();
        $this->assertStringContainsString('<meta http-equiv="refresh" content="60">', $html);
        $this->assertStringContainsString(e('Back at five, with a new player.').' '.e(trans_choice('{1} This page checks every minute and shows the site again as soon as it is back.|[2,*] This page checks every :count minutes and shows the site again as soon as it is back.', 1, ['count' => 1])), $html);
    }

    public function test_an_administrator_browsing_the_site_sees_that_maintenance_is_on(): void
    {
        $admin = User::factory()->admin()->create();

        $html = (string) $this->actingAs($admin)->get('/trend')->assertOk()->getContent();
        $this->assertStringContainsString('id="avd-maintenance-notice"', $html);
        $this->assertStringContainsString(e(__('Maintenance mode is on. Visitors see the maintenance page.')), $html);
        $this->assertStringContainsString('href="'.e(route('admin.settings.page', ['page' => 'maintenance'])).'"', $html);
        // under a header fixed over the banner ("Over the banner") the strip stays on top and the header moves below it
        // by the strip's height: the fixed header covered the strip and its link
        $this->assertStringContainsString('html[data-avd-header-style="transparent_overlay"] body.has-hero-zone .avd-maintenance-notice{position:sticky', $html);
        $this->assertStringContainsString("setProperty('--avd-maintenance-h'", $html);

        // the notice is never on the maintenance page itself, nor for a member or a guest when maintenance is off
        $this->app['auth']->forgetGuards();
        $this->assertStringNotContainsString('avd-maintenance-notice', (string) $this->get('/trend')->assertStatus(503)->getContent());
        config(['site.maintenance_mode' => false]);
        $this->assertStringNotContainsString('avd-maintenance-notice', (string) $this->actingAs($admin)->get('/trend')->assertOk()->getContent());
    }

    public function test_the_password_pages_stay_open_so_an_administrator_can_get_back_in(): void
    {
        // The always-open list said "password/*", a path the site never had: "Forgot password" and "Choose a new password"
        // (/forgot-password, /reset-password) showed the maintenance page, so an administrator who forgot the password
        // could not get back in to switch maintenance off.
        $this->get('/login')->assertOk();

        $forgot = $this->get('/forgot-password');
        $forgot->assertOk();
        $this->assertStringContainsString('name="email"', (string) $forgot->getContent());

        $this->assertNotSame(503, $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.com'])->getStatusCode());
        $this->get('/reset-password/made-up-token?email=nobody@example.com')->assertOk();
        $this->assertNotSame(503, $this->from('/reset-password/made-up-token')->post('/reset-password', [
            'token' => 'made-up-token',
            'email' => 'nobody@example.com',
            'password' => 'A-new-password-42',
            'password_confirmation' => 'A-new-password-42',
        ])->getStatusCode());

        // everything else still shows the maintenance page
        $this->get('/register')->assertStatus(503);
    }

    public function test_the_secret_preview_link_and_allowed_addresses_see_the_notice_without_the_admin_link(): void
    {
        config(['site.maintenance_allowed_ips' => '203.0.113.9']);

        $html = (string) $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/trend')->assertOk()->getContent();
        $this->assertStringContainsString('id="avd-maintenance-notice"', $html);
        $this->assertStringContainsString(e(__('Maintenance mode is on. Visitors see the maintenance page; this browser sees the site.')), $html);
        $this->assertStringNotContainsString('href="'.e(route('admin.settings.page', ['page' => 'maintenance'])).'"', $html);
    }

    /**
     * The secret preview link and an allowed address are guests: their pages (the strip included) were public and
     * edge-cacheable, so a CDN could hand the open site to every visitor while it was closed, and the strip for minutes
     * after maintenance ended. Whatever passes the closed site is that one browser's answer.
     */
    public function test_what_passes_the_closed_site_is_never_kept_by_a_shared_cache(): void
    {
        config(['site.maintenance_secret' => 'preview-only-for-me', 'sanctum.stateful' => ['stateful.example']]);
        $this->get('/preview-only-for-me')->assertRedirect('/');
        foreach (['/', '/trend'] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertStringContainsString('id="avd-maintenance-notice"', (string) $response->getContent());
            $this->assertNeverShared($response, 'preview link '.$path);
        }
        // the page's own API calls (the site's scripts share the session)
        $api = $this->withHeaders(['Referer' => 'https://stateful.example/'])->getJson('/api/videos')->assertOk();
        $this->assertNeverShared($api, 'preview link, the page\'s own API call');
        $this->flushHeaders();

        $this->flushSession();
        config(['site.maintenance_allowed_ips' => '203.0.113.9']);
        $this->assertNeverShared($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/trend')->assertOk(), 'allowed address');
        $this->assertNeverShared($this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/videos')->assertOk(), 'allowed address, API');
        // a picture is the same for everybody: it keeps its own cache headers (an avatar stays cacheable)
        $creator = User::factory()->create();
        $avatar = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(route('user.avatar', $creator, false))->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $avatar->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=', (string) $avatar->headers->get('Cache-Control'));
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);

        $admin = User::factory()->admin()->create();
        $this->assertNeverShared($this->actingAs($admin)->get('/trend')->assertOk(), 'administrator');
        $this->app['auth']->forgetGuards();

        // everybody else gets the maintenance page, which is not public either; with maintenance off a guest's feed is
        // public and edge-cacheable again, as before
        $this->flushSession();
        $this->assertStringNotContainsString('public', (string) $this->get('/trend')->assertStatus(503)->headers->get('Cache-Control'));
        config(['site.maintenance_mode' => false]);
        $open = $this->get('/trend')->assertOk();
        $this->assertStringContainsString('public', (string) $open->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('avd-maintenance-notice', (string) $open->getContent());
    }

    public function test_the_settings_link_is_only_for_whoever_may_open_the_admin_from_here(): void
    {
        // an administrator whose address is not on the admin IP list ("Only these internet addresses can open the admin")
        // cannot open the settings from here: the strip without the link
        config(['site.admin_ip_whitelist' => '203.0.113.50', 'site.maintenance_allowed_ips' => '127.0.0.1']);
        $admin = User::factory()->admin()->create();
        $settings = 'href="'.e(route('admin.settings.page', ['page' => 'maintenance'])).'"';

        $html = (string) $this->actingAs($admin)->get('/trend')->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Maintenance mode is on. Visitors see the maintenance page; this browser sees the site.')), $html);
        $this->assertStringNotContainsString($settings, $html);

        $html = (string) $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])->get('/trend')->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Maintenance mode is on. Visitors see the maintenance page.')), $html);
        $this->assertStringContainsString($settings, $html);
    }

    private function assertNeverShared(\Illuminate\Testing\TestResponse $response, string $who): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl, $who);
        $this->assertStringContainsString('private', $cacheControl, $who);
        $this->assertStringNotContainsString('public', $cacheControl, $who);
        $this->assertStringNotContainsString('s-maxage', $cacheControl, $who);
        $this->assertSame('no-store', $response->headers->get('CDN-Cache-Control'), $who);
        $this->assertSame('no-store', $response->headers->get('Cloudflare-CDN-Cache-Control'), $who);
    }
}
