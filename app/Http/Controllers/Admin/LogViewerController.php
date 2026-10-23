<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPagination;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class LogViewerController
{
    public function index(Request $request): View
    {
        $logPath = storage_path('logs');
        $files = [];

        if (is_dir($logPath)) {
            $allFiles = File::files($logPath);
            foreach ($allFiles as $file) {
                if ($file->getExtension() === 'log') {
                    $files[] = [
                        'name' => $file->getFilename(),
                        'size' => $this->formatBytes($file->getSize()),
                        'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                        'size_raw' => $file->getSize(),
                    ];
                }
            }
            usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
        }

        $selectedFile = $request->get('file', $files[0]['name'] ?? null);
        $content = '';
        $lineCount = 0;

        if ($selectedFile && file_exists($logPath . '/' . basename($selectedFile))) {
            $fullPath = $logPath . '/' . basename($selectedFile);
            $fileSize = filesize($fullPath);

            // Read last 200KB max for performance
            if ($fileSize > 200 * 1024) {
                $fp = fopen($fullPath, 'r');
                fseek($fp, -200 * 1024, SEEK_END);
                fgets($fp); // skip partial line
                $content = fread($fp, 200 * 1024);
                fclose($fp);
                $content = "... (showing last 200KB of file) ...\n\n" . $content;
            } else {
                $content = file_get_contents($fullPath);
            }
            $lineCount = substr_count($content, "\n") + 1;
        }

        $files = AdminPagination::collection($files, 25, 'files_page');

        return view('admin.logs.index', compact('files', 'selectedFile', 'content', 'lineCount'));
    }

    public function download(string $filename)
    {
        $path = storage_path('logs/' . basename($filename));
        if (!file_exists($path)) abort(404);
        return response()->download($path);
    }

    public function delete(string $filename): RedirectResponse
    {
        $path = storage_path('logs/' . basename($filename));

        if (! file_exists($path)) {
            return redirect()->route('admin.logs.index')->withErrors(['log' => "Log file {$filename} was not found."]);
        }

        $result = $this->clearLogFile($path);

        if ($result === 'skipped') {
            return redirect()->route('admin.logs.index')->withErrors([
                'log' => "Log file {$filename} is currently locked by another process and could not be cleared.",
            ]);
        }

        return redirect()
            ->route('admin.logs.index')
            ->withSuccess($result === 'deleted' ? "Log file {$filename} deleted." : "Log file {$filename} cleared.");
    }

    public function clearAll(): RedirectResponse
    {
        $logPath = storage_path('logs');

        if (! is_dir($logPath)) {
            return redirect()->route('admin.logs.index')->withErrors(['log' => 'Log directory was not found.']);
        }

        $deleted = 0;
        $truncated = 0;
        $skipped = 0;

        $files = File::files($logPath);
        foreach ($files as $file) {
            if ($file->getExtension() !== 'log') {
                continue;
            }

            match ($this->clearLogFile($file->getPathname())) {
                'deleted' => $deleted++,
                'truncated' => $truncated++,
                default => $skipped++,
            };
        }

        $cleared = $deleted + $truncated;
        $message = "{$cleared} log file(s) cleared.";
        if ($skipped > 0) {
            $message .= " {$skipped} locked/running log file(s) could not be cleared.";
        }

        return redirect()->route('admin.logs.index')->withSuccess($message);
    }

    private function clearLogFile(string $path): string
    {
        try {
            if (@unlink($path)) {
                return 'deleted';
            }
        } catch (\Throwable) {
            // A running scheduler/queue process can keep a log handle locked, especially on Windows.
        }

        try {
            if (@file_put_contents($path, '') !== false) {
                return 'truncated';
            }
        } catch (\Throwable) {
            // Leave locked files in place; the UI should report the skip instead of failing with 500.
        }

        return 'skipped';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}
