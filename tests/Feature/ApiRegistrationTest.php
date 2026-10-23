<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => 'none',
            'site.registration_enabled' => true,
            'site.api_registration' => true,
            'site.max_register_attempts' => 1000,
            'site.email_verification_required' => false,
            'site.api_rate_limit' => 1000,
        ]);

        // The in-process array limiter is shared across feature tests. Keep
        // this focused switch test independent of earlier web registrations.
        foreach (['127.0.0.1', 'register:127.0.0.1', 'register|127.0.0.1', md5('register127.0.0.1')] as $key) {
            RateLimiter::clear($key);
        }
    }

    public function test_mobile_api_can_register_without_creating_a_web_session(): void
    {
        $response = $this->postJson('/api/register', [
            'username' => 'mobile-signup',
            'email' => 'mobile-signup@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'cgu' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('username', 'mobile-signup')
            ->assertJsonPath('token', fn (mixed $token): bool => is_string($token) && $token !== '');

        $this->assertDatabaseHas('users', [
            'username' => 'mobile-signup',
            'email' => 'mobile-signup@example.test',
        ]);
        $this->assertGuest();
    }

    public function test_api_registration_honors_the_admin_switch(): void
    {
        config(['site.api_registration' => false]);

        $this->postJson('/api/register', [
            'username' => 'mobile-disabled',
            'email' => 'mobile-disabled@example.test',
            'password' => 'correct-password',
            'password_confirmation' => 'correct-password',
            'cgu' => true,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Registration is currently disabled.');

        $this->assertDatabaseMissing('users', [
            'email' => 'mobile-disabled@example.test',
        ]);
    }
}
