<?php

namespace App\Http\Controllers\Admin;

use App\Support\EnvBackups;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnvEditorController
{
    public function index(): View
    {
        $envPath = app()->environmentFilePath();
        $content = file_exists($envPath) ? file_get_contents($envPath) : '';
        $lineCount = substr_count($content, "\n") + 1;
        $lastModified = file_exists($envPath) ? date('Y-m-d H:i:s', filemtime($envPath)) : 'N/A';
        $fileSize = file_exists($envPath) ? filesize($envPath) : 0;

        return view('admin.env-editor.index', compact('content', 'lineCount', 'lastModified', 'fileSize'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate(['content' => 'required|string']);

        $envPath = app()->environmentFilePath();
        // The old version goes to storage/app/env-backups (0600, a name nobody can guess, the newest 5 kept), never
        // next to .env in the project folder.
        $original = is_file($envPath) ? (string) @file_get_contents($envPath) : '';
        $backup = $original !== '' ? EnvBackups::store($original) : null;
        file_put_contents($envPath, $request->content);

        // Tools > Logs > Admin actions (R2A-020): the names of the lines that changed, never a value.
        $changed = self::changedNames($original, (string) $request->content);

        if ($changed !== []) {
            AuditLogController::log('tools.env.saved', \App\Support\AuditDetails::names($changed));
        }

        \Artisan::call('config:clear');

        return redirect()->route('admin.env-editor.index')
            ->withSuccess($backup !== null
                ? __('.env saved. The old version is kept in :file.', ['file' => 'storage/app/env-backups/'.$backup])
                : __('.env saved.'));
    }

    /**
     * The names (APP_DEBUG, MAIL_HOST ...) whose value differs between two .env texts, added or removed ones too.
     *
     * @return list<string>
     */
    public static function changedNames(string $before, string $after): array
    {
        $read = static function (string $text): array {
            $values = [];

            foreach (preg_split('/\R/', $text) ?: [] as $line) {
                if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=(.*)$/', $line, $match) === 1) {
                    $values[$match[1]] = trim($match[2]);
                }
            }

            return $values;
        };
        $old = $read($before);
        $new = $read($after);
        $names = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $name) {
            if (($old[$name] ?? null) !== ($new[$name] ?? null)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }
}
