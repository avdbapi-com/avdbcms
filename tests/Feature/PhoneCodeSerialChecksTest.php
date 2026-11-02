<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SerialAttempts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * R1-164 (round 1 judge): the phone code's tries were read, compared and written without any guard (a read-compare-write
 * of a cache value), and POST /phone/verify had no route limit: requests sent side by side could each read "0 tries"
 * and make far more than 5 guesses for one code. Now the checks of one member run one at a time under a lock shared by
 * the controller and MustVerifyPhone::checkPhoneCode(), and the route allows 10 checks a minute per member.
 * (The real side-by-side proof ran parallel PHP processes on a test copy of the site.)
 */
class PhoneCodeSerialChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.brevo.api_key' => 'xkeysib-test-0123456789']);
        Cache::flush();
        Queue::fake();
        SerialAttempts::$waitSeconds = 1;
    }

    protected function tearDown(): void
    {
        SerialAttempts::$waitSeconds = SerialAttempts::WAIT_SECONDS;

        parent::tearDown();
    }

    public function test_a_check_that_does_not_get_its_turn_compares_nothing(): void
    {
        $member = $this->memberWithCode();
        $code = (string) $member->fresh()->phone_confirmation_code;

        // Another check of this member is running right now.
        $lock = Cache::lock('serial-attempts:'.$member->phoneCodeCheckKey(), 10);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $code])
                ->assertStatus(429)
                ->assertJsonPath('message', __('Too many tries. Wait a minute and try again.'));
            $this->assertNull($member->fresh()->phone_verified_at, 'the right code was not even compared');
            $this->assertSame(0, (int) Cache::get($member->phoneCodeTriesKey(), 0), 'no try was used');

            // The model's own check waits for the same turn.
            $this->assertSame('busy', $member->fresh()->checkPhoneCode($code));
            $this->assertSame(__('Too many tries. Wait a minute and try again.'), $member->fresh()->phoneCodeMessage('busy'));
        } finally {
            $lock->release();
        }

        // Its turn comes: the right code works.
        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $code])->assertOk()->assertJsonPath('verified', true);
        $this->assertNotNull($member->fresh()->phone_verified_at);
    }

    public function test_another_members_checks_never_wait_for_it(): void
    {
        $member = $this->memberWithCode();
        $other = $this->memberWithCode('+33612345679');
        $lock = Cache::lock('serial-attempts:'.$member->phoneCodeCheckKey(), 10);
        $this->assertTrue($lock->get());

        try {
            $this->actingAs($other)->postJson(route('phone.verify'), ['code' => (string) $other->fresh()->phone_confirmation_code])
                ->assertOk();
        } finally {
            $lock->release();
        }
    }

    public function test_the_verify_route_allows_ten_checks_a_minute_per_member(): void
    {
        $member = $this->memberWithCode();
        $code = (string) $member->fresh()->phone_confirmation_code;
        $wrong = $code === '00000' ? '11111' : '00000';

        $first = $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $wrong]);
        $first->assertStatus(422)->assertHeader('X-RateLimit-Limit', '10');

        for ($i = 2; $i <= 10; $i++) {
            $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $wrong]);
        }

        // The eleventh check within the minute is refused by the route itself.
        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $code])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertNull($member->fresh()->phone_verified_at);

        // A guest cannot check codes at all.
        $this->app['auth']->guard('web')->logout();
        $this->postJson(route('phone.verify'), ['code' => $code])->assertUnauthorized();
    }

    public function test_the_view_only_demo_account_verifies_nothing(): void
    {
        $demo = $this->memberWithCode('+33612345670');
        $demo->forceFill(['is_view_only' => true])->save();
        $code = (string) $demo->fresh()->phone_confirmation_code;

        $response = $this->actingAs($demo->fresh())->postJson(route('phone.verify'), ['code' => $code]);

        $this->assertNotSame(200, $response->getStatusCode(), 'a view-only demo account changes nothing');
        $this->assertNull($demo->fresh()->phone_verified_at);
    }

    public function test_five_wrong_codes_still_end_the_code_and_one_right_code_still_works(): void
    {
        $member = $this->memberWithCode();
        $code = (string) $member->fresh()->phone_confirmation_code;
        $wrong = $code === '00000' ? '11111' : '00000';

        for ($try = 1; $try <= 4; $try++) {
            $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $wrong])->assertStatus(422)->assertJsonPath('tries_left', 5 - $try);
        }
        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $wrong])->assertStatus(429)->assertJsonPath('tries_left', 0);
        $this->actingAs($member)->postJson(route('phone.verify'), ['code' => $code])->assertStatus(429);
        $this->assertNull($member->fresh()->phone_verified_at);
    }

    private function memberWithCode(string $phone = '+33612345678'): User
    {
        $member = User::factory()->create(['phone' => $phone]);
        $this->actingAs($member)->postJson(route('phone.send'))->assertOk();

        return $member->fresh();
    }
}
