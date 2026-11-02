<?php

namespace Tests\Feature\Member;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The stored password and Laravel's own rehash (app/Models/User.php). The User model hashes every value given
 * to `password`; Laravel's rehash hands over a fresh hash instead (the rehash on sign-in after the hashing cost
 * changed, hashing.rehash_on_login being on by default, and Auth::logoutOtherDevices()), which was hashed a second
 * time: the member could no longer sign in.
 */
class PasswordRehashTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_rehashed_at_sign_in_after_the_cost_was_raised_still_signs_the_member_in(): void
    {
        $member = User::factory()->create(['email' => 'rehash@example.test', 'password' => 'a-long-password-31']);
        $before = (string) DB::table('users')->where('id', $member->id)->value('password');
        $cost = (int) (password_get_info($before)['options']['cost'] ?? 0);
        $this->assertGreaterThan(0, $cost);

        // the owner raises the hashing cost (BCRYPT_ROUNDS): the next sign-in rehashes the password
        config(['hashing.bcrypt.rounds' => $cost + 1]);
        $this->app['hash']->forgetDrivers();
        $this->assertTrue(Hash::needsRehash($before));

        $this->assertTrue(Auth::attempt(['email' => 'rehash@example.test', 'password' => 'a-long-password-31']), 'the sign-in that rehashes');
        Auth::logout();

        $after = (string) DB::table('users')->where('id', $member->id)->value('password');
        $this->assertNotSame($before, $after, 'rehashed');
        $this->assertSame($cost + 1, (int) (password_get_info($after)['options']['cost'] ?? 0), 'with the new cost');
        $this->assertTrue(Hash::check('a-long-password-31', $after), 'the stored hash is the password, not a hash of a hash');
        $this->assertTrue(Auth::attempt(['email' => 'rehash@example.test', 'password' => 'a-long-password-31']), 'the next sign-in works');
    }

    public function test_laravels_sign_out_of_the_other_devices_keeps_the_password_working(): void
    {
        $member = User::factory()->create(['password' => 'another-long-password-7']);
        Auth::login($member);

        Auth::logoutOtherDevices('another-long-password-7');

        $stored = (string) DB::table('users')->where('id', $member->id)->value('password');
        $this->assertTrue(Hash::check('another-long-password-7', $stored));
        $this->assertSame($stored, $member->fresh()->getAuthPassword());
    }

    public function test_every_value_given_to_the_password_is_hashed_as_before(): void
    {
        $member = User::factory()->create(['password' => 'plain-text-password-1']);
        $stored = (string) DB::table('users')->where('id', $member->id)->value('password');
        $this->assertTrue(Hash::check('plain-text-password-1', $stored));
        $this->assertSame($stored, $member->fresh()->getAuthPassword(), 'every check reads the stored column');

        // a value that looks like a hash is hashed too (a shipped 1.2.1 seeder passed Hash::make('password123'): that
        // account must stay without a usable password)
        $member->forceFill(['password' => Hash::make('password123')])->save();
        $this->assertFalse(Hash::check('password123', (string) DB::table('users')->where('id', $member->id)->value('password')));
        $this->assertArrayNotHasKey('rehashed_password', $member->fresh()->getAttributes(), 'no such column is ever written');
    }
}
