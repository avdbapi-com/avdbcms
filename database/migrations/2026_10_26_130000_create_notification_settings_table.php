<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification settings (member design 6.14):
 *  - notification_settings: one row per member who changed a switch of Account settings > Notifications (New
 *    subscribers, Comments on my videos, Replies to my comments, New videos from channels I follow). No row: every
 *    switch is on, as before 1.2.3.
 *  - subscriptions.notify: the bell of each Subscribe button, per channel: 'all', 'highlights' (the channel's videos,
 *    not its Shorts) or 'none'; NULL (never chosen) follows the member's "New videos from channels I follow". A choice
 *    made on the bell wins over that switch.
 * Both are added only when missing (a site that ran a test build keeps its data), and removed on rollback.
 * A member's row goes with the account: a foreign key with ON DELETE CASCADE (like playback_progress_signals), and
 * NotificationSetting::forget() from the account's own clean-up (NotificationTasks::forgetMember).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_settings')) {
            Schema::create('notification_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->boolean('new_subscribers')->default(true);
                $table->boolean('comments')->default(true);
                $table->boolean('replies')->default(true);
                $table->boolean('new_videos')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('subscriptions') && ! Schema::hasColumn('subscriptions', 'notify')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->string('notify', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subscriptions') && Schema::hasColumn('subscriptions', 'notify')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('notify');
            });
        }

        Schema::dropIfExists('notification_settings');
    }
};
