<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The status pages and the small member pages (member design 6.15, auth-17, auth-20, auth-33):
 * the error pages are the kit's status card with an icon tile (no picture: 419, 429 and 401 had a
 * broken image), the maintenance page says "We are updating the site" with the admin's message and reloads by itself,
 * "Confirm your e-mail" shows the last result, and the contact form keeps its settings.
 */
class MemberPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site.captcha_provider' => 'none', 'app.debug' => false]);
        Cache::flush();
    }

    public function test_a_webhooks_403_page_does_not_tell_a_visitor_how_the_site_is_set_up(): void
    {
        // The Stripe webhook refuses a call while its signing secret is missing (VerifyStripeWebhookSignature): that
        // sentence is for the server's log, not for a person who posts there; the page says the general sentence.
        config(['cashier.webhook.secret' => null, 'services.stripe.webhook_secret' => null, 'site.stripe_webhook_secret' => null]);

        $html = (string) $this->post('/stripe-webhook', [], ['Accept' => 'text/html'])->assertStatus(403)->getContent();

        $this->assertStringNotContainsString('signing secret', $html);
        $this->assertStringContainsString(e(__('Sign in with the account that owns it, or go back to the home page.')), $html);
        $this->assertStringContainsString('class="mk-status-page"', $html);
    }

    public function test_404_is_the_status_card_with_home_and_search(): void
    {
        $html = (string) $this->get('/_m3-nothing-here')->assertNotFound()->getContent();

        $this->assertStringContainsString('class="mk-status-page"', $html);
        $this->assertStringContainsString(__('We cannot find this page'), $html);
        $this->assertStringContainsString('Error 404', $html);
        $this->assertStringContainsString('href="'.e(locale_url(route('search.index'))).'"', $html);
        $this->assertStringContainsString(__('Go home'), $html);
        $this->assertStringNotContainsString('images/errors/', $html, 'no picture');
    }

    public function test_a_suspended_members_channel_says_the_channel_is_no_longer_available(): void
    {
        $banned = User::factory()->create(['banned_at' => now()]);

        $html = (string) $this->get(route('user.show', $banned))->assertStatus(410)->getContent();
        $this->assertStringContainsString(__('This channel is no longer available'), $html);
        $this->assertStringContainsString('href="'.e(locale_url(route('subscription.discover'))).'"', $html);
    }

    public function test_419_offers_to_go_back_and_sign_in(): void
    {
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $html = (string) $this->post('/login', ['username' => 'x', 'password' => 'y'])->assertStatus(419)->getContent();
        $this->assertStringContainsString(__('This page timed out'), $html);
        $this->assertStringContainsString(__('Go back'), $html);
        $this->assertStringContainsString('href="'.e(locale_url(route('login'))).'"', $html);
    }

    public function test_429_tells_the_seconds_and_401_offers_sign_in_and_create_account(): void
    {
        $this->routeFirst('_m3-too-many', fn () => abort(429, '', ['Retry-After' => 42]));
        $this->routeFirst('_m3-sign-in-first', fn () => abort(401));

        $this->get('/_m3-too-many')->assertStatus(429)->assertSeeText(__('Wait a minute'))->assertSeeText('Wait 42 seconds, then try again.');

        $html = (string) $this->get('/_m3-sign-in-first')->assertStatus(401)->getContent();
        $this->assertStringContainsString(__('Sign in to continue'), $html);
        $this->assertStringContainsString('href="'.e('/login?redirect='.urlencode('/_m3-sign-in-first')).'"', $html);
        $this->assertStringContainsString('href="'.e(locale_url(route('registration'))).'"', $html);

        config(['site.registration_enabled' => false]);
        $this->assertStringNotContainsString('href="'.e(locale_url(route('registration'))).'"', (string) $this->get('/_m3-sign-in-first')->getContent());
    }

    public function test_500_says_something_went_wrong_on_our_side(): void
    {
        $this->routeFirst('_m3-broken', fn () => throw new \RuntimeException('boom'));

        $this->get('/_m3-broken')->assertStatus(500)->assertSeeText(__('Something went wrong on our side'))->assertSeeText(__('Try again'));
    }

    public function test_the_maintenance_page_shows_the_admins_message_and_reloads_itself(): void
    {
        config(['site.maintenance_mode' => true, 'site.maintenance_message' => 'Back at five, with a new player.', 'site.maintenance_retry_after' => 120]);

        $html = (string) $this->get('/trend')->assertStatus(503)->assertHeader('Retry-After', '120')->getContent();
        $this->assertStringContainsString(__('We are updating the site'), $html);
        $this->assertStringContainsString('Back at five, with a new player.', $html);
        $this->assertStringContainsString('<meta http-equiv="refresh" content="120">', $html);
        $this->assertStringContainsString('class="mk-status-page"', $html);
    }

    public function test_the_verify_notice_shows_the_address_and_what_the_last_resend_did(): void
    {
        config(['site.email_verification_required' => true]);
        $member = User::factory()->unverified()->create(['email' => 'new.member@example.test']);

        $html = (string) $this->actingAs($member)->get(route('verification.notice'))->assertOk()->getContent();
        $this->assertStringContainsString('<strong>new.member@example.test</strong>', $html);
        $this->assertStringContainsString(__('Send the link again'), $html);
        $this->assertStringContainsString(__('Change it in Account settings'), $html);

        $this->actingAs($member)->withSession(['status' => __('A new link is on its way. It works for :time.', ['time' => '24 hours'])])
            ->get(route('verification.notice'))->assertOk()->assertSeeText('A new link is on its way. It works for 24 hours.');
        $this->actingAs($member)->withSession(['error' => 'Verification email could not be sent. Please check the SMTP settings and try again.'])
            ->get(route('verification.notice'))->assertOk()->assertSeeText('Verification email could not be sent.');
    }

    /** A route before the site's catch-all video address (it answers every GET). */
    private function routeFirst(string $uri, \Closure $action): void
    {
        $router = $this->app['router'];
        $route = (new \Illuminate\Routing\Route(['GET', 'HEAD'], $uri, $action))->middleware('web')->setRouter($router)->setContainer($this->app);
        $routes = new \Illuminate\Routing\RouteCollection();
        $routes->add($route);
        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $routes->add($existing);
        }
        $router->setRoutes($routes);
    }

    public function test_the_contact_form_keeps_its_settings(): void
    {
        config([
            'validation.contact.name.min' => 4, 'validation.contact.name.max' => 33,
            'validation.contact.message.min' => 12, 'validation.contact.message.max' => 900,
            'site.contact_illustration_enabled' => false,
        ]);

        $html = (string) $this->get(route('contact.show'))->assertOk()->getContent();
        $this->assertStringContainsString('class="avd-mk mk-contact"', $html);
        $this->assertMatchesRegularExpression('#name="name" id="name" value=""\s+minlength="4" maxlength="33"#', $html);
        $this->assertMatchesRegularExpression('#minlength="12" maxlength="900"#', $html);
        $this->assertStringNotContainsString('images/pages/contact.png', $html, 'the picture is off');
        $this->assertStringNotContainsString('captcha_answer', $html, 'the robot check is off');
        $this->assertStringContainsString('name="website"', $html, 'the honeypot stays');

        config(['site.captcha_provider' => 'built_in', 'site.captcha_on_contact' => true, 'site.contact_illustration_enabled' => true]);
        $html = (string) $this->get(route('contact.show'))->assertOk()->getContent();
        $this->assertStringContainsString('for="captcha_answer"', $html);
        $this->assertStringContainsString('images/pages/contact.png', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
    }

    /**
     * QA account-4: the hop to the /<locale> address keeps the one-time messages. The state below is the one a
     * page leaves after back()->with('success', ...) to an address without the prefix (the message flashed, aged once);
     * the hop used it up before, so the French page never showed it.
     */
    public function test_the_language_redirect_keeps_the_one_time_messages(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        config(['site.default_locale' => 'en']);
        $member = User::factory()->create();

        $this->actingAs($member)
            ->withSession(['app_locale' => 'fr', 'success' => 'Saved.', '_old_input' => ['bio' => 'typed'], '_flash' => ['old' => ['success', '_old_input'], 'new' => []]])
            ->get('/trend?sort=new')
            ->assertRedirect('/fr/trend?sort=new');

        $this->assertSame('Saved.', session('success'), 'the message waits for the /fr page');
        $this->assertSame(['bio' => 'typed'], session('_old_input'), 'the old input too');
        $this->assertContains('success', (array) session('_flash.old'), 'and is shown once, on that page');
    }
}
