<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature as Middleware;
use Illuminate\Support\Facades\URL;

/**
 * Signed links (the e-mail confirmation of a new account and of a changed address).
 *
 * The site builds every link from APP_URL, in https whenever the site's address or the request says https
 * (EnvironmentServiceProvider), but the request a link opens can reach PHP under another scheme or host than the one
 * the link was signed with:
 *  - Cloudflare Flexible SSL in front of a server that puts the visitor's address in REMOTE_ADDR (mod_remoteip,
 *    OpenLiteSpeed "use client IP"): the request arrives over http, and its X-Forwarded-Proto is not from a proxy the
 *    site trusts, so Laravel sees http;
 *  - a TLS proxy in front that sends no proxy header at all;
 *  - a proxy that passes its own Host on (127.0.0.1:8080) instead of the site's.
 * Laravel compares the signature with the request's own address only, so such a link answered 403 Invalid signature.
 *
 * When that check fails, the same path and query are checked again under https and under the site's own address (the
 * root the links are built with, in its scheme and in https). The key, the path, the query and the expiry are the same
 * as in Laravel's check, so nobody without APP_KEY can make a link that passes, and a link signed for another site or
 * changed in any way still answers 403.
 *
 * The path is also checked in its other form, with or without /index.php in front: a site whose links keep /index.php
 * (a web server without rewrite rules: the fleet's nodes 2, 4 and 7) signs /index.php/email/verify/..., while
 * public/index.php takes /index.php off the address before Laravel compares it, so every such link answered 403 (round
 * 1b, R1B-007); a link signed before the owner added the rewrite rules still opens after it.
 */
class ValidateSignature extends Middleware
{
    /**
     * The names of the query string parameters that should be ignored.
     *
     * @var array<int, string>
     */
    protected $except = [
        // 'fbclid',
        // 'utm_campaign',
        // 'utm_content',
        // 'utm_medium',
        // 'utm_source',
        // 'utm_term',
    ];

    /**
     * @param  \Illuminate\Http\Request  $request
     *
     * @throws InvalidSignatureException
     */
    public function handle($request, Closure $next, ...$args)
    {
        [$relative, $ignore] = $this->parseArguments($args);

        if ($request->hasValidSignatureWhileIgnoring($ignore, ! $relative)) {
            return $next($request);
        }

        if (! $relative) {
            foreach (self::otherAddresses($request) as $address) {
                if (URL::hasValidSignature(self::requestAt($request, $address), true, $ignore)) {
                    return $next($request);
                }
            }
        }

        throw new InvalidSignatureException;
    }

    /**
     * The request's address (without the query) under the other origins the site may have signed it with: https with
     * the request's own host, and the root the site builds its links with (APP_URL), in its scheme and in https.
     *
     * @return list<string>
     */
    public static function otherAddresses(Request $request): array
    {
        $current = $request->url();
        $origin = $request->getSchemeAndHttpHost();

        if ($origin === '' || ! str_starts_with($current, $origin)) {
            return [];
        }

        // The part after the origin: a sub folder, /index.php and the route's path ('' for the home page).
        $rest = substr($current, strlen($origin));
        $origins = [$origin, 'https://'.$request->getHttpHost()];

        try {
            $root = URL::formatRoot(URL::formatScheme());
        } catch (\Throwable) {
            $root = '';
        }

        if (preg_match('#^(https?)://([^/?\#]+)#i', $root, $match) === 1) {
            $origins[] = strtolower($match[1]).'://'.$match[2];
            $origins[] = 'https://'.$match[2];
        }

        $addresses = [];

        foreach ([$rest, self::otherIndexPhpForm($rest, $request->getBasePath())] as $path) {
            foreach ($origins as $other) {
                // Laravel's own check already had the request's address.
                if (strcasecmp($other.$path, $current) !== 0) {
                    $addresses[] = $other.$path;
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * The path in its other form: without /index.php in front when it has it, with it when it has not; after the folder
     * of a site installed in one ("/site/email/verify/..." <-> "/site/index.php/email/verify/...", R2R-05).
     */
    private static function otherIndexPhpForm(string $path, string $folder = ''): string
    {
        $folder = rtrim($folder, '/');

        if ($folder !== '' && ($path === $folder || str_starts_with($path, $folder.'/'))) {
            return $folder.self::otherIndexPhpForm((string) substr($path, strlen($folder)));
        }

        if (preg_match('#^/index\.php(?=$|/)#i', $path) === 1) {
            return (string) substr($path, strlen('/index.php'));
        }

        return '/index.php'.$path;
    }

    /** A copy of the request at another address, with the query string exactly as it came (the signature covers it). */
    private static function requestAt(Request $request, string $address): Request
    {
        $query = (string) $request->server->get('QUERY_STRING', '');

        return Request::create($address.($query !== '' ? '?'.$query : ''), 'GET');
    }
}
