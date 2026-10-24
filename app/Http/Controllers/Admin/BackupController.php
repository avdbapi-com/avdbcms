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
                // Hidden files are not backups ('.', '..', a panel's .htaccess, .gitkeep, .DS_Store).
                if (str_starts_with($file, '.')) {
                    continue;
                }
                $fullPath = $backupPath . DIRECTORY_SEPARATOR . $file;
                if (! is_file($fullPath)) {
                    continue;
                }
                $size = (int) filesize($fullPath);
                $backups[] = [
                    'name' => $file,
                    'size' => $size,
                    'size_text' => self::sizeText($size),
                    'date' => (int) filemtime($fullPath),
                ];
            }
            usort($backups, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);
        }

        $backupCount = count($backups);
        $backupTotalSize = array_sum(array_column($backups, 'size'));
        $backupTotalText = self::sizeText($backupTotalSize);
        $backups = AdminPagination::collection($backups, 25, 'backups_page');

        // The database size as people read it (KB under 1 MB, like the backup sizes), or null when the server does
        // not tell (no information_schema): the page then leaves it out.
        $dbSize = null;
        try {
            $dbName = (string) config('database.connections.mysql.database');
            $result = DB::select(
                'SELECT SUM(data_length + index_length) AS size_bytes FROM information_schema.tables WHERE table_schema = ?',
                [$dbName]
            );
            $bytes = $result[0]->size_bytes ?? null;
            if (is_numeric($bytes)) {
                $dbSize = self::sizeText((int) $bytes);
            }
        } catch (\Throwable) {
            // unknown size: left out
        }

        return view(AdminPreferences::usesNewAdmin() ? 'admin.backups.list' : 'admin.backups.index', compact('backups', 'backupCount', 'backupTotalSize', 'backupTotalText', 'dbSize') + [
            'shellAvailable' => ShellRunner::available(),
            'mysqldumpBinary' => Environment::mysqldumpBinary(),
        ]);
    }

    /**
     * A file or database size as people read it: bytes under 1 KB, KB under 1 MB (never "0.0 MB"), then MB, GB and
     * TB, with two decimals under 10, one under 100 and none above ("35.3 KB", "3.38 MB", "512 KB", "1.5 GB"), like
     * the other admin lists. Whole numbers keep their zeros ("100 KB", "300 B"); a value that rounds up to 1024 moves
     * to the next unit ("1 MB", not "1,024 KB").
     */
    public static function sizeText(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) max(0, $bytes);
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        $precision = static fn (float $value, int $unit): int => $unit === 0 ? 0 : ($value >= 100 ? 0 : ($value >= 10 ? 1 : 2));
        if (round($size, $precision($size, $unit)) >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        $digits = $precision($size, $unit);
        $text = number_format($size, $digits, '.', ',');
        if ($digits > 0) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text.' '.$units[$unit];
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
