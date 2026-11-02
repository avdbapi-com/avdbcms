<?php

namespace Tests\Feature\Member;

use App\Models\User;
use App\Notifications\Account\VerifyPhone;
use App\Support\SmsAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The phone number of Account settings > Profile (member design 6.17): offered only while the site can send
 * text messages, always removable (account-11), one account per number with a plain message (account-12), a code that
 * works for 10 minutes and 5 tries, at most 5 codes an hour, one code state shared with the verify form, no country
 * chosen for the member (account-28).
 */
class PhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['site.default_locale' => 'en', 'app.locale' => 'en', 'services.brevo.api_key' => '']);
        $this->withoutVite();
    }

    private function smsOn(): void
    {
        config(['services.brevo.api_key' => 'xkeysib-test']);
    }

    public function test_without_text_messages_no_phone_field_is_offered_and_no_number_is_saved(): void
    {
        $member = User::factory()->create(['phone' => null]);
        $this->assertFalse(SmsAvailability::canSend());

        $html = $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="phone"', $html);
        $this->assertStringNotContainsString('id="phone-code"', $html);

        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'phone' => '0612345678', 'code' => 'FR'])
            ->assertSessionHasNoErrors();
        $this->assertNull($member->fresh()->getRawOriginal('phone'), 'a number nobody can check is not kept');
        Notification::assertNothingSent();
    }

    public function test_a_number_saved_before_can_be_seen_and_removed_while_no_text_message_can_be_sent(): void
    {
        $member = User::factory()->create();
        $member->forceFill(['phone' => '+33612345678', 'phone_verified_at' => now()])->save();

        $html = $this->actingAs($member)->get(route('user.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('+33 6 12 34 56 78', $html);
        $this->assertStringContainsString('id="mk-remove-phone"', $html);

        // account-11: "Remove this number"
        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'remove_phone' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('user.edit'));
        $this->assertNull($member->fresh()->getRawOriginal('phone'));
        $this->assertNull($member->fresh()->phone_verified_at);
    }

    public function test_an_emptied_phone_field_removes_the_number(): void
    {
        $this->smsOn();
        $member = User::factory()->create();
        $member->forceFill(['phone' => '+33612345678', 'phone_verified_at' => now()])->save();

        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'phone' => '', 'code' => 'FR'])
            ->assertSessionHasNoErrors();
        $this->assertNull($member->fresh()->getRawOriginal('phone'));
    }

    public function test_a_new_number_is_saved_in_the_international_format_and_gets_a_code(): void
    {
        $this->smsOn();
        $member = User::factory()->create();

        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'phone' => '06 12 34 56 78', 'code' => 'FR'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('info', __('Code sent. It works for 10 minutes.'));

        $fresh = $member->fresh();
        $this->assertSame('+33612345678', $fresh->getRawOriginal('phone'));
        $this->assertNull($fresh->phone_verified_at);
        $this->assertMatchesRegularExpression('/^\d{5}$/', (string) $fresh->phone_confirmation_code);
        Notification::assertSentTo($fresh, VerifyPhone::class);

        // the page now asks for the code, with "Send a new code" waiting a minute
        $html = $this->actingAs($fresh)->get(route('user.edit'))->getContent();
        $this->assertStringContainsString('id="phone-otp"', $html);
        // the number between Unicode isolate marks: it keeps its order inside a right-to-left sentence
        $this->assertStringContainsString(e(__('We sent a 5-digit code to :number.', ['number' => "\u{2066}+33 6 12 34 56 78\u{2069}"])), $html);
        $this->assertStringContainsString('id="mk-phone-send"', $html);
    }

    public function test_a_number_another_account_uses_is_refused_with_a_plain_message(): void
    {
        $this->smsOn();
        $owner = User::factory()->create();
        $owner->forceFill(['phone' => '+33612345678'])->save();
        $member = User::factory()->create();

        // account-12: the same number typed in another way is the same number
        $this->actingAs($member)->from(route('user.edit'))
            ->put(route('user.update'), ['section' => 'profile', 'phone' => '0612345678', 'code' => 'FR'])
            ->assertRedirect(route('user.edit'))
            ->assertSessionHasErrors(['phone' => __('This number is already used by another account.')]);
        $this->assertNull($member->fresh()->getRawOriginal('phone'));

        // the owner keeps saving the profile with their own number
        $this->actingAs($owner)->put(route('user.update'), ['section' => 'profile', 'phone' => '+33612345678', 'code' => 'FR'])
            ->assertSessionHasNoErrors();
    }

    public function test_a_number_needs_its_country_and_must_be_a_mobile_number(): void
    {
        $this->smsOn();
        $member = User::factory()->create();

        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'phone' => '0612345678', 'code' => 'XX'])
            ->assertSessionHasErrors('code');
        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'phone' => '0145678901', 'code' => 'FR'])
            ->assertSessionHasErrors(['phone' => __('This is not a mobile number of the country you chose.')]);
    }

    /**
     * A code has 5 digits and works for 10 minutes; after 5 wrong tries it stops working (even the right code) and a new
     * one is offered when it would have expired ("Too many tries. Send a new code in 10 minutes.", the words of the phone
     * verification controller); a new code starts with all its tries. One code a minute.
     */
    public function test_the_code_works_for_ten_minutes_and_five_tries_then_a_new_one_is_needed(): void
    {
        $this->smsOn();
        $member = User::factory()->create();
        $member->forceFill(['phone' => '+33612345678'])->save();

        $this->assertSame('none', $member->checkPhoneCode('12345'));
        $this->assertTrue($member->sendPhoneVerificationNotification());
        $code = (string) $member->fresh()->phone_confirmation_code;
        $this->assertMatchesRegularExpression('/^\d{5}$/', $code);
        $wrong = $code === '00000' ? '11111' : '00000';

        for ($try = 1; $try <= 4; $try++) {
            $this->assertSame('wrong', $member->fresh()->checkPhoneCode($wrong));
        }
        $this->assertSame(1, $member->fresh()->phoneCodeTriesLeft());
        $this->assertStringContainsString('1 try left', $member->fresh()->phoneCodeMessage('wrong'));

        $this->assertSame('locked', $member->fresh()->checkPhoneCode($wrong), 'the 5th wrong try');
        $this->assertSame('locked', $member->fresh()->checkPhoneCode($code), 'after 5 wrong tries the right code no longer works either');
        $this->assertFalse($member->fresh()->hasVerifiedPhone());
        $this->assertSame('Too many tries. Send a new code in 10 minutes.', $member->fresh()->phoneCodeMessage('locked'));
        $this->assertGreaterThan(9 * 60, $member->fresh()->phoneCodeResendSeconds(), 'the page offers a new code when this one would have expired');

        // the used-up code's 10 minutes are over: a new code, with all its tries again
        $this->travel(10)->minutes();
        $this->assertSame(0, $member->fresh()->phoneCodeResendSeconds());
        $this->assertTrue($member->fresh()->sendPhoneVerificationNotification());
        $this->assertSame(5, $member->fresh()->phoneCodeTriesLeft());
        $this->assertGreaterThan(0, $member->fresh()->phoneCodeResendSeconds(), 'one code a minute');

        // a code older than 10 minutes has expired
        $this->travel(11)->minutes();
        $this->assertSame('expired', $member->fresh()->checkPhoneCode((string) $member->fresh()->phone_confirmation_code));
        $this->assertStringContainsString('expired', $member->fresh()->phoneCodeMessage('expired'));

        $this->assertTrue($member->fresh()->sendPhoneVerificationNotification());
        $this->assertSame('verified', $member->fresh()->checkPhoneCode((string) $member->fresh()->phone_confirmation_code));
        $this->assertTrue($member->fresh()->hasVerifiedPhone());
        $this->assertNull($member->fresh()->phone_confirmation_code);
    }

    /**
     * Text messages cost the site money: at most 5 codes an hour per member, Profile saves and "Send a new code"
     * together (changing the number back and forth sent a text message every time). The Profile says when the next one
     * can come and offers "Send a code" for the saved number.
     */
    public function test_at_most_five_codes_an_hour_and_the_profile_says_when_the_next_can_come(): void
    {
        $this->smsOn();
        $member = User::factory()->create();
        $member->forceFill(['phone' => '+33612345678'])->save();

        for ($code = 1; $code <= 5; $code++) {
            $this->assertTrue($member->fresh()->sendPhoneVerificationNotification(), 'code '.$code);
            $this->travel(61)->seconds();
        }
        $waiting = (string) $member->fresh()->phone_confirmation_code;
        $this->assertFalse($member->fresh()->sendPhoneVerificationNotification(), 'the 6th code within the hour');
        $this->assertSame($waiting, (string) $member->fresh()->phone_confirmation_code, 'the code sent before keeps working');
        $this->assertGreaterThan(0, $member->fresh()->phoneCodeSendBlockedSeconds());
        $this->assertStringContainsString('No code was sent', $member->fresh()->phoneCodeLimitMessage());
        Notification::assertSentToTimes($member, VerifyPhone::class, 5);

        // a new number on the Profile: saved, its code waits for the hour, and the page says so
        $this->actingAs($member->fresh())->put(route('user.update'), ['section' => 'profile', 'phone' => '06 98 76 54 32', 'code' => 'FR'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('Your profile is saved.'))
            ->assertSessionMissing('info');
        $this->assertStringContainsString('No code was sent: you asked for too many codes.', (string) session('warning'));
        $this->assertSame('+33698765432', $member->fresh()->getRawOriginal('phone'));
        $this->assertNull($member->fresh()->phone_confirmation_code);

        $html = $this->actingAs($member->fresh())->get(route('user.edit'))->getContent();
        $this->assertStringContainsString(e(__('not verified')), $html);
        $this->assertStringContainsString('id="mk-phone-send"', $html, '"Send a code" for the saved number');
        $this->assertStringContainsString(e(__('Send a code')), $html);
        $this->assertMatchesRegularExpression('/x-data="\{ wait: [1-9]\d* \}"/', $html, 'counting down to the next code');

        $this->travel(61)->minutes();
        $this->assertTrue($member->fresh()->sendPhoneVerificationNotification(), 'the next hour');
    }

    /**
     * ONE state for the code (9.3): what a Profile save sends is what the Verify form checks, under the keys the
     * phone verification controller reads (phone-code.sent.<id>, phone-code.tries.<id>). Before, the Profile kept its
     * own state and that controller answered "This code has expired" for the right code.
     */
    public function test_the_code_of_a_profile_save_is_the_one_the_verify_form_checks(): void
    {
        $this->smsOn();
        $member = User::factory()->create();

        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'phone' => '06 12 34 56 78', 'code' => 'FR'])
            ->assertSessionHas('info', __('Code sent. It works for 10 minutes.'));

        $fresh = $member->fresh();
        $this->assertSame('phone-code.sent.'.$member->id, $fresh->phoneCodeSentKey());
        $this->assertSame('phone-code.tries.'.$member->id, $fresh->phoneCodeTriesKey());
        $this->assertTrue(is_numeric(Cache::get('phone-code.sent.'.$member->id)), 'the send time, where the phone verification controller reads it');
        $this->assertSame('wrong', $fresh->checkPhoneCode('99999' === $fresh->phone_confirmation_code ? '88888' : '99999'));
        $this->assertSame(1, (int) Cache::get('phone-code.tries.'.$member->id), 'the wrong try, where the phone verification controller counts it');

        $this->actingAs($fresh)->from(route('user.edit'))->post(route('phone.verify'), ['code' => (string) $fresh->phone_confirmation_code])
            ->assertRedirect();
        $this->assertTrue($member->fresh()->hasVerifiedPhone());
        $this->assertNull(Cache::get('phone-code.sent.'.$member->id));
        $this->assertNull(Cache::get('phone-code.tries.'.$member->id));
    }

    public function test_no_country_is_chosen_for_the_member(): void
    {
        $this->smsOn();
        $member = User::factory()->create(['country' => null, 'phone' => null]);

        // account-28: the old page gave everybody "FR +33"
        $this->assertNull($member->phoneCountry());
        $html = $this->actingAs($member)->get(route('user.edit'))->getContent();
        $this->assertDoesNotMatchRegularExpression('/<option value="FR"[^>]*selected/', $html);

        $member->forceFill(['country' => 'DE'])->save();
        $this->assertSame('DE', $member->fresh()->phoneCountry(), "the member's own country when it has phones");
    }
}
