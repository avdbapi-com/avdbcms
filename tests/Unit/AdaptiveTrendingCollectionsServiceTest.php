<?php

namespace Tests\Unit;

use App\Enums\VideoStatus;
use App\Services\AdaptiveTrendingCollectionsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdaptiveTrendingCollectionsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('views');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('categories');

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('background')->nullable();
            $table->timestamps();
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable();
            $table->unsignedInteger('avdb_id')->nullable();
            $table->unsignedInteger('xvid_id')->nullable();
            $table->unsignedTinyInteger('status')->default(VideoStatus::PUBLIC->value);
            $table->string('avdb_actor')->nullable();
            $table->dateTime('uploaded_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('language', 8)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('views', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('video_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45);
            $table->timestamp('view_at')->useCurrent();
            $table->softDeletes();
        });

        config([
            'site.trending_collections_enabled' => true,
            'site.trending_collections_limit' => 2,
            'site.trending_collections_lookback_days' => 14,
        ]);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        Schema::dropIfExists('views');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('categories');

        parent::tearDown();
    }

    public function test_settings_defaults_are_centralized_from_service_constants(): void
    {
        $this->assertSame([
            'enabled' => '1',
            'limit' => AdaptiveTrendingCollectionsService::DEFAULT_LIMIT,
            'limit_min' => AdaptiveTrendingCollectionsService::MIN_LIMIT,
            'limit_max' => AdaptiveTrendingCollectionsService::MAX_LIMIT,
            'lookback_days' => AdaptiveTrendingCollectionsService::DEFAULT_LOOKBACK_DAYS,
            'lookback_days_min' => AdaptiveTrendingCollectionsService::MIN_LOOKBACK_DAYS,
            'lookback_days_max' => AdaptiveTrendingCollectionsService::MAX_LOOKBACK_DAYS,
        ], AdaptiveTrendingCollectionsService::settingsDefaults());
    }

    public function test_collections_are_empty_when_feature_is_disabled(): void
    {
        config(['site.trending_collections_enabled' => false]);

        $categoryId = $this->createCategory('Music');
        $videoId = $this->createVideo($categoryId, now()->subDay());
        $this->createViews($videoId, 3, now()->subHour());

        $this->assertTrue(app(AdaptiveTrendingCollectionsService::class)->collections()->isEmpty());
    }

    public function test_collections_use_recent_actor_view_counts_and_limit(): void
    {
        $actionId = $this->createCategory('Action');
        $musicId = $this->createCategory('Music');
        $oldId = $this->createCategory('Old');
        $privateId = $this->createCategory('Private');

        $actionFirstVideoId = $this->createVideo($actionId, now()->subDays(2), actor: 'Mio Futaba');
        $actionSecondVideoId = $this->createVideo($actionId, now()->subDay(), actor: 'Mio Futaba');
        $musicVideoId = $this->createVideo($musicId, now()->subDay(), actor: 'Yua Mikami');
        $oldVideoId = $this->createVideo($oldId, now()->subDays(40), actor: 'Old Actor');
        $privateVideoId = $this->createVideo($privateId, now()->subDay(), status: VideoStatus::PRIVATE, actor: 'Private Actor');

        $this->createViews($actionFirstVideoId, 3, now()->subHours(3));
        $this->createViews($actionSecondVideoId, 2, now()->subHours(2));
        $this->createViews($musicVideoId, 3, now()->subHours(1));
        $this->createViews($oldVideoId, 10, now()->subDays(30));
        $this->createViews($privateVideoId, 9, now()->subHour());

        $collections = app(AdaptiveTrendingCollectionsService::class)->collections();

        $this->assertCount(2, $collections);
        $this->assertSame(['Mio Futaba', 'Yua Mikami'], $collections->pluck('actor_name')->all());
        $this->assertSame(5, (int) $collections->first()->trending_collections_views_count);
        $this->assertSame(2, (int) $collections->first()->trending_collections_videos_count);
    }

    public function test_collections_are_filtered_by_locale_while_allowing_global_videos(): void
    {
        app()->setLocale('fr');

        $frenchId = $this->createCategory('French');
        $globalId = $this->createCategory('Global');
        $englishId = $this->createCategory('English');

        $this->createViews($this->createVideo($frenchId, now()->subDay(), language: 'fr', actor: 'French Actor'), 2, now()->subHour());
        $this->createViews($this->createVideo($globalId, now()->subDay(), actor: 'Global Actor'), 1, now()->subHour());
        $this->createViews($this->createVideo($englishId, now()->subDay(), language: 'en', actor: 'English Actor'), 9, now()->subHour());

        $collections = app(AdaptiveTrendingCollectionsService::class)->collections();

        $this->assertContains('French Actor', $collections->pluck('actor_name')->all());
        $this->assertContains('Global Actor', $collections->pluck('actor_name')->all());
        $this->assertNotContains('English Actor', $collections->pluck('actor_name')->all());
    }

    public function test_invalid_setting_values_are_clamped_before_querying(): void
    {
        config([
            'site.trending_collections_limit' => AdaptiveTrendingCollectionsService::MAX_LIMIT + 100,
            'site.trending_collections_lookback_days' => 0,
        ]);

        foreach (range(1, AdaptiveTrendingCollectionsService::MAX_LIMIT + 1) as $index) {
            $categoryId = $this->createCategory('Category ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            $videoId = $this->createVideo($categoryId, now()->subHours($index), actor: 'Actor ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            $this->createViews($videoId, 1, now()->subHour());
        }

        $this->assertCount(AdaptiveTrendingCollectionsService::MAX_LIMIT, app(AdaptiveTrendingCollectionsService::class)->collections());
    }

    private function createCategory(string $title): int
    {
        return (int) DB::table('categories')->insertGetId([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'icon' => 'play',
            'background' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createVideo(int $categoryId, mixed $publishedAt, VideoStatus $status = VideoStatus::PUBLIC, ?string $language = null, ?string $actor = null): int
    {
        return (int) DB::table('videos')->insertGetId([
            'category_id' => $categoryId,
            'status' => $status->value,
            'avdb_actor' => $actor,
            'uploaded_at' => $publishedAt,
            'published_at' => $publishedAt,
            'scheduled_at' => null,
            'language' => $language,
            'deleted_at' => null,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);
    }

    private function createViews(int $videoId, int $count, mixed $viewedAt): void
    {
        foreach (range(1, $count) as $index) {
            DB::table('views')->insert([
                'video_id' => $videoId,
                'user_id' => null,
                'ip' => '127.0.0.' . $index,
                'view_at' => $viewedAt,
                'deleted_at' => null,
            ]);
        }
    }
}
