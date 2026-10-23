<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.captcha_provider' => 'none',
            'site.api_rate_limit' => 1000,
            'site.login_rate_limit' => 1000,
        ]);

        foreach (['127.0.0.1', 'register:127.0.0.1', 'register|127.0.0.1', md5('register127.0.0.1')] as $key) {
            RateLimiter::clear($key);
        }
    }

    public function test_api_login_accepts_a_client_without_a_device_name(): void
    {
        $user = User::factory()->create([
            'email' => 'mobile-login@example.test',
            // User::password is an automatically hashing attribute; pass the
            // plain fixture password so the factory does not double-hash it.
            'password' => 'correct-password',
        ]);

        $this->postJson('/api/login', [
            'username' => $user->email,
            'password' => 'correct-password',
        ])
            ->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonPath('token', fn (mixed $token): bool => is_string($token) && $token !== '');
    }

    public function test_auth_payloads_reject_non_scalar_credentials(): void
    {
        $this->postJson('/api/login', [
            'username' => ['mobile-login@example.test'],
            'password' => ['correct-password'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['username', 'password']);

        $this->from(route('registration'))
            ->post(route('registration.perform'), [
                'username' => ['mobile-user'],
                'email' => ['mobile-user@example.test'],
                'password' => 'correct-password',
                'password_confirmation' => 'correct-password',
                'cgu' => true,
            ])
            ->assertRedirect(route('registration'))
            ->assertSessionHasErrors(['username', 'email']);
    }
}
