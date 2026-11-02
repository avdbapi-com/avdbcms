<?php

namespace App\Http\Controllers\Admin;

use App\Support\AuditDetails;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeaturedController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            // The new admin: 25 per page in their order, with the video, member or category each pick points to.
            return view('admin.featured.list', ['featured' => DB::table('featured_content')
                ->leftJoin('videos', 'featured_content.video_id', '=', 'videos.id')
                ->leftJoin('users', 'featured_content.user_id', '=', 'users.id')
                ->leftJoin('categories', 'featured_content.category_id', '=', 'categories.id')
                ->select('featured_content.*', 'videos.title as video_title', 'users.username', 'categories.title as category_title')
                ->orderBy('featured_content.sort_order')
                ->orderBy('featured_content.id')
                ->paginate(AdminPagination::perPage($request, 25))
                ->withQueryString()]);
        }

        $featured = DB::table('featured_content')
            ->leftJoin('videos', 'featured_content.video_id', '=', 'videos.id')
            ->leftJoin('users', 'featured_content.user_id', '=', 'users.id')
            ->select('featured_content.*', 'videos.title as video_title', 'users.username')
            ->orderBy('featured_content.sort_order')
            ->paginate(\App\Support\AdminPagination::perPage(null, 25));

        return view('admin.featured.index', compact('featured'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'type' => 'required|in:video,user,category,banner',
            'video_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'title' => 'nullable|string|max:200',
            'description' => 'nullable|string|max:500',
            'image_url' => 'nullable|string|max:500',
            'link_url' => 'nullable|string|max:500',
            'position' => 'required|in:homepage_hero,homepage_sidebar,trending,category_top',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date',
        ]);

        $id = DB::table('featured_content')->insertGetId([
            'type' => $request->type,
            'video_id' => $request->video_id,
            'user_id' => $request->user_id,
            'category_id' => $request->category_id,
            'title' => $request->title,
            'description' => $request->description,
            'image_url' => $request->image_url,
            'link_url' => $request->link_url,
            'position' => $request->position,
            'sort_order' => $request->sort_order ?? 0,
            'is_active' => $request->has('is_active'),
            'starts_at' => $request->starts_at,
            'expires_at' => $request->expires_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tools > Logs > Admin actions (R3-019): the pick by what it shows and its number.
        AuditLogController::log('featured.created', self::pickName($id));

        return redirect()->route('admin.featured.index')->withSuccess(__('Pick added'));
    }

    public function toggle(int $id): RedirectResponse
    {
        $current = DB::table('featured_content')->where('id', $id)->value('is_active');

        if ($current !== null) {
            DB::table('featured_content')->where('id', $id)->update(['is_active' => !$current, 'updated_at' => now()]);
            AuditLogController::log($current ? 'featured.off' : 'featured.on', self::pickName($id));
        }

        return redirect()->route('admin.featured.index')->withSuccess($current ? __('Pick turned off') : __('Pick turned on'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $name = self::pickName($id);

        if (DB::table('featured_content')->where('id', $id)->delete() > 0) {
            AuditLogController::log('featured.deleted', $name);
        }

        return redirect()->route('admin.featured.index')->withSuccess(__('Pick deleted'));
    }

    /** "Sunset drive (#3)": a pick by its own title, else the title or name of what it shows, and its number. */
    private static function pickName(int $id): string
    {
        $pick = DB::table('featured_content')
            ->leftJoin('videos', 'featured_content.video_id', '=', 'videos.id')
            ->leftJoin('users', 'featured_content.user_id', '=', 'users.id')
            ->leftJoin('categories', 'featured_content.category_id', '=', 'categories.id')
            ->where('featured_content.id', $id)
            ->first(['featured_content.title', 'videos.title as video_title', 'users.username', 'categories.title as category_title']);

        $title = trim((string) ($pick->title ?? '')) ?: trim((string) ($pick->video_title ?? '')) ?: trim((string) ($pick->username ?? '')) ?: trim((string) ($pick->category_title ?? ''));

        return AuditDetails::titled((object) ['id' => $id, 'title' => $title]);
    }
}
