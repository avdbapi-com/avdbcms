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
     * @var array<int, string>
     */
    protected $except = [
        'avdbcms_update',
    ];
}
