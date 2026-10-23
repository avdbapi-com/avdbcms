<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Pivots\Subscription;
use App\Models\Video;
use App\Services\CreatorQualityPulseService;
use App\Services\CreatorRecoveryChecklistService;
use App\Services\StudioHealthAlertsService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;

class PageController extends Controller
{
    public function index(CreatorQualityPulseService $creatorQualityPulse, StudioHealthAlertsService $studioHealthAlerts, CreatorRecoveryChecklistService $creatorRecoveryChecklist) : View
    {
        $user = Auth::user()->load([
            'videos' => function ($query) {
                $query
                    ->filter()
                    ->orderBy('videos.created_at', 'desc')
                    ->limit(config('site.dashboard_widget_limit', 5));
            },
            'subscribers' => function ($query) {
                $query
                    ->filter()
                    ->withCount('subscribers')
                    ->orderBy('subscriptions.subscribe_at', 'desc')
                    ->limit(config('site.dashboard_widget_limit', 5));
            },
            "videos_comments" => function ($query) {
                $query
                    ->filter()
                    ->with(['user', 'video'])
                    ->whereNull('parent_id')
                    ->withCount('replies')
                    ->orderBy('comments.created_at', 'desc')
                    ->limit(config('site.dashboard_widget_limit', 5));
            },
            "videos_interactions" => function ($query) {
                $query
                    ->filter()
                    ->with([
                        'likeable' => function (MorphTo $morphTo) {
                            $morphTo->morphWith([
                                Video::class => ['user']
                            ]);
                        },
                        'user' => function ($query) {
                            $query->withTrashed();
                        }
                    ])
                    ->orderBy('interactions.perform_at', 'desc')
                    ->limit(config('site.dashboard_widget_limit', 5));
            }
        ])->loadCount([
            'subscribers' => fn($query) => $query->filter(),
            'videos_views' => fn($query) => $query->filter(),
            'videos_interactions' => fn($query) => $query->whereHasMorph('likeable', Video::class, fn($query) => $query->whereHas('interactions', fn($query) => $query->filter())),
            'videos' => fn($query) => $query->filter(),
            'videos_comments' => fn($query) => $query->filter(),
            'videos_likes' => fn($query) => $query->whereHasMorph('likeable', Video::class, fn($query) => $query->whereHas('likes', fn($query) => $query->filter())),
            'videos_dislikes' => fn($query) => $query->whereHasMorph('likeable', Video::class, fn($query) => $query->whereHas('dislikes', fn($query) => $query->filter())),
        ]);

        return view('users.index', [
            'user' => $user,
            'creatorQualityPulseEnabled' => config('site.creator_quality_pulse_enabled', CreatorQualityPulseService::DEFAULT_ENABLED),
            'creatorQualityPulseVideos' => $creatorQualityPulse->videosFor($user),
            'studioHealthAlertsEnabled' => config('site.studio_health_alerts_enabled', StudioHealthAlertsService::DEFAULT_ENABLED),
            'studioHealthAlerts' => $studioHealthAlerts->alertsFor($user),
            'creatorRecoveryChecklistEnabled' => config('site.creator_recovery_checklist_enabled', CreatorRecoveryChecklistService::DEFAULT_ENABLED),
            'creatorRecoveryChecklistItems' => $creatorRecoveryChecklist->itemsFor($user),
        ]);
    }

    public function subscribers() : View {
        return view('users.subscribers', [
            'subscriptions' => Subscription::query()
                ->where('user_id', Auth::id())
                ->filter()
                ->with([
                    'subscriber' => function ($query) {
                        return $query
                            ->withCount('subscribers')
                            ->withExists([
                                'subscribers as is_current_user_subscribe' => fn($query) => $query->where('subscriber_id', Auth::id())
                            ]);
                    }
                ])
                ->orderBy('subscriptions.subscribe_at', 'desc')
                ->paginate(config('site.per_page_users', 15))
                ->withQueryString()
        ]);
    }
}
