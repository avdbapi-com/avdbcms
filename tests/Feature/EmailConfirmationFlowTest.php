<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

/**
 * Confirming an e-mail address (sites with "E-mail confirmation required"):
 *  - the confirmation link lands on the home page WITH a message ("Your e-mail is confirmed ..."; the controller
 *    flashed "success", which the home page and the layout never print, so the address was confirmed silently);
 *  - a member whose address is confirmed is never asked to confirm it again: /email/verify takes them home with
 *    "Your e-mail is already confirmed." and "Send the link again" sends nothing;
 *  - a mail server that refuses the mail gives the member a plain sentence, never the owner's setup instructions
 *    (they are for the site's administrators, who still get them, and for the log);
 *  - an unconfirmed member refused by the "confirmed e-mail" check reads a translated sentence that says how to get a
 *    new link (Laravel's "Your email address is not verified." was shown as it is, in every language), and the
 *    "Confirm your e-mail" page names what waits for the confirmation.
 */
class EmailConfirmationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.email_verification_required' => true, 'cache.limiter' => 'array', 'site.default_locale' => 'en']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        app()->setLocale('en');

        parent::tearDown();
    }

    private function link(User $member): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $member->getKey(),
            'hash' => sha1($member->getEmailForVerification()),
        ]);
    }

    public function test_the_confirmation_link_lands_home_with_a_message_the_page_shows(): void
    {
        $member = User::factory()->unverified()->create();

        $this->actingAs($member)->get($this->link($member))->assertRedirect(locale_url(route('pages.home')));
        $this->assertTrue($member->fresh()->hasVerifiedEmail());

        $message = __('Your e-mail is confirmed. Welcome to :site.', ['site' => config('app.name')]);
        $home = (string) $this->actingAs($member->fresh())->get(locale_url(route('pages.home')))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<div class="mk-toast mk-toast--success" role="status" data-avd-toast data-avd-toast-flash>.*?<span class="mk-toast__text">'.preg_quote(e($message), '#').'</span>#s', $home);
    }

    public function test_a_confirmed_member_is_not_asked_again(): void
    {
        Notification::fake();
        $member = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($member)->get('/email/verify')->assertRedirect(locale_url(route('pages.home')));
        $this->assertSame(['message' => __('Your e-mail is already confirmed.'), 'type' => 'success'], session('toast'));

        $this->actingAs($member)->from('/email/verify')->post('/email/verification-notification')
            ->assertRedirect(locale_url(route('pages.home')))
            ->assertSessionHas('toast', ['message' => __('Your e-mail is already confirmed.'), 'type' => 'success']);
        Notification::assertNothingSent();

        // an old link of an already confirmed address: the same message, not a second welcome
        $this->actingAs($member)->get($this->link($member))->assertRedirect(locale_url(route('pages.home')));
        $this->assertSame(__('Your e-mail is already confirmed.'), session('toast')['message'] ?? null);
    }

    public function test_an_unconfirmed_member_still_gets_the_page_and_the_link(): void
    {
        Notification::fake();
        $member = User::factory()->unverified()->create();

        $html = (string) $this->actingAs($member)->get('/email/verify')->assertOk()->getContent();
        $this->assertStringContainsString(e(__('Confirm your e-mail')), $html);
        // the page names what waits for the confirmation (the refusal of a like or a subscription said nothing of it)
        $this->assertStringContainsString(e(__('Until then you can watch videos. Likes, comments, subscriptions, reports, uploads, new playlists and Premium wait until your e-mail is confirmed.')), $html);

        $this->actingAs($member)->from('/email/verify')->post('/email/verification-notification')->assertRedirect('/email/verify')->assertSessionHas('status');
        Notification::assertSentTo($member, VerifyEmail::class);
    }

    public function test_a_mail_server_refusal_gives_members_a_plain_sentence_and_admins_the_setup_hint(): void
    {
        $member = User::factory()->unverified()->create();
        // The mail server refuses: Brevo's answer for an SMTP account that is not activated yet.
        $this->app->instance(\Illuminate\Contracts\Notifications\Dispatcher::class, new class implements \Illuminate\Contracts\Notifications\Dispatcher {
            public function send($notifiables, $notification)
            {
                throw new RuntimeException('Expected response code 250 but got code "502", with message "502 5.7.0 Your SMTP account is not yet activated."');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                $this->send($notifiables, $notification);
            }
        });

        $this->actingAs($member)->from('/email/verify')->post('/email/verification-notification')->assertRedirect('/email/verify');
        $memberMessage = (string) session('error');
        $this->assertSame(__('We could not send the e-mail right now. Try again in a few minutes. If it keeps failing, contact us.'), $memberMessage);
        foreach (['SMTP', 'Brevo', 'settings'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $memberMessage);
        }

        $admin = User::factory()->unverified()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin->fresh())->from('/email/verify')->post('/email/verification-notification');
        $this->assertStringContainsString('Brevo', (string) session('error'), 'the owner still reads what to fix');
    }

    public function test_the_confirmed_e_mail_refusal_is_translated_and_says_how_to_get_a_new_link(): void
    {
        $member = User::factory()->unverified()->create();
        $creator = User::factory()->create();

        $english = $this->actingAs($member, 'sanctum')->postJson('/api/subscribe/'.$creator->id)->assertForbidden()->json('message');
        $this->assertSame(__('Confirm your e-mail first. Open the link we sent you, or ask for a new one in Account settings.'), $english);
        $this->assertNotSame('Your email address is not verified.', $english);

        $french = $this->actingAs($member, 'sanctum')->withHeaders(['X-Locale' => 'fr'])->postJson('/api/subscribe/'.$creator->id)->assertForbidden()->json('message');
        $this->assertNotSame($english, $french, 'translated');
        app()->setLocale('en');

        // a page (not a script) goes to "Confirm your e-mail" in the member's language
        $_SERVER['DETECTED_LOCALE'] = 'fr';
        $this->actingAs($member)->get('/profile/playlists/create')->assertRedirect(url('/fr/email/verify'));
    }
}
