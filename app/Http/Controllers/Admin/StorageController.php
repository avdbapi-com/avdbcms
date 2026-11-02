<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class StorageController
{
    public function index(): View
    {
        // The new admin shows the page at once and counts the folders after it appears (status probe "folders",
        // cached 10 minutes). The old admin answers with that probe's answer too: the probe counts for about 3 seconds
        // at most (a folder with more files shows "at least"), where the old admin counted every file before it
        // answered and, with about 78,000 files, passed the time limit and answered 500.
        if (AdminPreferences::usesNewAdmin()) {
            $directories = StatusProbeController::peek('folders')['data']['folders'] ?? null;
        } else {
            $directories = (array) (StatusProbeController::result('folders')['data']['folders'] ?? []);
        }

        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());
        $disk = is_numeric($total) && is_numeric($free) && $total > 0
            ? ['total' => $total, 'free' => $free, 'used' => $total - $free, 'percent' => round((1 - $free / $total) * 100, 1)]
            : ['total' => 0, 'free' => 0, 'used' => 0, 'percent' => 0];

        return view(AdminPreferences::usesNewAdmin() ? 'admin.storage.console' : 'admin.storage.index', compact('directories', 'disk'));
    }

    /**
     * The folders this page measures and clears: label => path.
     *
     * @return array<string, string>
     */
    public static function folders(): array
    {
        return [
            'storage/app' => storage_path('app'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
            'public/uploads' => public_path('uploads'),
            'public/thumbnails' => public_path('thumbnails'),
            'public/avatars' => public_path('avatars'),
        ];
    }

    public function clearDirectory(string $type): RedirectResponse
    {
        $paths = [
            'cache' => storage_path('framework/cache/data'),
            'sessions' => storage_path('framework/sessions'),
            'views' => storage_path('framework/views'),
            'logs' => storage_path('logs'),
        ];

        if (!isset($paths[$type])) {
            return redirect()->route('admin.storage.index')->withErrors(['error' => __('That folder cannot be cleared.')]);
        }

        // the folder as Tools > Cache & storage shows it
        $folder = ['cache' => 'storage/framework/cache', 'sessions' => 'storage/framework/sessions', 'views' => 'storage/framework/views', 'logs' => 'storage/logs'][$type] ?? $type;
        $path = $paths[$type];
        if (is_dir($path)) {
            ['removed' => $count, 'left' => $left] = self::removeFiles($path, microtime(true) + self::CLEAR_SECONDS);
            StatusProbeController::forget('folders');

            // Tools > Logs > Admin actions (R3-019): "storage/framework/cache: 120 files"; a folder with nothing to remove
            // writes nothing.
            if ($count > 0) {
                AuditLogController::log('tools.storage.cleared', $folder.': '.$count.' '.($count === 1 ? 'file' : 'files'));
            }
            $message = trans_choice('Removed 1 file from :folder.|Removed :count files from :folder.', $count, ['folder' => $folder]);

            return redirect()->route('admin.storage.index')
                ->withSuccess($left ? $message.' '.__('More files are left: click Clear again to remove them.') : $message);
        }

        return redirect()->route('admin.storage.index')->withSuccess(__('That folder is already empty.'));
    }

    /** Seconds one Clear may spend deleting files (a hosting time limit is often 30 s): the rest waits for the next. */
    private const CLEAR_SECONDS = 10;

    /**
     * Deletes the files under a folder (not its folders, and not the dot files that keep a folder in a package, as
     * before), one at a time and never past the deadline, so a folder of 100,000 cache files never holds the answer.
     *
     * @return array{removed: int, left: bool}
     */
    private static function removeFiles(string $path, float $deadline): array
    {
        $removed = 0;
        $seen = 0;

        try {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($items as $item) {
                if (($item->isFile() || $item->isLink()) && ! str_starts_with($item->getFilename(), '.') && @unlink($item->getPathname())) {
                    $removed++;
                }

                if ((++$seen & 255) === 0 && microtime(true) > $deadline) {
                    return ['removed' => $removed, 'left' => true];
                }
            }
        } catch (\Throwable) {
            // An unreadable part of the folder keeps its files; what could be deleted is counted.
        }

        return ['removed' => $removed, 'left' => false];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
