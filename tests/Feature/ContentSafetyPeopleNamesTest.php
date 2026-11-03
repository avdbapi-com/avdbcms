<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use App\Services\SeoService;
use App\Support\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 1.2.3 refresh 2 (lane CS2, N2-SAFE-01): an actor, director or studio named with a word that is always blocked
 * (App\Support\ContentSafety::hardMatch: words that present a child or a school-age minor) gets no creator channel since
 * the refresh (ContentSafetyCreatorImportTest), and is no fact for visitors either: the watch page's details rows (with
 * their search links), the page's description, the SEO templates' {actor} and {director}, the page's structured data,
 * the home page's trending-actors rail and the search index leave such a name out (ContentSafety::visibleNames), the
 * way a tag or a category named with one is left out since 1.2.3. The other names of the field are shown; the stored
 * field stays (the admin keeps it).
 */
class ContentSafetyPeopleNamesTest extends TestCase
{
    use RefreshDatabase;

    /** Words of the hard list a visitor must never read on these pages (test values; the list itself is in ContentSafety). */
    private const NEVER_SHOWN = ['Loli', 'Schoolgirl', 'Rori'];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        config(['site.feed_cache_ttl' => 0]);
    }

    public function test_visible_names_leaves_out_the_names_that_use_a_word_of_the_hard_list(): void
    {
        $this->assertNull(ContentSafety::visibleNames(null));
        $this->assertNull(ContentSafety::visibleNames(''));
        $this->assertNull(ContentSafety::visibleNames('  '));
        $this->assertSame('Jane Doe', ContentSafety::visibleNames('Jane Doe'));
        $this->assertSame('Jane Doe, John Roe', ContentSafety::visibleNames('Jane Doe, John Roe'));
        $this->assertSame('Jane Doe', ContentSafety::visibleNames('Loli Chan, Jane Doe'), 'the other names of the field stay');
        $this->assertNull(ContentSafety::visibleNames('Schoolgirls HD'), 'a studio named with such a word');
        $this->assertNull(ContentSafety::visibleNames('Rori Director'));
        $this->assertSame('Jane Doe, John Roe', ContentSafety::visibleNames('Jane Doe; Loli Chan | John Roe'), 'the catalogues\' separators');
        $this->assertSame('Jane Doe', ContentSafety::visibleNames('Jane Doe, Jane Doe'), 'once');
        // adult-only words, product codes and the owner's own list never hide a name (only the hard list does)
        $this->assertSame('Teen Dreams', ContentSafety::visibleNames('Teen Dreams'));
        $this->assertSame('JK-123 Studio', ContentSafety::visibleNames('JK-123 Studio'));
        $this->assertSame('Leaked Films', ContentSafety::visibleNames('Leaked Films'));
        $this->assertSame('Misaki Kaede', ContentSafety::visibleNames('Misaki Kaede'));
    }

    public function test_the_watch_page_never_shows_an_actor_director_or_studio_named_with_a_blocked_word(): void
    {
        $this->withoutVite();
        config([
            'site.video_meta_title_template' => '{video_title} with {actor} by {director}',
            'site.video_meta_description_template' => '{description}',
        ]);
        $video = $this->avdbVideo();

        $html = (string) $this->get($video->route)->assertOk()->getContent();

        // the clean names are shown and linked to search as before
        $this->assertStringContainsString('Misaki Kaede', $html);
        $this->assertStringContainsString('q=Misaki%20Kaede', $html);
        $this->assertStringContainsString('q=TEST-001', $html);
        // the hidden ones are nowhere: not in the rows, not in a search link, not in the description, the title or the structured data
        $this->assertStringNotContainsString('q=Loli', $html);
        $this->assertStringNotContainsString('q=Schoolgirls', $html);
        $this->assertStringNotContainsString('q=Rori', $html);
        $this->assertStringNotContainsString('Director:', $html, 'the description\'s director part is left out');
        $this->assertStringNotContainsString('Studio:', $html);
        $this->assertSame(1, preg_match('/<meta name="description" content="([^"]*)"/', $html, $meta));
        $this->assertStringContainsString('Actor: Misaki Kaede.', html_entity_decode($meta[1]));
        $this->assertSame(0, preg_match('/<span class="avd-detail-label">\s*<i class="fa-solid fa-video"><\/i>\s*Director\s*<\/span>/', $html), 'no director row');
        $this->assertSame(1, preg_match('/<title>([^<]*)<\/title>/', $html, $title));
        $this->assertStringContainsString('Clean import title with Misaki Kaede by', $title[1]);
        $this->assertNeverShown($html);
    }

    public function test_the_watch_page_of_an_xvidapi_import_never_shows_such_an_actor_or_studio(): void
    {
        $this->withoutVite();
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Clean xvid title',
                'slug' => 'clean-xvid-title',
                'description' => 'A description.',
                'xvid_id' => 3001,
                'xvid_code' => 'xvid-item-3001',
                'xvid_quality' => 'HD',
                'xvid_actor' => 'Loli Chan, Jane Doe',
                'xvid_author' => 'Schoolgirls HD',
            ]);

        $html = (string) $this->get($video->route)->assertOk()->getContent();

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('q=Jane%20Doe', $html);
        $this->assertStringNotContainsString('Studio:', $html);
        $this->assertSame(1, preg_match('/<meta name="description" content="([^"]*)"/', $html, $meta));
        $this->assertStringContainsString('Actor: Jane Doe.', html_entity_decode($meta[1]));
        $this->assertNeverShown($html);
    }

    public function test_the_seo_values_the_structured_data_and_the_search_index_leave_such_names_out(): void
    {
        $video = $this->avdbVideo();
        $seo = app(SeoService::class);

        $values = $seo->videoTemplateValues($video, 'A description.');
        $this->assertSame('Misaki Kaede', $values['actor']);
        $this->assertNull($values['director']);

        $schema = $seo->videoSchema($video);
        $this->assertSame([['@type' => 'Person', 'name' => 'Misaki Kaede']], $schema['actor']);
        $this->assertArrayNotHasKey('director', $schema);
        $this->assertNeverShown((string) json_encode($schema));

        $index = $video->fresh()->toSearchableArray();
        $this->assertSame('Misaki Kaede', $index['actor']);
        $this->assertNull($index['director']);
        $this->assertNeverShown((string) json_encode($index));

        // the stored field stays as the catalogue sent it (the admin keeps it)
        $this->assertSame('Loli Chan, Misaki Kaede', $video->fresh()->avdb_actor);
    }

    public function test_the_home_trending_actors_rail_never_offers_an_actor_named_with_a_blocked_word(): void
    {
        config([
            'site.trending_collections_enabled' => true,
            'site.trending_collections_limit' => 4,
            'site.trending_collections_lookback_days' => 14,
        ]);
        $category = Category::query()->create(['title' => 'Action', 'slug' => 'action', 'icon' => 'film', 'in_menu' => true, 'position' => 1]);
        $creator = User::factory()->create();
        $viewed = fn (string $actor, int $views): Video => tap(Video::factory()->for($category)->for($creator, 'user')->withStatus(VideoStatus::PUBLIC)->create([
            'avdb_actor' => $actor,
            'published_at' => now()->subDay(),
            'uploaded_at' => now()->subDay(),
        ]), function (Video $video) use ($views): void {
            for ($i = 0; $i < $views; $i++) {
                View::query()->create(['video_id' => $video->id, 'user_id' => null, 'ip' => '127.0.0.'.($i + 1), 'view_at' => now()->subHour()]);
            }
        });
        // the hidden name has the most views: it would lead the rail
        $viewed('Loli Chan', 3);
        $viewed('Mio Futaba', 1);

        $html = (string) $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertStringContainsString('Trending actors', $html);
        $this->assertStringContainsString('Mio Futaba', $html);
        $this->assertStringContainsString('q=Mio%20Futaba', $html);
        $this->assertNeverShown($html);

        // the same answer kept for the page (DiscoverySnapshots): still without the name
        $this->assertNeverShown((string) $this->get(route('pages.home'))->assertOk()->getContent());
    }

    public function test_the_home_page_offers_nothing_of_a_rail_whose_only_actor_is_named_with_a_blocked_word(): void
    {
        config([
            'site.trending_collections_enabled' => true,
            'site.trending_collections_limit' => 4,
            'site.trending_collections_lookback_days' => 14,
        ]);
        $video = Video::factory()->forUser()->withStatus(VideoStatus::PUBLIC)->create([
            'avdb_actor' => 'Loli Chan',
            'published_at' => now()->subDay(),
            'uploaded_at' => now()->subDay(),
        ]);
        View::query()->create(['video_id' => $video->id, 'user_id' => null, 'ip' => '127.0.0.1', 'view_at' => now()->subHour()]);

        $html = (string) $this->get(route('pages.home'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Actors viewers are watching now', $html);
        $this->assertNeverShown($html);
    }

    /** An AVDBAPI-shaped public video whose actors, director and studio fields use words of the hard list next to a clean actor. */
    private function avdbVideo(): Video
    {
        return Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Clean import title',
                'slug' => 'clean-import-title',
                'description' => 'A description.',
                'avdb_id' => 134,
                'avdb_code' => 'TEST-001',
                'avdb_actor' => 'Loli Chan, Misaki Kaede',
                'avdb_director' => 'Rori Director',
                'avdb_writer' => 'Schoolgirls HD',
                'avdb_country' => 'Japan',
            ]);
    }

    private function assertNeverShown(string $text): void
    {
        foreach (self::NEVER_SHOWN as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $text, 'a word of the hard list reached the visitor');
        }
    }
}
