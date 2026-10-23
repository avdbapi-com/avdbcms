<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\Contact;
use App\Services\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CaptchaSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_built_in_captcha_renders_on_enabled_login_form(): void
    {
        $this->enableCaptcha('login');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Security check')
            ->assertSee('name="captcha_id"', false)
            ->assertSee('name="captcha_answer"', false)
            ->assertSee('name="website"', false);
    }

    public function test_contact_form_requires_enabled_built_in_captcha(): void
    {
        $this->enableCaptcha('contact');

        $this->post(route('contact.contact'), $this->contactPayload())
            ->assertSessionHasErrors(['captcha']);
    }

    public function test_contact_form_accepts_valid_built_in_captcha(): void
    {
        Notification::fake();
        $this->enableCaptcha('contact');

        $id = 'captcha-test-id';
        $this->withSession([
            'captcha.challenges' => [
                $id => [
                    'action' => 'contact',
                    'answer' => '11',
                    'expires_at' => now()->addMinutes(5)->timestamp,
                ],
            ],
        ])->post(route('contact.contact'), array_merge($this->contactPayload(), [
            'captcha_id' => $id,
            'captcha_answer' => '11',
        ]))
            ->assertRedirect(route('contact.show'))
            ->assertSessionHas('success');

        Notification::assertSentOnDemand(Contact::class);
    }

    public function test_json_accept_header_does_not_bypass_web_captcha(): void
    {
        config([
            'site.captcha_provider' => CaptchaService::PROVIDER_BUILT_IN,
            'site.captcha_on_login' => true,
        ]);

        $service = app(CaptchaService::class);

        $this->assertTrue($service->enabledFor('login', Request::create(
            '/login',
            'POST',
            server: ['HTTP_ACCEPT' => 'application/json'],
        )));
        $this->assertFalse($service->enabledFor('login', Request::create(
            '/api/login',
            'POST',
            server: ['HTTP_ACCEPT' => 'application/json'],
        )));
    }

    public function test_honeypot_blocks_bot_submissions_even_without_captcha_provider(): void
    {
        config([
            'site.captcha_provider' => CaptchaService::PROVIDER_NONE,
            'site.honeypot_enabled' => true,
        ]);

        $this->post(route('contact.contact'), array_merge($this->contactPayload(), [
            'website' => 'https://spam.example',
        ]))
            ->assertSessionHasErrors(['captcha']);
    }

    public function test_turnstile_widget_renders_when_external_keys_are_configured(): void
    {
        config([
            'site.captcha_provider' => CaptchaService::PROVIDER_TURNSTILE,
            'site.captcha_site_key' => 'site-key',
            'site.captcha_secret_key' => 'secret-key',
            'site.captcha_on_login' => true,
            'site.honeypot_enabled' => true,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('cf-turnstile', false)
            ->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js', false)
            ->assertSee('data-sitekey="site-key"', false);
    }

    public function test_admin_can_update_captcha_security_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'security'), $this->securityPayload([
                'captcha_provider' => CaptchaService::PROVIDER_RECAPTCHA_V2,
                'captcha_site_key' => 'public-site-key',
                'captcha_secret_key' => 'private-secret-key',
                'captcha_on_login' => '1',
                'captcha_on_register' => '0',
                'captcha_on_contact' => '1',
                'captcha_on_password_reset' => '1',
            ]))
            ->assertRedirect(route('admin.settings.index', ['tab' => 'security']));

        $this->assertDatabaseHas('settings', [
            'group' => 'security',
            'key' => 'captcha_provider',
            'value' => CaptchaService::PROVIDER_RECAPTCHA_V2,
        ]);
        $this->assertSame('public-site-key', Setting::get('captcha_site_key'));
        $this->assertSame('private-secret-key', Setting::get('captcha_secret_key'));
        $this->assertSame('0', Setting::get('captcha_on_register'));
    }

    private function enableCaptcha(string $action): void
    {
        config([
            'site.captcha_provider' => CaptchaService::PROVIDER_BUILT_IN,
            "site.captcha_on_{$action}" => true,
            'site.honeypot_enabled' => true,
        ]);
    }

    private function contactPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Support User',
            'email' => 'support-user@example.test',
            'message' => 'This is a normal support request with enough text.',
            'website' => '',
        ], $overrides);
    }

    private function securityPayload(array $overrides = []): array
    {
        return array_replace([
            'force_https' => '0',
            'honeypot_enabled' => '1',
            'hsts_enabled' => '0',
            'csp_enabled' => '0',
            'xss_protection' => '1',
            'nosniff_header' => '1',
            'frame_options_header' => '1',
            'captcha_provider' => CaptchaService::PROVIDER_BUILT_IN,
            'captcha_site_key' => '',
            'captcha_secret_key' => '',
            'captcha_on_login' => '1',
            'captcha_on_register' => '1',
            'captcha_on_contact' => '1',
            'captcha_on_password_reset' => '1',
            'hsts_max_age' => '31536000',
            'csp_directives' => '',
            'admin_ip_whitelist' => '',
            'global_ip_blacklist' => '',
        ], $overrides);
    }
}
