<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SerialAttempts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * R1-085 (round 1 judge): password.confirm (change the password, "Sign out of all other devices", delete the account)
 * checked the current password on every request without a limit: a session somebody else got hold of could try
 * passwords until one fitted (15 wrong tries in 35 s, no 429) and then take the account over.
 *
 * Now the fifth wrong password within 10 minutes closes the check for 10 minutes, the right password included, per
 * account (the rule of the Tools > Updates password). An empty field is no try, the right password clears the count,
 * and the checks of one account run one at a time.
 */
class PasswordConfirmLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        SerialAttempts::$waitSeconds = SerialAttempts::WAIT_SECONDS;

        parent::tearDown();
    }

    public function test_the_fifth_wrong_password_closes_the_check_for_ten_minutes(): void
    {
        $member = User::factory()->create();
        $token = $member->remember_token;

        for ($try = 1; $try <= 4; $try++) {
            $this->signOutOthers($member, 'guess-'.$try)
                ->assertStatus(422)
                ->assertJsonPath('errors.current_password.0', 'That password is wrong. Try again.');
        }

        $this->signOutOthers($member, 'guess-5')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '600')
            ->assertJsonPath('errors.current_password.0', 'Too many wrong passwords. Try again in 10 minutes.');

        // Closed: the right password does not open it, and the action does not run.
        $this->signOutOthers($member, 'password')->assertStatus(429)
            ->assertJsonPath('message', 'Too many wrong passwords. Try again in 10 minutes.');
        $this->assertSame($token, $member->fresh()->remember_token, 'sign-out-others must not run while the check is closed');

        $this->travel(9)->minutes();
        $this->signOutOthers($member, 'password')->assertStatus(429)
            ->assertJsonPath('message', 'Too many wrong passwords. Try again in 1 minute.');

        $this->travel(61)->seconds();
        $this->signOutOthers($member, 'password')->assertRedirect();
        $this->assertNotSame($token, $member->fresh()->remember_token, 'the right password works again after 10 minutes');
    }

    public function test_the_page_form_shows_the_wait_under_the_password_field(): void
    {
        $member = User::factory()->create();
        $password = $member->password;

        for ($try = 1; $try <= 5; $try++) {
            $response = $this->actingAs($member)->from(route('user.edit', ['section' => 'security']))
                ->post(route('user.update.password'), [
                    'current_password' => 'guess-'.$try,
                    'new_password' => 'Another-password-77',
                    'new_password_confirmation' => 'Another-password-77',
                ]);
        }

        $response->assertRedirect(route('user.edit', ['section' => 'security']))
            ->assertSessionHasErrors(['current_password' => 'Too many wrong passwords. Try again in 10 minutes.']);

        $this->actingAs($member)->from(route('user.edit', ['section' => 'security']))
            ->post(route('user.update.password'), [
                'current_password' => 'password',
                'new_password' => 'Another-password-77',
                'new_password_confirmation' => 'Another-password-77',
            ])
            ->assertSessionHasErrors(['current_password' => 'Too many wrong passwords. Try again in 10 minutes.']);

        $this->assertSame($password, $member->fresh()->password, 'the password must not change while the check is closed');
    }

    public function test_an_empty_field_is_no_try_and_the_right_password_clears_the_count(): void
    {
        $member = User::factory()->create();

        for ($try = 1; $try <= 4; $try++) {
            $this->signOutOthers($member, 'guess-'.$try)->assertStatus(422);
        }
        for ($try = 1; $try <= 3; $try++) {
            $this->signOutOthers($member, '')->assertStatus(422)->assertJsonPath('message', 'Enter your password.');
        }

        $this->signOutOthers($member, 'password')->assertRedirect();

        // The count starts again: four more wrong passwords are still answered "wrong", not "too many".
        for ($try = 1; $try <= 4; $try++) {
            $this->signOutOthers($member, 'again-'.$try)->assertStatus(422);
        }
    }

    public function test_one_members_wrong_passwords_never_close_another_members_check(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        for ($try = 1; $try <= 5; $try++) {
            $this->signOutOthers($member, 'guess-'.$try);
        }

        $this->signOutOthers($member, 'password')->assertStatus(429);
        $this->signOutOthers($other, 'password')->assertRedirect();
    }

    public function test_guesses_sent_side_by_side_wait_for_their_turn(): void
    {
        $member = User::factory()->create();
        SerialAttempts::$waitSeconds = 1;

        // Another request of the same account is checking a password right now (it holds the account's turn).
        $lock = Cache::lock('serial-attempts:password-confirm:'.$member->getKey(), 10);
        $this->assertTrue($lock->get());

        try {
            // This one does not get its turn: it is refused without comparing anything, the right password included.
            $this->signOutOthers($member, 'password')->assertStatus(429);
        } finally {
            $lock->release();
        }

        // Another account is never held up by it.
        $this->assertTrue(Cache::lock('serial-attempts:password-confirm:'.$member->getKey(), 10)->get());
        $this->signOutOthers(User::factory()->create(), 'password')->assertRedirect();
    }

    public function test_a_guest_and_the_view_only_demo_account_cannot_use_the_check(): void
    {
        $this->postJson(route('user.devices.sign-out-others'), ['current_password' => 'password'])->assertUnauthorized();

        $demo = User::factory()->create();
        $demo->forceFill(['is_view_only' => true])->save();
        $token = $demo->remember_token;

        $response = $this->actingAs($demo)->postJson(route('user.devices.sign-out-others'), ['current_password' => 'password']);
        $this->assertContains($response->getStatusCode(), [403, 422, 423], 'a view-only demo account changes nothing');
        $this->assertSame($token, $demo->fresh()->remember_token);
    }

    private function signOutOthers(User $member, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($member)->postJson(route('user.devices.sign-out-others'), ['current_password' => $password]);
    }
}
