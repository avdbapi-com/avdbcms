<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * avdbcms_update (App\Support\Updater\Updater::COOKIE, written as a literal so this middleware, which runs on every
     * request, does not load the updater): the one-click update's cookie. The plain-PHP maintenance hook reads it
     * without Laravel while the files are swapped, so it must reach the browser as it is; it holds a random value whose
     * SHA-256 only the update plan knows.
     *
     * avd_hc (App\Http\Middleware\Security::HOST_COOKIES_MARK): "this browser dropped the old Domain cookies"; it holds 1.
     *
     * avd_try_look and avd_demo_ads (App\Support\VisitorChoices::LOOK_COOKIE, ADS_COOKIE): a demo site's visitor choices,
     * written by the page's own script (the look this browser tries, ads turned on); read only while their settings are
     * on, and only a valid choice counts.
     *
     * @var array<int, string>
     */
    protected $except = [
        'avdbcms_update',
        'avd_hc',
        'avd_try_look',
        'avd_demo_ads',
    ];
}
