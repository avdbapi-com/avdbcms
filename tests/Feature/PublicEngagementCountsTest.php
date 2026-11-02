<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use App\Support\EngagementCounters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicEngagementCountsTest extends TestCase
{
    use RefreshDatabase;

    private array $runtimeFlagEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Public rendering needs the normal installed-site routes (see ThemeStructureRenderTest).
        foreach (['APP_INSTALLED' => 'true', 'INSTALLER_ALLOW_REINSTALL' => 'false'] as $key => $value) {
            $this->runtimeFlagEnvironment[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        Cache::flush();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        foreach ($this->runtimeFlagEnvironment as $key => [$envValue, $serverValue, $processValue]) {
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }

            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }

            putenv($processValue === false ? $key : $key.'='.$processValue);
        }

        $this->runtimeFlagEnvironment = [];

        parent::tearDown();
    }

    public function test_moderated_comments_and_deleted_likes_do_not_inflate_public_counts(): void
    {
        $user = User::factory()->create();
        $video = Video::factory()->for($user)->withStatus(VideoStatus::PUBLIC)
            ->create(['show_likes' => true, 'allow_comments' => true]);
        Comment::factory()->for($user)->for($video)->createQuietly();
        Comment::factory()->for($user)->for($video)->banned()->createQuietly();
        $like = $video->interactions()->create(['user_id' => $user->id, 'status' => true, 'perform_at' => now()]);
        // The stored counters drift (comment bans, admin deletes and account removal bypass the counter events), so no
        // public display may read them.
        $video->updateQuietly(['likes_count' => 999, 'dislikes_count' => 999, 'comments_count' => 999]);

        $this->getJson(route('videos.show', $video->uuid))
            ->assertOk()
            ->assertJsonPath('likes', 1)
            ->assertJsonPath('dislikes', 0)
            ->assertJsonPath('comments', 1);

        // Public feeds stay lean on purpose (the 2026-09-27 lazy-load speed-up: no count subqueries per row): their
        // cards carry no engagement count, so the stored counters cannot leak through them ...
        $item = collect($this->getJson(route('videos.index'))->assertOk()->json('data'))->firstWhere('id', $video->id);
        $this->assertNotNull($item);
        foreach (['likes', 'dislikes', 'comments', 'likes_count', 'dislikes_count', 'comments_count'] as $key) {
            $this->assertArrayNotHasKey($key, $item);
        }
        // ... and the one feed that ranks by comments ranks by the count of public comments the video keeps (1.2.3,
        // DB-X1: no live count of every video's comments for each page), which the recount puts right after a drift.
        EngagementCounters::recount([$video->id]);
        $this->assertSame(1, (int) $video->fresh()->comments_count);
        $this->assertSame([$video->id], app(PublicVideoFeedService::class)->mostCommented()->pluck('id')->all());

        // Admin bulk deletion bypasses interaction events; the display must still be truthful.
        DB::table('interactions')->where('id', $like->id)->delete();
        $this->getJson(route('videos.show', $video->uuid))
            ->assertOk()
            ->assertJsonPath('likes', 0)
            ->assertJsonPath('dislikes', 0)
            ->assertJsonPath('comments', 1);
    }

    public function test_banned_comments_do_not_lift_a_video_in_the_most_commented_feed(): void
    {
        $user = User::factory()->create();
        // No public comment: 3 comments an admin hid (Admin > Comments: each one leaves the count it kept).
        $banned = Video::factory()->for($user)->withStatus(VideoStatus::PUBLIC)->create(['title' => 'Only Banned Comments']);
        Comment::factory()->count(3)->for($user)->for($banned)->create()->each(fn (Comment $comment) => $comment->update(['banned_at' => now()]));
        // One public comment.
        $commented = Video::factory()->for($user)->withStatus(VideoStatus::PUBLIC)->create(['title' => 'One Public Comment']);
        Comment::factory()->for($user)->for($commented)->create();

        // Most commented ranks by the count of public comments each video keeps (1.2.3, DB-X1)
        $this->assertSame(0, (int) $banned->fresh()->comments_count);
        $this->assertSame(1, (int) $commented->fresh()->comments_count);
        $feeds = app(PublicVideoFeedService::class);
        $this->assertSame([$commented->id, $banned->id], $feeds->mostCommented()->pluck('id')->all());

        // A count that drifted outside the site (a database tool, 1.2.2's bans) is put right by the recount
        $banned->updateQuietly(['comments_count' => 999]);
        $commented->updateQuietly(['comments_count' => 0]);
        EngagementCounters::recount([$banned->id, $commented->id]);
        $this->assertSame([$commented->id, $banned->id], $feeds->mostCommented()->pluck('id')->all());

        $first = $feeds->mostCommented()->cursorPaginate(1);
        $this->assertSame([$commented->id], collect($first->items())->pluck('id')->all());
        $second = $feeds->mostCommented()->cursorPaginate(1, ['*'], 'cursor', $first->nextCursor());
        $this->assertSame([$banned->id], collect($second->items())->pluck('id')->all());

        $this->assertSame(
            [$commented->id, $banned->id],
            collect($this->getJson(route('videos.index', ['source' => 'most_commented']))->assertOk()->json('data'))->pluck('id')->all()
        );
    }

    public function test_home_spotlight_liked_share_counts_live_votes_and_respects_hidden_likes(): void
    {
        $this->splitSpotlightSite();

        $video = Video::factory()->for(User::factory())->withStatus(VideoStatus::PUBLIC)->create(['show_likes' => true]);
        $this->vote($video, 3, 1);
        $video->updateQuietly(['likes_count' => 999, 'dislikes_count' => 0]);

        $main = $this->homeMain();
        $this->assertStringContainsString('avd-hero avd-hero--split', $main);
        $this->assertStringContainsString('<b>75%</b><span>Liked</span>', $main);

        // Votes removed behind the counters' back (Admin > Interactions): no share left to show.
        DB::table('interactions')
            ->where('likeable_type', $video->getMorphClass())
            ->where('likeable_id', $video->id)
            ->delete();
        $this->assertStringNotContainsString('<span>Liked</span>', $this->homeMain());

        // The creator hides likes: the home page keeps them hidden like the watch page and the API.
        $this->vote($video, 3, 1);
        $video->updateQuietly(['show_likes' => false, 'likes_count' => 3, 'dislikes_count' => 1]);
        Cache::flush(); // the hero list is cached for a few minutes
        $main = $this->homeMain();
        $this->assertStringContainsString('avd-hero avd-hero--split', $main);
        $this->assertStringNotContainsString('<span>Liked</span>', $main);
    }

    public function test_the_home_page_answers_while_its_cached_spotlight_holds_a_video_that_is_gone(): void
    {
        $this->serializingCache();
        $this->splitSpotlightSite();

        $top = Video::factory()->for(User::factory())->withStatus(VideoStatus::PUBLIC)->create(['show_likes' => true, 'views' => 5000, 'title' => 'Top Spotlight Clip']);
        Video::factory()->for(User::factory())->withStatus(VideoStatus::PUBLIC)->create(['show_likes' => true, 'views' => 10, 'title' => 'Second Spotlight Clip']);
        $this->vote($top, 3, 1);

        $this->assertStringContainsString('Top Spotlight Clip', $this->spotlightHtml());
        $this->assertTrue(Cache::has(PublicVideoFeedService::HERO_CACHE_KEY));

        // The row goes while the cached list (3 minutes) still holds it: a delete that raced a page re-caching the list,
        // or one that bypassed the model events. The spotlight counts the votes only and never reads the row again.
        DB::table('videos')->where('id', $top->id)->delete();
        $this->assertTrue(Cache::has(PublicVideoFeedService::HERO_CACHE_KEY));

        $this->get(route('pages.home'))->assertOk()->assertSee('avd-hero avd-hero--split', false);
    }

    public function test_deleting_a_video_takes_it_out_of_the_home_spotlight_at_once(): void
    {
        // The delete cascade removes the video's files: never the copy's real ones.
        Storage::fake();
        Storage::fake('public');
        $this->serializingCache();
        $this->splitSpotlightSite();

        $top = Video::factory()->for(User::factory())->withStatus(VideoStatus::PUBLIC)->create(['show_likes' => true, 'views' => 5000, 'title' => 'Top Spotlight Clip']);
        Video::factory()->for(User::factory())->withStatus(VideoStatus::PUBLIC)->create(['show_likes' => true, 'views' => 10, 'title' => 'Second Spotlight Clip']);
        $this->vote($top, 3, 1);
        $this->assertStringContainsString('Top Spotlight Clip', $this->spotlightHtml());

        // every delete of a video is permanent (the creator, the admin, bulk actions, account removal)
        $top->forceDelete();

        $this->assertFalse(Cache::has(PublicVideoFeedService::HERO_CACHE_KEY));
        $spotlight = $this->spotlightHtml();
        $this->assertStringNotContainsString('Top Spotlight Clip', $spotlight);
        $this->assertStringContainsString('Second Spotlight Clip', $spotlight);
    }

    /** A shipped look whose home hero is the split spotlight ("Liked" share beside the views). */
    private function splitSpotlightSite(): void
    {
        config([
            'site.theme_preset' => 'lavender_cloud',
            'site.theme_id' => 'lavender_cloud',
            'site.home_layout_mode' => 'youtube_spotlight',
            'site.hero_layout_mode' => 'snap_slider_carousel',
            'site.watch_layout_mode' => 'classic_two_column',
            'site.video_card_layout_style' => 'card_standard',
            'site.header_style' => 'glass_island',
            'site.sidebar_style' => 'hidden',
            'site.front_sidebar_pinned_enabled' => true,
        ]);
    }

    /** The cache stores of a live site (file, database, Redis) keep a serialized copy, as this array store then does. */
    private function serializingCache(): void
    {
        config(['cache.stores.array.serialize' => true]);
        app('cache')->forgetDriver('array');
        Cache::flush();
    }

    /** The split spotlight section of the home page. */
    private function spotlightHtml(): string
    {
        $html = (string) $this->get(route('pages.home'))->assertOk()->getContent();
        $start = strpos($html, '<section class="avd-hero avd-hero--split"');
        $this->assertNotFalse($start, 'the home page shows the split spotlight');

        return substr($html, $start, strpos($html, '</section>', $start) - $start);
    }

    private function vote(Video $video, int $likes, int $dislikes): void
    {
        foreach (range(1, $likes + $dislikes) as $index) {
            $video->interactions()->create([
                'user_id' => User::factory()->create()->id,
                'status' => $index <= $likes,
                'perform_at' => now(),
            ]);
        }
    }

    /** The <main> part of the home page (the hero and the feed, without the layout around them). */
    private function homeMain(): string
    {
        $html = (string) $this->get(route('pages.home'))->assertOk()->getContent();
        $start = strpos($html, '<main');
        $end = strrpos($html, '</main>');

        return $start === false || $end === false ? $html : substr($html, $start, $end - $start);
    }
}
