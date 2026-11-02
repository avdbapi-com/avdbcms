<?php

namespace App\Support;

use Illuminate\Routing\UrlGenerator;

/**
 * Laravel's URL generator with one difference (round 3 fix pass, R2R-05): a route's address without the scheme and the
 * host (route($name, $parameters, false), action(..., false)) is the path the browser asks for, from the domain's root.
 * For a site installed in a folder of its domain (https://example.com/site) that path starts with the folder
 * ("/site/admin/users", "/site/index.php/admin/users" where the site's links carry /index.php). Laravel's own relative
 * address is relative to the application and leaves the folder out ("/admin/users"): every page, form and script that
 * printed one (the admin's lists and forms, the updater's steps, the upload window's checks, the notification page's
 * requests) asked the domain's root instead of the site.
 *
 * At a domain's root this is exactly Laravel's relative address, and every absolute address (route(), url(), the
 * redirects) is Laravel's own everywhere. A path of the site (without the folder) for url(), redirect()->to() or
 * locale_url() is route(..., false) through site_path(), or route() itself.
 *
 * Bound in place of Laravel's generator by App\Providers\EnvironmentServiceProvider::register().
 */
class SiteUrlGenerator extends UrlGenerator
{
    /**
     * @param  \Illuminate\Routing\Route  $route
     * @param  mixed  $parameters
     * @param  bool  $absolute
     * @return string
     */
    public function toRoute($route, $parameters, $absolute)
    {
        $url = parent::toRoute($route, $parameters, true);

        if ($absolute) {
            return $url;
        }

        // The absolute address without its scheme and host (as Laravel takes them off its relative one), with the
        // folder that Laravel takes off too.
        return '/'.ltrim((string) preg_replace('#^(//|[^/?])+#', '', $url), '/');
    }
}
