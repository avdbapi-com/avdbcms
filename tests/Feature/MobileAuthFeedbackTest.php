<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MobileAuthFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.limiter' => 'array', 'site.captcha_provider' => 'none', 'site.default_locale' => 'en']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        parent::tearDown();
    }

    public function test_throttled_login_keeps_username_and_displays_feedback_without_password(): void
    {
        config(['site.login_rate_limit' => 1]);
        $payload = ['username' => 'phone-user', 'password' => 'private-test-password', 'remember' => 'on'];
        $this->from('/login')->post('/login', $payload)->assertRedirect('/login');
        $this->from('/login')->post('/login', $payload)
            ->assertRedirect('/login')
            ->assertSessionHasInput('username', 'phone-user')
            ->assertSessionMissing('_old_input.password');
        $this->get('/login')->assertOk()
            ->assertSee(__('Too many attempts !'))
            ->assertSee('value="phone-user"', false)
            ->assertDontSee('private-test-password');
    }

    public function test_throttled_registration_displays_feedback_and_preserves_only_safe_fields(): void
    {
        $payload = [
            'username' => 'phone-user', 'email' => 'phone-user@example.test',
            'password' => 'private-test-password', 'password_confirmation' => 'mismatch', 'cgu' => '1',
        ];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->from('/register')->post('/register', $payload)->assertRedirect('/register');
        }
        $this->from('/register')->post('/register', $payload)
            ->assertRedirect('/register')
            ->assertSessionHasInput('email', 'phone-user@example.test')
            ->assertSessionHasInput('cgu', '1')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation');
        $this->get('/register')->assertOk()
            ->assertSee(__('Too many attempts !'))
            ->assertSee('value="phone-user@example.test"', false)
            ->assertDontSee('private-test-password');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_disabled_redirects_directly_to_localized_login_with_feedback(): void
    {
        config(['site.registration_enabled' => false]);
        $_SERVER['DETECTED_LOCALE'] = 'vi';
        $this->withSession(['app_locale' => 'vi'])->get('/register')
            ->assertRedirect('/vi/login')
            ->assertSessionHas('error');
        // The front controller strips /vi before Laravel receives the request.
        $this->get('/login')->assertOk()->assertSee(__('Registration is currently disabled.'));
    }

    public function test_unconfigured_social_provider_redirects_directly_to_localized_login(): void
    {
        config(['site.allow_social_login' => true, 'services.google.client_id' => '', 'services.google.client_secret' => '']);
        $_SERVER['DETECTED_LOCALE'] = 'vi';
        $this->withSession(['app_locale' => 'vi'])->get('/oauth/connect/google')
            ->assertRedirect('/vi/login');
        $this->get('/login')->assertOk()->assertSee(__('This social login provider is not configured.'));
    }

    public function test_registration_terms_link_resolves_for_every_supported_locale(): void
    {
        foreach (array_keys(config('languages')) as $locale) {
            app()->setLocale($locale);
            $terms = __('registration.terms', ['link' => '/terms']);
            $this->assertStringContainsString('/terms', $terms, $locale);
            $this->assertStringContainsString('<a ', $terms, $locale);
            $this->assertStringNotContainsString('registration.terms', $terms, $locale);
            if ($locale !== 'en') {
                $this->assertNotSame('Show password', __('Show password'), $locale);
            }
        }
    }

    public function test_auth_validation_messages_are_localized_and_replace_placeholders(): void
    {
        foreach (array_keys(config('languages')) as $locale) {
            app()->setLocale($locale);
            $errors = Validator::make([
                'email' => 'not-an-email', 'password' => 'test-password', 'password_confirmation' => 'mismatch',
            ], ['username' => 'required', 'email' => 'email', 'password' => 'confirmed'])->errors();
            $this->assertCount(3, $errors, $locale);
            foreach ($errors->all() as $message) {
                $this->assertStringNotContainsString(':attribute', $message, $locale);
                if ($locale !== 'en') {
                    $this->assertStringNotContainsString('The ', $message, $locale);
                }
            }
        }
    }
}
