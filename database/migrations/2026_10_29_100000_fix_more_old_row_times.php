<?php

use App\Support\LegacyRowTimes;
use Illuminate\Database\Migrations\Migration;

/*
 * AVDBCMS 1.2.3: the activities of likes and dislikes and the "Date added" of playlists that 1.2.0 to 1.2.2 dated with
 * the database server's own clock join the conversion of 2026_10_27_000000_fix_old_row_times (App\Support\LegacyRowTimes).
 * That step notes every column on a site updated from 1.2.0, 1.2.1 or 1.2.2, and this one then does nothing; a site whose
 * update to an earlier build of 1.2.3 noted the views, subscriptions and saved playlists only gets the two new columns
 * noted here. Only notes (a few reads of the highest id), so quick on any site; safe to run twice (a noted column is
 * never noted again).
 */
return new class extends Migration
{
    public function up(): void
    {
        LegacyRowTimes::noteMissing();
    }

    public function down(): void
    {
        // Nothing: the rows put right stay right, and the notes stay (see 2026_10_27_000000_fix_old_row_times), so running
        // this step again never takes rows the site's clock wrote since.
    }
};
