<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * AVDBCMS 1.2.3: the publish dates the lists order and page by. A scheduled video without one gets its planned time, as
 * the studio stores it (video:published announces it at that time). A public video without one was never published: up
 * to 1.2.2 Admin > Videos > Unban (one or in bulk) and the bulk bar's Make public made a video public without a date, a
 * draft or a private video too. It stays as it is: any date given here would move it up Newest (the home page's main
 * feed), with its upload date to the top. The lists show it after the dated videos (App\Support\SafeCursor), and Tools >
 * Logs > Admin actions and Content > All videos tell the owner, who publishes it (Make public) or hides it (Make
 * private). A scheduled video without a planned time (1.2.2's Visibility field saved such) keeps no date either: it gets
 * one with its planned time (App\Observers\VideoObserver). A batch of ids at a time, so a big videos table is never held
 * long; safe to run twice and on 1.2.0 - 1.2.2.
 */
return new class extends Migration
{
    /** VideoStatus::PUBLIC and VideoStatus::PLANNED (the numbers, as the table stores them). */
    private const PUBLIC = 0;

    private const PLANNED = 2;

    public const BATCH = 1000;

    public function up(): void
    {
        if (! Schema::hasTable('videos') || ! Schema::hasColumns('videos', ['id', 'status', 'published_at', 'scheduled_at', 'uploaded_at'])) {
            return;
        }

        $dated = 0;
        $after = 0;

        do {
            // found through the (status, published_at, id) index
            $ids = DB::table('videos')
                ->where('status', self::PLANNED)
                ->whereNull('published_at')
                ->whereNotNull('scheduled_at')
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $after = (int) end($ids);
            $dated += DB::table('videos')->whereIn('id', $ids)->where('status', self::PLANNED)->whereNull('published_at')->whereNotNull('scheduled_at')
                ->update(['published_at' => DB::raw('scheduled_at')]);
        } while (count($ids) === self::BATCH);

        // the public videos visitors can see that were never published (what Video::neverPublished() selects)
        $never = DB::table('videos')
            ->where('status', self::PUBLIC)
            ->whereNull('published_at')
            ->whereNotNull('uploaded_at')
            ->when(Schema::hasColumn('videos', 'deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->count();

        $this->log('update.publish_dates', $dated,
            '1.2.3: 1 scheduled video got its planned time as its publish date',
            '1.2.3: %d scheduled videos got their planned time as their publish date');
        $this->log('update.never_published', $never,
            '1.2.3: 1 public video was never published (no publish date): Newest lists it last. See Content > All videos.',
            '1.2.3: %d public videos were never published (no publish date): Newest lists them last. See Content > All videos.');
    }

    public function down(): void
    {
        // Nothing to undo: the planned times are the right dates, and nothing else changed.
    }

    /**
     * One line in Tools > Logs > Admin actions (sites log only errors to laravel.log; the lists show 120 characters), in
     * English like the admin's own records; a line that is there already is not written again.
     */
    private function log(string $action, int $count, string $one, string $many): void
    {
        if ($count === 0) {
            return;
        }

        $details = $count === 1 ? $one : sprintf($many, $count);

        try {
            if (Schema::hasTable('admin_audit_logs') && ! DB::table('admin_audit_logs')->where('action', $action)->where('details', $details)->exists()) {
                DB::table('admin_audit_logs')->insert([
                    'user_id' => null,
                    'action' => $action,
                    'details' => $details,
                    'ip_address' => null,
                    'user_agent' => null,
                    'created_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::notice('AVDBCMS '.$details, ['log' => $e->getMessage()]);
        }
    }
};
