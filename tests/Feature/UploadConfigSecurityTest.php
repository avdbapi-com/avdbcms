<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadConfigSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_upload_config_uses_server_mode_by_default_without_provider_key(): void
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

        Sanctum::actingAs(User::factory()->create());

        $payload = $this->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->json();

        $this->assertSame('server', $payload['upload_mode']);
        $this->assertNull($payload['upload_url']);
        $this->assertArrayNotHasKey('apikey', $payload);
        $this->assertSame('15', $payload['cid']);
        $this->assertSame('0', $payload['mycid']);
        $this->assertSame('18', $payload['fid']);
        $this->assertSame(route('videos.upload18.chunk', [], false), $payload['server_chunk_url']);
        $this->assertSame(route('videos.upload18.status', [], false), $payload['status_url']);
        $this->assertTrue($payload['allow_server_fallback']);
        $this->assertFalse($payload['direct_only']);
    }

    public function test_regular_upload_config_keeps_server_mode_when_fallback_is_explicitly_enabled(): void
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

        Sanctum::actingAs(User::factory()->create());

        $payload = $this->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->json();

        $this->assertSame('server', $payload['upload_mode']);
        $this->assertNull($payload['upload_url']);
        $this->assertArrayNotHasKey('apikey', $payload);
        $this->assertSame('15', $payload['cid']);
        $this->assertSame('0', $payload['mycid']);
        $this->assertSame('18', $payload['fid']);
        $this->assertSame(route('videos.upload18.chunk', [], false), $payload['server_chunk_url']);
        $this->assertSame(route('videos.upload18.status', [], false), $payload['status_url']);
        $this->assertTrue($payload['allow_server_fallback']);
        $this->assertFalse($payload['direct_only']);
    }

    public function test_admin_upload_config_keeps_direct_upload_when_server_fallback_is_disabled(): void
    {
        config([
            'services.upload18.api_key' => 'secret-upload18-key',
            'services.upload18.api_url' => 'https://upload18.example/api',
            'services.upload18.allow_server_fallback' => false,
            'site.allow_uploads' => true,
        ]);

        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));

        $payload = $this->getJson(route('videos.upload18.config'))
            ->assertOk()
            ->json();

        $this->assertSame('direct', $payload['upload_mode']);
        $this->assertSame('https://upload18.example/api/upload', $payload['upload_url']);
        $this->assertSame('secret-upload18-key', $payload['apikey']);
        $this->assertNull($payload['server_chunk_url']);
        $this->assertFalse($payload['allow_server_fallback']);
        $this->assertTrue($payload['direct_only']);
    }
}
