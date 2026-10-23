<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\CorsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AdminCorsSettingsSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_wildcard_credentialed_cors_is_saved_but_runtime_clamped(): void
    {
        config(['app.url' => 'https://avdbcms.example']);

        $admin = User::factory()->admin()->create();
        $this->seedStaleCorsCache();

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'api'), $this->apiPayload([
                'cors_allowed_origins' => '*',
                'cors_supports_credentials' => '1',
            ]))
            ->assertRedirect(route('admin.settings.index', ['tab' => 'api']));

        $this->assertDatabaseHas('settings', [
            'key' => 'cors_allowed_origins',
            'value' => '*',
            'group' => 'api',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'cors_supports_credentials',
            'value' => '1',
            'group' => 'api',
        ]);
        $this->assertCorsCacheCleared();

        CorsConfig::applyFromSettings(Setting::getGroup('api'));

        $this->assertSame(['https://avdbcms.example'], config('cors.allowed_origins'));
        $this->assertTrue(config('cors.supports_credentials'));

        $response = $this->withHeader('Origin', 'https://attacker.example')
            ->getJson(route('videos.index'))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://avdbcms.example')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $this->assertNotSame(
            'https://attacker.example',
            $response->headers->get('Access-Control-Allow-Origin')
        );
    }

    public function test_admin_can_allow_public_wildcard_cors_when_credentials_are_disabled(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'api'), $this->apiPayload([
                'cors_allowed_origins' => '*',
                'cors_supports_credentials' => '0',
            ]))
            ->assertRedirect(route('admin.settings.index', ['tab' => 'api']));

        $this->assertSame('*', Setting::get('cors_allowed_origins'));
        $this->assertSame('0', Setting::get('cors_supports_credentials'));

        CorsConfig::applyFromSettings(Setting::getGroup('api'));

        $this->assertSame(['*'], config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));

        $this->withHeader('Origin', 'https://static-client.example')
            ->getJson(route('videos.index'))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_non_admin_cannot_update_api_cors_settings(): void
    {
        Setting::set('cors_allowed_origins', 'https://avdbcms.example', 'api');
        Setting::set('cors_supports_credentials', '1', 'api');

        $this->actingAs(User::factory()->create())
            ->putJson(route('admin.settings.update.group', 'api'), $this->apiPayload([
                'cors_allowed_origins' => '*',
                'cors_supports_credentials' => '0',
            ]))
            ->assertNotFound();

        $this->assertSame('https://avdbcms.example', Setting::get('cors_allowed_origins'));
        $this->assertSame('1', Setting::get('cors_supports_credentials'));
    }

    private function apiPayload(array $overrides = []): array
    {
        return array_replace([
            'api_rate_limit' => '60',
            'login_rate_limit' => '5',
            'upload_rate_limit' => '10',
            'subscribe_rate_limit' => '5',
            'comment_rate_limit' => '15',
            'report_rate_limit' => '5',
            'search_rate_limit' => '30',
            'video_view_rate_limit' => '120',
            'cors_allowed_origins' => 'https://avdbcms.example',
            'cors_allowed_methods' => 'GET,POST,PUT,DELETE,OPTIONS',
            'embed_allowed_domains' => '',
            'cors_max_age' => '600',
            'cors_supports_credentials' => '1',
        ], $overrides);
    }

    private function seedStaleCorsCache(): void
    {
        Cache::forever('settings.api', ['cors_allowed_origins' => 'stale']);
        Cache::forever('settings.__all_grouped', ['api' => ['cors_allowed_origins' => 'stale']]);
        Cache::forever('setting.cors_allowed_origins', 'stale');
        Cache::forever('setting.cors_supports_credentials', 'stale');
    }

    private function assertCorsCacheCleared(): void
    {
        $this->assertFalse(Cache::has('settings.api'));
        $this->assertFalse(Cache::has('settings.__all_grouped'));
        $this->assertFalse(Cache::has('setting.cors_allowed_origins'));
        $this->assertFalse(Cache::has('setting.cors_supports_credentials'));
    }
}
