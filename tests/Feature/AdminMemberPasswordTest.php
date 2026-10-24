<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Members › edit member › "New password". The old and the new admin both post to admin.users.update. The User model
 * hashes every password it is given, so the controller must pass on the typed password as it is: up to 1.2.1 it
 * hashed it first, the database got a hash of the hash, and nobody could sign in with the password the admin typed.
 * 1.2.2 needs it: its update gives members with a password others know a random one, and their @example.com
 * addresses get no reset e-mail, so the admin's New password is the way back in.
 */
class AdminMemberPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => 'none',
            'site.login_rate_limit' => 1000,
        ]);
    }

    public function test_a_password_the_admin_sets_signs_the_member_in(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['username' => 'pbergstrom', 'email' => 'user2@example.com']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $member), $this->form($member, ['password' => 'Fresh-start-2026']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.edit', $member));

        $stored = (string) DB::table('users')->where('id', $member->id)->value('password');
        $this->assertTrue(Hash::check('Fresh-start-2026', $stored), 'the stored hash is the hash of the typed password');
        $this->assertFalse(Hash::check('password', $stored));

        // The member signs in with it.
        $this->app['auth']->forgetGuards();
        $this->post(route('login.perform'), ['username' => 'pbergstrom', 'password' => 'Fresh-start-2026'])
            ->assertRedirect(route('user.index'));
        $this->assertAuthenticatedAs($member->fresh());
    }

    public function test_the_new_admin_saves_the_same_way(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['email' => 'member@example.org']);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $member), $this->form($member, ['password' => 'Another-one-77']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Another-one-77', (string) DB::table('users')->where('id', $member->id)->value('password')));
    }

    public function test_an_empty_password_keeps_the_old_one(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['email' => 'member@example.org']);
        $before = (string) DB::table('users')->where('id', $member->id)->value('password');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $member), $this->form($member, ['password' => '', 'description' => 'Changed']))
            ->assertSessionHasNoErrors();

        $this->assertSame($before, (string) DB::table('users')->where('id', $member->id)->value('password'));
        $this->assertSame('Changed', DB::table('users')->where('id', $member->id)->value('description'));
    }

    public function test_a_password_shorter_than_the_members_rule_is_refused(): void
    {
        config(['validation.user.password.min' => 6]);
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['email' => 'member@example.org']);
        $before = (string) DB::table('users')->where('id', $member->id)->value('password');

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $member))
            ->put(route('admin.users.update', $member), $this->form($member, ['password' => 'abc']))
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('admin.users.edit', $member));

        $this->assertSame($before, (string) DB::table('users')->where('id', $member->id)->value('password'));
    }

    /** @return array<string, mixed> the fields of the member form */
    private function form(User $member, array $changes): array
    {
        return array_replace([
            'username' => $member->username,
            'email' => $member->email,
            'description' => $member->description,
            'country' => $member->country,
        ], $changes);
    }
}
