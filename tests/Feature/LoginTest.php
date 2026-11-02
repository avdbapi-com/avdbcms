<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
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

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_login_view() :void
    {
        $response = $this->get(route('login'));

        $response->assertViewIs('auth.login');
    }

    public function test_login_success() :void
    {
        $user = User::factory()->create([
            'email' => 'test@test.fr',
            'password' => 'password'
        ]);

        $response = $this->post(route('login.perform'), [
            'username' => $user->email,
            'password' => 'password'
        ]);

        $response->assertRedirect(route('user.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_failed() :void
    {
        $user = User::factory()->create([
            'email' => 'test@test.fr',
            'password' => Hash::make('password')
        ]);

        $response = $this->from(route('login'))->post(route('login.perform'), [
            'username' => $user->email,
            'password' => 'invalid password'
        ]);

        $response->assertRedirect(route('login'));
        // one neutral sentence for a wrong name and a wrong password (the sign-in card adds "Reset your password")
        $response->assertSessionHas('error', __('The username or password is wrong.'));
        $response->assertSessionHas('auth_failed', true);

        $this->assertTrue(session()->hasOldInput('username'));
        $this->assertGuest();
    }

    public function test_login_banned_user() :void
    {
        $user = User::factory()->create([
            'email' => 'test@test.fr',
            'password' => 'password',
            'banned_at' => now()
        ]);

        $lastLogin = $user->fresh()->last_login_at;

        $response = $this->from(route('login'))->post(route('login.perform'), [
            'username' => $user->email,
            'password' => 'password'
        ]);

        // auth-33: a suspended account is never signed in, not even for one request, and its "last sign-in" is not
        // written; the sign-in page says so in the visitor's language with a contact link, the typed name kept
        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', __('This account is suspended.'));
        $response->assertSessionHas('auth_banned', true);
        $this->assertTrue(session()->hasOldInput('username'));
        $this->assertGuest();
        $this->assertEquals($lastLogin, $user->fresh()->last_login_at);

        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('This account is suspended. If you think this is a mistake, contact us.')
            ->assertSee('href="'.e(locale_url(route('contact.show'))).'"', false);

        // a member suspended while signed in is signed out at the next page, with the same message
        $member = User::factory()->create();
        $this->actingAs($member);
        $member->forceFill(['banned_at' => now()])->save();
        $this->get(route('user.index'))->assertRedirect(route('login'))->assertSessionHas('auth_banned', true);
        $this->assertGuest();
    }
}
