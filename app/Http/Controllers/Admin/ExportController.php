<?php

namespace App\Http\Controllers\Admin;

use App\Models\Export;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
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

    public function download (Export $export): StreamedResponse
    {
        return Storage::download($export->path);
    }

}
