<?php

namespace App\Support;

use App\Models\User;
use App\Models\View;
use App\Services\SeoService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * A member's watch history, as the History page and its API page it (member design 6.11, QA channel-30).
 *
 * One row per video and day (the day's latest view of that video), newest first, only of videos the member may still
 * watch. The list pages with a cursor (App\Support\SafeCursor: a cursor that does not fit gives the first page), so an
 * active member never gets thousands of rows in one answer; the days are the site's days (app.timezone) and their
 * labels (Today, Yesterday, "Thursday, Oct 22" in the admin's date format) are made here, in the member's language, so
 * the page and the API agree and no browser time zone can shift a day (the 1.2.2 page read "2026-10-25" as UTC
 * midnight: one day off west of UTC).
 *
 * The days are read from views.view_at as the site's wall-clock time, the way the application writes it. A view stored
 * by the database's own clock (its column default, in the database server's time zone) lands on the database's day:
 * App\Services\ViewService writes view_at itself since 1.2.3.
 *
 * "Pause history" (6.11): a member who paused gets no new rows (ViewService asks isPaused()). The switch is the column
 * users.history_paused_at (migration 2026_10_26_210000; until "Finish update" adds it, pausing is not offered).
 */
final class WatchHistory
{
    /**
     * Rows of the first page the History page prints itself (the API pages with per_page_videos): 8, as the mockup, so
     * the page content stays within its budget (member design 4.3: <= 16 KB / 3 KB gzip / 250 nodes).
     */
    public const FIRST_PAGE = 8;

    /** The longest search text that is used. */
    public const MAX_SEARCH = 100;

    /** The member's "Pause history" switch: the time it was paused, NULL while history is on. */
    public const PAUSE_COLUMN = 'history_paused_at';

    /** The route the History page calls to pause or resume (routes/api.php, the "me" group). */
    public const PAUSE_ROUTE = 'me.history.pause';

    /**
     * The history rows of $user, newest first, optionally only videos whose title contains $search.
     */
    public static function query(User $user, string $search = ''): Builder
    {
        $search = self::search($search);

        // the latest view of each video on each day (its row id; ids grow with time)
        $latestOfDay = View::query()
            ->selectRaw('MAX(id) AS latest_id')
            ->where('user_id', $user->getKey())
            ->groupByRaw('video_id, DATE(view_at)');

        return View::query()
            ->select('views.*')
            ->joinSub($latestOfDay, 'daily', 'daily.latest_id', '=', 'views.id')
            ->where('views.user_id', $user->getKey())
            ->whereHas('video', function (Builder $video) use ($search): void {
                $video->public(true);

                if ($search !== '') {
                    $video->where('title', 'like', '%' . addcslashes($search, '\\%_') . '%');
                }
            })
            // the channel, and what the owner's "Video link format" prints in each row's link (a {category} pattern
            // needs the category: without it every row asked for its own, as 1.2.2's history API did not)
            ->with(['video' => fn ($video) => $video->with(self::videoRelations())])
            ->orderByDesc('views.view_at')
            ->orderByDesc('views.id');
    }

    /**
     * The relations a video row of the member pages prints: its channel and the parts of the owner's video link format
     * (App\Services\SeoService::videoPathRelations()).
     *
     * @return list<string>
     */
    public static function videoRelations(): array
    {
        return array_values(array_unique(['user', ...app(SeoService::class)->videoPathRelations()]));
    }

    /**
     * One page of the history: the rows of $cursor's page.
     */
    public static function page(User $user, mixed $cursor = null, ?int $perPage = null, string $search = ''): CursorPaginator
    {
        $perPage ??= max(1, min(100, (int) config('site.per_page_videos', 24)));

        return SafeCursor::paginate(self::query($user, $search), $perPage, $cursor);
    }

    /**
     * Rows grouped by the site's day, in their order: [['date' => 'Y-m-d', 'label' => ..., 'views' => Collection], ...].
     *
     * @param  iterable<View>  $views
     * @return Collection<int, array{date: string, label: string, views: Collection<int, View>}>
     */
    public static function days(iterable $views, ?CarbonInterface $now = null): Collection
    {
        $now ??= now();

        return collect($views)
            ->groupBy(fn (View $view) => Carbon::parse($view->view_at)->timezone(config('app.timezone'))->format('Y-m-d'))
            ->map(fn (Collection $rows, string $date) => [
                'date' => $date,
                'label' => self::dayLabel($date, $now),
                'views' => $rows->values(),
            ])
            ->values();
    }

    /**
     * "Today", "Yesterday", else the weekday and the date in the admin's date format ("Thursday, Oct 22"; the year only
     * when it is not this year), in the current language.
     */
    public static function dayLabel(string $date, ?CarbonInterface $now = null): string
    {
        $now = Carbon::parse($now ?? now())->timezone(config('app.timezone'))->startOfDay();

        try {
            $day = Carbon::createFromFormat('!Y-m-d', $date, config('app.timezone'));
        } catch (\Throwable) {
            return $date;
        }

        if (! $day instanceof Carbon) {
            return $date;
        }

        $days = (int) $day->diffInDays($now, false);
        $day->locale(app()->getLocale());

        return match (true) {
            $days === 0 => __('Today'),
            $days === 1 => __('Yesterday'),
            default => __(':weekday, :date', ['weekday' => $day->translatedFormat('l'), 'date' => SiteDate::dayDate($day, $now)]),
        };
    }

    /**
     * The question of a day's "Remove": "Remove today's history?", "Remove yesterday's history?", else "Remove the
     * history of Thursday, Oct 22?".
     */
    public static function removeDayQuestion(string $date, ?CarbonInterface $now = null): string
    {
        $now = Carbon::parse($now ?? now())->timezone(config('app.timezone'))->startOfDay();

        return match (self::daysAgo($date, $now)) {
            0 => __('Remove today\'s history?'),
            1 => __('Remove yesterday\'s history?'),
            default => __('Remove the history of :day?', ['day' => self::dayLabel($date, $now)]),
        };
    }

    /**
     * Whether a day's question is a sentence of its own (today's, yesterday's) rather than "Remove the history of :day?"
     * with the day's label. The History page prints only those on the day's Remove (the page script makes the others
     * from the dialog's template): decided by the day, never by the words, so the page's markup is the same in every
     * language (1.2.3 round 3, R1-069).
     */
    public static function hasOwnDayQuestion(string $date, ?CarbonInterface $now = null): bool
    {
        return in_array(self::daysAgo($date, Carbon::parse($now ?? now())->timezone(config('app.timezone'))->startOfDay()), [0, 1], true);
    }

    /** How many of the site's days ago $date (Y-m-d) was, from the start of $today; null for a date that does not parse. */
    private static function daysAgo(string $date, CarbonInterface $today): ?int
    {
        try {
            $day = Carbon::createFromFormat('!Y-m-d', $date, config('app.timezone'));

            return $day instanceof Carbon ? (int) $day->diffInDays($today, false) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The number of history rows of $user (the Library shelf count), without the search.
     */
    public static function count(User $user): int
    {
        return self::query($user)->toBase()->getCountForPagination();
    }

    /**
     * The sentence of the "Clear all history?" dialog for $count rows.
     */
    public static function clearAllText(int $count): string
    {
        return trans_choice(':count video leaves your history on every device. Your likes and playlists stay.|All :count videos leave your history on every device. Your likes and playlists stay.', $count, ['count' => LocalNumber::short($count)]);
    }

    /**
     * The search text as it is used: trimmed, at most MAX_SEARCH characters.
     */
    public static function search(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, self::MAX_SEARCH) : '';
    }

    /**
     * Whether $user can pause: the site has the switch (its column is in the member's row, which the session loads
     * whole: no extra query) and the route that sets it.
     */
    public static function pauseAvailable(?User $user): bool
    {
        return self::pauseReady($user) && Route::has(self::PAUSE_ROUTE);
    }

    /**
     * Whether the member's row has the switch ("Finish update" adds the column).
     */
    public static function pauseReady(?User $user): bool
    {
        return $user instanceof User && array_key_exists(self::PAUSE_COLUMN, $user->getAttributes());
    }

    /**
     * Whether $user paused their history (false for guests and before the column exists).
     */
    public static function isPaused(?User $user): bool
    {
        return $user instanceof User && ($user->getAttributes()[self::PAUSE_COLUMN] ?? null) !== null;
    }

    /**
     * Pauses or resumes $user's history. False when the site does not have the switch yet.
     */
    public static function setPaused(User $user, bool $paused): bool
    {
        if (! self::pauseReady($user)) {
            return false;
        }

        $value = $paused ? now() : null;

        // a plain update of the one column: no model events (an account save would also touch updated_at)
        DB::table($user->getTable())->where($user->getKeyName(), $user->getKey())->update([self::PAUSE_COLUMN => $value]);
        $user->setRawAttributes(array_replace($user->getAttributes(), [self::PAUSE_COLUMN => $value?->format('Y-m-d H:i:s')]), true);

        return true;
    }
}
