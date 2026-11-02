<?php

use App\Support\LegacyRowTimes;
use Illuminate\Database\Migrations\Migration;

/*
 * AVDBCMS 1.2.3: the history, subscription and saved-playlist rows that 1.2.0 to 1.2.2 dated with the database server's
 * own clock (the columns' CURRENT_TIMESTAMP default: the database's time zone) are put in the site's time zone, like
 * every row 1.2.3 writes (App\Support\LegacyRowTimes). This step only notes which rows those are: three reads of the
 * highest id and a small table (legacy_time_fixes), so it is quick on any site; the scheduler rewrites the rows in short
 * slices a minute later (history:fix-old-times), and a row is never shifted twice. Safe to run twice: the rows are noted
 * once.
 */
return new class extends Migration
{
    public function up(): void
    {
        LegacyRowTimes::start();
    }

    public function down(): void
    {
        // Nothing: the rows put right stay right (older versions read them as the site's time too), and the notes stay,
        // so running this step again never takes rows the site's clock wrote (they would be shifted twice).
    }
};
