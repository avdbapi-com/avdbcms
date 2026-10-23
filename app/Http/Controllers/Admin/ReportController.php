<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Interfaces\Reportable;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminCache;
use App\Support\AdminPreferences;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ReportController extends Controller
{
    public function index(Request $request) : View {
        $newAdmin = AdminPreferences::usesNewAdmin($request->user());
        // Loaded with the page, not once per row: a reported comment's author and video, a video's creator. The old
        // admin also shows posters and links videos on the site (a video's address names its category); the new one
        // shows neither. Only the new list names the deleted video of a reported comment (the old one says "Video
        // deleted" for it, so it keeps loading live videos only).
        $videoRelations = $newAdmin ? ['user:id,username'] : ['user', 'category'];
        $video = fn ($query) => $newAdmin ? $query->without('thumbnail') : $query;
        $commentVideo = fn ($query) => $video($newAdmin ? $query->withTrashed() : $query)->with($videoRelations);
        // The new list opens on the reports that wait for a decision (?status=all lists every report).
        $status = $newAdmin ? (string) ($request->query('status') ?: ReportStatus::PENDING->value) : null;
        $filters = $newAdmin ? array_merge(Arr::except($request->query(), ['status']), $status === 'all' ? [] : ['status' => $status]) : null;
        $query = ($filters === [] ? Report::query() : Report::filter($filters ?? []))
            ->with([
                $newAdmin ? 'user:id,username' : 'user',
                'reportable' => function (MorphTo $morphTo) use ($videoRelations, $video, $commentVideo) {
                    $morphTo->morphWith([
                        User::class,
                        Video::class => $videoRelations,
                        Comment::class => ['user', 'video' => $commentVideo],
                    ])->constrain([
                        Video::class => $video,
                    ]);
                }
            ])
            ->orderBy('created_at', 'desc');

        if ($newAdmin) {
            return view('admin.reports.list', [
                'status' => $status,
                'reports' => $query->paginate(\App\Support\AdminPagination::perPage($request, 25))->withQueryString(),
                'counts' => AdminCache::remember('reports.tabs.v1', now()->addMinute(), fn (): array => Report::query()
                    ->toBase()
                    ->selectRaw('status, COUNT(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status')
                    ->mapWithKeys(fn ($total, $status): array => [(int) $status => (int) $total])
                    ->all()),
            ]);
        }

        return view('admin.reports.index', [
            'reports' => $query->paginate(\App\Support\AdminPagination::perPage(null, 12))->withQueryString()
        ]);
    }

    /**
     * "Block it" acts on a reported comment only: it hides the comment (the block date the comment lists read) and
     * closes every report about it. A reported member is blocked on their own page (Block member, with UserPolicy's
     * checks) and a reported video on its page (Ban video, the status visitors' lists go by). For those two this
     * address used to write users.status, a column the users table does not have (Laravel dropped it, so premium
     * members and administrators got blocked past the policy), or a video block date that no list reads (the video
     * stayed visible); it now answers 404 for them and changes nothing.
     */
    public function block (string $type, Reportable $reportable) : RedirectResponse {

        abort_unless($reportable instanceof Comment, 404);

        $reportable->update(['banned_at' => now()]);

        Report::query()
            ->where('reportable_type', $reportable->getMorphClass())
            ->where('reportable_id', $reportable->id)
            ->update([
                'status' => ReportStatus::BLOCKED
            ]);

        return redirect()->route('admin.index');

    }

    public function cancel (string $type, Reportable $reportable) : RedirectResponse {

        Report::query()
            ->where('reportable_type', $reportable->getMorphClass())
            ->where('reportable_id', $reportable->id)
            ->update([
                'status' => ReportStatus::CANCELLED
            ]);

        return redirect()->route('admin.index');
    }
}
