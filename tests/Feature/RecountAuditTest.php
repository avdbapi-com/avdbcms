<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Support\EngagementCounters;
use App\Support\TimeBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 1 of 1.2.3 (R1-097, with R1-002): the update's recount of likes, dislikes and comments (migration
 * 2026_10_26_100200) keeps its numbers in Tools > Logs > Admin actions like every other change of the update: one row
 * when the cron job goes on with it, one with the totals when the pass ends (there or in the scheduler). It stops at the
 * deadline of the request of Finish (it ran its own 10 seconds on top of the other database changes before).
 */
class RecountAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    protected function tearDown(): void
    {
        TimeBudget::clear();

        parent::tearDown();
    }

    public function test_the_recount_of_the_update_writes_how_far_it_came_and_then_its_totals(): void
    {
        $this->creator = User::factory()->create();
        $ids = $this->bareVideos(1200);
        // What older versions left: two wrong counts in the first step of 500 videos, one in the last.
        DB::table('videos')->whereIn('id', [$ids[0], $ids[1], $ids[1100]])->update(['likes_count' => 5]);

        // A request of Finish with hardly any time left: the migration counts one step, the cron job goes on.
        TimeBudget::until(microtime(true) + 0.5);
        $this->migration()->up();
        TimeBudget::clear();

        $this->assertSame(['1.2.3: likes, dislikes and comments counted again for 500 videos so far (2 put right); the cron job goes on'], $this->rows());
        $this->assertSame(5, (int) DB::table('videos')->where('id', $ids[1100])->value('likes_count'), 'not reached yet');

        $this->artisan('videos:recount-engagement', ['--seconds' => 5])->assertExitCode(0);

        $this->assertSame([
            '1.2.3: likes, dislikes and comments counted again for 500 videos so far (2 put right); the cron job goes on',
            '1.2.3: likes, dislikes and comments of every video counted again: 1,200 videos checked, 3 put right',
        ], $this->rows());
        $this->assertSame(0, (int) DB::table('videos')->where('id', $ids[1100])->value('likes_count'));

        // The daily passes of the scheduler write nothing.
        EngagementCounters::request();
        EngagementCounters::run(10.0);
        $this->assertCount(2, $this->rows());

        foreach ($this->rows() as $row) {
            $this->assertLessThanOrEqual(120, mb_strlen($row), 'the admin lists show 120 characters');
        }
    }

    public function test_a_site_counted_within_the_migration_gets_one_row_with_the_totals(): void
    {
        $this->creator = User::factory()->create();
        $ids = $this->bareVideos(30);
        DB::table('videos')->where('id', $ids[3])->update(['comments_count' => 4]);

        $this->migration()->up();

        $this->assertSame(['1.2.3: likes, dislikes and comments of every video counted again: 30 videos checked, 1 put right'], $this->rows());
    }

    public function test_a_site_being_installed_gets_no_row(): void
    {
        $this->migration()->up();

        $this->assertSame([], $this->rows());
    }

    /** @return list<string> */
    private function rows(): array
    {
        return DB::table('admin_audit_logs')->where('action', EngagementCounters::AUDIT_ACTION)->orderBy('id')->pluck('details')->all();
    }

    /** @return list<int> ids of $count plain video rows (fast: no factory) */
    private function bareVideos(int $count): array
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['uuid' => (string) Str::uuid(), 'title' => 'Counted '.$i, 'slug' => 'counted-'.$i, 'original_file_name' => 'counted.mp4',
                'user_id' => $this->creator->id, 'status' => VideoStatus::PUBLIC->value, 'created_at' => now(), 'updated_at' => now()->subDay()];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('videos')->insert($chunk);
        }

        return DB::table('videos')->where('title', 'like', 'Counted %')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_26_100200_recount_video_likes_and_comments.php');
    }
}
