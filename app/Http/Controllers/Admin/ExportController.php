<?php

namespace App\Http\Controllers\Admin;

use App\Models\Export;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController
{
    public function index (Request $request) : View {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.exports.list', [
                'exports' => Export::latest()
                    ->paginate(AdminPagination::perPage($request, 25))
                    ->withQueryString(),
            ]);
        }

        return view('admin.exports.index', [
            'exports' => Export::latest()
                ->paginate(\App\Support\AdminPagination::perPage(null, 15))
                ->withQueryString()
        ]);
    }

    public function download (Export $export): StreamedResponse|RedirectResponse
    {
        // An export file the site may not read (a queue worker run as another user of the server wrote it): the list
        // says so instead of a download that fails half way (local disks; another disk answers for itself).
        $disk = Storage::disk();

        if (config('filesystems.disks.'.config('filesystems.default').'.driver') === 'local' && $disk->exists($export->path)
            && ! \App\Logs\ReadableLogViewer::readable($disk->path($export->path))) {
            return redirect()->route('admin.exports.index')->withErrors([
                'export' => __('The site has no permission to read the export :file.', ['file' => (string) $export->file]),
            ]);
        }

        return Storage::download($export->path);
    }

}
