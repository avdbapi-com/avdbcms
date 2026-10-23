<?php

namespace App\Http\Controllers\Admin;

use App\Models\Video;
use App\Support\AdminCache;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ThumbnailController
{
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            // The new admin: 25 per page, the newest first; the counts in one query, kept for a minute.
            return view('admin.thumbnails.list', [
                'thumbnails' => DB::table('thumbnails')
                    ->join('videos', 'thumbnails.video_id', '=', 'videos.id')
                    ->join('users', 'videos.user_id', '=', 'users.id')
                    ->select('thumbnails.id', 'thumbnails.video_id', 'thumbnails.is_active', 'thumbnails.status', 'thumbnails.created_at', 'videos.title as video_title', 'videos.uuid as video_uuid', 'users.username')
                    ->orderByDesc('thumbnails.created_at')
                    ->paginate(AdminPagination::perPage($request, 25))
                    ->withQueryString(),
                'stats' => AdminCache::remember('thumbnails.counts.v1', now()->addMinute(), function (): array {
                    $row = DB::table('thumbnails')->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active, COUNT(DISTINCT video_id) as videos')->first();

                    return ['total' => (int) ($row->total ?? 0), 'active' => (int) ($row->active ?? 0), 'videos' => (int) ($row->videos ?? 0)];
                }),
            ]);
        }

        $thumbnails = DB::table('thumbnails')
            ->join('videos', 'thumbnails.video_id', '=', 'videos.id')
            ->join('users', 'videos.user_id', '=', 'users.id')
            ->select(
                'thumbnails.*',
                'videos.title as video_title',
                'videos.uuid as video_uuid',
                'users.username'
            )
            ->orderByDesc('thumbnails.created_at')
            ->paginate(\App\Support\AdminPagination::perPage(null, 24));

        $stats = DB::table('thumbnails')->selectRaw('
            COUNT(*) as total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as approved,
            COUNT(DISTINCT video_id) as unique_videos
        ')->first();

        return view('admin.thumbnails.index', compact('thumbnails', 'stats'));
    }

    public function setActive(int $id): RedirectResponse
    {
        $thumbnail = DB::table('thumbnails')->where('id', $id)->first();
        if ($thumbnail) {
            // Deactivate all thumbnails for this video
            DB::table('thumbnails')->where('video_id', $thumbnail->video_id)->update(['is_active' => 0]);
            // Activate this one
            DB::table('thumbnails')->where('id', $id)->update(['is_active' => 1]);
        }
        return redirect()->route('admin.thumbnails.index')->withSuccess('Thumbnail set as active.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $thumbnail = DB::table('thumbnails')->where('id', $id)->first();
        if ($thumbnail) {
            $path = Video::THUMBNAIL_FOLDER . '/' . $thumbnail->file;

            if ($thumbnail->file && Storage::exists($path)) {
                Storage::delete($path);
            }

            DB::table('thumbnails')->where('id', $id)->delete();
        }

        return redirect()->route('admin.thumbnails.index')->withSuccess('Thumbnail deleted.');
    }
}
