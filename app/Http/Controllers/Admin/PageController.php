<?php

namespace App\Http\Controllers\Admin;

use App\Support\AuditDetails;
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

        $id = DB::table('custom_pages')->insertGetId([
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

        // Tools > Logs > Admin actions (R3-019): the page by its title and number.
        AuditLogController::log('page.created', AuditDetails::titled((object) ['id' => $id, 'title' => (string) $request->title]));

        return redirect()->route('admin.pages.index')->withSuccess(__('Page created'));
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

        $before = DB::table('custom_pages')->where('id', $id)->first();
        $values = [
            'title' => $request->title,
            'slug' => $request->slug,
            'content' => $request->content,
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'is_published' => $request->has('is_published'),
            'show_in_footer' => $request->has('show_in_footer'),
            'show_in_header' => $request->has('show_in_header'),
            'sort_order' => $request->sort_order ?? 0,
        ];
        DB::table('custom_pages')->where('id', $id)->update($values + ['updated_at' => now()]);

        // Which fields changed, never their text (R3-019); a save that changed nothing writes nothing.
        if ($before !== null) {
            $fields = ['title' => 'title', 'slug' => 'address', 'content' => 'text', 'meta_title' => 'search_title', 'meta_description' => 'search_description',
                'is_published' => 'published', 'show_in_footer' => 'footer_link', 'show_in_header' => 'header_link', 'sort_order' => 'order'];
            $changed = [];

            foreach ($fields as $column => $field) {
                $old = $before->{$column} ?? null;
                $new = $values[$column];

                if (is_bool($new) ? (bool) $old !== $new : (string) ($old ?? '') !== (string) ($new ?? '')) {
                    $changed[] = $field;
                }
            }

            if ($changed !== []) {
                AuditLogController::log('page.updated', AuditDetails::titled((object) ['id' => $id, 'title' => (string) $request->title]).': '.implode(', ', $changed));
            }
        }

        return redirect()->route('admin.pages.index')->withSuccess(__('Changes saved.'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $page = DB::table('custom_pages')->where('id', $id)->first(['id', 'title']);

        if ($page !== null && DB::table('custom_pages')->where('id', $id)->delete() > 0) {
            AuditLogController::log('page.deleted', AuditDetails::titled($page));
        }

        return redirect()->route('admin.pages.index')->withSuccess(__('Page deleted'));
    }

    public function togglePublish(int $id): RedirectResponse
    {
        $page = DB::table('custom_pages')->where('id', $id)->first(['id', 'title', 'is_published']);
        $current = $page?->is_published;

        if ($page !== null) {
            DB::table('custom_pages')->where('id', $id)->update(['is_published' => !$current, 'updated_at' => now()]);
            AuditLogController::log($current ? 'page.unpublished' : 'page.published', AuditDetails::titled($page));
        }

        return redirect()->route('admin.pages.index')->withSuccess($current ? __('Page unpublished') : __('Page published'));
    }
}
