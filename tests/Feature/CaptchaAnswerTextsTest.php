<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Judge R2A-046: the built-in robot check answered an empty, a wrong and an expired answer with the same sentence,
 * "Please complete the security check.", so a person who had typed the sum was told to do the check. Each case has its
 * own sentence now; the form shows a new question every time.
 */
class CaptchaAnswerTextsTest extends TestCase
{
    use RefreshDatabase;

    private const EMPTY = 'Please complete the security check.';
    private const WRONG = 'That answer was wrong. Answer the new question.';
    private const EXPIRED = 'The security question expired. Answer the new one.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => CaptchaService::PROVIDER_BUILT_IN,
            'site.captcha_on_login' => true,
            'site.captcha_on_register' => true,
            'site.captcha_on_contact' => true,
            'site.captcha_on_password_reset' => true,
            'site.honeypot_enabled' => true,
            'site.login_rate_limit' => 1000,
            'site.default_locale' => 'en',
            'cache.limiter' => 'array',
        ]);
        Cache::flush();
    }

    public function test_an_empty_a_wrong_and_an_expired_answer_get_their_own_sentence(): void
    {
        $member = User::factory()->create(['password' => 'password']);
        $login = ['username' => $member->email, 'password' => 'password'];

        $this->assertSame(self::WRONG, $this->captchaError('/login', 'login', $login, '999'));
        $this->assertSame(self::EXPIRED, $this->captchaError('/login', 'login', $login, '11', now()->subMinute()->timestamp));
        $this->assertSame(self::EMPTY, $this->captchaError('/login', 'login', $login, ''));
        $this->assertSame(self::EMPTY, $this->captchaError('/login', 'login', $login, '   '));
        // a question this session never asked (the session is new, or the question was used in another tab)
        $this->flushSession();
        $this->from('/login')->post('/login', $login + ['captcha_id' => 'not-a-question', 'captcha_answer' => '11'])->assertSessionHasErrors('captcha');
        $this->assertSame(self::EXPIRED, session('errors')->first('captcha'));
        // a question of another form
        $this->assertSame(self::EXPIRED, $this->captchaError('/login', 'contact', $login, '11'));
        $this->assertGuest();

        // the right answer signs in, and the question cannot be used twice
        $this->flushSession();
        $this->withSession(['captcha.challenges' => ['q1' => ['action' => 'login', 'answer' => '11', 'expires_at' => now()->addMinutes(5)->timestamp]]])
            ->post('/login', $login + ['captcha_id' => 'q1', 'captcha_answer' => '11'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($member);
        $this->assertArrayNotHasKey('q1', (array) session('captcha.challenges'));
    }

    public function test_every_form_of_the_check_says_the_same(): void
    {
        Notification::fake();

        $forms = [
            ['/register', 'register', ['username' => 'newcomer', 'email' => 'newcomer@example.test', 'password' => 'password-123', 'password_confirmation' => 'password-123', 'cgu' => '1']],
            ['/forgot-password', 'password_reset', ['email' => 'someone@example.test']],
            ['/contact', 'contact', ['name' => 'Ann', 'email' => 'ann@example.test', 'message' => 'A question about the site, long enough to be sent.']],
        ];

        foreach ($forms as [$path, $action, $fields]) {
            $this->assertSame(self::WRONG, $this->captchaError($path, $action, $fields, '999'), $path);
            $this->assertSame(self::EXPIRED, $this->captchaError($path, $action, $fields, '11', now()->subSecond()->timestamp), $path);
        }
    }

    public function test_a_wrong_answer_shows_the_sentence_under_a_new_question(): void
    {
        $member = User::factory()->create(['password' => 'password']);
        $this->captchaError('/login', 'login', ['username' => $member->email, 'password' => 'password'], '999');

        $page = (string) $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<span class="mk-error" id="captcha-error">'.e(self::WRONG).'</span>', $page);
        // a new question: carried by the form, encrypted and signed (round 3 review SB-X1: it was kept in the session)
        $this->assertMatchesRegularExpression('#name="captcha_id" value="eyJ[A-Za-z0-9+/=]{100,}"#', $page, 'a new question');
        $this->assertStringNotContainsString('value="q-old"', $page);
    }

    /** Sends $path with a question of $questionAction (answer 11) and $answer; returns the captcha field's error. */
    private function captchaError(string $path, string $questionAction, array $fields, string $answer, ?int $expires = null): ?string
    {
        $this->flushSession();
        $this->withSession(['captcha.challenges' => ['q-old' => ['action' => $questionAction, 'answer' => '11', 'expires_at' => $expires ?? now()->addMinutes(5)->timestamp]]])
            ->from($path)
            ->post($path, $fields + ['captcha_id' => 'q-old', 'captcha_answer' => $answer]);

        $errors = session('errors');

        return $errors?->first('captcha') ?: null;
    }
}
