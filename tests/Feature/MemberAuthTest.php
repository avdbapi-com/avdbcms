<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\ReturnPath;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sign-in pages (member design 6.12) and their QA issues: auth-8 (back to the page after signing in),
 * auth-15 (a reset link without the e-mail), auth-16 (waits in seconds), auth-21 (the same answer for every address),
 * auth-22 (signing out is a POST), auth-23 (the user name rule), auth-24 (the confirmation's error on its field, one
 * summary only for two errors or more), auth-32 (the name and "Keep me signed in" kept, a welcome), the closed sign-up,
 * the honeypot, the header's guest buttons (one primary per view), account-13 (linking a provider from Account
 * settings goes back there). Also: the header's buttons are icon buttons below 1200px and its search gives way
 * from 992px (they ran off the screen in Cinema Night and Swiss Bento), the owner's Upload word is its name, the guest
 * prompt in each "Pop-up window style" and the account panel's native Language select (6.0, T), auth-26's card cap.
 */
class MemberAuthTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'csrf-test-token-0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => 'none',
            'site.login_rate_limit' => 1000,
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);

        parent::tearDown();
    }

    // ------------------------------------------------------------------ auth-8: back to the page

    public function test_signing_in_from_an_entry_link_goes_back_to_that_page(): void
    {
        $member = User::factory()->create(['password' => 'password']);

        $this->get('/login?redirect='.urlencode('/trend?sort=new'))->assertOk();
        $this->post('/login', ['username' => $member->email, 'password' => 'password'])
            ->assertRedirect(url('/trend?sort=new'));
        $this->assertAuthenticatedAs($member);
    }

    public function test_signing_in_after_a_same_site_page_goes_back_to_it_by_its_referer(): void
    {
        $member = User::factory()->create(['password' => 'password']);

        $this->get('/login', ['Referer' => url('/discover')])->assertOk();
        $this->post('/login', ['username' => $member->email, 'password' => 'password'])->assertRedirect(url('/discover'));
    }

    public function test_only_a_path_of_this_site_is_a_return_address(): void
    {
        $request = Request::create('http://localhost/login');

        foreach ([
            'https://evil.example/x', '//evil.example/x', '/\\evil.example', "/\tevil", 'javascript:alert(1)', 'x/y',
            '/login', '/vi/login', '/register', '/logout', '/reset-password/abc', '/email/verify', '/api/me', '/oauth/connect/google',
            'http://localhost@evil.example/', 'http://user:pass@localhost/profile',
        ] as $bad) {
            $this->assertNull(ReturnPath::safePath($bad, $request), $bad);
        }

        $this->assertSame('/video/x?t=3', ReturnPath::safePath('/video/x?t=3#c', $request));
        $this->assertSame('/vi/trend', ReturnPath::safePath('http://localhost/vi/trend', $request));
        $this->assertSame('/', ReturnPath::safePath('/', $request));
    }

    public function test_a_foreign_return_address_is_ignored_and_the_member_lands_on_the_studio(): void
    {
        $member = User::factory()->create(['password' => 'password']);

        $this->get('/login?redirect='.urlencode('https://evil.example/phish'), ['Referer' => 'https://evil.example/'])->assertOk();
        $this->post('/login', ['username' => $member->email, 'password' => 'password'])->assertRedirect('/profile');
    }

    public function test_a_page_the_sign_in_bounced_from_wins_over_the_referer(): void
    {
        $member = User::factory()->create(['password' => 'password']);

        $this->get(route('user.edit'))->assertRedirect(route('login'));
        $this->get('/login', ['Referer' => url('/trend')])->assertOk();
        $this->post('/login', ['username' => $member->email, 'password' => 'password'])->assertRedirect(route('user.edit'));
    }

    public function test_the_entry_links_carry_the_page_and_keep_the_language(): void
    {
        $this->get('/trend')->assertOk()
            ->assertSee('href="'.e('/login?redirect='.urlencode('/trend')).'"', false);

        $this->assertSame('/login?redirect='.urlencode('/discover'), ReturnPath::loginUrl('/discover'));
        app()->setLocale('vi');
        $this->assertSame('/vi/login?redirect='.urlencode('/vi/discover'), ReturnPath::loginUrl('/vi/discover'));
    }

    // ------------------------------------------------------------------ auth-22: sign out is a POST

    public function test_signing_out_is_a_post_with_the_token_and_a_get_only_asks(): void
    {
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $member = User::factory()->create();

        $this->actingAs($member)->get('/logout')->assertOk()->assertSee('action="'.e(locale_url(route('logout'))).'"', false);
        $this->assertAuthenticatedAs($member);

        $this->actingAs($member)->post('/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($member);

        $this->actingAs($member)->withSession(['_token' => self::TOKEN])->post('/logout', ['_token' => self::TOKEN])->assertRedirect('/');
        $this->assertGuest();

        // a guest's GET goes home
        $this->get('/logout')->assertRedirect('/');
    }

    public function test_the_account_panel_signs_out_with_a_form(): void
    {
        $member = User::factory()->create();
        $html = (string) $this->actingAs($member)->get('/trend')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<form method="POST" action="[^"]*/logout" class="mk-account__signout">\s*<input type="hidden" name="_token"#', $html);
        $this->assertStringNotContainsString('href="'.url('/logout').'"', $html);
        $this->assertStringNotContainsString('href="/logout"', $html);
    }

    // ------------------------------------------------------------------ auth-33 + auth-32: the sign-in form

    public function test_a_failed_sign_in_keeps_the_name_and_keep_me_signed_in_and_offers_the_reset(): void
    {
        User::factory()->create(['email' => 'member@example.test', 'password' => 'password']);

        $this->from('/login')->post('/login', ['username' => 'member@example.test', 'password' => 'wrong', 'remember' => '1'])
            ->assertRedirect('/login')
            ->assertSessionHasInput('username', 'member@example.test')
            ->assertSessionHasInput('remember', '1')
            ->assertSessionMissing('_old_input.password');

        $this->get('/login')->assertOk()
            ->assertSeeText('The username or password is wrong.')
            ->assertSee('href="'.e(locale_url(route('password.forgot'))).'"', false)
            ->assertSee('value="member@example.test"', false)
            ->assertSee('name="remember" value="1" checked', false);
    }

    public function test_a_suspended_member_is_never_signed_in_and_no_last_sign_in_is_written(): void
    {
        $member = User::factory()->create(['email' => 'banned@example.test', 'password' => 'password', 'banned_at' => now(), 'last_login_at' => null]);

        $this->from('/login')->post('/login', ['username' => 'banned@example.test', 'password' => 'password'])
            ->assertRedirect('/login')
            ->assertSessionHas('auth_banned', true)
            ->assertSessionHasInput('username', 'banned@example.test');

        $this->assertGuest();
        $this->assertNull($member->fresh()->last_login_at);
        $this->get('/login')->assertOk()->assertSeeText('This account is suspended. If you think this is a mistake, contact us.');
    }

    public function test_a_suspended_member_signed_in_by_the_keep_me_signed_in_cookie_gets_no_fresh_last_sign_in(): void
    {
        $member = User::factory()->create(['password' => 'password']);

        // signed in with "Keep me signed in" before the suspension
        $this->post('/login', ['username' => $member->email, 'password' => 'password', 'remember' => '1'])->assertRedirect();
        $member->refresh();
        $this->assertNotEmpty($member->getRememberToken());
        $recaller = $member->getAuthIdentifier().'|'.$member->getRememberToken().'|'.$member->getAuthPassword();

        // an administrator suspends the account; its sign-in record is cleared, so a new write would show
        $member->forceFill(['banned_at' => now(), 'last_login_at' => null, 'last_login_ip' => null])->saveQuietly();

        // the same browser later: a new session that only has the "Keep me signed in" cookie
        $recallerName = auth()->guard('web')->getRecallerName();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCookie($recallerName, $recaller)->get('/trend')->assertRedirect();

        $this->assertGuest('web');
        $fresh = $member->fresh();
        $this->assertNull($fresh->last_login_at, 'a suspended account gets no fresh "last sign-in" (auth-33)');
        $this->assertNull($fresh->last_login_ip);
    }

    // ------------------------------------------------------------------ auth-16: waits in seconds

    public function test_a_throttled_sign_in_says_how_many_seconds_to_wait(): void
    {
        config(['site.login_rate_limit' => 1]);
        $payload = ['username' => 'nobody', 'password' => 'nothing'];

        $this->from('/login')->post('/login', $payload);
        $response = $this->from('/login')->post('/login', $payload)->assertRedirect('/login');

        $message = (string) session('error');
        $this->assertMatchesRegularExpression('/^Too many tries\. Wait \d+ seconds?, then try again\.$/', $message);
        $this->assertGreaterThan(0, (int) session('auth_throttled'));
        $this->get('/login')->assertOk()->assertSeeText($message);
    }

    public function test_the_forgot_form_is_throttled_on_its_page_with_the_seconds_not_a_bare_429(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->from('/forgot-password')->post('/forgot-password', ['email' => 'someone'.$i.'@example.test'])->assertRedirect('/forgot-password');
        }

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'someone@example.test'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasInput('email', 'someone@example.test');
        $this->assertMatchesRegularExpression('/Wait \d+ seconds?/', (string) session('error'));
    }

    public function test_three_people_on_one_address_can_sign_up_in_ten_minutes(): void
    {
        Notification::fake();

        foreach (['first', 'second', 'third'] as $name) {
            $this->travel(61)->seconds(); // past the form's own limit of three posts a minute
            $this->post('/register', [
                'username' => $name.'_member', 'email' => $name.'@example.test', 'password' => 'password-123',
                'password_confirmation' => 'password-123', 'cgu' => '1',
            ])->assertRedirect();
            $this->assertDatabaseHas('users', ['username' => $name.'_member']);
            auth()->logout();
        }

        $this->travel(61)->seconds();
        $this->from('/register')->post('/register', [
            'username' => 'fourth_member', 'email' => 'fourth@example.test', 'password' => 'password-123',
            'password_confirmation' => 'password-123', 'cgu' => '1',
        ])->assertRedirect('/register')->assertSessionHasErrors('throttle');

        // the rest of the ten minutes, said in minutes (a wait over a minute is no longer "Wait 356 seconds")
        $this->assertMatchesRegularExpression('/^Too many tries\. Wait \d+ minutes, then try again\.$/', session('errors')->first('throttle'));
        $this->assertDatabaseMissing('users', ['username' => 'fourth_member']);
        RateLimiter::clear('register|127.0.0.1');
    }

    public function test_an_owners_stored_sign_up_limit_keeps_its_one_minute_window(): void
    {
        Notification::fake();
        // The installer stores "1" (database/install/example.sql): one sign-up per address and MINUTE, as the setting has
        // always meant. The ten-minute window belongs to the default of 3 only.
        Setting::set('max_register_attempts', '1', 'users');

        $this->post('/register', $this->signUpForm('first'))->assertRedirect()->assertSessionHasNoErrors();
        auth()->logout();

        // a second person on the same address within that minute waits for the rest of the minute, never longer
        $this->from('/register')->post('/register', $this->signUpForm('second'))->assertRedirect('/register')->assertSessionHasErrors('throttle');
        $message = (string) session('errors')->first('throttle');
        $this->assertMatchesRegularExpression('/^Too many tries\. Wait (\d+) seconds?, then try again\.$/', $message);
        preg_match('/Wait (\d+) second/', $message, $wait);
        $this->assertLessThanOrEqual(60, (int) $wait[1], $message);
        $this->assertDatabaseMissing('users', ['username' => 'second_member']);

        // after the minute the same address signs up again
        $this->travel(61)->seconds();
        $this->post('/register', $this->signUpForm('second'))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['username' => 'second_member']);
        RateLimiter::clear('register|127.0.0.1');
    }

    /**
     * @return array<string, string>
     */
    private function signUpForm(string $name): array
    {
        return [
            'username' => $name.'_member', 'email' => $name.'@example.test', 'password' => 'password-123',
            'password_confirmation' => 'password-123', 'cgu' => '1',
        ];
    }

    // ------------------------------------------------------------------ auth-21: one answer

    public function test_the_forgot_answer_is_the_same_for_every_address(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'member@example.test']);

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'member@example.test']);
        $knownStatus = session('status');
        $again = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'member@example.test']);
        $againStatus = session('status');
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test']);
        $unknownStatus = session('status');

        foreach ([$known, $again, $unknown] as $response) {
            $response->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        }

        $this->assertSame($knownStatus, $unknownStatus);
        $this->assertSame($knownStatus, $againStatus);
        $this->assertSame('If an account uses this e-mail, we sent a link to it. The link works for 60 minutes.', (string) $knownStatus);
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_the_pages_say_a_links_lifetime_in_hours_like_the_mails(): void
    {
        Notification::fake();
        config(['site.email_verification_required' => true, 'auth.verification.expire' => 1440, 'auth.passwords.users.expire' => 120]);
        $member = User::factory()->unverified()->create();

        // 6.21 / section 7: minutes are said in hours or days, as the mail itself says them ("The link works for 24 hours.")
        $this->actingAs($member)->from('/email/verify')->post(route('verification.send'))->assertRedirect('/email/verify');
        $this->assertSame('A new link is on its way. It works for 24 hours.', session('status'));

        auth()->logout();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nobody@example.test'])->assertRedirect('/forgot-password');
        $this->assertSame('If an account uses this e-mail, we sent a link to it. The link works for 2 hours.', session('status'));
    }

    // ------------------------------------------------------------------ auth-15: the reset page

    public function test_the_reset_page_locks_the_e_mail_only_when_the_link_carries_it(): void
    {
        $token = str_repeat('c3', 32);

        $with = (string) $this->get('/reset-password/'.$token.'?email='.urlencode('member@example.test'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#name="email"\s+id="email"\s+value="member@example\.test"[^>]*readonly#s', $with);
        $this->assertStringContainsString('Not you?', $with);

        $without = (string) $this->get('/reset-password/'.$token)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#name="email"\s+id="email"\s+value=""#', $without);
        $this->assertDoesNotMatchRegularExpression('#id="email"[^>]*readonly#s', $without);
    }

    public function test_after_a_reset_the_sign_in_form_has_the_e_mail(): void
    {
        $member = User::factory()->create(['email' => 'member@example.test']);
        $token = app('auth.password.broker')->createToken($member);

        $this->post('/reset-password', [
            'token' => $token, 'email' => 'member@example.test', 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123',
        ])->assertRedirect(locale_url(route('login')))->assertSessionHasInput('username', 'member@example.test');

        $this->get('/login')->assertOk()->assertSee('value="member@example.test"', false)
            ->assertSeeText('Your password is changed. Sign in with the new password.');
    }

    // ------------------------------------------------------------------ auth-23, auth-24: the sign-up form

    public function test_a_user_name_takes_letters_numbers_dots_dashes_and_underscores_only(): void
    {
        Notification::fake();

        $this->from('/register')->post('/register', [
            'username' => 'qa auth/member1 😀', 'email' => 'odd@example.test', 'password' => 'password-123',
            'password_confirmation' => 'password-123', 'cgu' => '1',
        ])->assertRedirect('/register')->assertSessionHasErrors(['username' => __('Use letters, numbers, dots, dashes or underscores: no spaces.')]);

        $this->post('/register', [
            'username' => 'nguyễn.văn_a-1', 'email' => 'nguyen@example.test', 'password' => 'password-123',
            'password_confirmation' => 'password-123', 'cgu' => '1',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['username' => 'nguyễn.văn_a-1']);
    }

    public function test_a_password_mismatch_is_marked_on_the_confirmation_and_two_errors_get_one_summary(): void
    {
        $this->from('/register')->post('/register', [
            'username' => 'mismatch', 'email' => 'mismatch@example.test', 'password' => 'password-123',
            'password_confirmation' => 'password-321', 'cgu' => '1',
        ])->assertRedirect('/register')->assertSessionHasErrors(['password_confirmation' => __('The passwords are not the same.')])
            ->assertSessionDoesntHaveErrors('password');

        $one = (string) $this->get('/register')->getContent();
        $this->assertStringContainsString('id="auth-password-confirmation-error"', $one);
        $this->assertStringNotContainsString('id="auth-errors"', $one, 'one error: no summary');

        $this->from('/register')->post('/register', ['username' => '', 'email' => 'not-an-email', 'password' => 'x', 'password_confirmation' => 'y']);
        $many = (string) $this->get('/register')->getContent();
        $this->assertStringContainsString('id="auth-errors" data-auth-focus', $many);
        $this->assertSame(1, substr_count($many, 'id="auth-errors"'));
        $this->assertStringContainsString('id="cgu-error"', $many, 'the terms error sits under its check box');
    }

    public function test_a_new_member_is_welcomed_and_gets_the_language_of_the_sign_up_page(): void
    {
        Notification::fake();
        $_SERVER['DETECTED_LOCALE'] = 'fr';

        $this->withSession(['app_locale' => 'fr'])->post('/register', [
            'username' => 'nouveau', 'email' => 'nouveau@example.test', 'password' => 'password-123',
            'password_confirmation' => 'password-123', 'cgu' => '1',
        ])->assertRedirect('/fr/profile/edit')->assertSessionHas('success');

        $this->assertSame('fr', User::query()->where('username', 'nouveau')->value('language'));
    }

    // ------------------------------------------------------------------ states of 6.12

    public function test_closed_sign_ups_send_to_the_sign_in_page_with_the_reason_and_hide_every_create_account(): void
    {
        config(['site.registration_enabled' => false]);

        $this->get('/register')->assertRedirect(locale_url(route('login')))
            ->assertSessionHas('registration_closed', true)
            ->assertSessionHas('error', __('Sign-ups are closed'));
        $this->get('/login')->assertOk()
            ->assertSee('<span class="mk-alert__title">'.e(__('Sign-ups are closed')).'</span>', false)
            ->assertDontSeeText(__('Registration is currently disabled.'))
            ->assertSeeText(__(':site is not taking new members right now. If you already have an account, you can sign in.', ['site' => config('app.name')]))
            ->assertDontSee('href="'.e(locale_url(route('registration'))).'"', false);

        $home = (string) $this->get('/trend')->getContent();
        $this->assertStringNotContainsString('href="'.e(locale_url(route('registration'))).'"', $home, 'no Create account in the header or the guest prompt');
    }

    public function test_the_honeypot_stays_hidden_and_a_filled_one_fails_quietly(): void
    {
        config(['site.honeypot_enabled' => true]);

        $html = (string) $this->get('/register')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<div class="mk-hp" aria-hidden="true">\s*<label>[^<]*<input type="text" name="website" value="" tabindex="-1" autocomplete="off"></label>#', $html);

        $this->from('/register')->post('/register', [
            'username' => 'bot_user', 'email' => 'bot@example.test', 'password' => 'password-123',
            'password_confirmation' => 'password-123', 'cgu' => '1', 'website' => 'http://spam.example',
        ])->assertRedirect('/register')->assertSessionHasErrors('captcha');
        $this->assertDatabaseMissing('users', ['username' => 'bot_user']);
    }

    public function test_the_robot_check_question_is_the_field_label(): void
    {
        config(['site.captcha_provider' => 'built_in', 'site.captcha_on_login' => true]);

        $html = (string) $this->get('/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<label class="mk-label" for="captcha_answer">Security check: what is <bdi dir="ltr">\d+ \+ \d+</bdi>\?</label>#', $html);
    }

    public function test_the_password_field_is_named_by_its_label_not_by_the_forgot_link(): void
    {
        // Section 8: a clean label on every field. "Forgot password?" sits on the label row (5.9), but the field's name is
        // "Password" only: the input points at the label's text, not at the whole label with its link.
        $html = (string) $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<label class="mk-label" for="auth-password"><span id="auth-password-label">'.preg_quote(e(__('Password')), '#').'</span><a class="mk-label__aside#', $html);
        $this->assertMatchesRegularExpression('#<input(?=[^>]*\bid="auth-password")(?=[^>]*\baria-labelledby="auth-password-label")[^>]*>#', $html);

        // the sign-up fields have no link in their label and keep it as their name
        $register = (string) $this->get('/register')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<input(?=[^>]*\bid="auth-password-confirmation")(?=[^>]*\baria-labelledby="auth-password-confirmation-label")[^>]*>#', $register);
    }

    public function test_social_buttons_only_for_configured_providers_and_no_or_line_without_them(): void
    {
        config(['site.allow_social_login' => true, 'services.google.client_id' => '', 'services.google.client_secret' => '', 'services.facebook.client_id' => '', 'services.facebook.client_secret' => '']);
        $none = (string) $this->get('/login')->getContent();
        $this->assertStringNotContainsString('mk-divider-text', $none);
        $this->assertStringNotContainsString('/oauth/connect/', $none);

        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'client-secret']);
        $google = (string) $this->get('/login')->getContent();
        $this->assertStringContainsString('mk-divider-text', $google);
        $this->assertStringContainsString(__('Continue with :provider', ['provider' => 'Google']), $google);
        $this->assertStringNotContainsString('/oauth/connect/facebook', $google);
    }

    public function test_linking_a_provider_from_account_settings_goes_back_there_with_the_reason(): void
    {
        config(['site.allow_social_login' => true, 'services.google.client_id' => '', 'services.google.client_secret' => '']);
        $member = User::factory()->create();

        $this->actingAs($member)->get('/oauth/connect/google')
            ->assertRedirect(locale_url(route('user.edit', ['section' => 'security'])))
            ->assertSessionHas('error', 'This social login provider is not configured.');
    }

    // ------------------------------------------------------------------ the header's guest buttons (6.13)

    public function test_the_sign_in_pages_have_no_guest_buttons_in_the_header(): void
    {
        foreach (['/login', '/register', '/forgot-password'] as $page) {
            $html = (string) $this->get($page)->assertOk()->getContent();
            $actions = $this->headerActions($html);
            $this->assertStringNotContainsString('avd-auth-btn', $actions, $page);
            $this->assertStringNotContainsString('avd-auth-action', $actions, $page);
        }
    }

    public function test_a_guest_on_a_member_page_gets_a_quiet_sign_in_and_a_secondary_create_account(): void
    {
        $channel = User::factory()->create();
        $actions = $this->headerActions((string) $this->get(route('user.show', $channel))->assertOk()->getContent());

        $this->assertMatchesRegularExpression('#avd-auth-action--signin">\s*<a class="mk-btn mk-btn--quiet mk-btn--sm" href="[^"]*/login\?redirect=#', $actions);
        $this->assertMatchesRegularExpression('#avd-auth-action--register">\s*<a class="mk-btn mk-btn--secondary mk-btn--sm"#', $actions);
        $this->assertStringNotContainsString('btn-primary', $actions, 'the page owns the primary (Subscribe)');
    }

    public function test_members_get_one_quiet_upload_with_a_name_and_none_while_uploads_are_off(): void
    {
        $member = User::factory()->create();
        $actions = $this->headerActions((string) $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent());

        $this->assertMatchesRegularExpression('#<button class="mk-btn mk-btn--secondary mk-btn--sm avd-upload-action" type="button" data-bs-toggle="modal" data-bs-target="\#video_create" aria-label="'.preg_quote(e(__('Upload video')), '#').'"#', $actions);
        $this->assertSame(1, substr_count($actions, 'data-bs-target="#video_create"'));

        // public pages keep the public button, with an accessible name below 1200px too (watch-25)
        $public = $this->headerActions((string) $this->actingAs($member)->get('/trend')->getContent());
        $this->assertStringContainsString('avd-upload-btn', $public);
        $this->assertStringContainsString('aria-label="'.e(__('Upload video')).'"', $public);

        config(['site.allow_uploads' => false]);
        $off = (string) $this->actingAs($member)->get(route('user.edit'))->getContent();
        $this->assertStringNotContainsString('avd-upload-action', $this->headerActions($off));
        $this->assertStringNotContainsString('mk-account__link" data-bs-toggle="modal"', $off, 'no Upload video in the account panel');
    }

    public function test_the_account_panel_upload_is_the_server_rendered_trigger_that_closes_the_panel_first(): void
    {
        $member = User::factory()->create();
        $html = (string) $this->actingAs($member)->get('/trend')->getContent();

        $this->assertMatchesRegularExpression('#<button type="button" class="mk-account__link" data-bs-toggle="modal" data-bs-target="\#video_create"\s+@click="window\.bootstrap && window\.bootstrap\.Offcanvas\.getInstance\(\$el\.closest\(\'\.offcanvas\'\)\)#', $html);
    }

    public function test_the_header_and_the_account_panel_honour_the_owners_switches(): void
    {
        $member = User::factory()->create();
        $channel = User::factory()->create();

        // the defaults: every control is there
        $page = (string) $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();
        $actions = $this->headerActions($page);
        $this->assertStringContainsString('avd-upload-action', $actions);
        $this->assertStringContainsString('<site-notifications', $actions);
        $this->assertStringContainsString('href="'.e(locale_url(route('pages.history'))).'"', $page, 'History in the account panel');
        $this->assertStringContainsString('name="account_theme"', $page, 'Theme in the account panel');

        config([
            'site.header_upload_button_enabled' => false,
            'site.header_notifications_enabled' => false,
            'site.show_history' => false,
            'site.sidebar_theme_switcher_enabled' => false,
            'site.allow_language_switch' => false,
        ]);
        Cache::flush();
        $page = (string) $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();
        $actions = $this->headerActions($page);
        $this->assertStringNotContainsString('avd-upload-action', $actions, 'header_upload_button_enabled off');
        $this->assertStringNotContainsString('<site-notifications', $actions, 'header_notifications_enabled off');
        $this->assertStringNotContainsString('href="'.e(locale_url(route('pages.history'))).'"', $page, 'show_history off');
        $this->assertStringNotContainsString('name="account_theme"', $page, 'sidebar_theme_switcher_enabled off');
        $this->assertStringNotContainsString('action="'.e(route('lang.update')).'"', $this->accountPanel($page), 'allow_language_switch off');
        $this->assertStringContainsString('data-bs-target="#video_create"', $this->accountPanel($page), 'the panel keeps Upload video while uploads are allowed');

        // guests on a member page: the owner's labels, and each button can be switched off
        auth()->logout();
        config(['site.header_login_label' => 'Members', 'site.header_register_label' => 'Join us']);
        $actions = $this->headerActions((string) $this->get(route('user.show', $channel))->assertOk()->getContent());
        $this->assertStringContainsString('<span>Members</span>', $actions);
        $this->assertStringContainsString('<span>Join us</span>', $actions);

        config(['site.header_login_button_enabled' => false, 'site.header_register_button_enabled' => false]);
        $actions = $this->headerActions((string) $this->get(route('user.show', $channel))->assertOk()->getContent());
        $this->assertStringNotContainsString('avd-auth-action', $actions);

        config(['site.header_login_button_enabled' => true, 'site.header_register_button_enabled' => true, 'site.registration_enabled' => false]);
        $actions = $this->headerActions((string) $this->get(route('user.show', $channel))->assertOk()->getContent());
        $this->assertStringContainsString('avd-auth-action--signin', $actions);
        $this->assertStringNotContainsString('avd-auth-action--register', $actions, 'no Create account while sign-ups are closed');
    }

    private function accountPanel(string $html): string
    {
        $start = strpos($html, 'id="account"');
        $this->assertNotFalse($start, 'the account panel is on the page');
        // the panel ends with its Sign out form
        $signOut = strpos($html, 'mk-account__signout', (int) $start);
        $this->assertNotFalse($signOut, 'the panel ends with Sign out');
        $end = strpos($html, '</form>', (int) $signOut);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    public function test_an_error_page_is_a_member_page_in_the_header_too(): void
    {
        config(['member_pages.marker' => true]);

        // a guest on a 404 (no route): Sign in quiet with the way back, Create account secondary, as in the error-* mockups
        $actions = $this->headerActions((string) $this->get('/no-such-page-'.bin2hex(random_bytes(4)).'/x/y')->assertNotFound()->getContent());
        $this->assertStringContainsString('avd-header-actions avd-mk', $actions);
        $this->assertMatchesRegularExpression('#avd-auth-action--signin">\s*<a class="mk-btn mk-btn--quiet mk-btn--sm" href="[^"]*/login#', $actions);
        $this->assertMatchesRegularExpression('#avd-auth-action--register">\s*<a class="mk-btn mk-btn--secondary mk-btn--sm"#', $actions);
        $this->assertStringNotContainsString('avd-auth-btn', $actions, 'not the public look\'s buttons');

        // a member on an error page: the kit Upload
        $member = User::factory()->create();
        $memberActions = $this->headerActions((string) $this->actingAs($member)->get('/no-such-page-'.bin2hex(random_bytes(4)).'/x/y')->assertNotFound()->getContent());
        $this->assertStringContainsString('avd-upload-action', $memberActions);
        $this->assertStringNotContainsString('avd-upload-btn', $memberActions);
    }

    // ------------------------------------------------------------------ auth-2: the API with the shipped .env

    public function test_api_sign_up_and_sign_in_answer_with_a_token_with_the_shipped_token_lifetime(): void
    {
        // .env.example ships SANCTUM_TOKEN_EXPIRATION=43200: a string from the environment, read by config/sanctum.php
        $key = 'SANCTUM_TOKEN_EXPIRATION';
        $saved = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = '43200';
        putenv($key.'=43200');

        try {
            config(['sanctum' => require config_path('sanctum.php')]);
        } finally {
            [$envValue, $serverValue, $processValue] = $saved;
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }
            putenv($processValue === false ? $key : $key.'='.$processValue);
        }
        $this->assertSame(43200, config('sanctum.expiration'));
        config([
            'site.registration_enabled' => true,
            'site.api_registration' => true,
            'site.email_verification_required' => false,
            'site.api_rate_limit' => 1000,
        ]);
        $this->travelTo(now()->startOfMinute());

        $this->postJson('/api/register', [
            'username' => 'api_member', 'email' => 'api.member@example.test', 'password' => 'password-123',
            'password_confirmation' => 'password-123', 'cgu' => true, 'device_name' => 'phone',
        ])->assertSuccessful()->assertJsonPath('token', fn (mixed $token): bool => is_string($token) && $token !== '');

        $login = $this->postJson('/api/login', ['username' => 'api.member@example.test', 'password' => 'password-123', 'device_name' => 'phone'])
            ->assertOk();
        $token = (string) $login->json('token');
        $this->assertNotSame('', $token);

        $expires = \Laravel\Sanctum\PersonalAccessToken::findToken($token)?->expires_at;
        $this->assertNotNull($expires, 'the token expires');
        $this->assertSame(43200, (int) round(now()->diffInMinutes($expires, true)), 'after the shipped 30 days');

        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/notifications')->assertOk();
    }

    // ------------------------------------------------------------------ the header row from 992 to 1199px

    public function test_the_public_guest_buttons_show_their_words_from_1200px_only_and_keep_their_names(): void
    {
        $actions = $this->headerActions((string) $this->get('/trend')->assertOk()->getContent());
        $signIn = preg_quote(e(__('Sign in')), '#');
        $create = preg_quote(e(__('Create account')), '#');

        // the public look's buttons, an icon below 1200px (the words ran off the screen at 992-1199px in Cinema
        // Night and Swiss Bento), named by their words and titled
        $this->assertMatchesRegularExpression('#<a class="btn btn-sm btn-outline-secondary px-2 px-xl-3 avd-auth-btn" href="[^"]*/login[^"]*" aria-label="'.$signIn.'" title="'.$signIn.'">\s*<i class="fa-solid fa-arrow-right-to-bracket d-inline d-xl-none" aria-hidden="true"></i>\s*<span class="d-none d-xl-inline">'.$signIn.'</span>#', $actions);
        $this->assertMatchesRegularExpression('#<a class="btn btn-sm btn-primary px-2 px-xl-3 avd-auth-btn" href="[^"]*/register" aria-label="'.$create.'" title="'.$create.'">\s*<i class="fa-solid fa-user-plus d-inline d-xl-none" aria-hidden="true"></i>\s*<span class="d-none d-xl-inline">'.$create.'</span>#', $actions);
        $this->assertStringNotContainsString('d-sm-inline', $actions, 'the words no longer show from 576px');
    }

    public function test_the_member_header_is_icon_only_below_1200px_and_its_search_gives_way_from_992px(): void
    {
        $css = $this->css('resources/sass/member/_chrome.scss');

        // ONE icon-only rule below 1200px for the Upload and both guest buttons: square, the label visually hidden
        $this->assertMatchesRegularExpression('/@media \(max-width: 1199\.98px\) \{\s*:root \.avd-header-actions \.avd-upload-action,\s*:root \.avd-header-actions\.avd-mk \.avd-auth-action > \.mk-btn \{[^}]*inline-size: var\(--mk-ctl-h, 40px\);[^}]*min-block-size: var\(--mk-ctl-h, 40px\);[^}]*\}\s*:root \.avd-header-actions \.avd-upload-action__label,\s*:root \.avd-header-actions\.avd-mk \.avd-auth-action > \.mk-btn > span:not\(\[aria-hidden\]\) \{[^}]*clip-path: inset\(50%\);/', $css);
        $this->assertStringNotContainsString('(min-width: 768px) and (max-width: 991.98px)', $css, 'the guest words no longer come back at 992px');

        // from 992px the inline search gives way and the brand keeps its room, on member pages ...
        // (the search's factor is header.scss's 1000 against .05: with 1 the brand zone still took its share of every
        // shortfall and cut 1-2px of the site's name while the search had room, round 2 lane CC)
        $this->assertMatchesRegularExpression('/@media \(min-width: 992px\) \{\s*html\[data-avd-member\] \.avd-header-inner > search-bar \{\s*flex-shrink: 1000;\s*min-inline-size: 7\.5rem;\s*\}\s*html\[data-avd-member\] \.avd-header-inner > \.avd-header-brand-zone \{\s*flex-shrink: \.05;\s*\}\s*\}/', $css);
        // ... and on the error pages, which carry it inline until the layout prints their member marker
        $html = (string) $this->get('/no-such-page-'.bin2hex(random_bytes(4)).'/x')->assertNotFound()->getContent();
        $this->assertStringContainsString('@media (min-width:992px){.avd-header-inner>search-bar{flex-shrink:1;min-inline-size:7.5rem}.avd-header-inner>.avd-header-brand-zone{flex-shrink:.05}}', $html);
    }

    public function test_the_upload_is_named_by_the_owners_own_word(): void
    {
        $member = User::factory()->create();
        config(['site.header_upload_label' => 'Share a clip']);

        // the visible word is part of the name on member and public pages (WCAG 2.5.3), the default stays "Upload video"
        $memberActions = $this->headerActions((string) $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent());
        $this->assertStringContainsString('avd-upload-action" type="button" data-bs-toggle="modal" data-bs-target="#video_create" aria-label="Share a clip" title="Share a clip"', $memberActions);
        $this->assertStringContainsString('<span class="avd-upload-action__label">Share a clip</span>', $memberActions);
        $public = $this->headerActions((string) $this->actingAs($member)->get('/trend')->assertOk()->getContent());
        $this->assertStringContainsString('avd-upload-btn d-flex align-items-center gap-2" type="button" data-bs-toggle="modal" data-bs-target="#video_create" aria-label="Share a clip" title="Share a clip"', $public);
    }

    // ------------------------------------------------------------------ settings marked (T) in 6.0

    /** @return array<string, array{string}> */
    public static function popUpWindowStyles(): array
    {
        return [
            'centred' => ['modal_glassmorphic_center'],
            'sliding sheet' => ['modal_slide_sheet'],
            'full screen' => ['modal_fullscreen_cinema'],
            'sharp' => ['modal_brutalist_sharp'],
        ];
    }

    /** modal_presentation_style (6.0, "T: one test per style"): the guest prompt is the kit's dialog in every style. */
    #[DataProvider('popUpWindowStyles')]
    public function test_the_guest_prompt_is_the_kit_dialog_in_every_pop_up_window_style(string $style): void
    {
        config(['site.modal_presentation_style' => $style]);
        $html = (string) $this->get('/trend')->assertOk()->getContent();

        // the page prints the style the site's dialog rules (and the kit's, _shared.scss) key on
        $this->assertMatchesRegularExpression('#<html\s[^>]*data-avd-modal-style="'.$style.'"#', $html);
        // the prompt: the kit dialog (.mk-dialog.mk-prompt, 420px), a visible close button, Create account + Sign in back here
        $start = strpos($html, '<div class="modal fade" id="avdAuthPromptModal"');
        $this->assertNotFalse($start, 'the guest prompt is on the page');
        $prompt = substr($html, (int) $start, 2600);
        $this->assertMatchesRegularExpression('#^<div class="modal fade" id="avdAuthPromptModal" tabindex="-1" aria-labelledby="avdAuthPromptTitle" aria-hidden="true">\s*<div class="modal-dialog modal-dialog-centered mk-dialog mk-prompt avd-mk">\s*<div class="modal-content">#', $prompt);
        $this->assertStringContainsString('<button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="'.e(__('Close')).'"', $prompt);
        $this->assertMatchesRegularExpression('#<a class="mk-btn mk-btn--secondary" href="[^"]*/register"><span>'.preg_quote(e(__('Create account')), '#').'</span></a>\s*<a class="mk-btn mk-btn--primary" href="[^"]*/login\?redirect=[^"]*" id="avdAuthPromptLoginBtn">#', $prompt);
        $this->assertStringNotContainsString('btn-close', $prompt, 'never Bootstrap\'s white X');
    }

    /** enhanced_selects_enabled (6.0, T): the account panel's Language stays a native select whether it is on or off. */
    public function test_the_account_panels_language_stays_native_with_searchable_lists_on_and_off(): void
    {
        $member = User::factory()->create();

        foreach ([true => 'on', false => 'off'] as $enabled => $printed) {
            config(['site.enhanced_selects_enabled' => (bool) $enabled]);
            $html = (string) $this->actingAs($member)->get('/trend')->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<html\s[^>]*data-avd-enhanced-selects="'.$printed.'"#', $html);
            $this->assertMatchesRegularExpression('#<select class="form-select" id="account-language" name="locale" data-native-select[\s>]#', $this->accountPanel($html), 'Language is native ('.$printed.')');
        }
    }

    // ------------------------------------------------------------------ auth-26, the card on tablets

    public function test_the_sign_in_card_is_at_most_460px_and_centred_below_1200px(): void
    {
        $css = $this->css('resources/sass/member/_auth.scss');

        // below 1200px the card is alone, at most 28.75rem (460px at the default text size) and centred
        $this->assertMatchesRegularExpression('/:root \.mk-auth \{\s*display: grid;\s*gap: 0;\s*inline-size: min\(100%, 28\.75rem\);\s*margin: clamp\(0px, 3vw, 2\.5rem\) auto;\s*\}/', $css);
        // from 1200px the brand panel joins it in the 1040px frame
        $this->assertMatchesRegularExpression('/@media \(min-width: 1200px\) \{\s*:root \.mk-auth \{\s*inline-size: min\(100%, 1040px\);\s*grid-template-columns: minmax\(0, 1fr\) minmax\(0, 460px\);/', $css);
    }

    private function headerActions(string $html): string
    {
        $start = strpos($html, 'avd-header-actions');
        $this->assertNotFalse($start, 'the header actions are on the page');
        $end = strpos($html, '</ul>', (int) $start);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    /** A stylesheet of the copy without its comments. */
    private function css(string $file): string
    {
        $css = file_get_contents(base_path($file));
        $this->assertIsString($css, $file);

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }
}
