<?php

namespace App\Support;

use App\Models\Comment;
use App\Models\Device;
use App\Models\Notification;
use App\Models\Playlist;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Video;
use App\Models\View;
use App\Notifications\Support\Inbox;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;

/**
 * The route models an asker may not see are not found at the route binding, exactly like an id or a link that does not
 * exist: the same status, body and headers (the rule of round 2a R2A-062, App\Support\OwnerOnlyVideoRoutes, for the
 * other routes that name something by its number or its link; round 3 R3-081, R3-082, R2R-07).
 *
 * A refusal later on (a policy, a check in the controller) answered after the middleware that only runs once the model is
 * found - the language middleware's Content-Language and Vary, the site's security headers, the limiter's X-RateLimit-*,
 * the member pages' Cache-Control and preload Link, the view-only demo's headers - and often with its own status or
 * sentence (403 "You are not authorized to clear this view", "This playlist is private", "This video is private",
 * "You can't subscribe to this user"): whether another member's playlist, history row, report, invoice, comment,
 * notification or device exists, whether a member is suspended or unconfirmed, or whether a link belongs to another
 * member's private video or playlist could be read from it. Refused at the binding, they take the missing one's way
 * (a route's own missing() answer included). Who may see the model goes on to the policies and controllers as before.
 *
 * Kept as they are (deliberate public states, as the watch page and the channel page answer them): a removed video and
 * a video whose channel is gone (410), an imported video not translated into the page's language, a suspended channel's
 * page (410 "This account has been suspended"). Not here: the upload routes (hands-off; their own video routes are in
 * OwnerOnlyVideoRoutes) and the embed page (hands-off; it shows its own "private" notice by design).
 *
 * Applied by RouteServiceProvider through the router's implicit binding: lookUpAsker() before the models are bound (who
 * asks is looked up for a missing id too: the side effects of signing in must not differ), check() after
 * OwnerOnlyVideoRoutes::check(). The watch page, which finds its video itself, uses isOutFor() (VideoController).
 */
