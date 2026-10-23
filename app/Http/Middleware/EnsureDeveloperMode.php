<?php

namespace App\Http\Middleware;

use App\Support\AdminNav;
use App\Support\AdminPreferences;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The developer pages (Custom code, Database, Developer zone: config/admin_nav.php 'developer') open only
 * for admins who turned Developer mode on in their account menu; the others get a 403 page that says how.
 * The menu hides these pages too, but the server decides. ADMIN_OPERATIONAL_TOOLS_ENABLED (admin.ops)
 * still applies on top. The old admin (admin.ui v1) keeps its previous behaviour for its one release.
 *
 * Until "Finish update" has added the preferences column, Developer mode cannot be switched on, so the pages
 * stay open as they were before (the database tools are among the ones that finish a failed update).
 */
class EnsureDeveloperMode
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $user = $request->user();

        if (! $route instanceof Route
            || ! AdminNav::isDeveloperRoute($route)
            || ! AdminPreferences::usesNewAdmin($user)
            || ! AdminPreferences::available($user)
            || AdminPreferences::developerMode($user)) {
            return $next($request);
        }

        $message = __('This tool is for developers. Turn on Developer mode in your account menu to use it.');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        if (! $request->isMethodSafe()) {
            abort(403, $message);
        }

        return response()->view('components.admin.developer-required', [
            'adminPage' => AdminNav::current($request)['id'] ?? null,
            'message' => $message,
        ], 403);
    }
}
