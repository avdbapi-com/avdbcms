<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.3: the home page (the hero items; member design 6.16) and the public pages
 * of the release: category head (watch-9, watch-34), shorts shelf (watch-32), share dialog (watch-11), search on phones
 * (watch-41).
 */
class HomeHeroAndPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_hero_list_is_cached_per_language(): void
    {
        config(['site.default_locale' => 'en', 'app.locale' => 'en']);

        $this->assertSame(PublicVideoFeedService::HERO_CACHE_KEY, PublicVideoFeedService::heroCacheKey('en'));
        $this->assertSame(PublicVideoFeedService::HERO_CACHE_KEY.':vi', PublicVideoFeedService::heroCacheKey('vi'));
        $this->assertNotSame(PublicVideoFeedService::heroCacheKey('fr'), PublicVideoFeedService::heroCacheKey('vi'));

        app()->setLocale('vi');
        $this->assertSame(PublicVideoFeedService::HERO_CACHE_KEY.':vi', PublicVideoFeedService::heroCacheKey());
    }

    public function test_the_hero_fallback_shows_only_live_videos_of_active_creators_in_the_pages_language(): void
    {
        $live = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->create(['title' => 'Live hero video', 'views' => 50, 'language' => null]);
        Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->create(['title' => 'Not yet published hero', 'views' => 900, 'published_at' => now()->addDay()]);
        Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->create(['title' => 'Still uploading hero', 'views' => 800, 'uploaded_at' => null]);
        Video::factory()->withStatus(VideoStatus::PRIVATE)->forUser()->create(['title' => 'Private hero video', 'views' => 700]);
        Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->create(['title' => 'Other language hero', 'views' => 600, 'language' => 'de']);

        $html = view('pages.partials.hero', ['heroLayoutMode' => 'editorial_lead', 'homeLayoutMode' => 'grid_modern'])->render();

        $this->assertStringContainsString('Live hero video', $html);
        foreach (['Not yet published hero', 'Still uploading hero', 'Private hero video', 'Other language hero'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, $hidden);
        }
        $this->assertTrue($live->exists);
    }

    public function test_an_old_premium_return_goes_to_the_premium_page(): void
    {
        $this->get('/?premium=1')->assertRedirect()->assertStatus(302);
        $this->assertStringEndsWith('/premium?checkout=success', $this->get('/?premium=1')->headers->get('Location'));
        $this->assertStringNotContainsString('modals.premium', (string) file_get_contents(resource_path('views/pages/home.blade.php')));
    }

    public function test_a_category_without_its_own_picture_gets_a_compact_band_and_a_readable_count(): void
    {
        $category = Category::query()->create(['title' => 'Travel', 'slug' => 'travel', 'background' => null]);
        $html = $this->get(route('pages.category', ['slug' => $category->slug]))->assertOk()->getContent();

        $this->assertStringContainsString('avd-category-hero--plain', $html);
        $this->assertStringNotContainsString('/images/default_banner.jpg"', $this->between($html, '<section class="avd-category-hero', '</section>'));
        $this->assertStringContainsString('avd-category-count', $html);
        $this->assertStringNotContainsString('avd-status-chip text-white', $html);
    }

    public function test_the_shorts_shelf_calls_long_videos_trending_not_shorts(): void
    {
        $long = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->count(4)->create(['duration' => 3 * 3600]);

        $html = view('pages.partials.hero.shorts', ['heroVideos' => $long, 'heroDeco' => 'shorts'])->render();
        $this->assertMatchesRegularExpression('/id="avdShortsTitle">\s*Trending\s*<\/h2>/', $html);

        $short = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->count(4)->create(['duration' => 45]);
        $html = view('pages.partials.hero.shorts', ['heroVideos' => $short, 'heroDeco' => 'shorts'])->render();
        $this->assertDoesNotMatchRegularExpression('/id="avdShortsTitle">\s*Trending\s*<\/h2>/', $html);
    }

    /**
     * The shelf's own query (trending videos of 3 minutes or less, a computed filter no index serves) runs
     * once per language every 3 minutes like the hero list, never on each home view, and is forgotten with the hero
     * list when a video leaves the site.
     */
    public function test_the_shorts_shelf_query_is_kept_with_the_hero_list(): void
    {
        $long = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->count(2)->create(['duration' => 3 * 3600]);
        Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->count(3)->create(['duration' => 50]);
        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'duration + 0 <= ?')) {
                $queries++;
            }
        });

        foreach ([1, 2] as $render) {
            $html = view('pages.partials.hero.shorts', ['heroVideos' => $long, 'heroDeco' => 'shorts'])->render();
            $this->assertSame(3, substr_count($html, 'data-avd-card'), 'render '.$render);
        }
        $this->assertSame(1, $queries, 'the shorts query ran on every render');

        // Per language, and forgotten with the hero list.
        $this->assertSame(PublicVideoFeedService::HERO_SHORTS_CACHE_KEY.':vi', PublicVideoFeedService::heroCacheKey('vi', PublicVideoFeedService::HERO_SHORTS_CACHE_KEY));
        PublicVideoFeedService::forgetHeroVideos();
        view('pages.partials.hero.shorts', ['heroVideos' => $long, 'heroDeco' => 'shorts'])->render();
        $this->assertSame(2, $queries);
    }

    public function test_the_share_dialog_starts_at_a_position_and_encodes_its_links(): void
    {
        $video = Video::factory()->withStatus(VideoStatus::PUBLIC)->forUser()->create(['title' => 'Tom & Jerry #1']);
        $html = $this->get($video->route)->assertOk()->getContent();
        $dialog = $this->between($html, 'id="share"', '<script>');

        $this->assertStringNotContainsString('type="time"', $dialog);
        $this->assertStringContainsString('id="share-start-at"', $dialog);
        $this->assertStringContainsString('for="share-link"', $dialog);
        $this->assertStringContainsString('for="share-start-on"', $dialog);
        $this->assertStringNotContainsString('description=test', $dialog);
        $this->assertStringContainsString('Tom%20%26%20Jerry%20%231', $dialog);
        $this->assertStringContainsString('mailto:?subject=', $dialog);
        $this->assertStringContainsString('fa-x-twitter', $dialog);
        $this->assertStringNotContainsString('Start at :', $dialog);
    }

    public function test_search_results_are_compact_rows_on_phones(): void
    {
        $css = (string) file_get_contents(resource_path('sass/enhancements.scss'));
        $phone = substr($css, strpos($css, '@media (max-width: 575.98px) {', strpos($css, '#main-container.avd-search-page') - 200));

        $this->assertMatchesRegularExpression('/\.avd-search-result-card\s*\{\s*flex-direction:\s*row;/', $phone);
        // The column fills the phone (a flex item of .avd-shell, it took its content's width: an empty
        // band of 70-155px beside cut titles).
        $this->assertMatchesRegularExpression('/#main-container\.avd-search-page\s*\{[^}]*flex:\s*1 1 0%;[^}]*min-inline-size:\s*0;/', $phone);
        $this->assertMatchesRegularExpression('/\.avd-search-result-desc\s*\{\s*display:\s*none;/', $phone);
        $this->assertDoesNotMatchRegularExpression('/\.avd-search-filter-toggle\s*\{\s*width:\s*100%/', $phone);
        $this->assertStringNotContainsString('border-radius: 20px 0 0 20px', $css);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'missing '.$start);
        $to = strpos($html, $end, $from);

        return substr($html, $from, ($to === false ? strlen($html) : $to) - $from);
    }
}
