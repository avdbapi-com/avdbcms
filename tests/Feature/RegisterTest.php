<?php

namespace Tests\Feature;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => 'none',
            'site.email_verification_required' => true,
            'site.max_register_attempts' => 1000,
        ]);

        // Keep the route limiter isolated from other feature classes running
        // in the same PHPUnit process with the array cache store.
        foreach (['127.0.0.1', 'register:127.0.0.1', 'register|127.0.0.1', md5('register127.0.0.1')] as $key) {
            RateLimiter::clear($key);
        }
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_register_view() :void
    {
        $this
            ->get(route('registration'))
            ->assertViewIs('auth.register');
    }

    public function test_register_success() :void
    {
        Notification::fake();

        $this
            ->post(route('registration.perform'), [
                'username' => 'test',
                'email' => 'test@test.fr',
                'password' => 'aaaaaaaa',
                'password_confirmation' => 'aaaaaaaa',
                'cgu' => true,
            ])
            ->assertRedirectToRoute('user.edit');

        $this->assertDatabaseCount('users', 1);

        $this->assertDatabaseHas('users', [
            'email' =>'test@test.fr',
        ]);

        Notification::assertSentTimes(VerifyEmail::class, 1);
    }
}
