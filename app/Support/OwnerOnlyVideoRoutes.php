<?php

namespace App\Support;

use App\Models\Video;
use App\Policies\VideoPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Route;

/**
 * The routes of a video that answer only its channel: the upload's draft checks and pictures, the thumbnails list, who
 * rated it (R1-090), deleting it, and the studio's video pages and actions. There another member's video is not found
 * at the route binding, exactly like an id that does not exist (round 2a R2A-062).
 *
 * The policies refused it later, after the middleware that runs once a video is found: so an existing id answered
 * differently from a missing one (403 on the edit routes and the draft checks, "Not Found" against the missing id's
 * sentence on the thumbnails list) and with other headers (Content-Language and Vary of the language middleware, the
 * limiter's X-RateLimit-*, the view-only demo's Cache-Control and its refusal of a change): whether a private or draft
 * video id exists could be read from any of them. Refused at the binding, both take the same way through the site:
 * the same status, body and headers. The video's channel and an administrator (what VideoPolicy::before() gives, the
 * admin IP lock applies) go on to the policies as before.
 *
 * Applied by RouteServiceProvider through the router's implicit binding (routes, controllers and the HTTP kernel stay
 * as they are; the upload routes are hands-off).
 */
final class OwnerOnlyVideoRoutes
{
    /** route name => the policy ability the route checks (VideoPolicy::ADMIN_ABILITIES let an administrator in) */
    public const ROUTES = [
        'videos.create-status' => 'update',
        'videos.generate-thumbnails' => 'update',
        'videos.generate-server-thumbnails' => 'update',
        'videos.delete' => 'delete',
        'thumbnails.list' => 'thumbnails',
        'interactions.list' => 'interactions',
        'user.videos.show' => 'view',
        'user.videos.edit' => 'update',
        'user.videos.update' => 'update',
        'user.videos.create' => 'update',
        'user.videos.store' => 'update',
        'user.videos.destroy' => 'delete',
        'user.videos.pin' => 'pin',
        'user.videos.unpin' => 'unpin',
        'video.thumbnails' => 'thumbnails',
    ];

    /**
     * After the route's models are bound: on these routes, a video the member may not open there is not found.
     *
     * @throws ModelNotFoundException
     */
    public static function check(Route $route): void
    {
        $ability = self::ROUTES[(string) $route->getName()] ?? null;
        $video = $ability === null ? null : $route->parameter('video');

        if (! $video instanceof Video) {
            return;
        }

        $user = request()->user();

        if ($user !== null && (string) $video->user_id === (string) $user->getAuthIdentifier()) {
            return;
        }

        if ($user !== null && in_array($ability, VideoPolicy::ADMIN_ABILITIES, true) && AdminIpLock::adminPowersHere($user)) {
            return;
        }

        throw (new ModelNotFoundException())->setModel(Video::class, [(string) $route->originalParameter('video')]);
    }
}
