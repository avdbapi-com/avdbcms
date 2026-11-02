<?php

namespace Tests\Feature;

use App\Support\OAuthProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Round 3 review SB-RV-05 (orchestrator SB-X2): the address Google and Facebook send the member back to was always the
 * clean APP_URL/oauth/callback/<provider> (config/services.php), which a server without rewrite rules (the fleet's nodes
 * 2/4/7 layout) answers with its own 404 page. It is now the site's own address of the callback: APP_URL with the
 * folder of a site installed in one, "/index.php" after it where the site's links carry it. A site with clean links
 * sends exactly what it sent before; an address the owner sets (GOOGLE_REDIRECT_URI / FACEBOOK_REDIRECT_URI) is kept.
 */
class OAuthCallbackAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // the callback as the package's config/services.php gives it (no GOOGLE_REDIRECT_URI / FACEBOOK_REDIRECT_URI)
        $services = require config_path('services.php');
        config([
            'site.allow_social_login' => true,
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect' => $services['google']['redirect'],
            'services.facebook.client_id' => 'client-id',
            'services.facebook.client_secret' => 'client-secret',
            'services.facebook.redirect' => $services['facebook']['redirect'],
        ]);
    }

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        app('url')->formatPathUsing(fn ($path) => $path);

        parent::tearDown();
    }

    public function test_the_package_names_no_callback_of_its_own(): void
    {
        // config/services.php: only what the owner sets in .env (no APP_URL . '/oauth/callback/...' any more)
        $services = require config_path('services.php');
        $this->assertArrayHasKey('redirect', $services['google']);
        $this->assertArrayHasKey('redirect', $services['facebook']);
        $source = (string) file_get_contents(config_path('services.php'));
        $this->assertStringContainsString("'redirect' => env('GOOGLE_REDIRECT_URI'),", $source);
        $this->assertStringContainsString("'redirect' => env('FACEBOOK_REDIRECT_URI'),", $source);
        $this->assertStringNotContainsString('/oauth/callback/', $source);
    }

    public function test_a_site_with_clean_links_sends_the_address_it_always_sent(): void
    {
        config(['app.url' => 'https://example.test']);
        $this->assertSame('https://example.test/oauth/callback/google', OAuthProviders::callbackUrl('google'));
        $this->assertSame('https://example.test/oauth/callback/facebook', OAuthProviders::callbackUrl('Facebook'));

        // APP_URL written with a slash at the end or with /index.php: the same address
        config(['app.url' => 'https://example.test/']);
        $this->assertSame('https://example.test/oauth/callback/google', OAuthProviders::callbackUrl('google'));
        config(['app.url' => 'https://example.test/index.php']);
        $this->assertSame('https://example.test/oauth/callback/google', OAuthProviders::callbackUrl('google'));
    }

    public function test_a_site_whose_links_carry_index_php_keeps_it_in_the_callback(): void
    {
        config(['app.url' => 'https://example.test']);
        $this->indexPhpLinks();

        $this->assertSame('https://example.test/index.php/oauth/callback/google', OAuthProviders::callbackUrl('google'));

        OAuthProviders::configureRedirect('google');
        $this->assertSame('https://example.test/index.php/oauth/callback/google', config('services.google.redirect'));
    }

    public function test_a_site_in_a_folder_keeps_the_folder_and_index_php_in_the_callback(): void
    {
        config(['app.url' => 'https://example.test/site']);
        URL::forceRootUrl('https://example.test/site');
        $this->assertSame('https://example.test/site/oauth/callback/google', OAuthProviders::callbackUrl('google'));

        $this->indexPhpLinks();
        $this->assertSame('https://example.test/site/index.php/oauth/callback/facebook', OAuthProviders::callbackUrl('facebook'));
    }

    public function test_a_visitor_who_came_through_index_php_gets_the_sites_own_callback(): void
    {
        // An Apache site with clean links; this visitor opened /index.php/login (an old bookmark), so this request's
        // links carry /index.php. The callback stays the one the provider's settings list: the clean one.
        $saved = [$_SERVER['SERVER_SOFTWARE'] ?? null, $_SERVER['AVDBCLIP_USED_INDEX_PHP'] ?? null];
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.58 (Ubuntu)';
        $_SERVER['AVDBCLIP_USED_INDEX_PHP'] = true;
        \App\Support\Environment::reset();

        try {
            config(['app.url' => 'https://example.test', 'app.clean_urls' => 'auto', 'app.clean_url_reason' => 'via_index_php', 'app.clean_url_fallback' => true]);
            $this->assertTrue(index_php_links(), 'this request\'s links carry /index.php');
            $this->assertSame('https://example.test/oauth/callback/google', OAuthProviders::callbackUrl('google'));
        } finally {
            foreach (['SERVER_SOFTWARE', 'AVDBCLIP_USED_INDEX_PHP'] as $i => $key) {
                if ($saved[$i] === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $saved[$i];
                }
            }
            \App\Support\Environment::reset();
        }
    }

    public function test_an_address_the_owner_set_is_kept(): void
    {
        config(['app.url' => 'https://example.test', 'services.google.redirect' => 'https://example.test/oauth/callback/google']);
        $this->indexPhpLinks();

        OAuthProviders::configureRedirect('google');
        $this->assertSame('https://example.test/oauth/callback/google', config('services.google.redirect'));
    }

    public function test_the_provider_is_sent_the_sites_own_callback(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->indexPhpLinks();

        // (a whole address: the test client would build the path with the generator, /index.php included, which
        // public/index.php takes off before Laravel sees it)
        $location = (string) $this->get('http://localhost/oauth/connect/google')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('http://localhost/index.php/oauth/callback/google', $query['redirect_uri'] ?? null);
    }

    /**
     * The site's links carry /index.php (APP_CLEAN_URLS=false; on the fleet's nodes 2/4/7 the site's own check says so),
     * as EnvironmentServiceProvider::applyCleanUrlFallback() applies it.
     */
    private function indexPhpLinks(): void
    {
        config(['app.clean_urls' => 'off', 'app.clean_url_reason' => 'setting_off', 'app.clean_url_fallback' => true]);
        app('url')->formatPathUsing(function ($path) {
            if ($path === '' || $path === '/') {
                return '/index.php';
            }

            return str_starts_with($path, '/index.php') ? $path : '/index.php'.(str_starts_with($path, '/') ? $path : '/'.$path);
        });
    }
}
