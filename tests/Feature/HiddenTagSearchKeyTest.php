<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\User;
use App\Models\Video;
use App\Services\VideoDatabaseSearchService;
use App\Support\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (lane KS, R3-004): a tag visitors never see (its name uses a word that is always blocked,
 * App\Support\ContentSafety) is no search key: not by its name, its address or its translations, not as a whole query
 * and not as a part of one, on the search page, in the public API's search and on the way that keeps a known tag's query
 * on the database when the optional external search is on. The same for a category visitors never see. A query that
 * itself uses such a word is never a tag or category key. Tags and categories visitors see work as before.
 *
 * Test data: neutral titles and names with one listed word.
 */
class HiddenTagSearchKeyTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        $this->creator = User::factory()->create(['email_verified_at' => now()]);
    }

    public function test_a_hidden_tag_is_no_search_key_on_the_site_or_in_the_api(): void
    {
        $hiddenTag = $this->tag('QA child tag');
        $tagged = $this->video('QA tagged later video', [$hiddenTag]);
        $visible = $this->video('QA harbour evening', [$this->tag('QA harbour tag')]);

        // the whole name, its address, a part of it
        foreach (['QA child tag', 'qa-child-tag', 'QA chi', 'child tag'] as $query) {
            $this->assertNotFound($query, $tagged);
        }

        // a translation of the hidden tag, clean as it is, is no key either
        TagTranslation::create(['tag_id' => $hiddenTag->id, 'locale' => 'en', 'name' => 'QA quiet label']);
        $this->assertNotFound('QA quiet label', $tagged);

        // a tag visitors see: as before, by its name and by a part of it
        $this->assertFound('QA harbour tag', $visible);
        $this->assertFound('harbour ta', $visible);
    }

    public function test_the_external_search_path_never_keeps_a_hidden_tag_on_the_database(): void
    {
        $this->video('QA tagged later video', [$this->tag('QA child tag')]);
        $this->video('QA harbour evening', [$this->tag('QA harbour tag')]);
        $search = app(VideoDatabaseSearchService::class);

        // SearchController and the public API keep a query that names a known tag on the database (an external index
        // can lag behind); a hidden tag is no known tag, so its query goes where the index has no hidden tag at all
        $this->assertFalse($search->hasTagMatch('QA child tag'));
        $this->assertFalse($search->hasTagMatch('qa-child-tag'));
        $this->assertTrue($search->hasTagMatch('QA harbour tag'));
    }

    public function test_a_hidden_category_is_no_search_key_either(): void
    {
        $hidden = Category::create(['title' => 'QA child category', 'slug' => 'qa-child-category', 'in_menu' => true, 'position' => 1]);
        $garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 2]);
        $inHidden = $this->video('QA quiet morning', [], $hidden);
        $inGarden = $this->video('QA bright afternoon', [], $garden);

        foreach (['QA child category', 'QA child cat', 'child category'] as $query) {
            $this->assertNotFound($query, $inHidden);
        }

        $this->assertFound('QA garden category', $inGarden);
        $this->assertFound('garden cat', $inGarden);
        // the video itself is not hidden: its own words still find it
        $this->assertFound('QA quiet morning', $inHidden);
    }

    public function test_the_spotlight_chips_never_take_a_hidden_tag_or_category_as_a_key(): void
    {
        // a public video whose only match for the chip "Japanese" is a part of a hidden tag's or category's name
        $hidden = Category::create(['title' => 'QA child Japanese category', 'slug' => 'qa-child-japanese-category', 'in_menu' => true, 'position' => 1]);
        $this->video('QA quiet morning', [$this->tag('QA child Japanese tag')], $hidden);
        Cache::flush();
        $this->assertSame([], \App\Support\SpotlightChips::all());

        // a tag visitors see that the chip names: the chip shows, and its check keeps its two queries per chip
        $this->video('QA bright afternoon', [$this->tag('Japanese')]);
        Cache::flush();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $chips = \App\Support\SpotlightChips::all();
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $this->assertSame(['Japanese'], array_column($chips, 'label'));
        $this->assertLessThanOrEqual(6, $queries, 'per chip: its tag lookup and its search, nothing read for the hidden names');
    }

    private function assertNotFound(string $query, Video $video): void
    {
        Cache::flush();
        $this->get(route('search.index', ['q' => $query]))->assertOk()->assertDontSee($video->title);
        $api = $this->getJson('/api/search?q='.rawurlencode($query))->assertOk()->json();
        $this->assertNotContains($video->uuid, array_column((array) ($api['items'] ?? []), 'uuid'), '"'.$query.'" finds no video through a hidden tag or category (API)');
    }

    private function assertFound(string $query, Video $video): void
    {
        Cache::flush();
        $this->get(route('search.index', ['q' => $query]))->assertOk()->assertSee($video->title);
        $api = $this->getJson('/api/search?q='.rawurlencode($query))->assertOk()->json();
        $this->assertContains($video->uuid, array_column((array) ($api['items'] ?? []), 'uuid'), '"'.$query.'" (API)');
    }

    private function tag(string $name): Tag
    {
        return Tag::create(['name' => $name, 'slug' => Str::slug($name), 'usage_count' => 1]);
    }

    /** @param list<Tag> $tags */
    private function video(string $title, array $tags, ?Category $category = null): Video
    {
        $video = Video::factory()->create([
            'user_id' => $this->creator->id,
            'title' => $title,
            'slug' => Str::slug($title),
            'description' => 'A neutral description.',
            'category_id' => $category?->id,
            'status' => VideoStatus::PUBLIC->value,
            'published_at' => now()->subDay(),
            'uploaded_at' => now()->subDay(),
            'language' => null,
        ]);
        $video->tags()->attach(array_map(fn (Tag $tag): int => $tag->id, $tags));

        return $video;
    }
}
