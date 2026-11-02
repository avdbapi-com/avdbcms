<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Account\VerifyUpdatedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Security plan S26: a member could change the profile e-mail, cancel, and change it again without limit; each change
 * mailed any address from the site's domain, with the member's user name as live Markdown in the heading (a link or a
 * remote image of their choice). Now a member gets 3 such mails an hour (shared with "send the link again"), and every
 * mail prints member text (user names, a video title) as text.
 */
class EmailChangeMailTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATES = [
        'account/ban', 'account/delete', 'account/email-update', 'account/forgot', 'account/password', 'account/verify',
        'premium/cancel', 'premium/card_expire', 'premium/invoice', 'premium/trial_end', 'premium/unpaid', 'premium/welcome',
        'video/ban',
    ];

    public function test_a_member_gets_three_mails_to_new_addresses_an_hour(): void
    {
        Notification::fake();
        $member = User::factory()->create(['email' => 'member@example.test']);
        $this->actingAs($member);

        foreach (['a1', 'a2', 'a3'] as $address) {
            $this->fromRoute('user.edit')->put(route('user.update'), ['email' => $address.'@example.test'])
                ->assertRedirectToRoute('user.edit')
                ->assertSessionHas('status');
            $this->assertSame($address.'@example.test', $member->fresh()->temporary_email);

            $this->fromRoute('user.edit')->post(route('verification.verify.update.cancel'))->assertRedirectToRoute('user.edit');
            $this->assertNull($member->fresh()->temporary_email);
        }

        Notification::assertSentToTimes($member, VerifyUpdatedEmail::class, 3);

        // The 4th within the hour: refused; the other fields are saved, nothing is pending, nothing is sent. The page says
        // both ("Too many attempts !" alone made the member think nothing was saved).
        $this->fromRoute('user.edit')->put(route('user.update'), ['email' => 'a4@example.test', 'username' => 'renamed_member'])
            ->assertRedirectToRoute('user.edit')
            ->assertSessionHas('status', __('Your profile is saved.'))
            ->assertSessionMissing('error');
        $this->assertStringStartsWith('Your e-mail was not changed: you changed it too often.', (string) session('warning'));

        $fresh = $member->fresh();
        $this->assertSame('renamed_member', $fresh->username);
        $this->assertNull($fresh->temporary_email);
        $this->assertSame('member@example.test', $fresh->email);
        Notification::assertSentToTimes($member, VerifyUpdatedEmail::class, 3);

        // "Send the link again" takes from the same budget: nothing either.
        $member->forceFill(['temporary_email' => 'a5@example.test'])->save();
        $this->fromRoute('user.edit')->post(route('verification.send.update'))
            ->assertRedirectToRoute('user.edit')
            ->assertSessionHas('error');
        $this->assertMatchesRegularExpression('/^You asked for too many confirmation links\. Try again in \d+ minutes?\.$/', (string) session('error'));
        Notification::assertSentToTimes($member, VerifyUpdatedEmail::class, 3);

        // Once the hour is over it works again.
        $member->forceFill(['temporary_email' => null])->save();
        RateLimiter::clear('email-change:'.$member->id);
        $this->fromRoute('user.edit')->put(route('user.update'), ['email' => 'a6@example.test'])->assertSessionHas('status');
        Notification::assertSentToTimes($member, VerifyUpdatedEmail::class, 4);
    }

    public function test_send_the_link_again_takes_from_the_same_budget(): void
    {
        Notification::fake();
        $member = User::factory()->create();
        $this->actingAs($member);

        $this->fromRoute('user.edit')->put(route('user.update'), ['email' => 'b1@example.test'])->assertSessionHas('status');
        $this->fromRoute('user.edit')->post(route('verification.send.update'))->assertSessionHas('status');

        // The button's own limit is one a minute.
        $this->travel(61)->seconds();
        $this->fromRoute('user.edit')->post(route('verification.send.update'))->assertSessionHas('status');

        $this->travel(61)->seconds();
        $this->fromRoute('user.edit')->post(route('verification.send.update'))->assertSessionHas('error');
        $this->assertMatchesRegularExpression('/^You asked for too many confirmation links\. Try again in \d+ minutes?\.$/', (string) session('error'));

        Notification::assertSentToTimes($member, VerifyUpdatedEmail::class, 3);
    }

    public static function hostileNames(): array
    {
        return [
            'a link' => ['[x](https://evil.example)'],
            'a link after a backslash' => ['\[x](https://evil.example)'],
            'a remote image' => ['![x](https://evil.example/p.png)'],
            'a link in capitals' => ['[x](HTTPS://evil.example)'],
        ];
    }

    #[DataProvider('hostileNames')]
    public function test_a_user_name_is_text_in_the_confirm_mail_and_the_link_still_works(string $name): void
    {
        $member = User::factory()->create();
        $member->forceFill(['username' => $name, 'temporary_email' => 'new@example.test'])->save();

        $html = (string) (new VerifyUpdatedEmail())->toMail($member)->render();

        $this->assertNoLinkOrImageTo('evil.example', $html);
        // Laravel's mail renderer drops a backslash right before [ in every mail (it did before too).
        $this->assertStringContainsString(str_replace('\[', '[', $name), $this->visible($html), 'the name prints as typed');

        $this->assertSame(1, preg_match('#<a href="([^"]+)" class="button button-primary"#', $html, $button));
        $this->actingAs($member)->get(html_entity_decode($button[1]))->assertRedirectToRoute('user.edit');
        $this->assertSame('new@example.test', $member->fresh()->email, 'the signed Confirm link still verifies');
    }

    #[DataProvider('hostileNames')]
    public function test_no_mail_turns_a_user_name_or_a_video_title_into_a_link(string $name): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
        $data = $this->mailData($name);

        foreach (self::TEMPLATES as $template) {
            $html = (string) app(Markdown::class)->render('mails.'.str_replace('/', '.', $template), $data);

            $this->assertNoLinkOrImageTo('evil.example', $html, $template);
            $this->assertStringContainsString(str_replace('\[', '[', $name), $this->visible($html), $template);
        }
    }

    public function test_every_changed_mail_shows_the_same_text_as_before(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));

        // The templates as they were: member text echoed straight into the Markdown.
        $dir = storage_path('framework/testing/s26-mails-'.uniqid());

        foreach (self::TEMPLATES as $template) {
            $source = (string) file_get_contents(resource_path('views/mails/'.$template.'.blade.php'));
            File::ensureDirectoryExists(dirname($dir.'/'.$template));
            file_put_contents($dir.'/'.$template.'.blade.php', (string) preg_replace('/\{\{ \\\\App\\\\Support\\\\MailMarkdown::text\((.*?)\) \}\}/', '{{ $1 }}', $source));
        }

        View::addNamespace('mailsbefore', $dir);

        try {
            foreach (['Nguyễn Văn A', 'Tom & "Jerry" O\'Neil'] as $name) {
                $data = $this->mailData($name);

                foreach (self::TEMPLATES as $template) {
                    $view = str_replace('/', '.', $template);
                    $markdown = app(Markdown::class);

                    $now = (string) $markdown->render('mails.'.$view, $data);
                    $this->assertSame((string) $markdown->render('mailsbefore::'.$view, $data), $now, $template.' (HTML) for '.$name);
                    $this->assertSame((string) $markdown->renderText('mailsbefore::'.$view, $data), (string) $markdown->renderText('mails.'.$view, $data), $template.' (text) for '.$name);
                    $this->assertStringContainsString($name, $this->visible($now), $template);
                }
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /** What every template needs, with $name as the member's user name and the video's title. */
    private function mailData(string $name): array
    {
        $plan = (object) ['price' => '$9.99', 'period' => 'month', 'name' => 'Monthly'];
        $subscription = (object) [
            'card_last4' => '4242', 'card_expired_at' => now()->addDays(20), 'created_at' => now()->subDays(5),
            'trial_ends_at' => now()->addDays(2), 'plan' => $plan,
        ];

        $notifiable = new User();
        $notifiable->forceFill(['username' => $name]);
        $notifiable->setRelation('premium_subscription', $subscription);

        return [
            'notifiable' => $notifiable,
            'url' => 'https://victim-site.example/confirm?signature=abc',
            'amount' => '$9.99',
            'transaction' => (object) ['date' => now(), 'subscription' => (object) ['next_payment' => now()->addMonth()]],
            'video' => (object) ['thumbnail_url' => 'https://victim-site.example/t.jpg', 'title' => $name, 'views' => 12, 'created_at' => now()->subDay()],
        ];
    }

    private function visible(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function assertNoLinkOrImageTo(string $host, string $html, string $where = ''): void
    {
        $this->assertDoesNotMatchRegularExpression('#<a\s[^>]*href="[a-z]+://'.preg_quote($host, '#').'#i', $html, $where);
        $this->assertDoesNotMatchRegularExpression('#<img\s[^>]*src="[a-z]+://'.preg_quote($host, '#').'#i', $html, $where);
    }
}
