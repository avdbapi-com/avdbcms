<?php

namespace App\Http\Controllers\Admin;

use App\Logs\ReadableLogViewer;
use App\Support\AdminPagination;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Tools > Logs > Site logs: the log files of storage/logs, the newest one open.
 *
 * A log file PHP may not read is left out of the list, and opening or downloading one by its address says so instead
 * of answering 500 (judge R2CD-RV-02, the same rule as the package viewer of R1BR-02): on a Linux host a log written
 * by root's cron (umask 077) or by another user of the server is there but closed to the site's own user, and as the
 * newest file it made the whole page fail.
 */
class LogViewerController
{
    /** The end of a long log that the page shows. */
    private const TAIL_BYTES = 200 * 1024;

    public function index(Request $request): View
    {
        $logPath = storage_path('logs');
        $files = [];
        $unreadable = 0;

        if (is_dir($logPath)) {
            $allFiles = File::files($logPath);
            foreach ($allFiles as $file) {
                if ($file->getExtension() !== 'log') {
                    continue;
                }

                if (! ReadableLogViewer::readable($file->getPathname())) {
                    $unreadable++;

                    continue;
                }

                $files[] = [
                    'name' => $file->getFilename(),
                    'size' => $this->formatBytes($file->getSize()),
                    'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                    'size_raw' => $file->getSize(),
                ];
            }
            usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
        }

        $asked = $request->query('file');
        $selectedFile = is_string($asked) && $asked !== '' ? basename($asked) : ($files[0]['name'] ?? null);
        $content = '';
        $lineCount = 0;
        $readError = null;
        $cut = false;

        if ($selectedFile && file_exists($logPath . '/' . $selectedFile)) {
            $tail = $this->tail($logPath . '/' . $selectedFile);

            if ($tail === null) {
                $readError = __('The site has no permission to read the log file :file.', ['file' => $selectedFile]);
            } else {
                [$content, $cut] = $tail;
                $lineCount = substr_count($content, "\n") + 1;
            }
        }

        $files = AdminPagination::collection($files, 25, 'files_page');

        return view('admin.logs.index', compact('files', 'selectedFile', 'content', 'lineCount', 'unreadable', 'readError', 'cut'));
    }

    public function download(string $filename): BinaryFileResponse|RedirectResponse
    {
        $path = storage_path('logs/' . basename($filename));
        if (!file_exists($path)) abort(404);

        if (! ReadableLogViewer::readable($path)) {
            return redirect()->route('admin.logs.index')->withErrors([
                'log' => __('The site has no permission to read the log file :file.', ['file' => basename($filename)]),
            ]);
        }

        return response()->download($path);
    }

    /**
     * The log's text and whether it is only its end (the last TAIL_BYTES of a long one, after the line they cut), or
     * null when PHP cannot read it (also when it stopped being readable after the list was made).
     *
     * @return array{0: string, 1: bool}|null
     */
    private function tail(string $path): ?array
    {
        if (! ReadableLogViewer::readable($path)) {
            return null;
        }

        $size = @filesize($path);

        if ($size === false) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if ($size <= self::TAIL_BYTES) {
                $text = stream_get_contents($handle);

                return $text === false ? null : [$text, false];
            }

            fseek($handle, -self::TAIL_BYTES, SEEK_END);
            fgets($handle); // the line the cut went through
            $text = stream_get_contents($handle);

            return $text === false ? null : [$text, true];
        } finally {
            fclose($handle);
        }
    }

    public function delete(string $filename): RedirectResponse
    {
        $path = storage_path('logs/' . basename($filename));

        if (! file_exists($path)) {
            return redirect()->route('admin.logs.index')->withErrors(['log' => __('The log file :file was not found.', ['file' => $filename])]);
        }

        $result = $this->clearLogFile($path);

        if ($result === 'skipped') {
            return redirect()->route('admin.logs.index')->withErrors([
                'log' => __('The log file :file is in use by another process and could not be cleared.', ['file' => $filename]),
            ]);
        }

        // Tools > Logs > Admin actions (R3-019): the file's name (deleted, or emptied while another process holds it).
        AuditLogController::log('tools.log.deleted', basename($path));

        return redirect()
            ->route('admin.logs.index')
            ->withSuccess($result === 'deleted' ? __('Log file :file deleted.', ['file' => $filename]) : __('Log file :file cleared.', ['file' => $filename]));
    }

    public function clearAll(): RedirectResponse
    {
        $logPath = storage_path('logs');

        if (! is_dir($logPath)) {
            return redirect()->route('admin.logs.index')->withErrors(['log' => __('The log folder was not found.')]);
        }

        $deleted = 0;
        $truncated = 0;
        $skipped = 0;
        $names = [];

        $files = File::files($logPath);
        foreach ($files as $file) {
            if ($file->getExtension() !== 'log') {
                continue;
            }

            $result = $this->clearLogFile($file->getPathname());

            match ($result) {
                'deleted' => $deleted++,
                'truncated' => $truncated++,
                default => $skipped++,
            };

            if ($result !== 'skipped') {
                $names[] = $file->getFilename();
            }
        }

        $cleared = $deleted + $truncated;

        // Tools > Logs > Admin actions (R3-019): the files cleared ("laravel.log, cron.log and 3 more"); a folder without
        // a log file to clear writes nothing.
        if ($names !== []) {
            sort($names);
            AuditLogController::log('tools.logs.cleared', \App\Support\AuditDetails::names($names));
        }
        $message = trans_choice('Cleared 1 log file.|Cleared :count log files.', $cleared);
        if ($skipped > 0) {
            $message .= ' '.trans_choice('1 log file is in use and was not cleared.|:count log files are in use and were not cleared.', $skipped);
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
