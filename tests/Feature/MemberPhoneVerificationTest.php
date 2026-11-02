<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PhoneVerification;
use App\Models\User;
use App\Notifications\Account\VerifyPhone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The phone code (member design 6.17, account-11; App\Http\Controllers\Auth\PhoneVerification and the queued
 * App\Notifications\Account\VerifyPhone): only while the site can send texts, sent through the queue (the request never
 * waits for the SMS service), one code works for 10 minutes and 5 tries, and every answer is a translated sentence with
 * the tries left: JSON for a script, a one-time message for the page.
 */
class MemberPhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // a site that can send texts: a Brevo key in .env (BREVO_API_KEY = config services.brevo.api_key, the key
        // App\Support\SmsAvailability::canSend() reads too)
        config(['services.brevo.api_key' => 'xkeysib-test-0123456789']);
        Cache::flush();
    }

    public function test_without_a_text_service_the_site_says_phone_checks_are_not_set_up(): void
    {
        config(['services.brevo.api_key' => '']);
        Notification::fake();
        $member = $this->member();

        $this->actingAs($member)->postJson(route('phone.send'))
            ->assertStatus(422)
            ->assertJsonPath('message', __('Phone checks are not set up on this site.'));

        Notification::assertNothingSent();
        $this->assertNull($member->fresh()->phone_confirmation_code);
    }

    public function test_a_member_without_a_number_is_asked_to_add_one_first(): void
    {
        Notification::fake();
        $member = User::factory()->create(['phone' => null]);

        $this->actingAs($member)->postJson(route('phone.send'))
            ->assertStatus(422)
            ->assertJsonPath('message', __('Add your phone number first.'));

        Notification::assertNothingSent();
    }

    public function test_the_code_goes_out_through_the_queue_and_works_for_ten_minutes(): void
    {
        Queue::fake();
        $member = $this->member();

        $this->actingAs($member)->postJson(route('phone.send'))
            ->assertOk()
            ->assertJsonPath('message', __('Code sent. It works for :minutes minutes.', ['minutes' => PhoneVerification::CODE_MINUTES]))
            ->assertJsonPath('tries_left', PhoneVerification::MAX_TRIES)
            ->assertJsonPath('expires_in', PhoneVerification::CODE_MINUTES * 60);

        // queued: the answer above came before any SMS was sent (account-11)
        $this->assertInstanceOf(ShouldQueue::class, new VerifyPhone());
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof VerifyPhone);
        $this->assertMatchesRegularExpression('/^\d{5}$/', (string) $member->fresh()->phone_confirmation_code);
    }

    public function test_a_wrong_code_says_the_tries_left_and_the_fifth_wrong_code_ends_the_code(): void
    {
        Queue::fake();
        $member = $this->member();
        $this->actingAs($member)->postJson(route('phone.send'))->assertOk();
        $code = (string) $member->fresh()->phone_confirmation_code;
        $wrong = $code === '00000' ? '11111' : '00000';

        for ($try = 1; $try < PhoneVerification::MAX_TRIES; $try++) {
            $left = PhoneVerification::MAX_TRIES - $try;
            $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $wrong])
                ->assertStatus(422)
                ->assertJsonPath('tries_left', $left)
                ->assertJsonPath('message', trans_choice(
                    '{1} That code is wrong. Check the message and try again. :count try left.|[2,*] That code is wrong. Check the message and try again. :count tries left.',
                    $left,
                    ['count' => $left]
                ));
        }

        // the fifth wrong code: the code is used up, the member is told when to ask for a new one
        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $wrong])
            ->assertStatus(429)
            ->assertJsonPath('tries_left', 0)
            ->assertJsonPath('message', 'Too many tries. Send a new code in 10 minutes.');

        // even the right code is refused now (no sixth guess)
        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $code])->assertStatus(429);
        $this->assertNull($member->fresh()->phone_verified_at);
    }

    public function test_the_right_code_verifies_the_number(): void
    {
        Queue::fake();
        $member = $this->member();
        $this->actingAs($member)->postJson(route('phone.send'))->assertOk();
        $code = (string) $member->fresh()->phone_confirmation_code;

        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $code])
            ->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('message', __('Your phone number is verified.'));

        $fresh = $member->fresh();
        $this->assertNotNull($fresh->phone_verified_at);
        $this->assertNull($fresh->phone_confirmation_code);
    }

    public function test_an_expired_code_asks_for_a_new_one(): void
    {
        Queue::fake();
        $member = $this->member();
        $this->actingAs($member)->postJson(route('phone.send'))->assertOk();
        $code = (string) $member->fresh()->phone_confirmation_code;

        $this->travel(PhoneVerification::CODE_MINUTES * 60 + 1)->seconds();

        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('message', __('This code has expired. Send a new one.'))
            ->assertJsonPath('tries_left', 0);
        $this->assertNull($member->fresh()->phone_verified_at);
    }

    public function test_the_page_form_gets_the_same_answers_as_one_time_messages(): void
    {
        Queue::fake();
        $member = $this->member();

        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.send'))
            ->assertRedirect(route('user.edit'))
            ->assertSessionHas('status', __('Code sent. It works for :minutes minutes.', ['minutes' => PhoneVerification::CODE_MINUTES]))
            ->assertSessionHas('phone_verification.tries_left', PhoneVerification::MAX_TRIES);

        // the code is five digits: anything else is a field error, not a try
        $this->actingAs($member)->from(route('user.edit'))->post(route('phone.verify'), ['code' => '12ab'])
            ->assertRedirect(route('user.edit'))
            ->assertSessionHasErrors('code');
        $this->assertNull(Cache::get('phone-code.tries.'.$member->getKey()));
    }

    private function member(): User
    {
        return User::factory()->create(['phone' => '+33612345678']);
    }
}
