<?php

namespace Tests\Feature;

use App\Support\OAuthProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'site.allow_social_login' => true,
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
            'services.google.redirect' => '',
            'services.facebook.client_id' => null,
            'services.facebook.client_secret' => null,
            'services.facebook.redirect' => '',
        ]);
    }

    public function test_only_real_oauth_providers_are_routable(): void
    {
        $this->assertSame(['google', 'facebook'], OAuthProviders::supported());

        $this->get('/oauth/connect/youtube')->assertNotFound();
        $this->get('/oauth/callback/paypal')->assertNotFound();
    }

    public function test_unconfigured_provider_returns_a_recoverable_login_error(): void
    {
        $this->from(route('login'))
            ->get(route('oauth.connect', ['service' => 'google']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'This social login provider is not configured.');
    }

    public function test_disabled_social_login_does_not_attempt_provider_bootstrap(): void
    {
        config([
            'site.allow_social_login' => false,
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
        ]);

        $this->get(route('oauth.connect', ['service' => 'google']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Social login is currently disabled.');
    }

    public function test_configured_provider_uses_the_current_callback_origin(): void
    {
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
        ]);

        $this->assertTrue(OAuthProviders::isAvailable('google'));

        OAuthProviders::configureRedirect('google');

        $this->assertSame(
            route('oauth.callback', ['service' => 'google']),
            config('services.google.redirect')
        );
    }

    public function test_explicit_provider_callback_is_preserved(): void
    {
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => 'https://accounts.example.test/callback',
        ]);

        OAuthProviders::configureRedirect('google');

        $this->assertSame(
            'https://accounts.example.test/callback',
            config('services.google.redirect')
        );
    }

    public function test_auth_forms_only_render_configured_social_providers(): void
    {
        config([
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('/oauth/connect/google', false)
            ->assertDontSee('/oauth/connect/facebook', false);

        $this->get(route('registration'))
            ->assertOk()
            ->assertSee('/oauth/connect/google', false)
            ->assertDontSee('/oauth/connect/facebook', false);
    }

    public function test_registration_disabled_hides_signup_link_and_blocks_form(): void
    {
        config(['site.registration_enabled' => false]);

        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('href="/register"', false)
            ->assertDontSee(__('Sign Up'));

        $this->get(route('registration'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Registration is currently disabled.');
    }
}
