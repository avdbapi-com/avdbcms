<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Runtime trusted-proxy list.
 *
 * The static `$proxies` property is only read at boot; the real source
 * of truth is the admin setting `site.trusted_proxies` (string of
 * comma / newline separated IPs or CIDR ranges, or `*`). The
 * {@see \App\Providers\EnvironmentServiceProvider} already pushes the
 * parsed list into `Request::setTrustedProxies()` so this middleware
 * simply mirrors that for Laravel's own pipeline checks and is
 * a no-op on boot when nothing is configured.
 */
class TrustProxies extends Middleware
{
    /**
     * @var array<int, string>|string|null
     */
    protected $proxies = null;

    /**
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_PREFIX |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function handle($request, \Closure $next)
    {
        $configured = (string) config('site.trusted_proxies', '');
        if ($configured !== '') {
            $proxies = array_values(array_filter(array_map(
                'trim',
                preg_split('/[\r\n,]+/', $configured) ?: []
            )));

            $this->proxies = in_array('*', $proxies, true) || in_array('**', $proxies, true)
                ? '*'
                : $proxies;
        }

        return parent::handle($request, $next);
    }
}
