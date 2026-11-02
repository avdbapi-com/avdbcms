<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The 403 page ("This page is private") and the refusals of the policies:
 *  - an e-mail link that expired or that a mail app cut (a signed link) says so, in the page's language, and offers a
 *    new link (the e-mail confirmation: the "Confirm your e-mail" page; a changed address: Account settings) instead
 *    of Laravel's raw "Invalid signature." with no way on;
 *  - a policy's refusal ("You are not authorized to edit this video") is translated on the page and in the JSON answer
 *    of a script (it was English in every language).
 */
class ForbiddenPageTextsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['site.email_verification_required' => true, 'site.default_locale' => 'en']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['DETECTED_LOCALE']);
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_an_expired_confirmation_link_says_so_and_offers_a_new_link(): void
    {
        $member = User::factory()->unverified()->create();
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $member->getKey(),
            'hash' => sha1($member->getEmailForVerification()),
        ]);

        $html = (string) $this->actingAs($member)->get($expired)->assertForbidden()->getContent();
        $this->assertStringNotContainsString('Invalid signature', $html);
        $this->assertStringContainsString(e(__('This link does not work any more')), $html);
        $this->assertStringContainsString(e(__('It is too old, or your e-mail app cut it. Ask for a new link and open the newest one.')), $html);
        $this->assertMatchesRegularExpression('#<a class="mk-btn mk-btn--primary" href="'.preg_quote(e(locale_url(route('verification.notice'))), '#').'">.*?<span>'.preg_quote(e(__('Get a new link')), '#').'</span></a>#s', $html);

        // a cut link (the signature is missing): the same
        $cut = preg_replace('/&signature=[^&]+/', '', $expired);
        $this->assertStringNotContainsString('Invalid signature', (string) $this->actingAs($member)->get($cut)->assertForbidden()->getContent());
    }

    public function test_an_expired_address_change_link_sends_to_account_settings(): void
    {
        $member = User::factory()->create(['temporary_email' => 'new-address@example.test']);
        $expired = URL::temporarySignedRoute('verification.verify.update', now()->subMinute(), [
            'id' => $member->id,
            'hash' => sha1($member->getTemporaryEmailForVerification()),
        ]);

        $html = (string) $this->actingAs($member)->get($expired)->assertForbidden()->getContent();
        $this->assertStringNotContainsString('Invalid signature', $html);
        $this->assertMatchesRegularExpression('#<a class="mk-btn mk-btn--primary" href="'.preg_quote(e(locale_url(route('user.edit'))), '#').'">.*?<span>'.preg_quote(e(__('Open Account settings')), '#').'</span></a>#s', $html);
    }

    public function test_the_expired_link_page_is_in_the_members_language(): void
    {
        $member = User::factory()->unverified()->create();
        $expired = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $member->getKey(),
            'hash' => sha1($member->getEmailForVerification()),
        ]);

        $_SERVER['DETECTED_LOCALE'] = 'fr';
        $html = (string) $this->actingAs($member)->get($expired)->assertForbidden()->getContent();
        app()->setLocale('fr');
        $title = __('This link does not work any more');
        app()->setLocale('en');

        $this->assertNotSame('This link does not work any more', $title);
        $this->assertStringContainsString(e($title), $html);
        $this->assertStringContainsString('href="/fr/email/verify"', $html);
    }

    public function test_a_policy_refusal_is_translated_on_the_page_and_for_scripts(): void
    {
        $owner = User::factory()->create();
        // The owner's own banned video: its edit page refuses with the policy's sentence. (Another member's video is not
        // found there at all since round 2a R2A-062, like an id that does not exist.)
        $video = Video::factory()->for($owner)->withStatus(VideoStatus::BANNED)->banned()->create();

        $_SERVER['DETECTED_LOCALE'] = 'fr';
        $html = (string) $this->actingAs($owner)->get(route('user.videos.edit', $video))->assertForbidden()->getContent();
        unset($_SERVER['DETECTED_LOCALE']);
        app()->setLocale('fr');
        $french = __('You are not authorized to edit this video');
        app()->setLocale('en');

        $this->assertNotSame('You are not authorized to edit this video', $french, 'a French translation exists');
        $this->assertStringContainsString(e($french), $html);
        $this->assertStringNotContainsString('You are not authorized to edit this video', $html);

        // a script's answer (a policy refusal as JSON) follows the request's language too (another member reads them)
        $this->actingAs(User::factory()->create());
        $closed = Video::factory()->for($owner)->withStatus(VideoStatus::PUBLIC)->create(['allow_comments' => false]);
        $english = $this->getJson('/api/videos/'.$closed->uuid.'/comments')->assertNotFound()->json('message');
        $this->assertSame('Comments are turned off', $english);
        $json = $this->withHeaders(['X-Locale' => 'fr'])->getJson('/api/videos/'.$closed->uuid.'/comments')->assertNotFound()->json('message');
        app()->setLocale('fr');
        $this->assertSame(__('Comments are turned off'), $json);
        app()->setLocale('en');
        $this->assertNotSame('Comments are turned off', $json, 'not left in English');
    }
}
