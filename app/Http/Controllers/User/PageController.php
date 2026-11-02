<?php

namespace App\Http\Controllers\User;

use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Pivots\Subscription;
use App\Models\Video;
use App\Services\CreatorQualityPulseService;
use App\Services\CreatorRecoveryChecklistService;
use App\Services\SeoService;
use App\Services\StudioHealthAlertsService;
use App\Support\Studio\Dashboard;
use App\Support\Studio\StudioFormat;
use App\Support\Studio\StudioSettings;
use App\Support\Studio\ZoneLabel;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The studio dashboard and the Subscribers page (member design 6.2, 6.8).
 */
class PageController extends Controller
{
    public function index(Request $request, CreatorQualityPulseService $creatorQualityPulse, StudioHealthAlertsService $studioHealthAlerts, CreatorRecoveryChecklistService $creatorRecoveryChecklist, SeoService $seo) : View
    {
        $user = Auth::user();
        $period = Dashboard::period(
            is_string($request->query('period')) ? $request->query('period') : null,
            $request->query('from'),
            $request->query('to'),
        );
        $limit = StudioSettings::widgetLimit();

        // A new member (no video yet) gets the figures once (zeros) and one "Upload your first video" instead of empty
        // panels (studio-36); nothing else is read. The same one query stamps the member's videos for the cached lists
        // below (studio-29: a counted view does not move the stamp).
        $stamp = Dashboard::videosStamp($user);
        $hasVideos = $stamp['total'] > 0;

        $data = [
            'user' => $user,
            'period' => $period,
            'figures' => Dashboard::figures($user, $period),
            'hasVideos' => $hasVideos,
            'uploadsAllowed' => StudioSettings::uploadsAllowed(),
            'attentionEnabled' => StudioSettings::healthAlertsEnabled() || StudioSettings::recoveryChecklistEnabled(),
            'tipsEnabled' => StudioSettings::qualityPulseEnabled(),
            'latestVideos' => [],
            'attention' => [],
            'latestComments' => [],
            'subscribers' => [],
            'tips' => [],
            'showSubscriberCounts' => StudioSettings::on('show_subscriber_count', true),
        ];

        if (! $hasVideos) {
            return view('users.index', $data);
        }

        // The video link format is part of the lists' stamp (the rows print studio links only since 1.2.3 round 1).
        $linkRelations = $seo->videoPathRelations();

        // The lists and boxes, cached 5 minutes per member in one entry (Dashboard::widgets: the videos stamp and the
        // member's own changes renew it at once). Plain values only: no model, no users row is ever written to the cache.
        $settings = [
            'limit' => $limit,
            'tips' => $data['tipsEnabled'],
            'tipsLimit' => $data['tipsEnabled'] ? $creatorQualityPulse->limit() : 0,
            'alerts' => StudioSettings::healthAlertsEnabled(),
            'checklist' => StudioSettings::recoveryChecklistEnabled(),
            'links' => $linkRelations,
        ];
        $widgets = Dashboard::widgets($user, $stamp, $settings,
            fn (): array => $this->widgets($user, $limit, $data['tipsEnabled'], $creatorQualityPulse, $studioHealthAlerts, $creatorRecoveryChecklist));

        return view('users.index', array_merge($data, $widgets));
    }

