<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Account\VerifyPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The phone code of Account settings and of the Verify form (member design 6.17, 9.3): ONE code state, kept by
 * App\Models\Traits\MustVerifyPhone under the keys App\Http\Controllers\Auth\PhoneVerification reads. The
 * code a Profile save sends verifies through the Verify form, 5 wrong codes end it, and "Send a new code" answers
 * the hourly limit of codes in words.
 */
class PhoneCodeAfterMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Cache::flush();
        config(['site.default_locale' => 'en', 'app.locale' => 'en', 'services.brevo.api_key' => 'xkeysib-test']);
        $this->withoutVite();
    }

    public function test_the_code_of_a_profile_save_verifies_through_the_verify_form(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'phone' => '06 12 34 56 78', 'code' => 'FR'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('info');
        $code = (string) $member->fresh()->phone_confirmation_code;
        $this->assertMatchesRegularExpression('/^\d{5}$/', $code);

        $this->actingAs($member->fresh())->from(route('user.edit'))->post(route('phone.verify'), ['code' => $code])
            ->assertRedirect(route('user.edit'))
            ->assertSessionHas('status', __('Your phone number is verified.'));
        $this->assertTrue($member->fresh()->hasVerifiedPhone());
    }

    public function test_five_wrong_codes_end_the_code_of_a_profile_save(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->put(route('user.update'), ['section' => 'profile', 'phone' => '06 12 34 56 78', 'code' => 'FR'])
            ->assertSessionHas('info');
        $code = (string) $member->fresh()->phone_confirmation_code;
        $wrong = $code === '00000' ? '11111' : '00000';

        for ($try = 1; $try <= 4; $try++) {
            $this->actingAs($member->fresh())->postJson(route('phone.verify'), ['code' => $wrong])->assertStatus(422)->assertJsonPath('tries_left', 5 - $try);
        }
        $this->actingAs($member->fresh())->postJson(route('phone.verify'), ['code' => $wrong])
            ->assertStatus(429)
            ->assertJsonPath('message', $member->fresh()->phoneCodeMessage('locked'));
        $this->actingAs($member->fresh())->postJson(route('phone.verify'), ['code' => $code])->assertStatus(429);
        $this->assertFalse($member->fresh()->hasVerifiedPhone());
    }

    public function test_send_a_new_code_answers_the_hourly_limit_in_words(): void
    {
        $member = User::factory()->create(['phone' => '+33612345678']);

        for ($code = 1; $code <= 5; $code++) {
            $this->actingAs($member->fresh())->postJson(route('phone.send'))->assertOk();
            $this->travel(61)->seconds();
        }
        $before = (string) $member->fresh()->phone_confirmation_code;

        $this->actingAs($member->fresh())->postJson(route('phone.send'))
            ->assertStatus(429)
            ->assertJsonPath('message', $member->fresh()->phoneCodeLimitMessage());
        $this->assertSame($before, (string) $member->fresh()->phone_confirmation_code, 'nothing was sent, the code before still works');
        Notification::assertSentToTimes($member, VerifyPhone::class, 5);
    }
}
