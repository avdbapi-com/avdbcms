<?php

namespace Tests\Unit;

use App\Enums\VideoStatus;
use App\Services\AdaptiveCreatorSpotlightService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdaptiveCreatorSpotlightServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username');
            $table->string('slug')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('avatar')->nullable();
            $table->boolean('show_subscribers')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('banned_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
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

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscriber_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('subscribe_at')->useCurrent();
            $table->timestamp('read_at')->useCurrent();
        });

        config([
            'site.creator_spotlight_enabled' => true,
            'site.creator_spotlight_limit' => 2,
            'site.creator_spotlight_lookback_days' => 14,
        ]);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('videos');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    public function test_settings_defaults_are_centralized_from_service_constants(): void
    {
        $this->assertSame([
            'enabled' => '1',
            'limit' => AdaptiveCreatorSpotlightService::DEFAULT_LIMIT,
            'limit_min' => AdaptiveCreatorSpotlightService::MIN_LIMIT,
            'limit_max' => AdaptiveCreatorSpotlightService::MAX_LIMIT,
            'lookback_days' => AdaptiveCreatorSpotlightService::DEFAULT_LOOKBACK_DAYS,
            'lookback_days_min' => AdaptiveCreatorSpotlightService::MIN_LOOKBACK_DAYS,
            'lookback_days_max' => AdaptiveCreatorSpotlightService::MAX_LOOKBACK_DAYS,
        ], AdaptiveCreatorSpotlightService::settingsDefaults());
    }

    public function test_creators_are_empty_when_feature_is_disabled(): void
    {
        config(['site.creator_spotlight_enabled' => false]);
        $this->createCreator('Music Host');

        $this->assertTrue(app(AdaptiveCreatorSpotlightService::class)->creators()->isEmpty());
    }

    public function test_creators_use_recent_public_upload_counts_and_bounds(): void
    {
        $alphaId = $this->createCreator('Alpha');
        $betaId = $this->createCreator('Beta');
        $oldId = $this->createCreator('Old');
        $unverifiedId = $this->createCreator('Unverified', verified: false);

        $this->createVideos($alphaId, 3, now()->subDays(2));
        $this->createVideos($betaId, 2, now()->subDays(1));
        $this->createVideos($oldId, 5, now()->subDays(60));
        $this->createVideos($unverifiedId, 4, now()->subDay());
        $this->subscribe($alphaId, 2);
        $this->subscribe($betaId, 3);

        $creators = app(AdaptiveCreatorSpotlightService::class)->creators();

        $this->assertCount(2, $creators);
        $this->assertSame(['Alpha', 'Beta'], $creators->pluck('username')->all());
        $this->assertSame(3, (int) $creators->first()->creator_spotlight_videos_count);
        $this->assertSame(2, (int) $creators->first()->subscribers_count);
    }

    public function test_creators_are_filtered_by_current_locale_while_allowing_global_videos(): void
    {
        app()->setLocale('fr');
        config(['site.creator_spotlight_limit' => 2]);

        $frenchId = $this->createCreator('French Creator');
        $globalId = $this->createCreator('Global Creator');
        $englishId = $this->createCreator('English Creator');

        $this->createVideos($frenchId, 1, now()->subDay(), 'fr');
        $this->createVideos($globalId, 1, now()->subDay());
        $this->createVideos($englishId, 3, now()->subDay(), 'en');

        $creators = app(AdaptiveCreatorSpotlightService::class)->creators();

        $this->assertContains('French Creator', $creators->pluck('username')->all());
        $this->assertContains('Global Creator', $creators->pluck('username')->all());
        $this->assertNotContains('English Creator', $creators->pluck('username')->all());
    }

    public function test_creators_backfill_with_active_users_when_not_enough_recent_uploaders(): void
    {
        config(['site.creator_spotlight_limit' => 3]);

        $activeUploaderId = $this->createCreator('Active Uploader');
        $quietOneId = $this->createCreator('Quiet One');
        $quietTwoId = $this->createCreator('Quiet Two');

        $this->createVideos($activeUploaderId, 1, now()->subDay());
        $this->subscribe($quietOneId, 2);
        $this->subscribe($activeUploaderId, 1);

        $creators = app(AdaptiveCreatorSpotlightService::class)->creators();

        $this->assertCount(3, $creators);
        $this->assertSame('Active Uploader', $creators->first()->username);
        $this->assertContains('Quiet One', $creators->pluck('username')->all());
        $this->assertContains('Quiet Two', $creators->pluck('username')->all());
        $this->assertSame(0, (int) $creators->firstWhere('username', 'Quiet One')->creator_spotlight_videos_count);
        $this->assertSame(2, (int) $creators->firstWhere('username', 'Quiet One')->subscribers_count);
    }

    private function createCreator(string $name, bool $verified = true): int
    {
        return (int) DB::table('users')->insertGetId([
            'username' => $name,
            'slug' => str($name)->slug()->toString(),
            'email' => str($name)->slug() . '@example.test',
            'password' => 'password',
            'avatar' => null,
            'show_subscribers' => true,
            'email_verified_at' => $verified ? now() : null,
            'banned_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function createVideos(int $userId, int $count, mixed $publishedAt, ?string $language = null): void
    {
        foreach (range(1, $count) as $index) {
            DB::table('videos')->insert([
                'user_id' => $userId,
                'status' => VideoStatus::PUBLIC->value,
                'uploaded_at' => $publishedAt,
                'published_at' => $publishedAt,
                'scheduled_at' => null,
                'language' => $language,
                'deleted_at' => null,
                'created_at' => $publishedAt,
                'updated_at' => $publishedAt,
            ]);
        }
    }

    private function subscribe(int $creatorId, int $count): void
    {
        foreach (range(1, $count) as $index) {
            $subscriberId = ($creatorId * 1000) + $index;

            DB::table('users')->insert([
                'id' => $subscriberId,
                'username' => 'Subscriber ' . $creatorId . ' ' . $index,
                'slug' => 'subscriber-' . $creatorId . '-' . $index,
                'email' => 'subscriber-' . $creatorId . '-' . $index . '@example.test',
                'password' => 'password',
                'avatar' => null,
                'show_subscribers' => true,
                'email_verified_at' => now(),
                'banned_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ]);

            DB::table('subscriptions')->insert([
                'subscriber_id' => $subscriberId,
                'user_id' => $creatorId,
                'subscribe_at' => now(),
                'read_at' => now(),
            ]);
        }
    }
}
