<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Support\SpotlightChips;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The "Trending Searches" chips of the Ctrl+K spotlight run on every page: their check is bounded and cached (a live
 * site runs it once an hour per locale), and a chip only shows when its search finds a public video.
 */
class SpotlightChipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chip_check_runs_a_bounded_number_of_queries_once_per_hour(): void
    {
        $this->createVideo('Japanese Morning Walk');
        Cache::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();
        SpotlightChips::all();
        $cold = count(DB::getQueryLog());

        DB::flushQueryLog();
        SpotlightChips::all();
        $warm = count(DB::getQueryLog());
        DB::disableQueryLog();

        // per chip a tag lookup and the search (MySQL/MariaDB: plus a full-text probe, and the index list once)
        $this->assertLessThanOrEqual(12, $cold, 'The Ctrl+K chip check runs more queries than its three chips need.');
        $this->assertSame(0, $warm, 'The Ctrl+K chip check must come from the cache after the first page.');
    }

    public function test_a_chip_is_listed_only_when_its_search_finds_a_public_video(): void
    {
        Cache::flush();
        $this->assertSame([], SpotlightChips::all());

        // The chip's search finds videos by tag, the same way on every database (MySQL's full-text index does not see
        // rows a test has not committed, so a title match would only work on SQLite here).
        $japanese = Tag::query()->create(['name' => 'Japanese', 'slug' => 'japanese', 'usage_count' => 0]);

        $this->createVideo('Private Clip', VideoStatus::PRIVATE)->tags()->attach($japanese->id);
        Cache::flush();
        $this->assertSame([], SpotlightChips::all());

        $this->createVideo('Morning Walk')->tags()->attach($japanese->id);
        Cache::flush();
        $this->assertSame(['Japanese'], array_column(SpotlightChips::all(), 'label'));
    }

    private function createVideo(string $title, VideoStatus $status = VideoStatus::PUBLIC): Video
    {
        return Video::factory()
            ->for(User::factory(), 'user')
            ->withStatus($status)
            ->create([
                'title' => $title,
                'slug' => str($title)->slug()->toString(),
                // a fixed text: the factory's random one can name a chip ("English")
                'description' => 'A short walk through the park.',
                'published_at' => now()->subDay(),
                'uploaded_at' => now()->subDay(),
                'language' => null,
            ]);
    }
}
