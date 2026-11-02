<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The hidden anti-spam field ("honeypot", the "website" input) of the sign-in, sign-up, forgot-password and contact
 * forms. A password manager or the browser's autofill sometimes fills it; the form is then refused, as before, but the
 * person now reads why and what to do, also while the robot check is switched off for that form (the refusal used to
 * be an error of the robot check's field, which the page does not print while the check is off: nothing was shown).
 * The error stays on the "captcha" key, as scripts and apps already read it.
 */
class HoneypotMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.honeypot_enabled' => true,
            'site.captcha_provider' => 'built_in',
            'site.captcha_on_login' => false,
            'site.captcha_on_register' => false,
            'site.captcha_on_password_reset' => false,
            'site.captcha_on_contact' => false,
            'site.login_rate_limit' => 1000,
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
        Notification::fake();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function forms(): array
    {
        return [
            'sign in' => ['/login', '/login', ['username' => 'someone', 'password' => 'password-123']],
            'sign up' => ['/register', '/register', [
                'username' => 'filled_member', 'email' => 'filled@example.test', 'password' => 'password-123',
                'password_confirmation' => 'password-123', 'cgu' => '1',
            ]],
            'forgot password' => ['/forgot-password', '/forgot-password', ['email' => 'filled@example.test']],
            'contact' => ['/contact', '/contact', ['name' => 'Filled Visitor', 'email' => 'visitor@example.test', 'message' => 'A message long enough to be sent.']],
        ];
    }

    #[DataProvider('forms')]
    public function test_a_filled_honeypot_is_refused_with_a_message_the_page_shows(string $page, string $action, array $fields): void
    {
        $this->from($page)->post($action, $fields + ['website' => 'https://autofill.example'])
            ->assertRedirect($page)
            ->assertSessionHasErrors('captcha');

        $message = __('The form was not sent: a hidden anti-spam field was filled in, which autofill sometimes does. Fill in the form by hand and send it again.');
        $this->assertSame($message, session('errors')->first('captcha'));

        $html = (string) $this->get($page)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<div class="mk-alert mk-alert--danger" role="alert"[^>]*>\s*<i[^>]*></i>\s*<div class="mk-alert__body">\s*<span>'.preg_quote(e($message), '#').'</span>#', $html, 'the refusal is shown in the alert of the form');
        $this->assertDatabaseMissing('users', ['username' => 'filled_member']);
    }

    #[DataProvider('forms')]
    public function test_with_the_robot_check_on_the_refusal_stays_under_the_check(string $page, string $action, array $fields): void
    {
        config(['site.captcha_on_login' => true, 'site.captcha_on_register' => true, 'site.captcha_on_password_reset' => true, 'site.captcha_on_contact' => true]);

        $this->from($page)->post($action, $fields + ['website' => 'https://autofill.example'])->assertSessionHasErrors('captcha');
        $message = (string) session('errors')->first('captcha');

        $html = (string) $this->get($page)->assertOk()->getContent();
        $this->assertStringContainsString('<span class="mk-error" id="captcha-error">'.e($message).'</span>', $html);
        $this->assertSame(1, substr_count($html, e($message)), 'shown once, under the check');
    }

    public function test_an_empty_honeypot_signs_in_as_before(): void
    {
        $member = User::factory()->create(['username' => 'plain_member', 'password' => 'password-123']);

        $this->post('/login', ['username' => 'plain_member', 'password' => 'password-123', 'website' => ''])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($member);
    }
}
