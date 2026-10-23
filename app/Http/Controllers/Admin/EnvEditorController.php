<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EnvEditorController
{
    public function index(): View
    {
        $envPath = base_path('.env');
        $content = file_exists($envPath) ? file_get_contents($envPath) : '';
        $lineCount = substr_count($content, "\n") + 1;
        $lastModified = file_exists($envPath) ? date('Y-m-d H:i:s', filemtime($envPath)) : 'N/A';
        $fileSize = file_exists($envPath) ? filesize($envPath) : 0;

        return view('admin.env-editor.index', compact('content', 'lineCount', 'lastModified', 'fileSize'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate(['content' => 'required|string']);

        $envPath = base_path('.env');
        $backup = $envPath . '.backup.' . date('YmdHis');
        copy($envPath, $backup);
        file_put_contents($envPath, $request->content);

        \Artisan::call('config:clear');

        return redirect()->route('admin.env-editor.index')
            ->withSuccess('.env file updated. Backup saved as ' . basename($backup));
    }
}
