<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * AVDBCMS 1.2.3: a ban keeps the status the video had (videos.status_before_ban) with the date of that ban
 * (status_before_ban_at, the same as banned_at), so Unban gives it back. Before, Unban made every video public, a private
 * video or a draft too (App\Support\VideoBans). The date ties the status to its ban: after an Undo of the update (files
 * only, the database stays) an older version bans and unbans without either column, and the status an earlier ban kept
 * must not come back. Empty for the videos banned before this update: they come back Private (VideoBans::LEGACY_STATUS).
 * Safe to run twice and on 1.2.0 - 1.2.2 databases.
 */
return new class extends Migration
{
    /** The columns and their MySQL / MariaDB types (what unsignedTinyInteger() / timestamp() ->nullable() write). */
    private const COLUMNS = ['status_before_ban' => 'tinyint unsigned null', 'status_before_ban_at' => 'timestamp null'];

    public function up(): void
    {
        if (! Schema::hasTable('videos')) {
            return;
        }

        $missing = array_values(array_filter(array_keys(self::COLUMNS), fn (string $column): bool => ! Schema::hasColumn('videos', $column)));

        if ($missing === []) {
            return;
        }

        // Added last (no ->after()): MySQL 8 and MariaDB 10.3+ add them without copying the videos table. On MySQL /
        // MariaDB in one statement, so MySQL 5.7 copies a big table once, not once per column.
        $connection = DB::connection();

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $grammar = $connection->getQueryGrammar();
            $connection->statement('alter table '.$grammar->wrapTable('videos').' '.implode(', ', array_map(
                fn (string $column): string => 'add '.$grammar->wrap($column).' '.self::COLUMNS[$column],
                $missing,
            )));

            return;
        }

        Schema::table('videos', function (Blueprint $table) use ($missing): void {
            if (in_array('status_before_ban', $missing, true)) {
                $table->unsignedTinyInteger('status_before_ban')->nullable();
            }

            if (in_array('status_before_ban_at', $missing, true)) {
                $table->timestamp('status_before_ban_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: older versions never read the columns, and dropping them would lose the earlier status of
        // every banned video (they would all come back Private).
    }
};
