<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;

class MediaLibraryController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.media.list', $this->listPage($request));
        }

        $disk = Storage::disk('public');
        $currentPath = $request->get('path', '');
        $currentPath = ltrim($currentPath, '/');

        $directories = [];
        $files = [];
        $rawDirs = [];
        $rawFiles = [];

        try {
            $rawDirs = $disk->directories($currentPath);
            foreach ($rawDirs as $dir) {
                $directories[] = [
                    'name' => basename($dir),
                    'path' => $dir,
                ];
            }

            $rawFiles = $disk->files($currentPath);
            foreach ($rawFiles as $file) {
                $files[] = [
                    'name' => basename($file),
                    'path' => $file,
                    'size' => $disk->size($file),
                    'modified' => $disk->lastModified($file),
                    'url' => $disk->url($file),
                    'is_image' => preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico)$/i', $file),
                    'is_video' => preg_match('/\.(mp4|webm|avi|mov)$/i', $file),
                ];
            }
        } catch (\Exception $e) {}

        // Breadcrumbs
        $breadcrumbs = [];
        if ($currentPath) {
            $parts = explode('/', $currentPath);
            $buildPath = '';
            foreach ($parts as $part) {
                $buildPath = $buildPath ? $buildPath . '/' . $part : $part;
                $breadcrumbs[] = ['name' => $part, 'path' => $buildPath];
            }
        }

        // Stats
        $totalFiles = count($rawFiles);
        $totalDirs = count($rawDirs);
        $totalSize = array_sum(array_column($files, 'size'));
        $files = AdminPagination::collection($files, 50, 'files_page');

        return view('admin.media.index', compact(
            'directories', 'files', 'currentPath', 'breadcrumbs',
            'totalFiles', 'totalDirs', 'totalSize'
        ));
    }

    /**
     * The new admin's folder: its folders, then its files 25 per page; only the files of the page are measured (size,
     * date), so a folder of thousands of thumbnails answers as fast as a small one.
     */
    private function listPage(Request $request): array
    {
        $disk = Storage::disk('public');
        $currentPath = ltrim((string) $request->get('path', ''), '/');
        $folders = [];
        $names = [];

        try {
            $folders = $disk->directories($currentPath);
            $names = $disk->files($currentPath);
        } catch (\Exception $e) {}

        $files = AdminPagination::collection($names, 25, 'files_page')->withQueryString();
        $files->setCollection($files->getCollection()->map(function (string $file) use ($disk): array {
            try {
                $size = $disk->size($file);
                $modified = $disk->lastModified($file);
            } catch (\Exception $e) {
                $size = null;
                $modified = null;
            }

            return [
                'name' => basename($file),
                'path' => $file,
                'size' => $size,
                'modified' => $modified,
                'url' => $disk->url($file),
                'is_image' => preg_match('/\.(jpg|jpeg|png|gif|webp|svg|ico)$/i', $file) === 1,
            ];
        }));

        $crumbs = [];
        $built = '';
        foreach ($currentPath === '' ? [] : explode('/', $currentPath) as $part) {
            $built = $built === '' ? $part : $built.'/'.$part;
            $crumbs[] = ['name' => $part, 'path' => $built];
        }

        return compact('folders', 'files', 'currentPath', 'crumbs');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $path = $request->input('path');
        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            $disk->delete($path);
            return redirect()->back()->withSuccess("File deleted: " . basename($path));
        }

        return redirect()->back()->withErrors(['file' => 'File not found.']);
    }
}
