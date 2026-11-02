<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Account\BanAccount;
use App\Notifications\Account\DeleteAccount;
use App\Notifications\Account\PasswordUpdate;
use App\Notifications\Account\VerifyUpdatedEmail;
use App\Notifications\Contact;
use App\Notifications\Support\MailTheme;
use App\Notifications\Support\MemberMail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The member e-mails (member design 6.21): one HTML layout (600px, inline styles, the theme's light brand
 * colour, one button whose address is printed under it) and a REAL plain-text part per mail (no Markdown "#", no HTML,
 * no entities, complete addresses), a translated subject per mail, the words of section 7, and the member's language
 * (the page's language at sign-up; User::preferredLocale afterwards).
 */
class MemberMailTest extends TestCase
{
    use RefreshDatabase;

    /** Every mail view: [html view, text view, data]. */
    private function mails(User $member): array
    {
        $plan = (object) ['price' => '$9.99', 'period' => 'month', 'name' => 'Monthly'];
        $member->setRelation('premium_subscription', (object) [
            'card_last4' => '4242', 'card_expired_at' => now()->addDays(20), 'created_at' => now()->subDays(5),
            'trial_ends_at' => now()->addDays(2), 'plan' => $plan,
        ]);
        $base = ['notifiable' => $member, 'url' => 'https://site.example/confirm?signature=abc'];

        return [
            'account.verify' => $base,
            'account.forgot' => $base,
            'account.password' => $base,
            'account.email-update' => $base,
            'account.ban' => $base,
            'account.delete' => $base,
            'premium.welcome' => $base,
            'premium.invoice' => $base + ['transaction' => (object) ['date' => now(), 'subscription' => (object) ['next_payment' => now()->addMonth()]]],
            'premium.cancel' => $base,
            'premium.unpaid' => $base + ['amount' => '$9.99'],
            'premium.trial_end' => $base,
            'premium.card_expire' => $base,
            'video.ban' => $base + ['video' => (object) ['thumbnail_url' => 'https://site.example/t.jpg', 'title' => 'Harbour lights', 'views' => 1234, 'created_at' => now()->subDay()]],
            'contact' => ['name' => 'Ann', 'email' => 'ann@example.test', 'contactMessage' => "Hello,\nwhere is part 2?", 'ip' => '203.0.113.9'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        unset($_SERVER['DETECTED_LOCALE']);
        parent::tearDown();
    }

    public function test_every_mail_has_an_html_part_in_the_layout_and_a_real_text_part(): void
    {
        $member = User::factory()->create(['username' => 'nightdrive_tv']);
        $brand = MailTheme::colors()['brand'];
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $brand);

        foreach ($this->mails($member) as $view => $data) {
            $html = view('mails.'.$view, $data)->render();
            $text = view('mails.text.'.$view, $data)->render();

            $this->assertStringContainsString('<html lang="en" dir="ltr">', $html, $view);
            $this->assertStringContainsString('background-color:'.$brand, $html, $view.': the theme\'s light brand colour');
            $this->assertMatchesRegularExpression('#<h1 style="[^"]+">[^<]+</h1>#', $html, $view);
            $this->assertStringNotContainsString('mail::', $html, $view);
            $this->assertStringContainsString('© 2026 '.config('app.name'), $html, $view);

            $this->assertStringNotContainsString('#', $text, $view.': no Markdown heading in the text part');
            $this->assertStringNotContainsString('<', $text, $view.': no HTML in the text part');
            $this->assertStringNotContainsString('&amp;', $text, $view);
            $this->assertStringNotContainsString('&copy;', $text, $view);
            $this->assertStringContainsString('© 2026 '.config('app.name'), $text, $view);
            $this->assertStringStartsWith(config('app.name'), trim($text), $view);

            if (isset($data['url']) && ! in_array($view, ['account.password', 'account.ban', 'account.delete', 'premium.welcome', 'premium.invoice', 'premium.cancel', 'premium.trial_end', 'video.ban'], true)) {
                $this->assertMatchesRegularExpression('#^'.preg_quote($data['url'], '#').'$#m', $text, $view.': the address on its own line');
                $this->assertStringContainsString('<a href="'.e($data['url']).'" class="button button-primary"', $html, $view);
            }
        }
    }

    public function test_the_words_of_section_7_replace_the_old_ones(): void
    {
        $member = User::factory()->create(['username' => 'nightdrive_tv']);
        $all = '';

        foreach ($this->mails($member) as $view => $data) {
            $all .= view('mails.text.'.$view, $data)->render()."\n";
        }

        $this->assertStringContainsString('Hello nightdrive_tv,', $all);
        $this->assertStringContainsString('You asked to change the e-mail of your '.config('app.name').' account.', $all);
        $this->assertStringContainsString('The link works for 60 minutes.', $all);
        $this->assertStringContainsString('Thank you. Premium is on.', $all);
        $this->assertStringContainsString('Need help? Contact us.', $all);
        $this->assertStringContainsString('Your last payment of $9.99', $all);
        foreach (['Hello nightdrive_tv !', 'A email update', 'Need help ?', 'Thank you for subscription', ' !'] as $old) {
            $this->assertStringNotContainsString($old, $all, $old);
        }
        $this->assertSame('24 hours', MemberMail::duration(1440));
        $this->assertSame('3 days', MemberMail::duration(4320));
        $this->assertSame('60 minutes', MemberMail::duration(60));
    }

    public function test_every_notification_has_a_subject_and_both_parts(): void
    {
        $member = User::factory()->create(['temporary_email' => 'new@example.test']);

        foreach ([new BanAccount(), new DeleteAccount(), new PasswordUpdate(), new VerifyUpdatedEmail()] as $notification) {
            $mail = $notification->toMail($member);
            $this->assertNotSame('', (string) $mail->subject, $notification::class);
            $this->assertIsArray($mail->view, $notification::class);
            $this->assertCount(2, $mail->view, $notification::class.': html + text');
            $this->assertStringStartsWith('mails.text.', $mail->view[1]);
        }

        $contact = (new Contact('Ann', 'ann@example.test', 'Hello there, a question.'))->toMail(new AnonymousNotifiable());
        $this->assertSame('New message from Ann on '.config('app.name'), $contact->subject);
        $this->assertSame([['ann@example.test', 'Ann']], $contact->replyTo);
    }

    public function test_a_member_who_signs_up_on_a_french_page_gets_the_confirmation_in_french(): void
    {
        config(['site.email_verification_required' => true, 'site.captcha_provider' => 'none', 'mail.default' => 'array', 'site.default_locale' => 'en']);
        $_SERVER['DETECTED_LOCALE'] = 'fr';

        $this->withSession(['app_locale' => 'fr'])->post('/register', [
            'username' => 'membre_fr', 'email' => 'membre@example.test', 'password' => 'password-123',
            'password_confirmation' => 'password-123', 'cgu' => '1',
        ])->assertRedirect();

        $this->assertSame('fr', User::query()->where('username', 'membre_fr')->value('language'));

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertGreaterThan(0, $messages->count(), 'the confirmation was sent');
        $message = $messages->last()->getOriginalMessage();
        $this->assertSame(__('Confirm your e-mail', [], 'fr'), $message->getSubject());
        $this->assertStringContainsString('<html lang="fr"', (string) $message->getHtmlBody());
        $this->assertStringContainsString('/email/verify/', (string) $message->getTextBody());
    }

    public function test_a_french_member_gets_the_mails_in_french(): void
    {
        $member = User::factory()->create(['language' => 'fr']);

        if (! $member instanceof HasLocalePreference) {
            $this->markTestSkipped('User::preferredLocale() is missing (member design 6.21).');
        }

        config(['mail.default' => 'array']);
        $member->notify(new PasswordUpdate());

        $message = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame(__('Your password was changed', [], 'fr'), $message->getSubject());
        $this->assertStringContainsString('<html lang="fr"', (string) $message->getHtmlBody());
    }

    public function test_an_arabic_mail_is_written_right_to_left(): void
    {
        app()->setLocale('ar');
        $member = User::factory()->create();

        $html = view('mails.account.password', ['notifiable' => $member])->render();
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $html);
        $this->assertStringContainsString('text-align:right', $html);
    }

    public function test_the_reset_mail_links_to_the_reset_page_with_the_e_mail(): void
    {
        config(['mail.default' => 'array', 'site.captcha_provider' => 'none']);
        $member = User::factory()->create(['email' => 'member@example.test', 'username' => 'resetter']);

        $this->post('/forgot-password', ['email' => 'member@example.test'])->assertRedirect();

        $message = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame(__('Reset your password'), $message->getSubject());
        $text = (string) $message->getTextBody();
        $this->assertMatchesRegularExpression('#^https?://[^/\s]+/reset-password/[A-Za-z0-9]+\?email=member%40example\.test$#m', $text, 'the address on its own line');
        $this->assertStringContainsString('Hello resetter,', $text);
        $this->assertStringContainsString('The link works for 60 minutes.', $text);
    }
}
