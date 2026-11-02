<?php

namespace App\Listeners;

use App\Http\Controllers\Admin\AuditLogController;
use App\Support\AuditDetails;
use Opcodes\LogViewer\Events\LogFileDeleted;

/**
 * Tools > Logs > Admin actions: log files deleted on Tools > Logs' file viewer (the log-viewer package: a file, several
 * ticked files or a folder; R3-019). The package says so file by file; one action is one row, so the names are kept
 * for the request and written once when it ends ("laravel.log, cron.log and 3 more"). The Site logs page (its own
 * controller) writes its own rows and never fires this event.
 */
class AuditLogFileDeleted
{
    /** @var list<string> the files deleted by this request */
    private static array $names = [];

    private static bool $pending = false;

    public function handle(LogFileDeleted $event): void
    {
        self::$names[] = (string) $event->file->name;

        if (! self::$pending) {
            self::$pending = true;
            app()->terminating(static function (): void {
                self::flush();
            });
        }
    }

    /** Writes the row of the files deleted so far (the request's end does it; a test may too). */
    public static function flush(): void
    {
        $names = array_values(array_unique(self::$names));
        self::$names = [];
        self::$pending = false;

        if ($names === []) {
            return;
        }

        sort($names);
        AuditLogController::log(count($names) === 1 ? 'tools.log.deleted' : 'tools.logs.deleted', AuditDetails::names($names));
    }
}
