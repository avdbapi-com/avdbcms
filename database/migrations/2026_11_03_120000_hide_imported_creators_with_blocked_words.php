<?php

use App\Support\BlockedCreatorsPass;
use App\Support\BlockedWordsPass;
use App\Support\TimeBudget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * AVDBCMS 1.2.3 (refresh, N2-SAFE-01): the creator channels the AVDBAPI and XVIDAPI importers made from a studio,
 * director or actor name whose handle, name or description uses a word that is always blocked (words that present a
 * child or a school-age minor, App\Support\ContentSafety::HARD) are blocked with a stored reason ("Blocked words
 * (1.2.3)"), and their videos banned with one ("Blocked creator channel (1.2.3)"), so visitors no longer see them
 * anywhere; the admin reviews them in Members > Blocked and Home says how many until the admin dismisses it
 * (App\Support\BlockedCreatorsPass). Only the importers' accounts: a member's own account and the administrator's are
 * never read. The importers no longer make or reuse such a channel.
 *
 * One small new table, the stored reasons of members' blocks (user_ban_reasons, like video_ban_reasons), and two
 * columns of the passes table for this pass's counts. The pass works in slices: within a request of Finish at most
 * BlockedCreatorsPass::SECONDS and never past its deadline (App\Support\TimeBudget), from the console without a limit
 * of its own; the scheduler goes on with what is left (videos:hide-blocked-words). Safe to run twice: the pass is noted
 * once and keeps its place, a member is blocked once, and a member the admin unblocked is never blocked again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(BlockedCreatorsPass::REASONS)) {
            Schema::create(BlockedCreatorsPass::REASONS, function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->primary();
                $table->string('reason', 120);
                // the date of the block the reason belongs to (users.banned_at): an Unblock, or a later block of the admin's, ends it
                $table->timestamp('banned_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['reason', 'user_id']);
            });
        }

        if (Schema::hasTable(BlockedWordsPass::PASSES)) {
            Schema::table(BlockedWordsPass::PASSES, function (Blueprint $table): void {
                if (! Schema::hasColumn(BlockedWordsPass::PASSES, 'members_checked')) {
                    $table->unsignedBigInteger('members_checked')->default(0);
                }

                if (! Schema::hasColumn(BlockedWordsPass::PASSES, 'members_hidden')) {
                    $table->unsignedInteger('members_hidden')->default(0);
                }
            });
        }

        BlockedCreatorsPass::forget();
        BlockedCreatorsPass::start();
        // A request of Finish: its share of the deadline. The console (php artisan migrate): the whole pass.
        BlockedCreatorsPass::run(TimeBudget::active() ? TimeBudget::cap(BlockedCreatorsPass::SECONDS) : 600.0);
    }

    public function down(): void
    {
        // Nothing: the blocks stay (the admin unblocks in Members) and so do their reasons and the pass's place, so
        // running this step again never blocks a member the admin unblocked.
    }
};
