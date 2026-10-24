<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\MaintenanceHook;
use App\Support\Updater\Updater;
use Tests\TestCase;

/**
 * What Tools > Updates says about a plan that ended without its result (F8 13): an undo that failed or was cancelled
 * leaves the new version (it must never read "The update could not be installed"), an update the administrator stopped
 * says so, and each has a title of its own.
 */
class UpdaterLabelTest extends TestCase
{
    public function test_an_undo_that_did_not_finish_says_the_new_version_stays(): void
    {
        $failed = ['kind' => 'undo', 'version' => '1.0.0', 'from' => '1.0.1', 'status' => 'failed', 'rolled_back' => true,
            'failure' => ['step' => 'swap', 'reason' => 'app/A.php changed while the update was running.']];

        $this->assertSame('The undo could not finish. Your site keeps the new version. Nothing in the database was changed.', Updater::label($failed));
        $this->assertSame('The undo did not finish', Updater::failureTitle($failed));

        $cancelled = ['failure' => ['step' => 'swap', 'reason' => MaintenanceHook::STOPPED]] + $failed;
        $this->assertSame('The undo was cancelled. Your site keeps the new version.', Updater::label($cancelled));
        $this->assertSame('The undo was cancelled', Updater::failureTitle($cancelled));

        // Called off before its swap (Cancel on Tools > Updates while it compared the files).
        $this->assertSame('The undo was cancelled. Your site keeps the new version.', Updater::label(['kind' => 'undo', 'status' => 'cancelled']));
        $this->assertSame('Putting the files of AVDBCMS 1.0.0 back...', Updater::label(['kind' => 'undo', 'version' => '1.0.0', 'status' => 'preparing', 'prepare' => ['done' => 3, 'total' => 9]]));
    }

    public function test_an_update_that_was_put_back_says_why(): void
    {
        $failed = ['kind' => 'update', 'version' => '1.0.1', 'status' => 'failed', 'rolled_back' => true, 'failure' => ['step' => 'swap', 'reason' => 'x']];

        $this->assertSame('The update could not be installed. Your site was put back as it was. Nothing in the database was changed.', Updater::label($failed));
        $this->assertSame('The update was not installed', Updater::failureTitle($failed));

        $stopped = ['failure' => ['step' => 'swap', 'reason' => MaintenanceHook::STOPPED]] + $failed;
        $this->assertSame('The update was stopped. Your site was put back as it was. Nothing in the database was changed.', Updater::label($stopped));
        $this->assertSame('The update was stopped', Updater::failureTitle($stopped));

        // A failure before the swap: its own reason, the general title.
        $early = ['kind' => 'update', 'status' => 'failed', 'failure' => ['step' => 'verify', 'reason' => 'The download is damaged.']];
        $this->assertSame('The download is damaged.', Updater::label($early));
        $this->assertSame('The update stopped.', Updater::failureTitle($early));
        $this->assertSame('The update was cancelled. Nothing on your site was changed.', Updater::label(['status' => 'cancelled']));
    }

    public function test_the_database_backup_counts_its_tables_only_once_it_has_listed_them(): void
    {
        // The answer of the last unpack step: backing up, the backup not begun (1.2.0 said "table 0 of 0").
        $begun = ['kind' => 'update', 'version' => '1.0.1', 'status' => 'backing-up'];

        $this->assertSame('Backing up the database...', Updater::label($begun));
        $this->assertNull(Updater::progress($begun), 'no bar value either');
        $this->assertSame('Backing up the database...', Updater::label($begun + ['db' => ['tables' => [], 'table' => 0]]));

        $listed = $begun + ['db' => ['tables' => [['users', 'id'], ['videos', 'id'], ['settings', null]], 'table' => 1]];
        $this->assertSame('Backing up the database: table 2 of 3', Updater::label($listed));
        $this->assertSame(['done' => 1, 'total' => 3], Updater::progress($listed));
    }
}
