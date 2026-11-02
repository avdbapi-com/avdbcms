<?php

use App\Notifications\Support\NotificationIndexes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The notifications page's "Unread" tab at any size (1.2.3, judge R1-008): the index notifications_unread_created_id_idx
 * (notifiable_type, notifiable_id, read_at, created_at, id) makes each of its pages one short range read.
 *
 * Building it reads the whole notifications table, so it is built here only on a table of up to
 * NotificationIndexes::$buildNowRows rows (fresh installs and most sites: well under a second). On a bigger table this
 * migration only asks for it and "Finish update" goes on at once: the scheduler builds it in the background a minute
 * later (notifications:index, online on MySQL / MariaDB: the site keeps reading and writing the table), and the Unread
 * tab works without it meanwhile (App\Notifications\Support\UnreadKeys).
 * Safe to run twice; down() removes only this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        NotificationIndexes::forget();

        if (NotificationIndexes::missing([NotificationIndexes::UNREAD_LIST]) === [] || ! NotificationIndexes::smallEnough()) {
            return; // there already, or a big table: the scheduler builds it
        }

        // a small table: at once (online where the server can, else the plain way, which is short here)
        NotificationIndexes::build([NotificationIndexes::UNREAD_LIST], plainOnBigTable: true);
    }

    public function down(): void
    {
        NotificationIndexes::drop([NotificationIndexes::UNREAD_LIST]);
    }
};
