<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Laravel loads database/schema/<connection>-schema.sql before it runs the migrations of an empty database. The dump that
 * 1.2.0 to 1.2.2 shipped recorded 46 migrations whose files no longer exist and already had users.language, so the
 * installer's "Run migrations and seeders" failed on every MySQL/MariaDB site ("Duplicate column name 'language'").
 * 1.2.3 ships no dump. If one is added again, it must record exactly the migrations of the release.
 */
class SchemaDumpGuardTest extends TestCase
{
    public function test_a_shipped_schema_dump_records_exactly_the_migrations_of_the_release(): void
    {
        $migrations = collect(File::files(database_path('migrations')))
            ->map(fn ($file): string => $file->getFilenameWithoutExtension())
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($migrations);

        foreach (File::glob(database_path('schema/*-schema.sql')) ?: [] as $dump) {
            preg_match_all('/INSERT INTO [`"]?migrations[`"]?[^;]*;/s', File::get($dump), $inserts);
            preg_match_all("/'(\d{4}_\d{2}_\d{2}_\d{6}_[A-Za-z0-9_]+)'/", implode("\n", $inserts[0]), $names);
            $recorded = collect($names[1])->unique()->sort()->values()->all();

            $this->assertSame(
                $migrations,
                $recorded,
                basename($dump).' is out of step with database/migrations: delete it, or dump it again from a fully migrated database.'
            );
        }
    }
}
