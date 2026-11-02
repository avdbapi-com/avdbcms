<?php

namespace App\Http\Controllers\Api\Private;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ViewOnlyDemo;
use App\Models\Notification;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\Support\BellResource;
use App\Notifications\Support\Inbox;
use App\Notifications\Support\NotificationTasks;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Laravel\Sanctum\TransientToken;

/**
 * The bell's list and actions for the signed-in member (quick with a million notifications, see Inbox). Every answer
 * carries the member's unread count (up to 100, the badge shows "99+" from there). The single-item routes answer 404
 * for a notification of another member (NotificationPolicy).
 *
 * "Mark as read", "Mark as unread" and "Mark all as read" are POST since 1.2.3. The GET of older pages (a page still
 * open during an update) is still answered, but only with the page's own token header, so another site cannot make a
 * member's browser do it; a view-only demo account gets the same 403 there as for the POST (ViewOnlyDemo refuses those
 * before this controller runs).
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        [$page, $markers] = Inbox::page($user);
        $this->carryOn($request);
        BellResource::preload($page->items(), $user);

        // The rows as the list draws them: the sentence in parts with the names in bold, the member's avatar
        // and the video's picture when the notification names them (BellResource); the same fields as before besides.
        return BellResource::collection($page)
            ->additional(['unread' => Inbox::unreadCount($user, $markers), 'unread_cap' => Inbox::BADGE_CAP])
            ->response();
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        $this->refuseForeignGet($request);
        $notification->markAsRead();

        return $this->answer($request);
    }

    public function unread(Request $request, Notification $notification): JsonResponse
    {
        $this->refuseForeignGet($request);
        // always saved with a new updated_at: a "Mark all as read" still running skips what the member changed after
        // the click (a row it has not reached yet is unread already, so markAsUnread() alone would not save it)
        $notification->forceFill(['read_at' => null, 'updated_at' => now()])->save();
        NotificationTasks::keepUnread((int) $request->user()->getKey(), $notification);

        return $this->answer($request);
    }

    public function delete(Request $request, Notification $notification): JsonResponse
    {
        $notification->delete();

        return $this->answer($request);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->refuseForeignGet($request);

        return response()->json(Inbox::readAll($request->user()));
    }

    public function deleteAll(Request $request): JsonResponse
    {
        return response()->json(Inbox::deleteAll($request->user()));
    }

    public function deleteSelected(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['string', 'max:64'],
        ]);
        $user = $request->user();
        $deleted = Inbox::deleteSelected($user, $validated['ids']);

        return response()->json(['deleted' => $deleted, 'unread' => Inbox::unreadCount($user)]);
    }

    /**
     * The bell of a Subscribe button (member design 6.14): which new videos of this channel reach the member's bell.
     * "all" (every new video), "highlights" (its videos, not its Shorts) or "none"; the choice wins over the member's
     * global "New videos from channels I follow" (Account settings > Notifications), which only counts for channels
     * left on their default ("all" without a choice). 404 when the member does not follow the channel.
     * Interface for the Subscribe button (Subscribe.tsx): GET and POST /api/notifications/channels/{user}, {level}.
     */
    public function channel(Request $request, User $user): JsonResponse
    {
        $level = NotificationSetting::bellFor($request->user(), $user);

        abort_if($level === null, 404, __('You do not follow this channel.'));

        return response()->json(['level' => $level]);
    }

    public function setChannel(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'level' => ['required', 'string', 'in:'.implode(',', NotificationSetting::BELLS)],
        ]);

        abort_if(NotificationSetting::bellFor($request->user(), $user) === null, 404, __('You do not follow this channel.'));

        if (! NotificationSetting::setBell($request->user(), $user, $validated['level'])) {
            abort(409, __('This setting is not available until the site finishes its update.'));
        }

        return response()->json(['level' => $validated['level']]);
    }

    /** The member's unread count after a change (the badge's cache is forgotten first). */
    private function answer(Request $request): JsonResponse
    {
        $user = $request->user();
        Inbox::forget($user);

        return response()->json(['unread' => Inbox::unreadCount($user)]);
    }

    /**
     * A GET that changes something (pages opened before 1.2.3) needs the page's token header: a link or an image on
     * another site cannot send it. Apps signed in with an API token are not affected (a browser signed in by its
     * session cookie always is, whatever Authorization header the request carries). A view-only demo account is
     * refused there like on the POST: 403 with the view-only message, nothing started (a GET of such an account is
     * rolled back, but a fake success would still tell the page it worked).
     */
    private function refuseForeignGet(Request $request): void
    {
        if (! $request->isMethodSafe()) {
            return; // POST: the token middleware and ViewOnlyDemo checked it before
        }

        if (! self::signedInWithToken($request)) {
            $token = (string) $request->header('X-CSRF-TOKEN', '');
            $session = $request->hasSession() ? (string) $request->session()->token() : '';

            if ($token === '' || $session === '' || ! hash_equals($session, $token)) {
                throw new TokenMismatchException('CSRF token mismatch.'); // the site's usual "refresh the page" answer (419)
            }
        }

        if (ViewOnlyDemo::appliesTo($request)) {
            $message = __('This is a view-only demo account: you can look at everything, but changes are switched off.');
            $response = new JsonResponse([
                'ok' => false,
                'success' => false,
                'message' => $message,
                'error' => $message,
                'errors' => ['view_only_demo' => [$message]],
            ], 403);
            $response->headers->set(ViewOnlyDemo::HEADER, '1');
            $response->headers->set('Cache-Control', 'no-store, private');

            throw new HttpResponseException($response);
        }
    }

    /** Whether the member was signed in by an API token (an app), not by the browser's session (Sanctum: TransientToken). */
    private static function signedInWithToken(Request $request): bool
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'currentAccessToken')) {
            return false;
        }

        $token = $user->currentAccessToken();

        return $token !== null && ! $token instanceof TransientToken;
    }

    /** The bell's own requests carry long notification work on when no worker does (never for a view-only account). */
    private function carryOn(Request $request): void
    {
        if (! ViewOnlyDemo::appliesTo($request)) {
            NotificationTasks::continueAfterResponse();
        }
    }
}
