<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Services\PublicVideoFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryVisibleCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_category_and_directory_display_zero(): void
    {
        $category = $this->category('Amateur');

        $this->get(route('pages.category', $category->slug))
            ->assertOk()
            ->assertViewHas('category', fn ($item) => $item->videos_count === 0)
            ->assertSee('0 videos')
            ->assertSee('No videos found');

        $this->get(route('pages.categories'))
            ->assertOk()
            ->assertSee('0 Total Videos')
            ->assertDontSee('154,200')
            ->assertDontSee('thousands');
    }

    public function test_category_count_matches_visible_feed_and_current_locale(): void
    {
        $category = $this->category('Visible');
        $user = User::factory()->create();
        $base = ['category_id' => $category->id, 'uploaded_at' => now()->subDay(), 'published_at' => now()->subDay()];
        $create = fn (array $attributes = [], ?User $owner = null) => Video::factory()->for($owner ?? $user)
            ->withStatus(VideoStatus::PUBLIC)->create(array_merge($base, $attributes));

        $create();
        $create(['status' => VideoStatus::PLANNED, 'scheduled_at' => now()->subHour()]);
        $create(['status' => VideoStatus::PRIVATE]);
        $create(['status' => VideoStatus::UNLISTED]);
        $create(['uploaded_at' => null]);
        $create(['published_at' => now()->addDay()]);
        $create(['status' => VideoStatus::PLANNED, 'scheduled_at' => now()->addDay()]);
        $create(['deleted_at' => now()]);
        $create([], User::factory()->banned()->create());
        $create([], User::factory()->create(['email_verified_at' => null]));
        $create(['language' => 'vi']);
        $create(['language' => 'fr', 'avdb_id' => 934561]);

        $feeds = app(PublicVideoFeedService::class);
        $this->assertSame(2, $feeds->category($category)->count());
        $this->get(route('pages.category', $category->slug))
            ->assertOk()->assertViewHas('category', fn ($item) => $item->videos_count === 2);

        app()->setLocale('vi');
        $this->assertSame(4, $feeds->category($category)->count());
        // Exercise the controller at this locale without middleware changing it.
        $view = app(\App\Http\Controllers\PageController::class)->category($category->slug);
        $this->assertSame(4, $view->getData()['category']->videos_count);
    }

    public function test_count_updates_after_video_changes_without_waiting_for_cache(): void
    {
        $category = $this->category('Fresh');
        Cache::put('pages.category.active_videos_count.' . $category->id, 999, 1800);
        $url = route('pages.category', $category->slug);
        $this->get($url)->assertViewHas('category', fn ($item) => $item->videos_count === 0);

        $video = Video::factory()->for(User::factory()->create())->withStatus(VideoStatus::PUBLIC)
            ->create(['category_id' => $category->id]);
        $this->get($url)->assertViewHas('category', fn ($item) => $item->videos_count === 1);
        $video->update(['status' => VideoStatus::PRIVATE]);
        $this->get($url)->assertViewHas('category', fn ($item) => $item->videos_count === 0);
    }

    public function test_duplicate_slug_directory_count_belongs_to_linked_category(): void
    {
        $other = $this->category('Same', 2);
        $canonical = $this->category('Same', 1);
        // The sortable creation hook assigns positions; set the fixture order explicitly.
        $other->updateQuietly(['position' => 2]);
        $canonical->updateQuietly(['position' => 1]);
        Video::factory()->for(User::factory()->create())->withStatus(VideoStatus::PUBLIC)
            ->create(['category_id' => $other->id]);

        $this->get(route('pages.categories'))->assertViewHas('categories', function ($categories) use ($canonical) {
            return $categories->count() === 1 && $categories->first()->id === $canonical->id
                && $categories->first()->videos_count === 0;
        });
        $this->get(route('pages.category', $canonical->slug))
            ->assertViewHas('category', fn ($item) => $item->id === $canonical->id && $item->videos_count === 0);
    }

    private function category(string $title, int $position = 1): Category
    {
        return Category::query()->create([
            'title' => $title, 'slug' => str($title)->slug()->toString(),
            'icon' => 'folder', 'in_menu' => true, 'position' => $position,
        ]);
    }
}
