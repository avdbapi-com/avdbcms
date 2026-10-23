<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PageController
{
    public function index(Request $request): View
    {
        $pages = DB::table('custom_pages')->orderBy('sort_order')->paginate(\App\Support\AdminPagination::perPage($request, 25));

        // The new admin's list keeps the page's other query values (per page) in the pager's links.
        return AdminPreferences::usesNewAdmin($request->user())
            ? view('admin.pages.list', ['pages' => $pages->withQueryString()])
            : view('admin.pages.index', compact('pages'));
    }

    public function create(): View
    {
        return AdminPreferences::usesNewAdmin()
            ? view('admin.pages.record', ['page' => null])
            : view('admin.pages.form');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:200',
            'slug' => 'required|string|max:200|unique:custom_pages,slug',
            'content' => 'nullable|string',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:500',
            'is_published' => 'nullable|boolean',
            'show_in_footer' => 'nullable|boolean',
            'show_in_header' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        DB::table('custom_pages')->insert([
            'title' => $request->title,
            'slug' => $request->slug,
            'content' => $request->content,
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'is_published' => $request->has('is_published'),
            'show_in_footer' => $request->has('show_in_footer'),
            'show_in_header' => $request->has('show_in_header'),
            'sort_order' => $request->sort_order ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.pages.index')->withSuccess('Page created.');
    }

    public function edit(int $id): View
    {
        $page = DB::table('custom_pages')->where('id', $id)->first();
        if (!$page) abort(404);
        return view(AdminPreferences::usesNewAdmin() ? 'admin.pages.record' : 'admin.pages.form', compact('page'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'title' => 'required|string|max:200',
            'slug' => 'required|string|max:200',
            'content' => 'nullable|string',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:500',
        ]);

        DB::table('custom_pages')->where('id', $id)->update([
            'title' => $request->title,
            'slug' => $request->slug,
            'content' => $request->content,
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'is_published' => $request->has('is_published'),
            'show_in_footer' => $request->has('show_in_footer'),
            'show_in_header' => $request->has('show_in_header'),
            'sort_order' => $request->sort_order ?? 0,
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.pages.index')->withSuccess('Page updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('custom_pages')->where('id', $id)->delete();
        return redirect()->route('admin.pages.index')->withSuccess('Page deleted.');
    }

    public function togglePublish(int $id): RedirectResponse
    {
        $current = DB::table('custom_pages')->where('id', $id)->value('is_published');
        DB::table('custom_pages')->where('id', $id)->update(['is_published' => !$current, 'updated_at' => now()]);
        return redirect()->route('admin.pages.index')->withSuccess('Page ' . (!$current ? 'published' : 'unpublished') . '.');
    }
}
