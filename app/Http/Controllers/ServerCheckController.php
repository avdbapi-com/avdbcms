<?php

namespace App\Http\Controllers;

use App\Support\SendFile;
use App\Support\ServerChecks;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers the site's checks of its own web server (App\Support\ServerChecks), at /_avdbcms/check/<check>/<name>. Only for
 * a check file that exists right now (a check writes it, asks, deletes it): any other address is a plain 404. No session,
 * no cookies (the route has no "web" middleware), never cached.
 *
 *  - clean-urls: the check file's content, status 200.
 *  - stream: an X-Accel-Redirect to the check file under nginx's internal /_internal/ location, exactly as a video gets
 *    (App\Support\SendFile). nginx sends the file when the location exists; when nginx sends the address back to PHP
 *    instead (no such location, only try_files), the answer is 404 and the check sees no file.
 */
class ServerCheckController extends Controller
{
    public function __invoke(Request $request, string $check, string $name): Response
    {
        $file = ServerChecks::file($name);
        $headers = ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow'];

        if ($file === null || ! in_array($check, ServerChecks::CHECKS, true) || ! is_file($file)) {
            return response('', 404, $headers);
        }

        if ($check === ServerChecks::CLEAN_URLS) {
            return response((string) @file_get_contents($file), 200, $headers + ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return SendFile::nginxCheckResponse($file, $request, $headers);
    }
}