final class HiddenRouteModels
{
    /**
     * route name => [route parameter => rule] (checked in this order):
     *  own            the member's own row (another member's: missing)
     *  own_or_admin   the same, an administrator (with the admin IP lock's powers here) sees every one (InvoicePolicy)
     *  channel        a comment on one of the member's own videos (the studio's comment routes)
     *  shown_comment  a comment the comment list shows (not hidden by a moderator)
     *  author_or_shown, author_channel_or_shown, channel_or_shown
     *                 the apps' comment actions: the member's own comment (edit; delete), one on the member's own video
     *                 (delete, pin, unpin), else a comment the list shows - whose refusal (403) tells nothing new - while
     *                 one a moderator hid is missing
     *  watchable      a video the watch page shows the asker (out for everybody, the asker's own, an administrator)
     *  picture        the same for the video's picture and file routes (which also bind deleted videos)
     *  visible        a playlist its page shows the asker (public or unlisted, the asker's own, an administrator)
     *  favorite       a playlist the member may save: the member's own (its policy decides), another member's public
     *                 one, or another member's unlisted one with proof of its link (App\Support\LinkProof, R2R-06)
     *  active         a member who is active (not suspended; confirmed when the site asks for it), or the asker
     *  active_picture the same for a member's pictures, an administrator sees every one (UserPolicy::before())
     *  shown          a channel page: an unconfirmed member is missing; a suspended one keeps the page's 410 notice
     */
    public const ROUTES = [
        // R3-081: another member's rows on the routes of the member's own rows
        'user.playlists.edit' => ['playlist' => 'own'],
        'user.playlists.update' => ['playlist' => 'own'],
        'user.playlists.destroy' => ['playlist' => 'own'],
        'user.playlists.favorite' => ['playlist' => 'favorite'],
        'playlists.delete' => ['playlist' => 'own'],
        'me.clear' => ['view' => 'own'],
        'user.reports.cancel' => ['report' => 'own'],
        'user.invoices.show' => ['transaction' => 'own_or_admin'],
        'user.comments.destroy' => ['comment' => 'channel'],
        'user.comments.replies' => ['comment' => 'channel'],
        'user.notifications.click' => ['notification' => 'own'],
        'notifications.read' => ['notification' => 'own'],
        'notifications.unread' => ['notification' => 'own'],
        'notifications.remove' => ['notification' => 'own'],
        'devices.update' => ['device' => 'own'],
        'devices.delete' => ['device' => 'own'],
        // R2R-07: another member's private video (or draft, or planned) by its link, and its comments
        'videos.show' => ['video' => 'watchable'],
        'videos.view' => ['video' => 'watchable'],
        'videos.player' => ['video' => 'watchable'],
        'videos.progress' => ['video' => 'watchable'],
        'comments.index' => ['video' => 'watchable'],
        'comments.replies' => ['video' => 'watchable', 'comment' => 'shown_comment'],
        'comments.store' => ['video' => 'watchable'],
        'comments.update' => ['video' => 'watchable', 'comment' => 'author_or_shown'],
        'comments.delete' => ['video' => 'watchable', 'comment' => 'author_channel_or_shown'],
        'comments.pin' => ['video' => 'watchable', 'comment' => 'channel_or_shown'],
        'comments.unpin' => ['video' => 'watchable', 'comment' => 'channel_or_shown'],
        'video.thumbnail' => ['video' => 'picture'],
        'video.file' => ['video' => 'picture'],
        // ... and another member's private playlist by its link
        'playlist.show' => ['playlist' => 'visible'],
        'playlists.show' => ['playlist' => 'visible'],
        'playlists.videos' => ['playlist' => 'visible'],
        // R3-082: a suspended or unconfirmed member by number
        'subscribe' => ['user' => 'active'],
        'notifications.channel' => ['user' => 'active'],
        'notifications.channel.update' => ['user' => 'active'],
        'user.avatar' => ['user' => 'active_picture'],
        'user.banner' => ['user' => 'active_picture'],
        'user.show' => ['user' => 'shown'],
        'users.show' => ['user' => 'shown'],
        'users.videos' => ['user' => 'shown'],
        'users.playlists' => ['user' => 'shown'],
    ];

    /** The request attribute that keeps who asks (lookUpAsker()); false: a guest. */
    private const ASKER = 'avd.hidden_route_models.asker';

    /**
     * BEFORE the route's models are bound: who asks is looked up for a missing id as for a found one. Signing in has side
     * effects that would otherwise tell the two apart: a session renewed from a "remember me" cookie (a picture route then
     * sends a session cookie and Cache-Control: no-store), the apps' token marked as just used (the member's Devices
     * list shows it).
     */
    public static function lookUpAsker(Route $route): void
    {
        if (isset(self::ROUTES[(string) $route->getName()])) {
            request()->attributes->set(self::ASKER, self::asker($route) ?? false);
        }
    }

    /**
     * After the route's models are bound: a model the asker may not see on this route is not found.
     *
     * @throws ModelNotFoundException
     */
    public static function check(Route $route): void
    {
        $rules = self::ROUTES[(string) $route->getName()] ?? null;

        if ($rules === null) {
            return;
        }

        $known = request()->attributes->get(self::ASKER);
        $asker = $known instanceof User ? $known : ($known === false ? null : self::asker($route));

        foreach ($rules as $parameter => $rule) {
            $model = $route->parameter($parameter);

            if ($model instanceof Model && ! self::allows($rule, $model, $asker)) {
                throw (new ModelNotFoundException())->setModel($model::class, [(string) $route->originalParameter($parameter)]);
            }
        }
    }

