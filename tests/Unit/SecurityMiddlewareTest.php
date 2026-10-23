<?php

namespace Tests\Unit;

use App\Http\Middleware\Security;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class SecurityMiddlewareTest extends TestCase
{
    public function test_embed_routes_do_not_emit_frame_options_header(): void
    {
        config()->set('site.frame_options_header', true);

        $response = $this->handle('/video/demo/embed');

        $this->assertFalse($response->headers->has('X-Frame-Options'));
    }

    public function test_embed_routes_replace_global_frame_ancestors_with_embed_allowlist(): void
    {
        config()->set('site.csp_enabled', true);
        config()->set('site.csp_directives', "default-src 'self'; frame-ancestors 'none'; script-src 'self';");
        config()->set('site.embed_allowed_domains', "https://partner.example\n*.trusted.test");

        $response = $this->handle('/video/demo/embed');
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString('frame-ancestors https://partner.example https://*.trusted.test', $csp);
        $this->assertStringNotContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_non_embed_routes_keep_frame_options_header(): void
    {
        config()->set('site.frame_options_header', true);

        $response = $this->handle('/videos');

        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    private function handle(string $path): Response
    {
        $request = Request::create($path, 'GET');

        return (new Security())->handle($request, fn () => new Response('ok'));
    }
}
