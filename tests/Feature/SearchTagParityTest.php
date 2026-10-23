<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\Video;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SearchTagParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_and_public_api_search_return_the_same_tagged_video(): void
    {
        $video = $this->videoWithTag('Harbor Morning Search Result', 'Coastal Walk');

        $this->get(route('search.index', ['q' => 'Coastal Walk']))
            ->assertOk()
            ->assertSee($video->title);

        $this->getJson('/api/search?q=' . rawurlencode('Coastal Walk'))
            ->assertOk()
            ->assertJsonFragment([
                'uuid' => $video->uuid,
            ]);
    }

    public function test_search_count_is_the_filtered_total_not_the_current_page_size(): void
    {
        foreach (range(1, 23) as $index) {
            Video::factory()
                ->forUser()
                ->withStatus(VideoStatus::PUBLIC)
                ->create([
                    'title' => 'Truthful Count Result '.$index,
                    'slug' => Str::slug('Truthful Count Result '.$index),
                ]);
        }

        $response = $this->get(route('search.index', ['q' => 'Truthful Count Result']))
            ->assertOk();

        $this->assertSame(23, $response->viewData('results')->total());
    }

    public function test_public_api_category_suggestion_matches_and_labels_localized_title(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\Language::class);
        app()->setLocale('vi');

        $category = Category::query()->create([
            'title' => 'Amateur',
            'slug' => 'amateur-search-category',
            'icon' => 'video',
        ]);

        $payload = $this->getJson('/api/search?q='.rawurlencode('nghiệp dư'))
            ->assertOk()
            ->json();

        $this->assertSame($category->id, $payload['categories'][0]['id'] ?? null);
        $this->assertSame('nghiệp dư', $payload['categories'][0]['title'] ?? null);
    }

    public function test_localized_tag_label_is_shared_by_web_and_public_api_search(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\Language::class);
        app()->setLocale('vi');

        $video = $this->videoWithTag('Vietnamese Shore Search Result', 'Coastal Walk');
        $tag = $video->tags()->firstOrFail();

        TagTranslation::create([
            'tag_id' => $tag->id,
            'locale' => 'vi',
            'name' => 'Đi dạo bờ biển',
        ]);

        $localizedQuery = 'Đi dạo bờ biển';

        $this->get(route('search.index', ['q' => $localizedQuery]))
            ->assertOk()
            ->assertSee($video->title);

        $this->getJson('/api/search?q=' . rawurlencode($localizedQuery))
            ->assertOk()
            ->assertJsonFragment([
                'uuid' => $video->uuid,
            ]);
    }

    public function test_known_tag_uses_database_fallback_when_meilisearch_is_configured(): void
    {
        $video = $this->videoWithTag('Harbor Morning Search Result', 'Coastal Walk');

        Config::set([
            'scout.driver' => 'meilisearch',
            'scout.meilisearch.host' => 'http://127.0.0.1:7700',
        ]);

        $this->get(route('search.index', ['q' => 'Coastal Walk']))
            ->assertOk()
            ->assertSee($video->title);

        $this->getJson('/api/search?q=' . rawurlencode('Coastal Walk'))
            ->assertOk()
            ->assertJsonFragment([
                'uuid' => $video->uuid,
            ]);
    }

    public function test_single_character_tag_does_not_fall_back_to_browse_or_empty_api_results(): void
    {
        $video = $this->videoWithTag('Harbor Morning Search Result', 'X');

        $this->get(route('search.index', ['q' => 'X']))
            ->assertOk()
            ->assertSee($video->title);

        $this->getJson('/api/search?q=X')
            ->assertOk()
            ->assertJsonFragment([
                'uuid' => $video->uuid,
            ]);
    }

    public function test_searchable_video_payload_contains_tag_names_slugs_and_translations(): void
    {
        $video = $this->videoWithTag('Harbor Morning Search Result', 'Coastal Walk');
        $tag = $video->tags()->firstOrFail();

        TagTranslation::create([
            'tag_id' => $tag->id,
            'locale' => 'vi',
            'name' => 'Đi dạo bờ biển',
        ]);

        $searchable = $video->fresh()->load('tags.translations')->toSearchableArray();

        $this->assertContains('Coastal Walk', $searchable['tags']);
        $this->assertContains('coastal-walk', $searchable['tags']);
        $this->assertContains('Đi dạo bờ biển', $searchable['tags']);
    }

    public function test_partial_keyword_is_not_hijacked_by_a_similar_tag_label(): void
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => 'Coastline Keyword Result',
                'slug' => Str::slug('Coastline Keyword Result'),
                'description' => 'The title carries the partial keyword.',
            ]);

        $this->videoWithTag('Unrelated Tagged Result', 'Coastal Walk');

        $this->get(route('search.index', ['q' => 'Coa']))
            ->assertOk()
            ->assertSee($video->title);

        $this->getJson('/api/search?q=Coa')
            ->assertOk()
            ->assertJsonFragment([
                'uuid' => $video->uuid,
            ]);
    }

    public function test_same_tag_query_keeps_web_and_api_cache_entries_separate_by_locale(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\Language::class);

        $tag = Tag::create([
            'name' => 'Locale Split Tag',
            'slug' => 'locale-split-tag',
            'usage_count' => 2,
        ]);
        $english = $this->createTaggedVideo($tag, 'English Locale Result', [
            'language' => 'en',
        ]);
        $vietnamese = $this->createTaggedVideo($tag, 'Vietnamese Locale Result', [
            'language' => 'vi',
        ]);

        app()->setLocale('en');
        $webEnglish = $this->get(route('search.index', ['q' => 'Locale Split Tag']))
            ->assertOk();
        $apiEnglish = $this->getJson('/api/search?q=' . rawurlencode('Locale Split Tag'))
            ->assertOk();

        app()->setLocale('vi');
        $webVietnamese = $this->get(route('search.index', ['q' => 'Locale Split Tag']))
            ->assertOk();
        $apiVietnamese = $this->getJson('/api/search?q=' . rawurlencode('Locale Split Tag'))
            ->assertOk();

        $webEnglish
            ->assertSee($english->title)
            ->assertDontSee($vietnamese->title);
        $webVietnamese
            ->assertSee($vietnamese->title)
            ->assertDontSee($english->title);

        $this->assertSame([$english->uuid], $this->responseUuids($apiEnglish));
        $this->assertSame([$vietnamese->uuid], $this->responseUuids($apiVietnamese));
    }

    public function test_tag_search_excludes_private_future_and_banned_owner_videos(): void
    {
        $tag = Tag::create([
            'name' => 'Visibility Split Tag',
            'slug' => 'visibility-split-tag',
            'usage_count' => 4,
        ]);

        $public = $this->createTaggedVideo($tag, 'Visible Public Result');
        $private = $this->createTaggedVideo($tag, 'Hidden Private Result', [
            'status' => VideoStatus::PRIVATE->value,
        ]);
        $future = $this->createTaggedVideo($tag, 'Hidden Future Result', [
            'status' => VideoStatus::PLANNED->value,
            'scheduled_at' => now()->addHour(),
        ]);
        $bannedOwner = User::factory()->banned()->create();
        $banned = $this->createTaggedVideo($tag, 'Hidden Banned Owner Result', [], $bannedOwner);

        $this->get(route('search.index', ['q' => 'Visibility Split Tag']))
            ->assertOk()
            ->assertSee($public->title)
            ->assertDontSee($private->title)
            ->assertDontSee($future->title)
            ->assertDontSee($banned->title);

        $items = $this->getJson('/api/search?q=' . rawurlencode('Visibility Split Tag'))
            ->assertOk()
            ->json('items');
        $uuids = collect($items)->pluck('uuid')->map(fn ($uuid): string => (string) $uuid)->all();

        $this->assertContains((string) $public->uuid, $uuids);
        $this->assertNotContains((string) $private->uuid, $uuids);
        $this->assertNotContains((string) $future->uuid, $uuids);
        $this->assertNotContains((string) $banned->uuid, $uuids);
    }

    private function videoWithTag(string $title, string $tagName): Video
    {
        $video = Video::factory()
            ->forUser()
            ->withStatus(VideoStatus::PUBLIC)
            ->create([
                'title' => $title,
                'slug' => Str::slug($title),
                'description' => 'Search fixture without the tag keyword in the title or description.',
            ]);

        $tag = Tag::create([
            'name' => $tagName,
            'slug' => Str::slug($tagName),
            'usage_count' => 1,
        ]);

        $video->tags()->attach($tag);

        return $video;
    }

    private function createTaggedVideo(Tag $tag, string $title, array $attributes = [], ?User $user = null): Video
    {
        $video = Video::factory()
            ->for($user ?? User::factory()->create())
            ->create(array_merge([
                'title' => $title,
                'slug' => Str::slug($title),
                'description' => 'Visibility and locale search fixture.',
                'status' => VideoStatus::PUBLIC->value,
                'published_at' => now(),
                'uploaded_at' => now(),
            ], $attributes));

        $video->tags()->attach($tag);

        return $video;
    }

    /**
     * @return array<int, string>
     */
    private function responseUuids(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->json('items'))
            ->pluck('uuid')
            ->map(fn ($uuid): string => (string) $uuid)
            ->values()
            ->all();
    }
}
