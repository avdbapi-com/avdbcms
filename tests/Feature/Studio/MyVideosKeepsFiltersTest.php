<?php

namespace Tests\Feature\Studio;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Round 3 fix pass, R2R-12: My videos comes back with its tab, filters, sort and page after Delete, bulk Delete and bulk
 * visibility, also for a member who reads the site in another language (the list's address is /vi/profile/videos?...),
 * on a site whose links carry /index.php, and on a site installed in a folder (https://example.test/site). The list's
 * address was compared with route('user.videos.index')'s path ("/profile/videos") as it was: in any other language than
 * the site's own the list came back without them (already in 1.2.3 RC1).
 */
class MyVideosKeepsFiltersTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'status=public&sort=oldest&page=2&search=sunset&category=3&type=short&date=week&from=2026-10-01&to=2026-10-29';

    private const KEPT = ['status' => 'public', 'sort' => 'oldest', 'page' => '2', 'search' => 'sunset', 'category' => '3', 'type' => 'short',
        'date' => 'week', 'from' => '2026-10-01', 'to' => '2026-10-29'];

    protected function setUp(): void
    {
        parent::setUp();

        // a deleted video takes its files with it: never the copy's real disks
        foreach ([null, 'public', 'thumbnails', 'videos'] as $disk) {
            Storage::fake($disk);
        }
    }

    protected function tearDown(): void
    {
        URL::forceRootUrl(null);
        app('url')->formatPathUsing(fn ($path) => $path);

        parent::tearDown();
    }

    /** @return array<string, array{0: string}> the list's address as the member's browser had it (its Referer) */
    public static function listAddresses(): array
    {
        return [
            'the site\'s own language' => ['/profile/videos'],
            'Vietnamese' => ['/vi/profile/videos'],
            'Arabic' => ['/ar/profile/videos'],
            'French, through /index.php' => ['/index.php/fr/profile/videos'],
            'through /index.php' => ['/index.php/profile/videos'],
        ];
    }

    #[DataProvider('listAddresses')]
    public function test_delete_keeps_the_lists_filters_in_every_language(string $list): void
    {
        $member = User::factory()->create();
        $video = $this->video($member);

        $response = $this->actingAs($member)
            ->from(url($list).'?'.self::QUERY)
            ->delete(route('user.videos.destroy', $video));

        $this->assertKept($response->headers->get('Location'), $list);
        $this->assertNull(Video::find($video->id));
    }

    #[DataProvider('listAddresses')]
    public function test_bulk_delete_and_bulk_visibility_keep_the_lists_filters_in_every_language(string $list): void
    {
        $member = User::factory()->create();
        $one = $this->video($member);
        $two = $this->video($member);

        $visibility = $this->actingAs($member)
            ->from(url($list).'?'.self::QUERY)
            ->post(route('user.videos.mass-visibility'), ['ids' => [$one->id], 'visibility' => 'private']);
        $this->assertKept($visibility->headers->get('Location'), $list);
        $this->assertSame(VideoStatus::PRIVATE, $one->fresh()->status);

        $delete = $this->actingAs($member)
            ->from(url($list).'?'.self::QUERY)
            ->delete(route('user.videos.mass-delete'), ['ids' => [$two->id]]);
        $this->assertKept($delete->headers->get('Location'), $list);
        $this->assertNull(Video::find($two->id));
    }

    public function test_in_a_sub_folder_the_list_keeps_its_filters_too(): void
    {
        config(['app.url' => 'https://example.test/site']);
        URL::forceRootUrl('https://example.test/site');
        URL::forceScheme('https');
        $member = User::factory()->create();
        $video = $this->video($member);

        foreach (['https://example.test/site/vi/profile/videos', 'https://example.test/site/index.php/de/profile/videos', 'https://example.test/site/profile/videos'] as $list) {
            // the request as public/index.php hands it over in the folder: Laravel's base address is /site
            $response = $this->withServerVariables(['SCRIPT_NAME' => '/site/index.php', 'PHP_SELF' => '/site/index.php', 'SCRIPT_FILENAME' => public_path('index.php')])
                ->actingAs($member)
                ->from($list.'?'.self::QUERY)
                ->post(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'unlisted']);
            $location = (string) $response->headers->get('Location');

            $this->assertStringStartsWith('https://example.test/site/profile/videos?', $location, $list);
            parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
            $this->assertSame(self::KEPT, array_intersect_key($query, self::KEPT), $list);
        }
    }

    public function test_another_page_or_another_site_gives_the_plain_list(): void
    {
        $member = User::factory()->create();
        $video = $this->video($member);

        foreach (['/vi/profile/videos/'.$video->id.'/edit?'.self::QUERY, 'https://elsewhere.example/profile/videos?'.self::QUERY, '/vi/library?'.self::QUERY] as $from) {
            $response = $this->actingAs($member)
                ->from(str_starts_with($from, 'http') ? $from : url($from))
                ->post(route('user.videos.mass-visibility'), ['ids' => [$video->id], 'visibility' => 'private']);

            $this->assertSame(route('user.videos.index'), $response->headers->get('Location'), $from);
        }
    }

    private function assertKept(?string $location, string $list): void
    {
        $this->assertNotNull($location);
        $this->assertSame(parse_url(route('user.videos.index'), PHP_URL_PATH), parse_url((string) $location, PHP_URL_PATH), $list);
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->assertSame(self::KEPT, array_intersect_key($query, self::KEPT), $list.': the tab, filters, sort and page are kept');
    }

    private function video(User $owner): Video
    {
        return Video::factory()->for($owner, 'user')->create([
            'status' => VideoStatus::PUBLIC->value,
            'category_id' => null,
            'language' => null,
            'published_at' => now()->subDays(3),
            'uploaded_at' => now()->subDays(3),
        ]);
    }
}
