<?php

namespace App\Http\Middleware;

use App\Models\Video;
use App\Support\Upload18\UploadClaims;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The status checks and the finish of a member's Upload18 upload (routes/api.php, videos.upload18.status and
 * videos.upload18.complete). An upload this member already announced (a pending draft, App\Support\Upload18\UploadClaims)
 * passed the upload limits when it was kept, with its file size: checking them again would count its own draft twice, so
 * a free member's last allowed upload would be refused at 92 % although it is already on Upload18. Every other request
 * is authorized exactly as before (VideoPolicy::upload: uploads switched on, the free plan's count and space).
 */
final class AuthorizeUpload18Continue
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $did = $request->input('did');

        if ($user !== null && is_scalar($did) && UploadClaims::holds((int) $user->getKey(), trim((string) $did))) {
            return $next($request);
        }

        Gate::forUser($user)->authorize('upload', Video::class);

        return $next($request);
    }
}
