<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;

class StorageController
{
    public function index(): View
    {
        // The new admin shows the page at once and counts the folders after it appears (status probe "folders",
        // cached 10 minutes); the old admin counts them before it answers, as it always did.
        if (AdminPreferences::usesNewAdmin()) {
            $directories = StatusProbeController::peek('folders')['data']['folders'] ?? null;
        } else {
            $directories = array_map(fn (string $path): array => $this->getDirectoryInfo($path), self::folders());
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
            return redirect()->route('admin.storage.index')->withErrors(['error' => 'Invalid directory type.']);
        }

        $path = $paths[$type];
        if (is_dir($path)) {
            $files = File::allFiles($path);
            $count = count($files);
            foreach ($files as $file) {
                @unlink($file->getPathname());
            }
            StatusProbeController::forget('folders');
            return redirect()->route('admin.storage.index')
                ->withSuccess("{$count} files removed from {$type}.");
        }

        return redirect()->route('admin.storage.index')->withSuccess("Directory is already empty.");
    }

    private function getDirectoryInfo(string $path): array
    {
        if (!is_dir($path)) {
            return ['size' => 0, 'files' => 0, 'exists' => false];
        }

        $size = 0;
        $count = 0;
        try {
            $files = File::allFiles($path);
            foreach ($files as $file) {
                $size += $file->getSize();
                $count++;
            }
        } catch (\Exception $e) {
            // Skip unreadable directories
        }

        return [
            'size' => $size,
            'files' => $count,
            'exists' => true,
            'formatted_size' => self::formatBytes($size),
        ];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
