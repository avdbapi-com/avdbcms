<?php

use App\Support\Upload18\BundledKey;
use Illuminate\Database\Migrations\Migration;

/**
 * The Upload18 key that AVDBCMS 1.2.0 to 1.2.2 shipped in database/install/example.sql no longer works (Upload18
 * regenerated it on 25 October 2026). A site that still has exactly that key gets the key the 1.2.3 package ships (a
 * separate restricted "AVDBCMS public download" key: Upload + Read only; browsers only get upload tokens made with
 * it), read from the package while this runs, so no key is written in code (App\Support\Upload18\BundledKey). Any other
 * value is the owner's and is never touched; an empty field stays empty. Logged in Tools > Logs > Admin actions. Safe to
 * run again (the stored key is no longer the retired one then) and on 1.2.0, 1.2.1 and 1.2.2 databases.
 */
return new class extends Migration
{
    public function up(): void
    {
        BundledKey::replaceRetired();
    }

    public function down(): void
    {
        // Nothing: putting the retired key back would stop every upload of the site.
    }
};
