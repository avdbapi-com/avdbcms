<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Security plan S18: the password-reset page has the reset token in its address and the e-mail in its query. It sends
 * "Referrer-Policy: no-referrer" (fonts and images it loads never name the address), and the owner's own head code and
 * scripts (an analytics or chat snippet reads location.href) are left out there; custom CSS stays. Other pages are
 * unchanged.
 */
class PasswordResetPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = '<meta name="avd-s18-head-marker" content="1">';

    private const JS = 'window.avdS18JsMarker = 1;';

    private const CSS = '.avd-s18-css-marker{color:red}';

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('custom_code_enabled', '1', 'appearance');
        Setting::set('custom_head_html', self::HEAD, 'appearance');
        Setting::set('custom_js', self::JS, 'appearance');
        Setting::set('custom_css', self::CSS, 'appearance');
        Cache::flush();
    }

    public function test_the_reset_page_names_no_referrer_and_runs_no_custom_code(): void
    {
        $response = $this->get('/reset-password/'.str_repeat('a1', 32).'?email='.urlencode('member@example.test'));

        $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('avd-s18-head-marker', $html);
        $this->assertStringNotContainsString('avdS18JsMarker', $html);
        $this->assertStringContainsString('avd-s18-css-marker', $html, 'custom CSS stays');
    }

    public function test_other_pages_keep_the_custom_code_and_the_old_policy(): void
    {
        foreach (['/', '/login'] as $page) {
            $response = $this->get($page);

            $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer-when-downgrade');
            $this->assertStringContainsString('avd-s18-head-marker', (string) $response->getContent(), $page);
            $this->assertStringContainsString('avdS18JsMarker', (string) $response->getContent(), $page);
        }
    }

    public function test_the_reset_form_still_goes_back_to_its_page_without_a_referer(): void
    {
        $page = '/reset-password/'.str_repeat('b2', 32).'?email='.urlencode('member@example.test');
        $this->get($page)->assertOk();

        // The browser sends no Referer from that page now: back() uses the page the session remembered.
        $this->post('/reset-password', [
            'token' => str_repeat('b2', 32),
            'email' => 'member@example.test',
            'password' => 'New-password-123',
            'password_confirmation' => 'New-password-123',
        ])->assertRedirect(url($page));
    }
}
