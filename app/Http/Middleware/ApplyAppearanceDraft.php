<?php

namespace App\Http\Middleware;

use App\Support\AppearanceDraft;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Draft preview of the Appearance pages (interface I9). The settings are copied into config('site.*') while the
 * application boots, before the session and the signed-in user exist, so the draft is applied here instead (web group,
 * after StartSession): a valid ?avd_draft= link shows the unpublished changes to the admin who made them, and only to
 * them. Any response to a preview link (?avd_draft=, ?theme=, ?theme_mode=, ...) is private and never indexed.
 */
class ApplyAppearanceDraft
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query(AppearanceDraft::QUERY);

        if (is_string($token) && $token !== '') {
            $user = $request->user();

            if ($user !== null && $user->is_admin) {
                $status = AppearanceDraft::status($token, $user);

                if ($status['state'] === 'ok') {
                    AppearanceDraft::apply($status['values']);
                }

                // The banner on public pages: 'ok', or 'stale' (the link is older than the draft or expired).
                if ($status['state'] !== 'invalid') {
                    $request->attributes->set('avd.appearance_draft', $status['state']);
                }
            }
        }

        $response = $next($request);

        return AppearanceDraft::isPreviewRequest($request) ? AppearanceDraft::markPrivate($response) : $response;
    }
}
