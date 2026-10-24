<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Ad after every N" (Money > Ads) = N videos before each ad (1.2.2), on the server: the wide ad box that the
 * <x-videos-area> skeleton reserves, and the card ad of the channel page grids. The browser side (VideosList) runs
 * the same rule: tests/Unit/FeedAdSlotsTest.php.
 */
class ListAdPlacementTest extends TestCase
{
    use RefreshDatabase;

    private const AD_MARK = 'lane-f-test-ad';

    private function adsOn(array $extra = []): void
    {
        config(array_merge([
            'site.ads_enabled' => true,
            'site.ads_hide_for_premium' => true,
            'site.ad_grid_max_per_feed' => 1,
            'site.grid_columns' => 4,
            'site.mobile_video_columns' => 2,
        ], $extra));
    }

    /**
     * The number of skeleton cards before each reserved wide ad box, with the box's classes.
     *
     * @return list<array{after: int, class: string}>
     */
    private function reserveBoxes(string $html): array
    {
        $boxes = [];
        $offset = 0;

        while (preg_match('/<div class="([^"]*)" data-avd-ad-reserve>/', $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $position = $match[0][1];
            $boxes[] = [
                'after' => substr_count(substr($html, 0, $position), 'class="avd-video-card"'),
                'class' => $match[1][0],
            ];
            $offset = $position + strlen($match[0][0]);
        }

        return $boxes;
    }

    public function test_the_server_skeleton_reserves_the_wide_ad_row_after_the_row_of_video_n(): void
    {
        $this->adsOn([
            'site.ad_grid_position' => 8,
            'site.ad_code_grid_new_videos' => '<div class="'.self::AD_MARK.'">AD</div>',
            'site.ad_grid_layout_new_videos' => 'wide_row',
        ]);

        // 3 rows: 6 skeleton cards on phones and tablets (2 columns), 12 from 1200px (4 columns). The row after video 8
        // exists only where 12 cards show.
        $html = (string) $this->blade('<x-videos-area url="/api/videos?source=newest" columns="4" max-rows="3" ad-context="new_videos" early="false" />');

        $this->assertSame([['after' => 8, 'class' => 'col-12 avd-grid-ad-col avd-grid-ad-col--wide d-none d-xl-flex']], $this->reserveBoxes($html));
    }

    public function test_on_three_columns_the_wide_ad_row_follows_the_row_that_holds_video_n(): void
    {
        $this->adsOn([
            'site.ad_grid_position' => 8,
            'site.ad_code_grid_new_videos' => '<div class="'.self::AD_MARK.'">AD</div>',
            'site.ad_grid_layout_new_videos' => 'wide_row',
        ]);

        // No columns set: 2 columns on phones and tablets (8 skeleton cards), 3 at 1200-1399px (12 cards), 4 from
        // 1400px (16 cards). N = 8: after video 8 where a row holds 2 or 4 videos; on 3 columns after video 9, the end
        // of the row that holds video 8 (1.2.1: after video 6, so only 6 videos came first).
        $html = (string) $this->blade('<x-videos-area url="/api/videos?source=newest" ad-context="new_videos" early="false" />');

        $this->assertSame([
            ['after' => 8, 'class' => 'col-12 avd-grid-ad-col avd-grid-ad-col--wide d-xl-none d-xxl-flex'],
            ['after' => 9, 'class' => 'col-12 avd-grid-ad-col avd-grid-ad-col--wide d-none d-xl-flex d-xxl-none'],
        ], $this->reserveBoxes($html));
    }

    public function test_n_of_one_reserves_the_box_after_the_first_video_on_a_one_column_phone(): void
    {
        $this->adsOn([
            'site.ad_grid_position' => 1,
            'site.mobile_video_columns' => 1,
            'site.ad_code_grid_new_videos' => '<div class="'.self::AD_MARK.'">AD</div>',
            'site.ad_grid_layout_new_videos' => 'wide_row',
        ]);

        $html = (string) $this->blade('<x-videos-area url="/api/videos?source=newest" columns="4" max-rows="3" ad-context="new_videos" early="false" />');

        // Phones (one column): after video 1 (1.2.1 put it after video 2). Tablets (2 columns): after the first row.
        // From 1200px (4 columns): after the first row.
        $this->assertSame([
            ['after' => 1, 'class' => 'col-12 avd-grid-ad-col avd-grid-ad-col--wide d-md-none'],
            ['after' => 2, 'class' => 'col-12 avd-grid-ad-col avd-grid-ad-col--wide d-none d-md-flex d-xl-none'],
            ['after' => 4, 'class' => 'col-12 avd-grid-ad-col avd-grid-ad-col--wide d-none d-xl-flex'],
        ], $this->reserveBoxes($html));
    }

    public function test_card_ads_and_ads_off_reserve_no_wide_box(): void
    {
        $this->adsOn([
            'site.ad_grid_position' => 2,
            'site.ad_code_grid_new_videos' => '<div class="'.self::AD_MARK.'">AD</div>',
            'site.ad_grid_layout_new_videos' => 'grid_card',
        ]);

        $html = (string) $this->blade('<x-videos-area url="/api/videos?source=newest" columns="4" max-rows="3" ad-context="new_videos" early="false" />');
        $this->assertSame([], $this->reserveBoxes($html));

        config(['site.ads_enabled' => false, 'site.ad_grid_layout_new_videos' => 'wide_row']);
        $html = (string) $this->blade('<x-videos-area url="/api/videos?source=newest" columns="4" max-rows="3" ad-context="new_videos" early="false" />');
        $this->assertSame([], $this->reserveBoxes($html));
    }

    /**
     * The featured grid of a channel page: [videos shown, ads shown, videos before the ad].
     *
     * @return array{0: int, 1: int, 2: int|null}
     */
    private function featuredGrid(int $videoCount, int $adEvery, string $columns = '3', string $rows = '3'): array
    {
        $this->withoutVite();
        $this->adsOn([
            'site.ad_grid_position' => $adEvery,
            'site.ad_code_grid_channel_home_featured' => '<div class="'.self::AD_MARK.'">AD</div>',
            'site.ad_grid_layout_channel_home_featured' => 'grid_card',
            'site.channel_customization_enabled' => true,
            'site.channel_default_featured_mode' => 'latest',
            'site.channel_default_featured_columns' => $columns,
            'site.channel_default_featured_rows' => $rows,
            'site.channel_customization_latest_uploads_enabled' => false,
        ]);

        $user = User::factory()->create();

        foreach (range(1, $videoCount) as $index) {
            Video::factory()->withStatus(VideoStatus::PUBLIC)->create([
                'user_id' => $user->id,
                'title' => 'Lane F channel video '.$index,
                'published_at' => now()->subDays($index),
                'uploaded_at' => now()->subDays($index),
                'language' => null,
            ]);
        }

        $html = $this->get($user->route)->assertOk()->getContent();
        $start = strpos($html, 'avd-channel-featured-grid');
        $this->assertNotFalse($start, 'the channel page shows its featured grid');
        $grid = substr($html, $start, strpos($html, '</section>', $start) - $start);
        $adAt = strpos($grid, self::AD_MARK);

        return [
            substr_count($grid, '<article class="col avd-card-col"'),
            substr_count($grid, self::AD_MARK),
            $adAt === false ? null : substr_count(substr($grid, 0, $adAt), '<article class="col avd-card-col"'),
        ];
    }

    public function test_a_channel_grid_shows_n_videos_then_the_card_ad(): void
    {
        // 3 x 3 grid, N = 8, 12 videos: 8 videos, then the ad in the 9th place.
        $this->assertSame([8, 1, 8], $this->featuredGrid(12, 8));
    }

    public function test_a_channel_grid_without_room_after_video_n_shows_every_place_as_a_video(): void
    {
        // 3 x 3 grid, N = 9: no room after video 9. 1.2.1 kept the 9th place empty and never showed the ad.
        $this->assertSame([9, 0, null], $this->featuredGrid(12, 9));
    }

    public function test_a_channel_with_n_videos_shows_no_card_ad(): void
    {
        // Only 8 videos with N = 8: nothing would follow the ad.
        $this->assertSame([8, 0, null], $this->featuredGrid(8, 8));
    }
}