    /**
     * Whether a video is out for this asker as the watch page decides it: out for everybody, the asker's own, or an
     * administrator's view (with the admin IP lock's powers here). A removed video and one whose channel is gone count as
     * out: their own answer (410) is the watch page's, as before.
     */
    public static function isOutFor(Video $video, ?User $asker): bool
    {
        $askerId = $asker?->getAuthIdentifier();

        return self::watchable(
            $video,
            static fn (mixed $ownerId): bool => $askerId !== null && $ownerId !== null && (string) $ownerId === (string) $askerId,
            static fn (): bool => AdminIpLock::adminPowersHere($asker),
        );
    }

    private static function allows(string $rule, Model $model, ?User $asker): bool
    {
        $askerId = $asker?->getAuthIdentifier();
        $mine = static fn (mixed $ownerId): bool => $askerId !== null && $ownerId !== null && (string) $ownerId === (string) $askerId;
        $admin = static fn (): bool => AdminIpLock::adminPowersHere($asker);

        return match ($rule) {
            'own' => $mine(self::ownerOf($model, $asker)),
            'own_or_admin' => $mine(self::ownerOf($model, $asker)) || $admin(),
            'channel' => $model instanceof Comment && $mine($model->video?->user_id),
            'shown_comment' => $model instanceof Comment && $model->banned_at === null,
            'author_or_shown' => $model instanceof Comment && ($mine($model->user_id) || $model->banned_at === null),
            'author_channel_or_shown' => $model instanceof Comment
                && ($mine($model->user_id) || $mine($model->video?->user_id) || $model->banned_at === null),
            'channel_or_shown' => $model instanceof Comment && ($mine($model->video?->user_id) || $model->banned_at === null),
            'watchable' => $model instanceof Video && self::watchable($model, $mine, $admin),
            'picture' => $model instanceof Video && ($admin() || (! $model->trashed() && ($model->is_public || $mine($model->user_id)))),
            'visible' => $model instanceof Playlist && ($model->is_active || $mine($model->user_id) || $admin()),
            'favorite' => $model instanceof Playlist && ($mine($model->user_id)
                || ($model->is_active && $model->is_deletable && LinkProof::allowsPlaylist($model, $asker, self::input('playlist_uuid')))),
            'active' => $model instanceof User && ($mine($model->getKey()) || $model->is_active),
            'active_picture' => $model instanceof User && ($mine($model->getKey()) || $model->is_active || $admin()),
            'shown' => $model instanceof User && ($mine($model->getKey()) || $model->is_active || $model->is_banned),
            default => true,
        };
    }

    /**
     * The watch page's rule (VideoPolicy::show) for "out": out for everybody, the asker's own or an administrator's view.
     * A removed video and one whose channel is gone keep the policy's own answer (410, as on the watch page).
     */
    private static function watchable(Video $video, \Closure $mine, \Closure $admin): bool
    {
        if ($video->is_banned || ($video->user_id && ! $video->user)) {
            return true;
        }

        return $video->is_public || $mine($video->user_id) || $admin();
    }

    /** The member a row belongs to (null: nobody's). */
    private static function ownerOf(Model $model, ?User $asker): mixed
    {
        return match (true) {
            $model instanceof Notification => $asker !== null && Inbox::owns($asker, $model) ? $asker->getAuthIdentifier() : null,
            $model instanceof Device => $model->getAttribute('tokenable_id'),
            $model instanceof Playlist, $model instanceof View, $model instanceof Report, $model instanceof Transaction => $model->getAttribute('user_id'),
            default => null,
        };
    }

    /**
     * Who asks: the member of the request's guard, or - on the apps' public routes (the api group), where
     * OptionalAuthSanctum signs the token in only after the binding - the token's member (the same lookup it does; the
     * site's pages never read a token).
     */
    private static function asker(Route $route): ?User
    {
        $request = request();
        $user = $request->user();

        if ($user === null && $request->bearerToken() && in_array('api', $route->gatherMiddleware(), true)) {
            $user = Auth::guard('sanctum')->user();
        }

        return $user instanceof User ? $user : null;
    }

    private static function input(string $key): ?string
    {
        $value = request()->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
