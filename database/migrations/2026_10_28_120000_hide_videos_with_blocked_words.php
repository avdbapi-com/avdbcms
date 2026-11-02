<?php

use App\Support\BlockedWordsPass;
use App\Support\TimeBudget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * AVDBCMS 1.2.3: the videos a site already has whose title, tags or category use a word that is always blocked (words
 * that present a child or a school-age minor, App\Support\ContentSafety::HARD) are banned with a stored reason ("Blocked
 * words (1.2.3)"), so visitors no longer see them anywhere; the admin reviews them in Content > Videos (Banned) and
 * Home says how many until the admin dismisses it (App\Support\BlockedWordsPass). Tags and categories named with such a
 * word are hidden from visitors by the code of 1.2.3 itself (nothing is deleted). The words of the site's own list only
 * keep new imports out: existing videos that use only those stay as they are.
 *
 * Two small new tables: the stored reasons of bans (video_ban_reasons) and the progress of the pass
 * (blocked_word_passes). The pass works in slices: within a request of Finish at most BlockedWordsPass::SECONDS and never
 * past its deadline (App\Support\TimeBudget; 200,000 videos take a few seconds), from the console without a limit of
 * its own; the scheduler goes on with what is left (videos:hide-blocked-words). Safe to run twice: the pass is noted
 * once and keeps its place, a video is banned once, and a video the admin unbanned is never banned again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(BlockedWordsPass::REASONS)) {
            Schema::create(BlockedWordsPass::REASONS, function (Blueprint $table): void {
                $table->unsignedBigInteger('video_id')->primary();
                $table->string('reason', 120);
                // the date of the ban the reason belongs to (videos.banned_at): an Unban, or a later ban of the admin's, ends it
                $table->timestamp('banned_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['reason', 'video_id']);
            });
        }

        if (! Schema::hasTable(BlockedWordsPass::PASSES)) {
            Schema::create(BlockedWordsPass::PASSES, function (Blueprint $table): void {
                $table->string('name', 32)->primary();
                $table->string('stage', 16)->default('tags');
                // the last tag (stage tags) or video (stage videos) looked at
                $table->unsignedBigInteger('next_id')->default(0);
                $table->longText('hard_tags')->nullable();
                $table->text('hard_categories')->nullable();
                $table->unsignedBigInteger('videos_checked')->default(0);
                $table->unsignedInteger('videos_hidden')->default(0);
                $table->unsignedInteger('tags_hidden')->default(0);
                $table->unsignedInteger('categories_hidden')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        BlockedWordsPass::forget();
        BlockedWordsPass::start();
        // A request of Finish: its share of the deadline. The console (php artisan migrate): the whole pass.
        BlockedWordsPass::run(TimeBudget::active() ? TimeBudget::cap(BlockedWordsPass::SECONDS) : 600.0);
    }

    public function down(): void
    {
        // Nothing: the bans stay (the admin unbans in Content > Videos) and so do their reasons and the pass's place, so
        // running this step again never bans a video the admin unbanned.
    }
};
