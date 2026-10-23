<?php

namespace Tests\Feature;

use App\Enums\ReportReason;
use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Report;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RateLimitAbuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.limiter', 'array');
        Cache::flush();
        Notification::fake();
        Config::set('scout.driver', 'collection');
    }

    public function test_api_login_abuse_is_throttled_by_admin_configured_limit(): void
    {
        $this->configureRateLimits(['login_rate_limit' => 1]);

        $payload = [
            'username' => 'attacker@example.test',
            'password' => 'bad-password',
            'device_name' => 'abuse-probe',
        ];

        $this->postJson('/api/login', $payload)->assertUnauthorized();
        $this->postJson('/api/login', $payload)->assertTooManyRequests();
    }

    public function test_subscribe_abuse_is_throttled_by_admin_configured_limit(): void
    {
        $this->configureRateLimits(['subscribe_rate_limit' => 1]);

        $subscriber = User::factory()->create();
        $creator = User::factory()->create();

        $this->actingAs($subscriber)
            ->postJson(route('subscribe', $creator))
            ->assertNoContent();

        $this->actingAs($subscriber)
            ->postJson(route('subscribe', $creator))
            ->assertTooManyRequests();
    }

    public function test_comment_mutation_abuse_is_throttled_by_admin_configured_limit(): void
    {
        $this->configureRateLimits(['comment_rate_limit' => 1]);

        $commenter = User::factory()->create();
        $video = $this->publicVideo('Comment Rate Target');

        $this->actingAs($commenter)
            ->postJson(route('comments.store', $video->uuid), [
                'content' => 'First comment from the rate-limit abuse probe.',
            ])
            ->assertSuccessful();

        $this->actingAs($commenter)
            ->postJson(route('comments.store', $video->uuid), [
                'content' => 'Second comment should be throttled before storage.',
            ])
            ->assertTooManyRequests();
    }

    public function test_report_abuse_is_throttled_by_admin_configured_limit(): void
    {
        $this->configureRateLimits(['report_rate_limit' => 1]);

        $reporter = User::factory()->create();
        $video = $this->publicVideo('Report Rate Target');

        $this->actingAs($reporter)
            ->postJson(route('report'), [
                'reason' => ReportReason::MISINFORMATION->value,
                'type' => Video::class,
                'id' => $video->id,
                'comment' => 'First report from the rate-limit abuse probe.',
            ])
            ->assertNoContent();

        $this->actingAs($reporter)
            ->postJson(route('report'), [
                'reason' => ReportReason::MISINFORMATION->value,
                'type' => Video::class,
                'id' => $video->id,
                'comment' => 'Second report should be throttled before duplicate checks.',
            ])
            ->assertTooManyRequests();

        $this->assertSame(1, Report::query()->count());
    }

    public function test_public_search_abuse_is_throttled_by_admin_configured_limit(): void
    {
        $this->configureRateLimits(['search_rate_limit' => 1]);
        $client = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)]);

        $client->getJson('/api/search?q=probe')->assertOk();
        $client->getJson('/api/search?q=probe')->assertTooManyRequests();
    }

    public function test_public_search_throttle_returns_localized_json_message_and_retry_after(): void
    {
        $this->configureRateLimits(['search_rate_limit' => 1]);
        $client = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)]);

        $client->getJson('/api/search?q=throttle-message-probe')->assertOk();

        $response = $client->getJson('/api/search?q=throttle-message-probe')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $retryAfter = (int) $response->headers->get('Retry-After');

        $this->assertGreaterThan(0, $retryAfter);
        $this->assertSame(__('Too many attempts !'), $response->json('message'));
        $this->assertNotSame('throttle.general', $response->json('message'));
    }

    public function test_custom_rate_middleware_allows_normal_response_objects(): void
    {
        $request = \Illuminate\Http\Request::create('/_test/rate-response', 'GET');
        $request->setRouteResolver(fn () => new \Illuminate\Routing\Route('GET', '/_test/rate-response', [
            'as' => 'test.rate.response',
        ]));

        $response = (new \App\Http\Middleware\RateLimit())->handle($request, fn () => response('ok'), 2);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function test_video_view_abuse_is_throttled_by_admin_configured_limit(): void
    {
        $this->configureRateLimits(['video_view_rate_limit' => 1]);

        $video = $this->publicVideo('View Rate Target');

        $this->postJson(route('videos.view', $video->uuid))->assertNoContent();
        $this->postJson(route('videos.view', $video->uuid))->assertTooManyRequests();
    }

    public function test_admin_can_persist_endpoint_specific_rate_limit_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('admin.settings.update.group', 'api'), $this->apiSettingsPayload([
                'subscribe_rate_limit' => '7',
                'comment_rate_limit' => '11',
                'report_rate_limit' => '3',
                'search_rate_limit' => '19',
                'video_view_rate_limit' => '240',
            ]))
            ->assertRedirect(route('admin.settings.index', ['tab' => 'api']));

        $this->assertSame('7', Setting::get('subscribe_rate_limit'));
        $this->assertSame('11', Setting::get('comment_rate_limit'));
        $this->assertSame('3', Setting::get('report_rate_limit'));
        $this->assertSame('19', Setting::get('search_rate_limit'));
        $this->assertSame('240', Setting::get('video_view_rate_limit'));
    }

    public function test_admin_api_settings_form_renders_endpoint_specific_rate_limit_controls(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.settings.index', ['tab' => 'api']))
            ->assertOk()
            ->assertSee('name="subscribe_rate_limit"', false)
            ->assertSee('name="comment_rate_limit"', false)
            ->assertSee('name="report_rate_limit"', false)
            ->assertSee('name="search_rate_limit"', false)
            ->assertSee('name="video_view_rate_limit"', false);
    }

    private function configureRateLimits(array $overrides = []): void
    {
        Config::set('site.api_rate_limit', 1000);
        Config::set('site.login_rate_limit', 1000);
        Config::set('site.subscribe_rate_limit', 1000);
        Config::set('site.comment_rate_limit', 1000);
        Config::set('site.report_rate_limit', 1000);
        Config::set('site.search_rate_limit', 1000);
        Config::set('site.video_view_rate_limit', 1000);

        foreach ($overrides as $key => $value) {
            Config::set("site.{$key}", $value);
        }
    }

    private function apiSettingsPayload(array $overrides = []): array
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

    private function publicVideo(string $title): Video
    {
        $category = Category::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'icon' => 'play',
            'in_menu' => true,
            'position' => 1,
        ]);

        return Video::factory()
            ->for(User::factory()->create(), 'user')
            ->for($category)
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                'published_at' => now()->subMinute(),
                'uploaded_at' => now()->subMinute(),
                'language' => null,
                'allow_comments' => true,
                'views' => 0,
            ]);
    }
}
