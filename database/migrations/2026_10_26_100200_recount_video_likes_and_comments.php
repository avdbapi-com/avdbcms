<?php

use App\Support\EngagementCounters;
use App\Support\TimeBudget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * AVDBCMS 1.2.3: the like, dislike and comment counts each video keeps had drifted (Admin > Likes deletes, hidden
 * comments, removed accounts; the 1.2.0 counts also took in hidden and deleted comments). Every video is counted again
 * (App\Support\EngagementCounters): up to SECONDS here, and never past the deadline of the request of Finish
 * (App\Support\TimeBudget: a strict host ended Finish when this ran on top of the other database changes), so most
 * sites are right when the update finishes, and the scheduler carries the rest on a few minutes later
 * (videos:recount-engagement). Tools > Logs > Admin actions says what was counted: one row here when the scheduler goes
 * on, and one with the totals when the pass ends (EngagementCounters::AUDIT_ACTION). The 2026_09_12 migration that
 * added the counts stays as it was. Safe to run twice.
 */
return new class extends Migration
{
    public const SECONDS = 10.0;

    public function up(): void
    {
        if (! EngagementCounters::available() || ! Schema::hasTable('interactions') || ! Schema::hasTable('comments')) {
            return;
        }

        EngagementCounters::request(true);
        $result = EngagementCounters::run(TimeBudget::cap(self::SECONDS));

        // A finished pass wrote its totals itself; one the scheduler goes on with says how far it came.
        if (! $result['done'] && $result['checked'] > 0) {
            EngagementCounters::audit(sprintf('1.2.3: likes, dislikes and comments counted again for %s so far (%s put right); the cron job goes on',
                EngagementCounters::videos($result['checked']), number_format($result['corrected'])));
        }
    }

    public function down(): void
    {
        // Nothing to undo: the counts are right.
    }
};
