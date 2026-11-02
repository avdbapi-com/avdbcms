<?php

namespace Tests\Feature\Studio;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Support\Studio\ViewsSeries;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 1.2.3 (member design 6.5; studio-13): the Analytics series. Before, the labels stopped one or two days before the
 * data (today was never drawn), a video uploaded today had no column at all, and the chart started at the upload
 * whatever the period. The series now ends TODAY (a partial column), starts at the later of the period's first day
 * and the publication day, and says when the daily figures start after the video.
 */
class ViewsSeriesTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-10-25 15:30:00', config('app.timezone'));
        $this->travelTo($this->now);
    }

    public function test_the_series_ends_today_with_a_partial_column_and_starts_at_the_publication_day(): void
    {
        $video = $this->video($this->now->subDays(20)->setTime(18, 0), 1000);
        $this->stats($video, $this->now->subDays(20), [100, 200, 300]);
        $this->stats($video, $this->now, [40]);

        $series = ViewsSeries::build($video, '28', $this->now);

        $this->assertCount(21, $series['buckets'], '20 days ago .. today: 21 columns');
        $this->assertTrue($series['startsAtPublish']);
        $this->assertSame($this->now->subDays(20)->toDateString(), $series['buckets'][0]['key']);
        $last = end($series['buckets']);
        $this->assertSame($this->now->toDateString(), $last['key'], 'today is drawn');
        $this->assertTrue($last['partial'], 'today is "so far"');
        $this->assertSame(40, $last['views']);
        $this->assertSame(640, $series['total']);
    }

    public function test_a_video_uploaded_today_has_one_column(): void
    {
        $video = $this->video($this->now->setTime(9, 4), 42);
        $this->stats($video, $this->now, [42]);

        $series = ViewsSeries::build($video, '28', $this->now);

        $this->assertCount(1, $series['buckets']);
        $this->assertSame(42, $series['buckets'][0]['views']);
        $this->assertNull($series['note'], 'the daily figures hold every view');
    }

    public function test_a_period_shorter_than_the_videos_age_starts_at_the_period(): void
    {
        $video = $this->video($this->now->subDays(60), 500);

        $series = ViewsSeries::build($video, '7', $this->now);

        $this->assertCount(7, $series['buckets']);
        $this->assertFalse($series['startsAtPublish']);
        $this->assertSame($this->now->subDays(6)->toDateString(), $series['buckets'][0]['key']);
    }

    public function test_daily_figures_that_start_after_the_video_are_explained(): void
    {
        $video = $this->video($this->now->subDays(40), 900);
        $this->stats($video, $this->now->subDays(5), [10, 10, 10, 10, 10, 10]);

        $series = ViewsSeries::build($video, '28', $this->now);

        $this->assertSame(60, $series['dailySum']);
        $this->assertNotNull($series['note']);
        $this->assertStringStartsWith('Daily figures start on', $series['note']);

        $none = ViewsSeries::build($this->video($this->now->subDays(3), 12), '28', $this->now);
        $this->assertSame('There are no daily figures for this video yet. The total above counts every view.', $none['note']);
    }

    public function test_a_long_life_is_drawn_in_weeks_never_more_than_90_columns(): void
    {
        $video = $this->video($this->now->subDays(400), 5000);

        $series = ViewsSeries::build($video, 'all', $this->now);

        $this->assertSame('week', $series['unit']);
        $this->assertLessThanOrEqual(ViewsSeries::MAX_BUCKETS, count($series['buckets']));
    }

    public function test_the_scale_has_a_round_top_and_labels_that_fit_the_columns(): void
    {
        $video = $this->video($this->now->subDays(20), 14260);
        $this->stats($video, $this->now->subDays(20), [160, 1810, 1340, 910, 980, 760, 690, 640, 720, 700, 650, 590, 560, 610, 540, 520, 480, 500, 460, 420, 220]);

        $series = ViewsSeries::build($video, '28', $this->now);

        $this->assertSame(2000, $series['top']);
        $this->assertSame([0, 500, 1000, 1500, 2000], $series['ticks']);
        $this->assertGreaterThanOrEqual(3, count($series['labels']));
        $this->assertLessThanOrEqual(6, count($series['labels']));
    }

    private function video(CarbonImmutable $published, int $views): Video
    {
        return Video::factory()->for(User::factory()->create(), 'user')->create([
            'status' => VideoStatus::PUBLIC->value,
            'category_id' => null,
            'language' => null,
            'views' => $views,
            'published_at' => $published,
            'uploaded_at' => $published,
            'created_at' => $published,
        ]);
    }

    /** @param list<int> $views one per day from $from */
    private function stats(Video $video, CarbonImmutable $from, array $views): void
    {
        foreach ($views as $i => $count) {
            DB::table('video_view_daily_stats')->insert([
                'video_id' => $video->id,
                'view_date' => $from->addDays($i)->toDateString(),
                'views_count' => $count,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