    /**
     * The dashboard's lists and boxes (6.2): each one grouped query (its users eager-loaded, its pictures a column), turned
     * into plain values for the cache (ids, texts, addresses, counts, ISO times; never a model, an e-mail address or a
     * password hash).
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function widgets($user, int $limit, bool $tipsEnabled, CreatorQualityPulseService $creatorQualityPulse, StudioHealthAlertsService $studioHealthAlerts, CreatorRecoveryChecklistService $creatorRecoveryChecklist): array
    {
        // 1.2.3 round 1 (R1-074): a cold dashboard stays within the 4.3 budget (20 queries for the page). The rows print
        // studio links only (the video's id), so no public-link relation is loaded; the picture is a column of the same
        // query (an active thumbnail exists, else the poster) instead of a thumbnails query; the comment count is the
        // stored public one (as the watch page shows it); a comment brings its video's title in the same query.
        $latestVideos = Video::query()
            ->without('thumbnail')
            ->where('user_id', $user->id)
            ->whereNotIn('status', Dashboard::hiddenFromLatest())
            ->whereNotNull('uploaded_at')
            ->withExists(['thumbnail as studio_has_thumbnail'])
            ->latest('videos.created_at')
            ->latest('videos.id')
            ->limit($limit)
            ->get()
            ->each(fn (Video $video) => $video->setRelation('user', $user));

        // R1-165: the public comments, as viewers see them (a comment a moderator hid is not listed)
        $latestComments = Comment::query()
            ->join('videos', 'videos.id', '=', 'comments.video_id')
            ->where('videos.user_id', $user->id)
            ->whereNull('videos.deleted_at')
            ->whereNull('comments.parent_id')
            ->whereNull('comments.banned_at')
            ->select('comments.*', 'videos.title as studio_video_title')
            ->with('user')
            ->latest('comments.created_at')
            ->latest('comments.id')
            ->limit($limit)
            ->get();

        $subscribers = Subscription::query()
            ->where('user_id', $user->id)
            ->with(['subscriber' => fn ($query) => $query->withCount('subscribers')])
            ->orderByDesc('subscribe_at')
            ->limit($limit)
            ->get();

        return [
            'latestVideos' => $latestVideos->map(fn (Video $video): array => $this->videoItem($video))->values()->all(),
            'latestComments' => $latestComments->map(fn (Comment $comment): array => $this->commentItem($comment))->values()->all(),
            'subscribers' => $subscribers->map(fn (Subscription $subscription): array => $this->subscriberItem($subscription))->values()->all(),
            'attention' => $this->attention($user, $studioHealthAlerts, $creatorRecoveryChecklist)->map(fn (array $item): array => $this->attentionItem($item))->values()->all(),
            'tips' => $tipsEnabled ? $this->tips($creatorQualityPulse->videosFor($user, max($creatorQualityPulse->limit() * 3, CreatorQualityPulseService::MAX_LIMIT), false), $creatorQualityPulse->limit()) : [],
        ];
    }

    public function subscribers(Request $request) : View|RedirectResponse
    {
        $filters = [];
        $search = $request->query('search');

        if (is_string($search) && trim($search) !== '') {
            $filters['search'] = trim($search);
        }

        $date = $request->query('date');

        if (is_string($date) && in_array($date, ['7', '28', '90'], true)) {
            $filters['date_start'] = CarbonImmutable::now(ZoneLabel::zone())->startOfDay()->subDays(((int) $date) - 1)->toDateTimeString();
        }

        $perPage = StudioSettings::int('per_page_users', 15, 1, 100);

        $query = Subscription::query()
            ->where('user_id', Auth::id())
            ->filter($filters === [] ? ['__none' => '1'] : $filters)
            ->with([
                'subscriber' => function ($query) {
                    return $query
                        ->withCount('subscribers')
                        ->withExists([
                            'subscribers as is_current_user_subscribe' => fn($query) => $query->where('subscriber_id', Auth::id())
                        ]);
                }
            ])
            ->orderByDesc('subscriptions.subscribe_at')
            ->orderByDesc('subscriptions.id');
        $subscriptions = $query->paginate($perPage)->withQueryString();

        // 1.2.3 round 1 (R1-108): a page past the end of the list (people unsubscribed meanwhile, an old address) is its
        // last page, with the same filters, as in My videos and Comments, instead of an empty "Showing 0-0 of 4"
        if ($subscriptions->total() > 0 && $subscriptions->currentPage() > $subscriptions->lastPage()) {
            return redirect()->to($subscriptions->url($subscriptions->lastPage()));
        }

        return view('users.subscribers', [
            'subscriptions' => $subscriptions,
            'total' => Subscription::query()->where('user_id', Auth::id())->count(),
            'recent' => Subscription::query()->where('user_id', Auth::id())->where('subscribe_at', '>=', now()->subDays(28))->count(),
            'filtersActive' => $filters !== [],
            'showSubscriberCounts' => StudioSettings::on('show_subscriber_count', true),
            'uploadsAllowed' => StudioSettings::uploadsAllowed(),
        ]);
    }

    /**
     * "Needs your attention" (6.2): the admin's "Creator alerts box" and "Comeback checklist" in one list (each still
     * follows its own switch, limit and days), the same video and kind only once.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function attention($user, StudioHealthAlertsService $alerts, CreatorRecoveryChecklistService $checklist): Collection
    {
        $items = collect();

        if (StudioSettings::healthAlertsEnabled()) {
            // studio links only (attentionItem): no public-link relations to load
            $items = $items->merge($alerts->alertsFor($user, false)->map(fn (array $alert): array => $alert + ['source' => 'alerts']));
        }

        if (StudioSettings::recoveryChecklistEnabled()) {
            $items = $items->merge($checklist->itemsFor($user)->map(fn (array $item): array => $item + ['source' => 'checklist']));
        }

        $seen = [];

        return $items->filter(function (array $item) use (&$seen): bool {
            $video = $item['video'] ?? null;
            $kind = in_array($item['type'] ?? '', ['upload_failed', 'failed_upload'], true) ? 'failed' : (string) ($item['type'] ?? '');
            $key = $kind.':'.($video instanceof Video ? $video->id : spl_object_id((object) $item));

            if (isset($seen[$key])) {
                return false;
            }

            $seen[$key] = true;

            return true;
        })->values();
    }

    /**
     * One "Needs your attention" item as plain values: its tone and icon, one sentence, one line, and its actions
     * (Upload again is printed only while uploads are on: the page decides it, the setting may change in the 5 minutes).
     *
     * @param  array<string, mixed>  $item
     * @return array{tone: string, icon: string, sentence: string, line: string, actions: list<array<string, string>>}
     */
    private function attentionItem(array $item): array
    {
        $video = ($item['video'] ?? null) instanceof Video ? $item['video'] : null;
        $title = $video ? (string) ($video->getRawOriginal('title') ?: __('Untitled video')) : '';
        $type = (string) ($item['type'] ?? '');
        $count = (int) ($item['count'] ?? 1);

        // the short date of a failed upload ends its sentence: one full stop after "22 oct." (R2A-023)
        $failedOn = StudioFormat::shortDate($video?->updated_at ?? now());

        [$sentence, $line] = match ($type) {
            'upload_failed', 'failed_upload' => [__('Upload failed: :title', ['title' => $title]), StudioFormat::endingWith(__('The file did not finish processing on :date.', ['date' => $failedOn]), $failedOn)],
            'recent_reports' => [__('Reported: :title', ['title' => $title]), trans_choice(':count new report|:count new reports', $count, ['count' => $count])],
            'missing_thumbnail' => [__('No picture chosen: :title', ['title' => $title]), __('A chosen picture gets more clicks.')],
            'low_engagement' => [__('No recent views: :title', ['title' => $title]), __('A clearer title or picture can help.')],
            'draft' => [__('Draft not finished: :title', ['title' => $title]), __('Add a title, then publish it.')],
            'stale_unpublished' => [__('Not public yet: :title', ['title' => $title]), __('Check its visibility or its date.')],
            default => [$title !== '' ? __(':label: :value', ['label' => (string) ($item['title'] ?? __('Needs your attention')), 'value' => $title]) : (string) ($item['title'] ?? __('Needs your attention')), (string) ($item['message'] ?? '')],
        };

        $actions = [];

        if (in_array($type, ['upload_failed', 'failed_upload'], true) && $video) {
            $actions[] = ['type' => 'upload', 'label' => __('Upload again')];
            $actions[] = ['type' => 'delete', 'label' => __('Delete'), 'url' => route('user.videos.destroy', $video), 'title' => $title];
        } elseif ($type === 'draft' && $video) {
            $actions[] = ['type' => 'link', 'label' => __('Finish draft'), 'url' => route('user.videos.create', $video)];
        } elseif ($video && $type === 'recent_reports' && $video->is_created) {
            $actions[] = ['type' => 'link', 'label' => __('Review'), 'url' => route('user.videos.show', $video)];
        } elseif ($video && ! $video->is_banned && ! $video->is_failed) {
            $actions[] = ['type' => 'link', 'label' => $type === 'missing_thumbnail' ? __('Pick a picture') : __('Review'), 'url' => $video->is_draft ? route('user.videos.create', $video) : route('user.videos.edit', $video)];
        }

        return [
            'tone' => match ($item['severity'] ?? 'info') {
                'danger' => 'mk-attn--danger',
                'warning' => 'mk-attn--warning',
                default => '',
            },
            'icon' => match ($type) {
                'upload_failed', 'failed_upload' => 'triangle-exclamation',
                'recent_reports' => 'flag',
                'missing_thumbnail' => 'image',
                'low_engagement' => 'chart-line',
                'draft' => 'file-pen',
                'stale_unpublished' => 'clock-rotate-left',
                default => 'circle-info',
            },
            'sentence' => $sentence,
            'line' => $line,
            'actions' => $actions,
        ];
    }

