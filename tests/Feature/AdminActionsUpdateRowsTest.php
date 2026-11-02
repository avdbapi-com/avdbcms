<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AuditDetails;
use App\Support\DatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Tools > Logs > Admin actions, the rows of an update (round 3):
 *
 *  - R3-031: the "database backed up" row names the backup the way Tools > Backups lists it now. The updater of 1.2.0 to
 *    1.2.2 wrote the file's name of then, and the update to 1.2.3 gave the file its random part afterwards (StorageHider);
 *    1.2.3's own rows keep the name without it (DatabaseBackup::shownName(): the update's records outlive it). The row
 *    is shown with the name of the file that is there; a view-only account (Tools > Backups is closed to it) and a
 *    backup that was deleted keep the name as written.
 *  - R3-028: the texts row says what its count is: the owner's own wording of texts this version corrects ("0 own texts
 *    kept" read as if the owner's texts were lost); none, nothing is said.
 */
class AdminActionsUpdateRowsTest extends TestCase
{
    use RefreshDatabase;

    private const SHOWN = 'backup_before_update_1.2.3_2026-10-28_195014.sql.gz';

    private const LISTED = 'backup_before_update_1.2.3_2026-10-28_195014_9917fc92c1d0.sql.gz';

    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    public function test_the_database_backup_row_names_the_file_as_tools_backups_lists_it(): void
    {
        $details = self::SHOWN.' (12.30 MB, mysqldump)';
        File::ensureDirectoryExists(storage_path('app/backups'));
        $this->file = storage_path('app/backups/'.self::LISTED);
        File::put($this->file, str_repeat('x', 200));
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin);
        $this->assertSame(self::LISTED.' (12.30 MB, mysqldump)', AuditDetails::text('update.database_backup', $details));
        $this->assertSame('<bdi dir="ltr">'.self::LISTED.' (12.30 MB, mysqldump)</bdi>', (string) AuditDetails::html('update.database_backup', $details));
        $this->assertSame(self::LISTED, DatabaseBackup::listedName(self::SHOWN), 'the same file Tools > Updates names');

        // The Admin actions page itself.
        DB::table('admin_audit_logs')->insert(['user_id' => $admin->id, 'action' => 'update.database_backup', 'details' => $details, 'created_at' => now()]);
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
        $html = $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk()->getContent();
        $this->assertStringContainsString(self::LISTED, $html);

        // A view-only account sees the name as written (no random part).
        $viewOnly = User::factory()->admin()->create(['is_view_only' => true]);
        $this->actingAs($viewOnly);
        $this->assertSame($details, AuditDetails::text('update.database_backup', $details));

        // A backup deleted since: the name as written.
        $this->actingAs($admin);
        @unlink($this->file);
        $this->assertSame($details, AuditDetails::text('update.database_backup', $details));
    }

    public function test_the_texts_row_says_what_its_count_is_and_nothing_when_there_is_none(): void
    {
        // Rows of 1.2.3 release candidates ("N own texts kept").
        $this->assertSame('lang/de.json (Deutsch): 324 texts corrected to the wording of this version',
            AuditDetails::text('update.texts', 'lang/de.json (Deutsch): 324 texts corrected to the wording of this version, 0 own texts kept'));
        $this->assertSame('lang/de.json (Deutsch): 2 texts corrected to the wording of this version, your own wording kept for 1 text this version corrects',
            AuditDetails::text('update.texts', 'lang/de.json (Deutsch): 2 texts corrected to the wording of this version, 1 own text kept'));

        // Rows of this version (Updater::textRows()), with the texts this version no longer uses (judge R3-026).
        $this->assertSame('lang/de.json (Deutsch): 324 texts corrected to the wording of this version, your own wording kept for 2 texts this version corrects, 73 texts this version no longer uses removed',
            AuditDetails::text('update.texts', 'lang/de.json (Deutsch): 324 texts corrected to the wording of this version, your own wording kept for 2 texts this version corrects, 73 texts this version no longer uses removed'));
        $this->assertStringNotContainsString('of your own texts kept', AuditDetails::text('update.texts', 'lang/de.json (Deutsch): 1 text corrected to the wording of this version, 0 own texts kept'));
    }
}
