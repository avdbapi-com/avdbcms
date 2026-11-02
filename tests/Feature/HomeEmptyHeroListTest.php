<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Home's hero shows the controller's list (PageController::home(): PublicVideoFeedService::heroVideos(), kept 3 minutes
 * per language), also when that list is empty: a visitor whose language no video can be shown in. The hero partial read
 * the trending list again at every view of the page when the list was empty, uncached (on a big site with thousands of
 * hidden videos in the visitor's language: a read of those rows at each view). A partial rendered without the
 * controller's list still reads it itself (HomeHeroAndPublicPagesTest::test_the_hero_fallback_...).
 */
class HomeEmptyHeroListTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_hero_list_is_read_once_not_at_every_view_of_home(): void
    {
        config([
            'site.home_layout_mode' => 'youtube_spotlight',
            'site.hero_layout_mode' => 'snap_slider_carousel',
        ]);
        // every video in German: an English visitor is shown none of them, so the hero list is empty
        Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->count(3)->create(['language' => 'de', 'title' => 'German only clip']);
        $trendingReads = 0;
        DB::listen(function ($query) use (&$trendingReads): void {
            // the hero list: the trending order, ten videos (the shelf takes six, the feeds a page)
            if (preg_match('/order by\s+[`"]?views[`"]?\s+desc/i', $query->sql) && preg_match('/limit\s+10\b/i', $query->sql)) {
                $trendingReads++;
            }
        });

        foreach ([1, 2, 3] as $view) {
            $html = (string) $this->get(route('pages.home'))->assertOk()->getContent();
            $this->assertStringNotContainsString('German only clip', $html, 'view '.$view);
        }

        $this->assertSame(1, $trendingReads, 'the hero list is read once and kept, not read again at every view');
    }

    public function test_a_hero_list_with_videos_is_shown_as_before(): void
    {
        config([
            'site.home_layout_mode' => 'youtube_spotlight',
            'site.hero_layout_mode' => 'snap_slider_carousel',
        ]);
        Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->create(['language' => null, 'title' => 'Hero clip shown', 'views' => 90]);

        $html = (string) $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Hero clip shown', $html);
    }
}