    /** @return array<string, mixed> a "Latest videos" row as plain values */
    private function videoItem(Video $video): array
    {
        [$kind, $label] = StudioFormat::status($video);
        $scheduled = $video->status === VideoStatus::PLANNED && $video->scheduled_at?->isFuture();

        // the picture as Video::thumbnail_url gives it (the active thumbnail, else the poster), from the row's own column
        $poster = match (true) {
            (bool) $video->studio_has_thumbnail => route('video.thumbnail', $video),
            ! empty($video->poster_url) => (string) $video->poster_url,
            default => null,
        };

        return [
            'id' => (int) $video->id,
            'title' => (string) ($video->getRawOriginal('title') ?: __('Untitled video')),
            'poster' => $poster,
            'duration' => (string) $video->duration,
            'kind' => $kind,
            'label' => $label,
            'scheduled' => $scheduled ? ZoneLabel::goesPublic($video->scheduled_at, false) : null,
            'views' => (int) $video->views,
            'comments' => (int) $video->comments_count,
            'edit' => route('user.videos.edit', $video),
        ];
    }

    /**
     * @return array<string, mixed> a "Latest comments" row as plain values (the author's name and picture only; the
     * video's own title comes with the comment's row, studio_video_title)
     */
    private function commentItem(Comment $comment): array
    {
        return [
            'id' => (int) $comment->id,
            'author' => $comment->user?->username,
            'avatar' => $comment->user ? (string) $comment->user->avatar_url : null,
            'content' => (string) $comment->content,
            'at' => self::iso($comment->created_at),
            'video' => [
                'title' => (string) ($comment->studio_video_title ?: __('Untitled video')),
                'url' => route('user.comments.index', ['video' => (int) $comment->video_id]),
            ],
            'destroy' => route('user.comments.destroy', $comment),
        ];
    }

