<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * "Choose a new password" (POST /reset-password):
 *  - it never tells whether an address has an account: a made-up link for an unknown address and a wrong or used link
 *    for a member's address get the same answer, a plain sentence with a way to ask for a new link (the broker's
 *    "We can't find a user with that email address." / "This password reset token is invalid." were shown as they were);
 *  - it is limited like the other forms of the sign-in pages (5 a minute per internet address), and a throttled try
 *    goes back to the form with the wait in seconds and the e-mail kept;
 *  - the broker's own sentences (lang/<locale>/passwords.php) are plain words, the same for "unknown address" and
 *    "bad link", in every language of the site (they were English everywhere, with "token").
 */
class PasswordResetLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '/reset-password/';

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.limiter' => 'array']);
        Cache::flush();
        RateLimiter::clear('password-reset');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        app()->setLocale('en');

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function form(string $email, string $token): array
    {
        return ['token' => $token, 'email' => $email, 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123'];
    }

    /**
     * The answer to one try, as the member sees it: the errors, the one-time messages, and the reset page shown after it.
     *
     * @return array{errors: array<string, list<string>>, flash: array<string, mixed>, page: string}
     */
    private function answer(string $email, string $token): array
    {
        $page = self::PAGE.$token.'?email='.urlencode($email);
        $response = $this->from($page)->post('/reset-password', $this->form($email, $token));
        $response->assertRedirect(url($page));

        $errors = session('errors')?->getBag('default')->getMessages() ?? [];
        $flash = [];
        foreach (['error', 'status', 'reset_link_invalid', 'auth_throttled'] as $key) {
            $flash[$key] = session($key);
        }

        return ['errors' => $errors, 'flash' => $flash, 'page' => (string) $this->get($page)->getContent()];
    }

    public function test_an_unknown_address_and_a_bad_link_for_a_member_get_the_same_answer(): void
    {
        User::factory()->create(['email' => 'member@example.test']);

        $unknown = $this->answer('nobody@example.test', str_repeat('d4', 32));
        RateLimiter::clear('password-reset');
        $member = $this->answer('member@example.test', str_repeat('e5', 32));

        $this->assertSame($unknown['errors'], $member['errors'], 'the same field errors');
        $this->assertSame($unknown['flash'], $member['flash'], 'the same one-time messages');
        $this->assertTrue((bool) $member['flash']['reset_link_invalid']);

        foreach ([$unknown, $member] as $answer) {
            $this->assertStringNotContainsString("We can't find a user", $answer['page']);
            $this->assertStringNotContainsString('We can&#039;t find a user', $answer['page']);
            $this->assertStringNotContainsString('token', $this->visibleText($answer['page']));
            $this->assertStringContainsString(e(__('This reset link does not work any more.')), $answer['page']);
            // a way to ask for a new link
            $this->assertMatchesRegularExpression('#<a href="'.preg_quote(e(locale_url(route('password.forgot'))), '#').'">'.preg_quote(e(__('Ask for a new link')), '#').'</a>#', $answer['page']);
        }
    }

    public function test_a_valid_link_still_changes_the_password(): void
    {
        $member = User::factory()->create(['email' => 'member@example.test']);
        $token = app('auth.password.broker')->createToken($member);

        $this->post('/reset-password', $this->form('member@example.test', $token))
            ->assertRedirect(locale_url(route('login')))
            ->assertSessionHas('success', __('Your password is changed. Sign in with the new password.'));
    }

    public function test_the_reset_form_is_limited_and_says_how_long_to_wait(): void
    {
        $token = str_repeat('f6', 32);
        $page = self::PAGE.$token.'?email='.urlencode('someone@example.test');

        for ($i = 0; $i < 5; $i++) {
            $this->from($page)->post('/reset-password', $this->form('someone'.$i.'@example.test', $token))->assertRedirect(url($page));
            $this->assertNull(session('auth_throttled'), 'try '.($i + 1).' is not throttled');
        }

        $this->from($page)->post('/reset-password', $this->form('someone@example.test', $token))
            ->assertRedirect(url($page))
            ->assertSessionHas('auth_throttled')
            ->assertSessionHasInput('email', 'someone@example.test');
        $this->assertMatchesRegularExpression('/^Too many tries\. Wait \d+ seconds?, then try again\.$/', (string) session('error'));

        // a script gets the same sentence with 429 and Retry-After
        $this->postJson('/reset-password', $this->form('someone@example.test', $token))
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_the_brokers_sentences_are_plain_and_translated_in_every_language(): void
    {
        $english = require lang_path('en/passwords.php');
        $this->assertSame($english['token'], $english['user'], 'an unknown address reads like a bad link');
        foreach ($english as $key => $line) {
            $this->assertStringNotContainsStringIgnoringCase('token', $line, 'en.'.$key);
            $this->assertStringNotContainsString(' !', $line, 'en.'.$key);
        }

        $locales = array_values(array_filter(array_map('basename', glob(lang_path('*'), GLOB_ONLYDIR) ?: []), fn ($code) => $code !== 'en'));
        $this->assertCount(17, $locales);
        foreach ($locales as $locale) {
            $lines = require lang_path($locale.'/passwords.php');
            $this->assertSame(array_keys($english), array_keys($lines), $locale);
            $this->assertSame($lines['token'], $lines['user'], $locale.': an unknown address reads like a bad link');
            foreach ($english as $key => $line) {
                $this->assertNotSame($line, $lines[$key], $locale.'.'.$key.' is translated');
                $this->assertStringNotContainsStringIgnoringCase('token', $lines[$key], $locale.'.'.$key);
            }
        }
    }

    public function test_a_french_member_reads_the_refusal_in_french(): void
    {
        $token = str_repeat('a7', 32);
        $page = self::PAGE.$token.'?email='.urlencode('nobody@example.test');

        $_SERVER['DETECTED_LOCALE'] = 'fr';
        $this->from($page)->post('/reset-password', $this->form('nobody@example.test', $token));
        $html = (string) $this->get($page)->getContent();
        app()->setLocale('fr');
        $sentence = __('This reset link does not work any more.');
        app()->setLocale('en');

        $this->assertNotSame('This reset link does not work any more.', $sentence, 'a French translation exists');
        $this->assertStringContainsString(e($sentence), $html);
        $this->assertStringNotContainsString('This password reset token is invalid.', $html);
    }

    /** The page's visible words (no scripts, styles or tags), lower case. */
    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|template)\b.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<input\b[^>]*>#i', ' ', $html);

        return strtolower(html_entity_decode(strip_tags($html)));
    }
}
