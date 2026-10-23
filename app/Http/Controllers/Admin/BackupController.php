<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use App\Support\DatabaseBackup;
use App\Support\Environment;
use App\Support\ShellRunner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BackupController
{
    public function index(): View
    {
        $backups = [];
        $backupPath = storage_path('app/backups');

        if (is_dir($backupPath)) {
            $files = scandir($backupPath) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                $fullPath = $backupPath . DIRECTORY_SEPARATOR . $file;
                if (! is_file($fullPath)) {
                    continue;
                }
                $backups[] = [
                    'name' => $file,
                    'size' => (int) filesize($fullPath),
                    'date' => (int) filemtime($fullPath),
                ];
            }
            usort($backups, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);
        }

        $backupCount = count($backups);
        $backupTotalSize = array_sum(array_column($backups, 'size'));
        $backups = AdminPagination::collection($backups, 25, 'backups_page');

        $dbSize = '0 MB';
        try {
            $dbName = (string) config('database.connections.mysql.database');
            $result = DB::select(
                'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.tables WHERE table_schema = ?',
                [$dbName]
            );
            $dbSize = ($result[0]->size_mb ?? 0) . ' MB';
        } catch (\Throwable) {
            // leave default
        }

        return view(AdminPreferences::usesNewAdmin() ? 'admin.backups.list' : 'admin.backups.index', compact('backups', 'backupCount', 'backupTotalSize', 'dbSize') + [
            'shellAvailable' => ShellRunner::available(),
            'mysqldumpBinary' => Environment::mysqldumpBinary(),
        ]);
    }

    /**
     * Create an SQL dump using mysqldump / mariadb-dump, or fallback to pure-PHP PDO dumper
     * when shell exec is disabled, mysqldump is missing, or default panel security forbids proc_open.
     * The dump itself lives in App\Support\DatabaseBackup (the updater makes its backup with it too).
     */
    public function createDatabase(): RedirectResponse
    {
        $result = app(DatabaseBackup::class)->create('backup_');

        return $result['ok']
            ? redirect()->route('admin.backups.index')->withSuccess($result['message'])
            : redirect()->route('admin.backups.index')->withErrors(['backup' => $result['message']]);
    }

    public function download(string $filename): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $path = storage_path('app/backups/' . basename($filename));
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->download($path);
    }

    public function destroy(string $filename): RedirectResponse
    {
        $path = storage_path('app/backups/' . basename($filename));
        if (file_exists($path)) {
            @unlink($path);
        }

        return redirect()->route('admin.backups.index')
            ->withSuccess(__('Backup :filename deleted.', ['filename' => $filename]));
    }
}
