<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadConfigSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const MEMBER_TOKEN = 'u18t_member.short.lived';

    protected function setUp(): void
    {
        parent::setUp();

        // The direct upload's config asks Upload18 for an upload token (1.2.3): a test never reaches the real Upload18.
        Http::preventStrayRequests();
    }

    /**
     * Every member who may upload sends the file straight from the browser to Upload18 (owner, 2026-09-28: uploads
     * failed for members on the server flow; kept 2026-10-25: no VPS bandwidth is a hard requirement), so a regular
     * member gets the direct mode, not the server flow this test asserted before. Its security intent stays: the
     * provider key does not reach a member's browser. Since 1.2.3 "apikey" carries a short-lived upload token that the
     * server minted with the key (Authorization: Bearer) whenever Upload18 mints one (the only fallback, a restricted
     * Upload + Read key, is covered by Upload18BrowserTokenTest), and the server fallback stays off unless enabled.
     */
    public function test_regular_upload_config_uses_direct_mode_with_an_upload_token_never_the_provider_key(): void
    {
        config([
            'services.upload18.api_key' => 'secret-upload18-key',
            'services.upload18.api_url' => 'https://upload18.example/api',
            'services.upload18.default_cid' => '15',
            'services.upload18.default_mycid' => '0',
            'services.upload18.default_fid' => '18',
            'services.upload18.allow_server_fallback' => false,
            'site.allow_uploads' => true,
        ]);
        $this->fakeUploadToken();

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson(route('videos.upload18.config'))->assertOk();
        $payload = $response->json();

        $this->assertSame('direct', $payload['upload_mode']);
        $this->assertSame('https://upload18.example/api/upload', $payload['upload_url']);
        $this->assertSame(self::MEMBER_TOKEN, $payload['apikey']);
        $this->assertStringNotContainsString('secret-upload18-key', $response->getContent());
        $this->assertSame('15', $payload['cid']);
        $this->assertSame('0', $payload['mycid']);
        $this->assertSame('18', $payload['fid']);
        $this->assertNull($payload['server_chunk_url']);
        $this->assertSame(route('videos.upload18.status', [], false), $payload['status_url']);
        $this->assertFalse($payload['allow_server_fallback']);
        $this->assertTrue($payload['direct_only']);
        $this->assertTokenWasMintedServerSide();
    }

    /**
     * With the server fallback explicitly enabled, a regular member still uploads directly with an upload token (not
     * the provider key, when Upload18 mints one) and is also offered the server chunk route as the fallback.
     */
    public function test_regular_upload_config_offers_the_server_fallback_when_explicitly_enabled_never_the_provider_key(): void
    {
        config([
            'services.upload18.api_key' => 'secret-upload18-key',
            'services.upload18.api_url' => 'https://upload18.example/api',
            'services.upload18.default_cid' => '15',
            'services.upload18.default_mycid' => '0',
            'services.upload18.default_fid' => '18',
            'services.upload18.allow_server_fallback' => true,
            'site.allow_uploads' => true,
        ]);
        $this->fakeUploadToken();

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson(route('videos.upload18.config'))->assertOk();
        $payload = $response->json();

        $this->assertSame('direct', $payload['upload_mode']);
        $this->assertSame('https://upload18.example/api/upload', $payload['upload_url']);
        $this->assertSame(self::MEMBER_TOKEN, $payload['apikey']);
        $this->assertStringNotContainsString('secret-upload18-key', $response->getContent());
        $this->assertSame('15', $payload['cid']);
        $this->assertSame('0', $payload['mycid']);
        $this->assertSame('18', $payload['fid']);
        $this->assertSame(route('videos.upload18.chunk', [], false), $payload['server_chunk_url']);
        $this->assertSame(route('videos.upload18.status', [], false), $payload['status_url']);
        $this->assertTrue($payload['allow_server_fallback']);
        $this->assertFalse($payload['direct_only']);
        $this->assertTokenWasMintedServerSide();
    }

    public function test_admin_upload_config_keeps_direct_upload_when_server_fallback_is_disabled(): void
    {
        config([
            'services.upload18.api_key' => 'secret-upload18-key',
            'services.upload18.api_url' => 'https://upload18.example/api',
            'services.upload18.allow_server_fallback' => false,
            'site.allow_uploads' => true,
        ]);

        Http::fake([
            'upload18.example/api/uploadtoken' => Http::response([
                'status' => 'success',
                'upload_token' => 'u18t_short.lived.token',
                'upload_url' => 'https://upload18.example/api/upload',
            ]),
        ]);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $response = $this->getJson(route('videos.upload18.config'))->assertOk();
        $payload = $response->json();

        $this->assertSame('direct', $payload['upload_mode']);
        $this->assertSame('https://upload18.example/api/upload', $payload['upload_url']);
        // 1.2.3: the browser gets a short-lived upload token, never the site's key.
        $this->assertSame('u18t_short.lived.token', $payload['apikey']);
        $this->assertStringNotContainsString('secret-upload18-key', $response->getContent());
        Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Bearer secret-upload18-key'));
        $this->assertNull($payload['server_chunk_url']);
        $this->assertFalse($payload['allow_server_fallback']);
        $this->assertTrue($payload['direct_only']);
    }

    /** Upload18 answers the server's token request; nothing else may leave the test (preventStrayRequests). */
    private function fakeUploadToken(): void
    {
        Http::fake([
            'upload18.example/api/uploadtoken' => Http::response([
                'status' => 'success',
                'upload_token' => self::MEMBER_TOKEN,
                'upload_url' => 'https://upload18.example/api/upload',
            ]),
        ]);
    }

    /** The key stayed on the server: it went only in the Authorization header of the token request, never in its body. */
    private function assertTokenWasMintedServerSide(): void
    {
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://upload18.example/api/uploadtoken'
            && $request->hasHeader('Authorization', 'Bearer secret-upload18-key')
            && ! str_contains($request->body(), 'secret-upload18-key'));
    }
}
