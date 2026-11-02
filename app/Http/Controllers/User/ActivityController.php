<?php

namespace App\Http\Controllers\User;

use App\Filters\ActivityFilters;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Comment;
use App\Models\Interaction;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\Studio\StudioFormat;
use App\Support\Studio\StudioSettings;
use App\Support\Studio\ZoneLabel;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Your activity" (member design 6.8): a timeline grouped by day (Today, Yesterday, 23 Oct) with the filter
 * links All, Likes, Dislikes, Comments, Replies (studio-9: Likes and Dislikes answered 500) and a date filter.
 */
class ActivityController extends Controller
{
    public function index(Request $request, SeoService $seo) : View|RedirectResponse {

        $filters = [];
        $type = $request->query('type');

        if (is_string($type) && in_array($type, ActivityFilters::TYPES, true)) {
            $filters['type'] = $type;
        }

        $date = $request->query('date');

        if (is_string($date) && in_array($date, ['7', '28', '90'], true)) {
            $filters['date'] = $date;
        }

        $videoRelations = array_values(array_unique(['user', ...$seo->videoPathRelations()]));

        $activities = Activity::filter($filters === [] ? ['__none' => '1'] : $filters)
            ->where('user_id', Auth::id())
            ->with([
                'subject' => function (MorphTo $morphTo) use ($videoRelations) {
                    // Everything a sentence names is loaded here, once per page: the video and its channel ("You liked
                    // Blue hour above the valley by oceanlens"), the author of the comment a reply answered ("You
                    // replied to qa_studio_viewer on ...") and the author of a liked comment.
                    $morphTo->morphWith([
                        Comment::class => [
                            'video' => function ($query) use ($videoRelations) {
                                $query->withTrashed()->with($videoRelations);
                            },
                            'parent' => function ($query) {
                                $query->withTrashed()->with('user');
                            },
                        ],
                        Interaction::class => [
                            'likeable' => function (MorphTo $morphTo) use ($videoRelations) {
                                $morphTo->morphWith([
                                    Video::class => $videoRelations,
                                    Comment::class => [
                                        'user',
                                        'video' => function ($query) use ($videoRelations) {
                                            $query->withTrashed()->with($videoRelations);
                                        },
                                    ],
                                ]);
                            }
                        ],
                    ]);
                }
            ])
            ->latest('perform_at')
            ->latest('id')
            ->paginate(StudioSettings::int('per_page_activity', 12, 1, 100))
            ->withQueryString();

        // 1.2.3 round 1 (R1-108): a page past the end of the list is its last page (as in My videos and Comments), not
        // an empty "Showing 0-0 of 4"
        if ($activities->total() > 0 && $activities->currentPage() > $activities->lastPage()) {
            return redirect()->to($activities->url($activities->lastPage()));
        }

        return view('users.activity.index', [
            'activities' => $activities,
            'days' => $this->days($activities->getCollection()),
            'type' => $filters['type'] ?? '',
            'date' => $filters['date'] ?? '',
            'filtersActive' => $filters !== [],
        ]);
    }

    /**
     * The page's entries grouped by the day they happened on, in the site's time zone: "Today", "Yesterday", then the
     * date ("23 Oct", with the year when it is not this year).
     *
     * @return list<array{label: string, items: list<Activity>}>
     */
    private function days($activities): array
    {
        $zone = ZoneLabel::zone();
        $today = CarbonImmutable::now($zone)->startOfDay();
        $groups = [];

        foreach ($activities as $activity) {
            $at = $activity->perform_at ? CarbonImmutable::instance($activity->perform_at)->setTimezone($zone) : $today;
            $key = $at->toDateString();

            if (! isset($groups[$key])) {
                $label = match (true) {
                    $at->isSameDay($today) => __('Today'),
                    $at->isSameDay($today->subDay()) => __('Yesterday'),
                    default => StudioFormat::dayLabel($at),
                };
                $groups[$key] = ['label' => $label, 'items' => []];
            }

            $groups[$key]['items'][] = $activity;
        }

        return array_values($groups);
    }
}
