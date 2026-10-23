<?php

namespace Tests\Feature\Api;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Thumbnail;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

class VideoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_videos_index(): void
    {
        $videos = Video::factory(10)
            ->withStatus(VideoStatus::PUBLIC)
            ->forUser()
            ->has(
                Thumbnail::factory()
                    ->generated()
                    ->count(3)
            )
            ->create()
            ->each(function($video) {
                Thumbnail::factory(3)
                    ->generated()
                    ->for($video)
                    ->create();
            });

        $response = $this->get(route('videos.index'));

        $response
            ->assertStatus(200)
            ->assertJson(fn (AssertableJson $json) =>
            $json
                ->has('data', $videos->count(), fn (AssertableJson $json) =>
                $json
                    ->whereType('id', 'integer')
                    ->whereType('uuid', 'string')
                    ->whereType('title', 'string')
                    ->whereType('short_title', 'string')
                    ->whereType('thumbnail', 'string')
                    ->whereType('formated_duration', 'string')
                    ->whereType('views', 'integer')
                    ->whereType('route', 'string')
                    ->whereType('published_at', 'string')
                    ->has('user', fn (AssertableJson $json) =>
                        $json->whereType('id', 'integer')
                            ->whereType('username', 'string')
                            ->whereType('slug', 'string')
                            ->whereType('avatar', 'string')
                            ->whereType('route', 'string')
                            ->whereType('show_subscribers', 'boolean')
                            ->whereType('created_at', 'string')
                    )
                )
                ->has('links')
                ->has('meta')
            );
    }

    public function test_videos_index_query_count_stays_bounded(): void
    {
        Video::factory(12)
            ->withStatus(VideoStatus::PUBLIC)
            ->forUser()
            ->has(
                Thumbnail::factory()
                    ->generated()
                    ->count(3)
            )
            ->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('videos.index'))->assertOk();

        $this->assertLessThanOrEqual(
            8,
            count(DB::getQueryLog()),
            'The public video feed should eager-load card relationships instead of issuing per-video queries.'
        );
    }

    public function test_player_bootstrap_does_not_block_on_upload18_metadata_lookup(): void
    {
        Http::fake();

        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'upload18_vid' => 'slow-test',
                'embed_code' => 'slow-test',
                'duration' => 0,
                'file' => null,
                'show_ad' => false,
            ]);

        $this->getJson(route('videos.player', ['video' => $video->uuid]))
            ->assertOk()
            ->assertJsonPath('is_upload18', true)
            ->assertJsonPath('embed_url', 'https://upload18.org/play/index/slow-test');

        Http::assertNothingSent();
    }

    public function test_videos_trend(): void
    {
        $videos = Video::factory(10)
            ->withStatus(VideoStatus::PUBLIC)
            ->forUser()
            ->has(
                Thumbnail::factory()
                    ->generated()
                    ->count(3)
            )
            ->create()
            ->each(function($video) {
                Thumbnail::factory(3)
                    ->generated()
                    ->for($video)
                    ->create();
            });

        $response = $this->get(route('videos.trend'));

        $response
            ->assertStatus(200)
            ->assertJson(fn (AssertableJson $json) =>
            $json
                ->has('data', $videos->count(), fn (AssertableJson $json) =>
                $json
                    ->whereType('id', 'integer')
                    ->whereType('uuid', 'string')
                    ->whereType('title', 'string')
                    ->whereType('short_title', 'string')
                    ->whereType('thumbnail', 'string')
                    ->whereType('formated_duration', 'string')
                    ->whereType('views', 'integer')
                    ->whereType('route', 'string')
                    ->whereType('published_at', 'string')
                    ->has('user', fn (AssertableJson $json) =>
                    $json->whereType('id', 'integer')
                        ->whereType('username', 'string')
                        ->whereType('slug', 'string')
                        ->whereType('avatar', 'string')
                        ->whereType('route', 'string')
                        ->whereType('show_subscribers', 'boolean')
                        ->whereType('created_at', 'string')
                    )
                )
                ->has('links')
                ->has('meta')
            );
    }

    public function test_videos_index_supports_homepage_most_viewed_source_and_limit(): void
    {
        $creator = User::factory()->create();

        Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Low Views Homepage Feed Video',
                'slug' => 'low-views-homepage-feed-video',
                'views' => 2,
                'published_at' => now()->subDays(2),
                'uploaded_at' => now()->subDays(2),
            ]);
        Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'High Views Homepage Feed Video',
                'slug' => 'high-views-homepage-feed-video',
                'views' => 50,
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
            ]);

        $this->get(route('videos.index', ['source' => 'most_viewed', 'per_page' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'High Views Homepage Feed Video');
    }

    public function test_videos_index_supports_homepage_most_commented_source(): void
    {
        $creator = User::factory()->create();
        $quiet = Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Quiet Homepage Feed Video',
                'slug' => 'quiet-homepage-feed-video',
                'published_at' => now()->subDays(2),
                'uploaded_at' => now()->subDays(2),
            ]);
        $busy = Video::factory()
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Busy Homepage Feed Video',
                'slug' => 'busy-homepage-feed-video',
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
            ]);

        Comment::factory()
            ->count(3)
            ->for($busy)
            ->for($creator)
            ->create();
        Comment::factory()
            ->for($quiet)
            ->for($creator)
            ->create();

        $this->get(route('videos.index', ['source' => 'most_commented', 'per_page' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Busy Homepage Feed Video');
    }

    public function test_videos_index_supports_homepage_category_source(): void
    {
        $creator = User::factory()->create();
        $selected = Category::query()->create([
            'title' => 'Homepage Selected Category',
            'slug' => 'homepage-selected-category',
            'icon' => 'film',
            'in_menu' => true,
            'position' => 1,
        ]);
        $other = Category::query()->create([
            'title' => 'Homepage Other Category',
            'slug' => 'homepage-other-category',
            'icon' => 'music',
            'in_menu' => true,
            'position' => 2,
        ]);

        Video::factory()
            ->for($selected)
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Selected Category Homepage Feed Video',
                'slug' => 'selected-category-homepage-feed-video',
            ]);
        Video::factory()
            ->for($other)
            ->for($creator, 'user')
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Other Category Homepage Feed Video',
                'slug' => 'other-category-homepage-feed-video',
            ]);

        $this->get(route('videos.index', ['source' => 'category', 'category' => $selected->id, 'per_page' => 5]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Selected Category Homepage Feed Video');
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_video_show_public(): void
    {
        $video = Video::factory()
            ->withStatus(VideoStatus::PUBLIC)
            ->forUser()
            ->has(
                Thumbnail::factory()
                    ->generated()
                    ->count(3)
            )
            ->create();

        $response = $this->get('/api/videos/'.$video->uuid);

        $response
            ->assertStatus(200)
            ->assertJsonIsObject()
            ->assertJson(fn (AssertableJson $json) =>
                $json
                    ->whereType('id', 'integer')
                    ->whereType('uuid', 'string')
                    ->whereType('file', 'string')
                    ->whereType('allow_comments', 'boolean')
                    ->whereType('show_likes', 'boolean')
                    ->when($video->show_likes, fn() =>
                        $json->whereType('likes', 'integer')
                            ->whereType('dislikes', 'integer')
                    )
                    ->whereType('default_comments_sort', 'string')
                    ->whereType('title', 'string')
                    ->whereType('short_title', 'string')
                    ->whereType('short_description', 'string')
                    ->whereType('description', 'string')
                    ->whereType('description_is_long', 'boolean')
                    ->whereType('thumbnail', 'string')
                    ->whereType('duration', 'integer')
                    ->whereType('formated_duration', 'string')
                    ->whereType('views', 'integer')
                    ->whereType('route', 'string')
                    ->has('user', fn (AssertableJson $json) =>
                        $json->whereType('id', 'integer')
                            ->whereType('username', 'string')
                            ->whereType('slug', 'string')
                            ->whereType('avatar', 'string')
                            ->whereType('route', 'string')
                            ->whereType('show_subscribers', 'boolean')
                            ->whereType('created_at', 'string')
                            ->etc()
                    )
                    ->whereType('comments', 'integer')
                    ->whereType('published_at', 'string')
                    ->whereType('status', 'integer')
                    ->whereType('first_comment', 'string|null')
                    ->whereType('suggested', 'array')
            );
    }

    /**
     * A basic feature test example.
     *
     * @return void
     */
    public function test_video_show_private(): void
    {
        $video = Video::factory()
            ->withStatus(VideoStatus::PRIVATE)
            ->forUser()
            ->create();

        $response = $this->get('/api/videos/'.$video->uuid);

        $response->assertNotFound();
    }
}
