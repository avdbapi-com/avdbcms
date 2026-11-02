<?php

use App\Support\StorageHider;
use Illuminate\Database\Migrations\Migration;

/**
 * Security plan S03 + S24, once per site ("Finish update"; harmless on a new site, safe to run again): the files under
 * storage/ that carry secrets get names nobody can guess, in case a server ever serves storage/. What moves and how is
 * App\Support\StorageHider (logs, the old file-cache folders, database backups, members exports, invoice PDFs, the
 * install lock's e-mail address, copies of .env, the mode of .env). Every step is best effort and logged; the one-click
 * update's Finish and the daily storage check run the same again for files an older release writes after an Undo.
 *
 * It only renames and never deletes, so it is quick also on a busy site: the expired entries of the old file cache
 * (tens of thousands on a 1.2.2 site) move with their folders into storage/framework/cache/stale-<random>, which the
 * updater's clean-up deletes later in short slices (Tools > Updates while it is open, the scheduler's updates:tidy).
 * Within a request of Finish it stops in time (App\Support\TimeBudget); what it had no time for is done by the next
 * run. down() changes nothing: the new names keep working with an older release (it only lists and reads the folders).
 */
return new class extends Migration
{
    public function up(): void
    {
        // The test suite migrates a fresh database before every test: the files of the folder it runs in are not a
        // site's to move (StorageHiderTest runs the same on a folder of its own).
        if (app()->runningUnitTests()) {
            return;
        }

        StorageHider::run();
    }

    public function down(): void
    {
        // Nothing to undo.
    }
};
