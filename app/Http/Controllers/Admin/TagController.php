<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCache;
use App\Support\AdminPreferences;
use App\Support\AuditDetails;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TagController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.tags.list', $this->listPage($request));
        }

        $query = DB::table('tags');

        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $tags = $query->orderByDesc('usage_count')->paginate(\App\Support\AdminPagination::perPage(null, 50));

        $stats = [
            'total' => DB::table('tags')->count(),
            'used' => DB::table('tags')->where('usage_count', '>', 0)->count(),
            'unused' => DB::table('tags')->where('usage_count', 0)->count(),
        ];

        return view('admin.tags.index', compact('tags', 'stats'));
    }

    /**
     * The new admin's list: 25 per page, most used first, the Used / Not used tabs (?usage=) and a search; the three
     * counts in one query, kept for a minute (any admin change clears them).
     */
    private function listPage(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));
        $usage = (string) $request->query('usage', '');

        $tags = DB::table('tags')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($usage === 'used', fn ($query) => $query->where('usage_count', '>', 0))
            ->when($usage === 'unused', fn ($query) => $query->where('usage_count', 0))
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->paginate(\App\Support\AdminPagination::perPage($request, 25))
            ->withQueryString();

        $stats = AdminCache::remember('tags.tabs.v1', now()->addMinute(), function (): array {
            $row = DB::table('tags')->selectRaw('COUNT(*) as total, SUM(CASE WHEN usage_count > 0 THEN 1 ELSE 0 END) as used')->first();

            return ['total' => (int) ($row->total ?? 0), 'used' => (int) ($row->used ?? 0), 'unused' => (int) ($row->total ?? 0) - (int) ($row->used ?? 0)];
        });

        return compact('tags', 'stats');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $name = trim($request->name);
        $slug = Str::slug($name);

        $exists = DB::table('tags')->where('slug', $slug)->exists();
        if ($exists) {
            return redirect()->route('admin.tags.index')->withErrors(['name' => __('This tag exists already.')]);
        }

        $id = DB::table('tags')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'usage_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tools > Logs > Admin actions (R3-019): the tag by its name and number.
        AuditLogController::log('tag.created', self::tagName((object) ['id' => $id, 'name' => $name]));

        return redirect()->route('admin.tags.index')->withSuccess(__('Tag ":name" created.', ['name' => $name]));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:100']);
        $before = DB::table('tags')->where('id', $id)->first(['id', 'name']);
        DB::table('tags')->where('id', $id)->update([
            'name' => trim($request->name),
            'slug' => Str::slug(trim($request->name)),
            'updated_at' => now(),
        ]);

        // A new name: "old name (#id) -> new name"; the same name again writes nothing.
        if ($before !== null && (string) $before->name !== trim($request->name)) {
            AuditLogController::log('tag.renamed', self::tagName($before).' -> '.Str::limit(trim($request->name), AuditDetails::NAME_MAX));
        }

        return redirect()->route('admin.tags.index')->withSuccess(__('Changes saved.'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $tag = DB::table('tags')->where('id', $id)->first(['id', 'name']);
        $deleted = DB::table('tags')->where('id', $id)->delete();
        DB::table('video_tags')->where('tag_id', $id)->delete();

        if ($tag !== null && $deleted > 0) {
            AuditLogController::log('tag.deleted', self::tagName($tag));
        }

        return redirect()->route('admin.tags.index')->withSuccess(__('Tag deleted'));
    }

    public function merge(Request $request): RedirectResponse
    {
        $request->validate([
            'source_id' => 'required|integer',
            'target_id' => 'required|integer|different:source_id',
        ]);

        $source = DB::table('tags')->where('id', $request->source_id)->first(['id', 'name']);
        $target = DB::table('tags')->where('id', $request->target_id)->first(['id', 'name']);

        // Move all video associations from source to target
        DB::table('video_tags')->where('tag_id', $request->source_id)
            ->update(['tag_id' => $request->target_id]);

        // Update target count
        $count = DB::table('video_tags')->where('tag_id', $request->target_id)->count();
        DB::table('tags')->where('id', $request->target_id)->update(['usage_count' => $count]);

        // Delete source
        DB::table('tags')->where('id', $request->source_id)->delete();

        // "source (#1) -> target (#2)": the tag that went and the one that has its videos now.
        if ($source !== null && $target !== null) {
            AuditLogController::log('tags.merged', self::tagName($source).' -> '.self::tagName($target));
        }

        return redirect()->route('admin.tags.index')->withSuccess(__('Tags merged'));
    }

    public function deleteUnused(): RedirectResponse
    {
        $unused = DB::table('tags')->where('usage_count', 0)->orderBy('id')->limit(AuditDetails::LIST_MAX)->get(['id', 'name']);
        $count = DB::table('tags')->where('usage_count', 0)->delete();

        if ($count > 0) {
            AuditLogController::log('tags.unused_deleted', AuditDetails::names($unused->map(fn (object $tag): string => self::tagName($tag)), $count));
        }

        return redirect()->route('admin.tags.index')->withSuccess(trans_choice('Deleted 1 tag no video uses.|Deleted :count tags no video uses.', $count));
    }

    /** "music (#12)": a tag as Tools > Logs > Admin actions names it (R3-019). */
    private static function tagName(object $tag): string
    {
        return AuditDetails::titled((object) ['id' => (int) ($tag->id ?? 0), 'title' => (string) ($tag->name ?? '')]);
    }
}
