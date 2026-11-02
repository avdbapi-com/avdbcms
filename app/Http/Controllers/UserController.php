<?php

namespace App\Http\Controllers;

use App\Enums\ImageType;
use App\Models\Pivots\Subscription;
use App\Models\User;
use App\Support\AvdbCreatorAvatarFactory;
use App\Support\FeedAdSlots;
use App\Support\LocalNumber;
use App\Support\SafeCursor;
use App\Support\WatchHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserController
{
    /** Videos per page of the channel's Videos tab ("Show more videos" pages the same list). */
    public const VIDEOS_PER_PAGE = 24;

    /** Playlists per page of the channel's Playlists tab. */
    public const PLAYLISTS_PER_PAGE = 12;

    /** The video sorts of the Videos tab. */
    public const SORTS = ['latest', 'popular', 'oldest'];

    /**
     * The channel page /@{user} (member design 6.10, 6.19).
     *
     * The Home tab is printed with the page. The Videos and Playlists tabs load their cards when they are first opened
     * (a GET of this same address with ?part=videos|playlists and an Accept: application/json header answers
     * {html, next_cursor, count}): a channel's first screen stays small (4.3) and the page never runs the player chunk
     * for a list (QA channel-4). Both tabs page through every public item ("Show more", QA channel-2).
     */
    public function show(Request $request, User $user): View|RedirectResponse|JsonResponse
    {
        $requested = ltrim((string) $request->segment(1), '@');
        if ($requested !== $user->profileRouteKeyValue()) {
            // the canonical address in the page's language, with the query (as a video's canonical redirect): /ar/@old
            // went to the default language's address, so a second hop (and search engines) lost the language
            $query = $request->getQueryString();

            return redirect()->to(locale_url($user->route . ($query !== null && $query !== '' ? '?' . $query : '')), 301);
        }

        $part = (string) $request->query('part', '');
        if (in_array($part, ['videos', 'playlists', 'about'], true) && $request->expectsJson()) {
            return match ($part) {
                'videos' => $this->videosPart($request, $user),
                'playlists' => $this->playlistsPart($request, $user),
                default => $this->aboutPart($user),
            };
        }

        $isSubscribed = Auth::user()?->isSubscribeTo($user) ?? false;
        $channel = $user->channelCustomization();

        // Home: Featured (the creator's featured columns x rows; one lead card at 1 x 1), Latest uploads (the admin's
        // profile_videos_limit, at least the creator's columns x rows) and Playlists (the admin's profile_playlists_limit,
        // at least the creator's columns x rows, as 1.2.2 loaded them). The admin's values are used as set (1-48): no
        // cap of the page's own (the content budget is met with the defaults, 8 and 6).
        $latestColumns = $this->clamp($channel['latest_uploads_columns'] ?? 3, 1, 6);
        $latestLimit = max(1, (int) config('site.profile_videos_limit', 8), $latestColumns * $this->clamp($channel['latest_uploads_rows'] ?? 1, 1, 6));
        $playlistColumns = $this->clamp($channel['playlists_columns'] ?? 1, 1, 4);
        $featuredLimit = $this->clamp($channel['featured_columns'] ?? 3, 1, 6) * $this->clamp($channel['featured_rows'] ?? 1, 1, 4);
        $playlistLimit = max(1, (int) config('site.profile_playlists_limit', 6), $playlistColumns * $this->clamp($channel['playlists_rows'] ?? 3, 1, 6));
        // what the owner's video link format prints in each card's link (a {category} pattern needs the category)
        $videoRelations = WatchHistory::videoRelations();

        if ($isSubscribed) {
            $this->updateReadDate($user);
        }

        $user->load([
            'videos' => fn (HasMany $query) => $query
                ->with($videoRelations)
                ->active()
                ->forLocale()
                ->latest('published_at')
                ->latest('id')
                ->limit($latestLimit + $featuredLimit),
            'playlists' => fn (HasMany $query) => $query
                ->active()
                ->withCount(['videos' => fn ($videoQuery) => $videoQuery->active()->forLocale()])
                ->withWhereHas('videos', fn ($videoQuery) => $videoQuery->active()->forLocale()->limit(1))
                ->latest('updated_at')
                ->latest('id')
                ->limit($playlistLimit),
            'pinned_video' => fn ($query) => $query->with($videoRelations)->forLocale(),
            'reportByAuthUser',
        ])->loadCount([
            'subscribers',
            'videos as active_videos_count' => fn ($query) => $query->active()->forLocale(),
            'playlists as active_playlists_count' => fn ($query) => $query
                ->active()
                ->whereHas('videos', fn ($videoQuery) => $videoQuery->active()->forLocale()),
        ])->loadSum([
            'videos as videos_views_count' => fn ($query) => $query->active()->forLocale(),
        ], 'views');

        return view('users.show', [
            'user' => $user,
            'channel' => $channel,
            'is_subscribed' => $isSubscribed,
            'latestColumns' => $latestColumns,
            'latestLimit' => $latestLimit,
            'playlistColumns' => $playlistColumns,
            'videoRelations' => $videoRelations,
        ]);
    }

    /**
     * The About tab (member design 6.10; QA channel-8, 16, 18, 22), loaded when it is first opened: the description and
     * the details (the real address with a copy button, website, joined, views and videos written with the language's
     * separators, country). 404 when the creator or the site hides the About panel.
     */
    private function aboutPart(User $user): JsonResponse
    {
        $channel = $user->channelCustomization();
        abort_unless($channel['show_about_panel'] === '1' && config('site.channel_customization_sections_enabled', true), 404);

        $user->loadCount(['videos as active_videos_count' => fn ($query) => $query->active()->forLocale()])
            ->loadSum(['videos as videos_views_count' => fn ($query) => $query->active()->forLocale()], 'views');

        $website = null;
        if ($user->website && $channel['show_social_links'] === '1' && config('site.channel_customization_socials_enabled', true)) {
            $website = Str::startsWith($user->website, ['http://', 'https://']) ? $user->website : 'https://' . $user->website;
        }

        return response()->json([
            'html' => view('library.channel-about', [
                'user' => $user,
                'details' => [
                    'website' => $website,
                    'stats' => $channel['show_stats'] === '1' && config('site.channel_customization_stats_enabled', true),
                    'views' => LocalNumber::format((int) ($user->videos_views_count ?? 0)),
                    'videos' => LocalNumber::format((int) ($user->active_videos_count ?? 0)),
                ],
            ])->render(),
            'next_cursor' => null,
            'count' => 1,
        ]);
    }

    /**
     * One page of the Videos tab: the channel's public videos in the asked sort, as cards, with the channel_videos list
     * ad where the admin's "Ad after every N" puts it (counted over the whole list: ?shown= videos before this page).
     */
    private function videosPart(Request $request, User $user): JsonResponse
    {
        $sort = in_array($request->query('sort'), self::SORTS, true) ? (string) $request->query('sort') : 'latest';
        $shown = max(0, (int) $request->query('shown', 0));

        $query = $user->videos()
            ->active()
            ->forLocale()
            ->with(WatchHistory::videoRelations())
            ->when($sort === 'latest', fn (Builder|HasMany $q) => $q->latest('published_at')->orderByDesc('id'))
            ->when($sort === 'popular', fn (Builder|HasMany $q) => $q->orderByDesc('views')->orderByDesc('id'))
            ->when($sort === 'oldest', fn (Builder|HasMany $q) => $q->oldest('published_at')->orderBy('id'));

        $total = (clone $query)->toBase()->getCountForPagination();
        $page = SafeCursor::paginate($query, self::VIDEOS_PER_PAGE, $request->query('cursor'));
        $adEvery = FeedAdSlots::adEvery(config('site.ad_grid_position', 6));
        $maxAds = FeedAdSlots::maxAds(config('site.ad_grid_max_per_feed', 1));

        // the overall numbers of the videos an ad follows on this page (it needs a video after it)
        $adAfter = [];
        foreach ($page->items() as $index => $video) {
            $number = $shown + $index + 1;
            $ordinal = $adEvery > 0 ? intdiv($number, $adEvery) : 0;
            if ($adEvery > 0 && $number % $adEvery === 0 && $number < $total && ($maxAds === 0 || $ordinal <= $maxAds)) {
                $adAfter[] = $index + 1;
            }
        }

        return response()->json([
            'html' => view('library.cards', [
                'videos' => collect($page->items()),
                'showChannel' => false,
                'adContext' => 'channel_videos',
                'adAfter' => $adAfter,
            ])->render(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'count' => $total,
        ]);
    }

    /**
     * One page of the Playlists tab: every public playlist that has a video the visitor may watch, the last updated
     * first (the playlist's id breaks ties, so no playlist is skipped at a page edge).
     */
    private function playlistsPart(Request $request, User $user): JsonResponse
    {
        $query = $user->playlists()
            ->active()
            ->withCount(['videos' => fn ($videoQuery) => $videoQuery->active()->forLocale()])
            ->withWhereHas('videos', fn ($videoQuery) => $videoQuery->active()->forLocale()->limit(1))
            ->latest('updated_at')
            ->orderByDesc('playlists.id');

        $total = (clone $query)->toBase()->getCountForPagination();
        $page = SafeCursor::paginate($query, self::PLAYLISTS_PER_PAGE, $request->query('cursor'));

        return response()->json([
            'html' => view('library.playlist-cards', ['playlists' => collect($page->items())])->render(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'count' => $total,
        ]);
    }

    private function updateReadDate (User $user) : void {
        Subscription::where([
            'user_id' => $user->id,
            'subscriber_id' => Auth::user()->id,
        ])->update([
            'read_at' => now()
        ]);
    }

    public function avatar (User $user): Response|BinaryFileResponse
    {
        $avatar = AvdbCreatorAvatarFactory::ensureForUser($user);

        if ($avatar) {
            $path = User::AVATAR_FOLDER.'/'.$avatar;

            if (Storage::exists($path)) {
                try {
                    $file = Storage::get($path);

                    if (str_ends_with(strtolower($avatar), '.svg')) {
                        $file = AvdbCreatorAvatarFactory::withoutSmil((string) $file);
                    }

                    return response($file)->withHeaders([
                        'Content-Type' => $this->storageMimeType($path, ImageType::PNG->value),
                        ...$this->profileImageCacheHeaders($user),
                    ]);
                } catch (\Throwable) {
                    // Fall through to memory SVG generation
                }
            }
        }

        // Resilient dynamic fallback: if disk storage is inaccessible or permission-restricted,
        // dynamically generate the branded creator/user SVG in memory so the visitor never sees default silhouette.
        $svg = AvdbCreatorAvatarFactory::svgForUser($user);
        if ($svg !== null) {
            return response($svg)->withHeaders([
                'Content-Type' => 'image/svg+xml',
                ...$this->profileImageCacheHeaders($user),
            ]);
        }

        return response()->file(public_path(User::DEFAULT_AVATAR), [
            'Content-Type' => ImageType::PNG->value,
            ...$this->profileImageCacheHeaders($user),
        ]);
    }

    public function banner (User $user): Response|BinaryFileResponse
    {
        if ($user->banner) {
            $path = User::BANNER_FOLDER.'/'.$user->banner;

            if (Storage::exists($path)) {
                $file = Storage::get($path);

                return response($file)->withHeaders([
                    'Content-Type' => $this->storageMimeType($path, ImageType::JPG->value),
                    ...$this->profileImageCacheHeaders($user),
                ]);
            }
        }

        return response()->file(public_path(User::DEFAULT_BANNER), [
            'Content-Type' => ImageType::JPG->value,
            ...$this->profileImageCacheHeaders($user),
        ]);
    }

    private function profileImageCacheHeaders(User $user): array
    {
        if ($user->is_active) {
            return ['Cache-Control' => 'public, max-age=604800, immutable'];
        }

        return ['Cache-Control' => 'private, no-store'];
    }

    private function storageMimeType(string $path, string $fallback): string
    {
        try {
            return Storage::mimeType($path) ?: $this->mimeTypeFromExtension($path, $fallback);
        } catch (\Throwable) {
            return $this->mimeTypeFromExtension($path, $fallback);
        }
    }

    private function mimeTypeFromExtension(string $path, string $fallback): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => ImageType::JPG->value,
            'png' => ImageType::PNG->value,
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => $fallback,
        };
    }

    private function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
