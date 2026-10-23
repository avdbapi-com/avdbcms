<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Support\CorsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowserSecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_web_response_emits_browser_security_headers(): void
    {
        config([
            'app.url' => 'https://avdbcms.example',
            'site.csp_directives' => "default-src 'self'; frame-ancestors 'none';",
            'site.csp_enabled' => true,
            'site.frame_options_header' => true,
            'site.hsts_enabled' => true,
            'site.hsts_max_age' => 63072000,
            'site.nosniff_header' => true,
            'site.xss_protection' => true,
        ]);

        $this->withServerVariables([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'avdbcms.example',
        ])
            ->get(route('pages.home'))
            ->assertOk()
            ->assertHeader('X-XSS-Protection', '1; mode=block')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer-when-downgrade')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains')
            ->assertHeader('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none';");
    }

    public function test_embed_response_keeps_frame_exception_and_embed_csp_allowlist(): void
    {
        config([
            'site.csp_directives' => "default-src 'self'; frame-ancestors 'none'; script-src 'self';",
            'site.csp_enabled' => true,
            'site.embed_allowed_domains' => "https://partner.example\n*.trusted.test",
            'site.frame_options_header' => true,
        ]);

        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create();

        $response = $this->get(route('video.embed', $video))->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertFalse($response->headers->has('X-Frame-Options'));
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString('frame-ancestors https://partner.example https://*.trusted.test', $csp);
        $this->assertStringNotContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_credentialed_wildcard_cors_is_clamped_to_the_app_origin(): void
    {
        config(['app.url' => 'https://avdbcms.example']);

        CorsConfig::applyFromSettings([
            'cors_allowed_origins' => '*',
            'cors_supports_credentials' => '1',
        ]);

        $this->assertSame(['https://avdbcms.example'], config('cors.allowed_origins'));

        $attackerResponse = $this->withHeader('Origin', 'https://attacker.example')
            ->getJson(route('videos.index'))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://avdbcms.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $this->assertNotSame(
            'https://attacker.example',
            $attackerResponse->headers->get('Access-Control-Allow-Origin')
        );

        $this->withHeader('Origin', 'https://avdbcms.example')
            ->getJson(route('videos.index'))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://avdbcms.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_wildcard_cors_can_be_public_only_when_credentials_are_disabled(): void
    {
        CorsConfig::applyFromSettings([
            'cors_allowed_origins' => '*',
            'cors_supports_credentials' => '0',
        ]);

        $this->withHeader('Origin', 'https://static-client.example')
            ->getJson(route('videos.index'))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_session_cookie_defaults_are_httponly_and_samesite_lax(): void
    {
        // Cacheable anonymous pages and the suite's array driver may both omit
        // Set-Cookie. These are the defaults Laravel applies whenever it emits
        // the real session cookie.
        $this->assertTrue((bool) config('session.http_only'));
        $this->assertSame('lax', strtolower((string) config('session.same_site')));
    }
}
