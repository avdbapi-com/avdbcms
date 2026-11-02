<?php

namespace App\Http\Controllers\Api\Private;

use App\Events\UserSubscribed;
use App\Models\User;
use App\Support\LegacyRowTimes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SubscribeController
{
    /**
     * Subscribe to a channel or leave it.
     *
     * - With "state" = "on" or "off" (the site's Subscribe buttons since 1.2.3): the member ends up subscribed or not,
     *   whatever the button showed before (a second tab or a double tap can no longer flip it back), and the answer is
     *   {subscribed, subscribers}: the channel's new count, or null when the channel or the site hides counts.
     * - Without it (1.2.2 clients, the mobile app): the old toggle, answered with 204.
     *
     * The subscription's times are the application's clock (QA studio-23: the database default wrote its own time zone's
     * time, so new subscribers showed "5 hours from now"), once the update has noted the rows that clock dated before
     * (App\Support\LegacyRowTimes puts them in the site's zone); until then the database's clock, like the rows before.
     */
    public function subscribe (Request $request, User $user) : Response|JsonResponse {
        $member = $request->user();
        $state = $request->input('state');
        $pivot = LegacyRowTimes::stamp('subscriptions');

        if (! in_array($state, ['on', 'off'], true)) {
            $subscription = $member->subscriptions()->toggle([$user->getKey() => $pivot]);
            UserSubscribed::dispatchIf($subscription['attached'] !== [], $user, $member);

            return response()->noContent();
        }

        $subscribed = $member->subscriptions()->whereKey($user->getKey())->exists();

        if ($state === 'on' && ! $subscribed) {
            $member->subscriptions()->attach($user->getKey(), $pivot);
            UserSubscribed::dispatch($user, $member);
        } elseif ($state === 'off' && $subscribed) {
            $member->subscriptions()->detach($user->getKey());
        }

        $countShown = $user->show_subscribers && config('site.show_subscriber_count', true);

        return response()->json([
            'subscribed' => $state === 'on',
            'subscribers' => $countShown ? $user->subscribers()->count() : null,
        ]);
    }

}