    /** @return array<string, mixed> a "New subscribers" row as plain values (name, picture, channel address, count) */
    private function subscriberItem(Subscription $subscription): array
    {
        $fan = $subscription->subscriber;

        return [
            'name' => $fan?->username,
            'avatar' => $fan ? (string) $fan->avatar_url : null,
            'url' => $fan ? (string) $fan->route : null,
            'count' => (int) ($fan->subscribers_count ?? 0),
            'hidesCount' => $fan !== null && $fan->show_subscribers === false,
            'at' => self::iso($subscription->subscribe_at),
        ];
    }

    private static function iso(mixed $date): ?string
    {
        return $date instanceof CarbonInterface ? $date->toIso8601String() : (is_string($date) && $date !== '' ? $date : null);
    }

    /**
     * "Tips for your videos" (6.2): one tip per video of the admin's "Creator tips box" (creator_quality_pulse_*) that
     * has something to do (a picture, a description, a playlist), most watched first, up to the admin's limit. A video
     * with nothing to fix is not a tip (four "is ready" rows used to push the real tips out), so the candidates
     * are three times the limit (at least the largest limit, 12); with nothing to do the panel is not shown.
     *
     * @param  Collection<int, Video>  $videos
     * @return list<array<string, string>>
     */
    private function tips(Collection $videos, int $limit): array
    {
        return $videos->map(function (Video $video): ?array {
            // the member's own title, as everywhere in the studio (the translated title read one query per video in
            // every language but English)
            $title = (string) ($video->getRawOriginal('title') ?: __('Untitled video'));

            if (! $video->creator_quality_thumbnail_ready) {
                return ['icon' => 'image', 'title' => __('Pick a thumbnail for :title', ['title' => $title]),
                    'text' => __('It still shows the first frame.'), 'action' => __('Pick'), 'url' => route('user.videos.edit', $video)];
            }

            if (trim((string) $video->getRawOriginal('description')) === '') {
                return ['icon' => 'align-left', 'title' => __('Add a description to :title', ['title' => $title]),
                    'text' => __('Two lines help people find it in search.'), 'action' => __('Add'), 'url' => route('user.videos.edit', $video)];
            }

            if ((int) ($video->creator_quality_playlists_count ?? 0) === 0 && $video->status !== VideoStatus::PRIVATE) {
                return ['icon' => 'rectangle-list', 'title' => __('Add :title to a playlist', ['title' => $title]),
                    'text' => __('Playlists keep viewers watching.'), 'action' => __('Add'), 'url' => route('user.videos.edit', $video)];
            }

            return null;
        })->filter()->take($limit)->values()->all();
    }
}
