<?php

namespace App\Http\Controllers\Admin;

use App\Support\AdminCache;
use App\Support\AdminPreferences;
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
            return redirect()->route('admin.tags.index')->withErrors(['name' => 'Tag already exists.']);
        }

        DB::table('tags')->insert([
            'name' => $name,
            'slug' => $slug,
            'usage_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('admin.tags.index')->withSuccess("Tag \"{$name}\" created.");
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate(['name' => 'required|string|max:100']);
        DB::table('tags')->where('id', $id)->update([
            'name' => trim($request->name),
            'slug' => Str::slug(trim($request->name)),
            'updated_at' => now(),
        ]);
        return redirect()->route('admin.tags.index')->withSuccess('Tag updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        DB::table('tags')->where('id', $id)->delete();
        DB::table('video_tags')->where('tag_id', $id)->delete();
        return redirect()->route('admin.tags.index')->withSuccess('Tag deleted.');
    }

    public function merge(Request $request): RedirectResponse
    {
        $request->validate([
            'source_id' => 'required|integer',
            'target_id' => 'required|integer|different:source_id',
        ]);

        // Move all video associations from source to target
        DB::table('video_tags')->where('tag_id', $request->source_id)
            ->update(['tag_id' => $request->target_id]);

        // Update target count
        $count = DB::table('video_tags')->where('tag_id', $request->target_id)->count();
        DB::table('tags')->where('id', $request->target_id)->update(['usage_count' => $count]);

        // Delete source
        DB::table('tags')->where('id', $request->source_id)->delete();

        return redirect()->route('admin.tags.index')->withSuccess('Tags merged successfully.');
    }

    public function deleteUnused(): RedirectResponse
    {
        $count = DB::table('tags')->where('usage_count', 0)->delete();
        return redirect()->route('admin.tags.index')->withSuccess("{$count} unused tags deleted.");
    }
}
