<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\Studio\StudioSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * "Reports you sent" (member design 6.8). The security rule (S06) is kept: a member cancels only their own
 * report while it is pending; any other report answers 404.
 */
class ReportController extends Controller
{
    public function index(SeoService $seo) : View|RedirectResponse {

        // Every row links its video (or the video of its comment): the relations of a video's address are loaded with
        // the page, not once per row.
        $videoRelations = array_values(array_unique(['user', ...$seo->videoPathRelations()]));

        $reports = Report::query()
            ->where('user_id', Auth::id())
            ->with([
                'reportable' => function (MorphTo $morphTo) use ($videoRelations) {
                    $morphTo->morphWith([
                        User::class,
                        Video::class => $videoRelations,
                        Comment::class => ['user', 'video' => fn ($query) => $query->with($videoRelations)],
                    ]);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(StudioSettings::int('per_page_users', 15, 1, 100))
            ->withQueryString();

        // 1.2.3 round 1 (R1-108): a page past the end of the list is its last page, not an empty "Showing 0-0 of 4"
        if ($reports->total() > 0 && $reports->currentPage() > $reports->lastPage()) {
            return redirect()->to($reports->url($reports->lastPage()));
        }

        return view('users.reports.index', [
            'reports' => $reports,
        ]);
    }

    public function cancel (Report $report) : RedirectResponse {

        // Only the member's own report, while it waits for a moderator (the list offers Cancel for exactly those).
        abort_unless((int) $report->user_id === (int) Auth::id() && $report->is_pending, 404);

        $report->delete();

        return redirect()->route('user.reports.index')->with('success', __('Report cancelled.'));
    }
}
