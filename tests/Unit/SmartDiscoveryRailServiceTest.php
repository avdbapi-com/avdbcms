<?php

namespace Tests\Unit;

use App\Enums\VideoStatus;
use App\Services\SmartDiscoveryRailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmartDiscoveryRailServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tag_translations');
        Schema::dropIfExists('video_tags');
        Schema::dropIfExists('tags');
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
            $table->dateTime('uploaded_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('language', 8)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->unsignedInteger('usage_count')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('video_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['video_id', 'tag_id']);
            $table->index('tag_id');
        });

        Schema::create('tag_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 150);
            $table->timestamps();
            $table->unique(['tag_id', 'locale']);
            $table->index(['locale', 'name']);
        });

        config([
            'site.smart_discovery_rail_enabled' => true,
            'site.smart_discovery_rail_limit' => 8,
            'site.smart_discovery_rail_lookback_days' => 14,
        ]);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        Schema::dropIfExists('tag_translations');
        Schema::dropIfExists('video_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('categories');

        parent::tearDown();
    }

    public function test_tags_are_empty_when_discovery_rail_is_disabled(): void
    {
        config(['site.smart_discovery_rail_enabled' => false]);
        $categoryId = $this->createCategory('Music');
        $tagId = $this->createTag('Popular');
        $this->createVideo($categoryId, now()->subDay(), $tagId);

        $this->assertTrue(app(SmartDiscoveryRailService::class)->tags()->isEmpty());
    }

    public function test_tags_use_public_video_counts_and_limit(): void
    {
        config(['site.smart_discovery_rail_limit' => 2]);

        $categoryId = $this->createCategory('Action');
        $actionId = $this->createTag('Action');
        $musicId = $this->createTag('Music');
        $newsId = $this->createTag('News');

        $this->createVideos($categoryId, $actionId, 3);
        $this->createVideos($categoryId, $musicId, 2);
        $this->createVideos($categoryId, $newsId, 1);
        $this->createVideo($categoryId, now()->subDays(30), $newsId);

        $tags = app(SmartDiscoveryRailService::class)->tags();

        $this->assertCount(2, $tags);
        $this->assertSame(['Action', 'Music'], $tags->pluck('name')->all());
        $this->assertSame(3, (int) $tags->first()->smart_discovery_videos_count);
    }

    public function test_tags_keep_imported_videos_global_without_bypassing_public_filters(): void
    {
        app()->setLocale('fr');
        config(['site.smart_discovery_rail_limit' => 10]);

        $categoryId = $this->createCategory('Locale');
        $importedId = $this->createTag('Imported');
        $frenchId = $this->createTag('French');
        $globalId = $this->createTag('Global');
        $englishId = $this->createTag('English');
        $privateId = $this->createTag('Private');
        $deletedId = $this->createTag('Deleted');

        $this->createVideo($categoryId, now()->subDay(), $importedId, language: 'en', avdbId: 101);
        $this->createVideo($categoryId, now()->subDay(), $frenchId, language: 'fr');
        $this->createVideo($categoryId, now()->subDay(), $globalId);
        $this->createVideo($categoryId, now()->subDay(), $englishId, language: 'en');
        $this->createVideo($categoryId, now()->subDay(), $privateId, status: VideoStatus::PRIVATE, language: 'fr');
        $this->createVideo($categoryId, now()->subDay(), $deletedId, language: 'fr', deletedAt: now());

        $names = app(SmartDiscoveryRailService::class)->tags()->pluck('name')->all();

        $this->assertContains('Imported', $names);
        $this->assertContains('French', $names);
        $this->assertContains('Global', $names);
        $this->assertNotContains('English', $names);
        $this->assertNotContains('Private', $names);
        $this->assertNotContains('Deleted', $names);
    }

    public function test_invalid_setting_values_are_clamped_before_querying(): void
    {
        config([
            'site.smart_discovery_rail_limit' => SmartDiscoveryRailService::MAX_LIMIT + 100,
            'site.smart_discovery_rail_lookback_days' => 0,
        ]);

        $categoryId = $this->createCategory('Category');
        foreach (range(1, SmartDiscoveryRailService::MAX_LIMIT + 1) as $index) {
            $tagId = $this->createTag('Tag ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            $this->createVideo($categoryId, now()->subHours(12), $tagId);
        }

        $tags = app(SmartDiscoveryRailService::class)->tags();

        $this->assertCount(SmartDiscoveryRailService::MAX_LIMIT, $tags);
    }

    public function test_settings_defaults_are_centralized_from_service_constants(): void
    {
        $this->assertSame([
            'enabled' => '1',
            'limit' => SmartDiscoveryRailService::DEFAULT_LIMIT,
            'limit_min' => SmartDiscoveryRailService::MIN_LIMIT,
            'limit_max' => SmartDiscoveryRailService::MAX_LIMIT,
            'lookback_days' => SmartDiscoveryRailService::DEFAULT_LOOKBACK_DAYS,
            'lookback_days_min' => SmartDiscoveryRailService::MIN_LOOKBACK_DAYS,
            'lookback_days_max' => SmartDiscoveryRailService::MAX_LOOKBACK_DAYS,
        ], SmartDiscoveryRailService::settingsDefaults());
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

    private function createTag(string $name): int
    {
        return (int) DB::table('tags')->insertGetId([
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'usage_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createVideos(int $categoryId, int $tagId, int $count): void
    {
        foreach (range(1, $count) as $index) {
            $this->createVideo($categoryId, now()->subHours($index), $tagId);
        }
    }

    private function createVideo(
        int $categoryId,
        mixed $publishedAt,
        int $tagId,
        VideoStatus $status = VideoStatus::PUBLIC,
        ?string $language = null,
        ?int $avdbId = null,
        ?int $xvidId = null,
        mixed $deletedAt = null,
    ): void
    {
        $videoId = (int) DB::table('videos')->insertGetId([
            'category_id' => $categoryId,
            'avdb_id' => $avdbId,
            'xvid_id' => $xvidId,
            'status' => $status->value,
            'uploaded_at' => $publishedAt,
            'published_at' => $publishedAt,
            'scheduled_at' => null,
            'language' => $language,
            'deleted_at' => $deletedAt,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);

        DB::table('video_tags')->insert([
            'video_id' => $videoId,
            'tag_id' => $tagId,
            'created_at' => $publishedAt,
            'updated_at' => $publishedAt,
        ]);
    }
}
