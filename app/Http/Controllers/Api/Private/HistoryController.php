<?php

namespace App\Http\Controllers\Api\Private;

use App\Http\Resources\Video\VideoListResource;
use App\Models\View;
use App\Support\WatchHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HistoryController
{
    /**
     * The member's watch history, one page at a time (QA channel-30: 1.2.2 sent the whole history in one answer).
     *
     * - JSON (the default, the mobile app): {data: [{date, label, views: [{id, video}]}], meta: {next_cursor}}; days in
     *   the site's time zone with their label made on the server; a day can continue on the next page.
     * - ?format=html (the History page's "Show older"): {html, next_cursor}: the next rows in the page's own markup
     *   (resources/views/library/history-days.blade.php), so the page needs no copy of it in JavaScript.
     * - ?q= keeps only the videos whose title contains the text (the page's search).
     *
     * With the admin's "Watch history" off (show_history; member design 6.0) the history cannot be read: 403 with the
     * site's own sentence, the one the /history page shows when it sends the member home. Removing rows (clear,
     * clearDay, clearAll) and pausing stay possible: a member can always take their own views out.
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless((bool) config('site.show_history', true), 403, __('Watch history is currently unavailable.'));

        $search = WatchHistory::search($request->query('q'));
        $page = WatchHistory::page($request->user(), $request->query('cursor'), null, $search);
        $days = WatchHistory::days($page->items());
        $next = $page->nextCursor()?->encode();

        if ($request->query('format') === 'html') {
            return response()->json([
                'html' => view('library.history-days', ['days' => $days, 'searching' => $search !== ''])->render(),
                'next_cursor' => $next,
            ]);
        }

        // the 1.2.2 shape (App\Http\Resources\ViewsCollection) plus each day's label and the next page's cursor
        return response()->json([
            'data' => $days->map(fn (array $day) => [
                'date' => $day['date'],
                'label' => $day['label'],
                'views' => $day['views']->map(fn (View $view) => [
                    'id' => $view->id,
                    'video' => new VideoListResource($view->video),
                ])->all(),
            ])->all(),
            'meta' => ['next_cursor' => $next],
        ]);
    }

    /**
     * Removes one row (the video's views of that day). 204 as before; with ?count=1 (the History page) 200 with what is
     * left: {remaining, clear_all_text} for the page's "Clear all history?" question.
     */
    public function clear(Request $request, View $view): Response|JsonResponse
    {
        View::query()
            ->where('user_id', $view->user_id)
            ->where('video_id', $view->video_id)
            ->whereDate('view_at', $view->view_at)
            ->delete();

        return $this->answer($request);
    }

    /**
     * Removes one day of the member's history (a Y-m-d date of the site's days). Same answers as clear().
     */
    public function clearDay(Request $request): Response|JsonResponse
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);

        View::query()
            ->where('user_id', $request->user()->id)
            ->whereDate('view_at', $request->date)
            ->delete();

        return $this->answer($request);
    }

    public function clearAll(Request $request): Response
    {
        $request->user()->views()->delete();

        return response()->noContent();
    }

    /**
     * "Pause history" (member design 6.11): {paused: true|false} pauses or resumes the member's history; while it is
     * paused no new row is stored (App\Services\ViewService). Answers {paused, message}; 409 while the site's update
     * has not added the switch yet. The route is me.history.pause (POST /api/me/history/pause).
     */
    public function pause(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'paused' => ['required', 'boolean'],
        ]);

        $paused = filter_var($validated['paused'], FILTER_VALIDATE_BOOLEAN);

        abort_unless(
            WatchHistory::setPaused($request->user(), $paused),
            409,
            __('This setting is not available until the site finishes its update.')
        );

        return response()->json([
            'paused' => $paused,
            'message' => $paused ? __('History paused') : __('History is on again'),
        ]);
    }

    /**
     * 204 for the mobile app and 1.2.2 pages; the History page asks for the rows that are left (?count=1).
     */
    private function answer(Request $request): Response|JsonResponse
    {
        if (! $request->boolean('count')) {
            return response()->noContent();
        }

        $remaining = WatchHistory::count($request->user());

        return response()->json([
            'remaining' => $remaining,
            'clear_all_text' => WatchHistory::clearAllText($remaining),
        ]);
    }
}
