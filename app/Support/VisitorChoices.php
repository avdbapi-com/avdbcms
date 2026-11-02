<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What a visitor chose for their own browser on a demo site (1.2.3, the owner's request of 29 Oct): a look to try (the
 * round palette button; Appearance > Themes > "Let visitors try the looks") and whether ads show (the "Demo · Ads off"
 * pill; Money > Ads > "Demo site: ads are off until a visitor turns them on"). Both settings are off by default: then
 * nothing here runs, no cookie is read and no page changes.
 *
 * The choices live in two cookies the page's own script writes (30 days, the site's path, SameSite=Lax, Secure on
 * https), never on the server: the look in LOOK_COOKIE as "<look id>.<dark|light>" (one of the 12 Complete looks of
 * MasterExperienceCatalog), ads in ADS_COOKIE as "1" while this browser turned ads on. A cookie is read only while its
 * setting is on, only for a page of the public site, and only when it holds exactly one of these values; anything else
 * is ignored and removed. A page of the public site is every page the public layout draws, whatever its address or
 * method: the ApplyVisitorChoices middleware applies the choices before the controller of a page a visitor reads
 * (isSitePage()), the layout applies them to anything else it draws (applyForLayout(): the 404 page of an /admin, /api
 * or /install address, the answer to a form such as "This page timed out", an error page under /profile). Never the
 * admin's own pages (their layout), an embed page, the installer (its own pages) or an API answer (no layout). A themed
 * mail sent during such a request is made in the site's own look (guardMails()).
 *
 * A tried look is rendered like a preview: its settings over the stored ones for this request only, through the code
 * that applies the published ones (AppearanceSettings::applyToConfig, as an admin's draft link does). The published look,
 * the Appearance settings and every other visitor stay as they are. An explicit preview link (?theme=, ?avd_draft=, ...)
 * shows that preview instead.
 *
 * Ads: while the demo switch is on, config('site.ads_enabled') is false for a visitor who did not turn ads on, so every
 * place that prints an ad leaves it out (the layout's banners, phone strip, pop-under and window.AD_CONFIG with the
 * pre-roll, the list ads, the watch page's side ad, the channel page's card ads): the path that already gives Premium
 * members no ads, without touching the player or the pre-roll component. The member back office (/profile: the studio
 * and Account settings) prints no ad anyway and keeps the site's own ad options in its forms.
 *
 * Such pages depend on the visitor's cookies, so while either setting is on no page is shared by a cache
 * (EdgeCacheVideoHtml calls markPrivate()).
 */
final class VisitorChoices
{
    public const LOOK_COOKIE = 'avd_try_look';

    public const ADS_COOKIE = 'avd_demo_ads';

    public const COOKIE_DAYS = 30;

    public const MODES = ['dark', 'light'];

    /** Request attribute: the look this browser tries, ['look' => id, 'mode' => dark|light] (valid cookie only). */
    public const LOOK_ATTRIBUTE = 'avd.try_look';

    /** Request attribute: ['site' => the site shows ads, 'on' => this browser turned them on] while the demo switch is on. */
    public const ADS_ATTRIBUTE = 'avd.demo_ads';

    /** Request attribute: the choices were read and applied for this request (once). */
    private const DONE_ATTRIBUTE = 'avd.visitor_choices';

    /** Request attribute: the names of this feature's cookies that hold something else than a valid choice. */
    private const FORGET_ATTRIBUTE = 'avd.visitor_choices_forget';

    /** Request attribute: a tried look was applied to this request's config (guardMails() puts the site's look back). */
    private const APPLIED_ATTRIBUTE = 'avd.try_look_applied';

    /** Container key: the mail guard is registered once per application. */
    private const MAIL_GUARD = 'avd.visitor_choices.mail_guard';

    private const LOOK_PATTERN = '/^([a-z0-9_]{1,64})\.(dark|light)$/D';

    /** Paths that are never pages of the public site (public/index.php has taken /index.php and the language off). */
    private const NOT_SITE_PATHS = ['admin', 'admin/*', 'api', 'api/*', 'install', 'install/*', 'index.php/install', 'index.php/install/*', 'video/*/embed'];

    private const NOT_SITE_ROUTES = ['admin.*', 'install.*', 'video.embed'];

    /** The member back office (routes/user.php: the studio and Account settings). */
    private const BACK_OFFICE_PATHS = ['profile', 'profile/*'];

    public static function lookPickerOn(): bool
    {
        return filter_var(config('site.visitor_look_picker_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function demoAdsOn(): bool
    {
        return filter_var(config('site.demo_ads_switch_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function anyOn(): bool
    {
        return self::lookPickerOn() || self::demoAdsOn();
    }

    /**
     * A page of the public site a visitor reads (GET or HEAD) whose controller may run with the choices applied (the
     * ApplyVisitorChoices middleware): not an address of the admin, an embed page, the installer or the API. What the
     * public layout draws for any other request (the 404 page of such an address, the answer to a form) gets the choices
     * from the layout itself (applyForLayout()).
     */
    public static function isSitePage(Request $request): bool
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }

        $name = $request->route()?->getName();

        if (is_string($name) && Str::is(self::NOT_SITE_ROUTES, $name)) {
            return false;
        }

        return ! $request->is(...self::NOT_SITE_PATHS);
    }

    /**
     * The ApplyVisitorChoices middleware: read this browser's choices and apply them before the controller of a page of
     * the public site runs (isSitePage()), so the controller and every view already see them (a channel page's list ads,
     * the watch page's box next to the video). Once per request.
     * Returns the cookies to remove (names): those that hold something else than a valid choice.
     *
     * @return list<string>
     */
    public static function applyFor(Request $request): array
    {
        if (! self::anyOn() || ! self::isSitePage($request)) {
            return [];
        }

        return self::apply($request);
    }

    /**
     * The public layout (components/layout): every page it draws is a page of the public site, whatever its address or
     * method, so the choices count there too: the 404 page of an /admin, /api or /install address (what a guest, a
     * member or the view-only demo account gets for /admin), the answer to a form ("This page timed out"), an error page
     * under /profile, a page rendered outside the web middleware (the maintenance page). Once per request: nothing more
     * when the middleware already applied them. Never in the admin's own layout or an embed page; nothing while both
     * settings are off.
     */
    public static function applyForLayout(Request $request, string $layoutType): void
    {
        if ($layoutType === 'admin' || ! self::anyOn() || self::isEmbed($request)) {
            return;
        }

        self::apply($request);

        // The member back office's own pages (layout "user") print no ad, and their forms keep the site's own ad options
        // (apply() leaves the switch alone under /profile). Any other page the public layout draws there (an error page)
        // leaves the ads out like every page of the site.
        if ($layoutType !== 'user') {
            self::leaveAdsOut($request);
        }
    }

    /**
     * The cookies of this feature that hold something else than a valid choice, found for this request (by the
     * middleware or by the layout): the ApplyVisitorChoices middleware removes them on the way out.
     *
     * @return list<string>
     */
    public static function cookiesToForget(Request $request): array
    {
        $forget = $request->attributes->get(self::FORGET_ATTRIBUTE);

        return is_array($forget) ? array_values(array_filter($forget, 'is_string')) : [];
    }

    /**
     * Read the two cookies (each only while its setting is on), keep the choices on the request and apply them to this
     * request's config, once per request.
     *
     * @return list<string> the cookies to remove
     */
    private static function apply(Request $request): array
    {
        if ($request->attributes->get(self::DONE_ATTRIBUTE) === true) {
            return [];
        }

        $request->attributes->set(self::DONE_ATTRIBUTE, true);
        $looks = self::lookPickerOn();
        $ads = self::demoAdsOn();
        $forget = [];

        if ($looks) {
            $raw = self::cookie($request, self::LOOK_COOKIE);
            $choice = self::parseLook($raw);

            if ($raw !== null && $choice === null) {
                $forget[] = self::LOOK_COOKIE;
            }

            if ($choice !== null) {
                $request->attributes->set(self::LOOK_ATTRIBUTE, $choice);

                // An explicit preview link shows that preview (the visitor still sees their choice marked in the panel).
                if (! AppearanceDraft::isPreviewRequest($request)) {
                    self::applyLook($request, $choice);
                }
            }
        }

        if ($ads) {
            $raw = self::cookie($request, self::ADS_COOKIE);
            $on = $raw === '1';

            if ($raw !== null && ! $on) {
                $forget[] = self::ADS_COOKIE;
            }

            $request->attributes->set(self::ADS_ATTRIBUTE, ['site' => (bool) config('site.ads_enabled', false), 'on' => $on]);

            // The member back office (studio and account, /profile) prints no ad at all: its forms keep the site's own
            // ad options (Video details' "Show ads on this video" shows while the site shows ads). An error page the
            // public layout draws there leaves them out (applyForLayout()).
            if (! $request->is(...self::BACK_OFFICE_PATHS)) {
                self::leaveAdsOut($request);
            }
        }

        $request->attributes->set(self::FORGET_ATTRIBUTE, $forget);

        return $forget;
    }

    /**
     * The demo switch: no ad on this page for a visitor who did not turn ads on (config('site.ads_enabled') is false for
     * the rest of this request, the path that already gives Premium members no ads).
     */
    private static function leaveAdsOut(Request $request): void
    {
        $state = $request->attributes->get(self::ADS_ATTRIBUTE);

        if (is_array($state) && ($state['on'] ?? false) !== true) {
            config(['site.ads_enabled' => false]);
        }
    }

    /**
     * An embed page (the player on other sites, route video.embed, /video/<uuid>/embed): never part of these choices.
     */
    private static function isEmbed(Request $request): bool
    {
        return $request->route()?->getName() === 'video.embed' || $request->is('video/*/embed', 'index.php/video/*/embed');
    }

    /**
     * The look a cookie value names: ['look' => id, 'mode' => dark|light], or null for anything else (an unknown or
     * retired look id, another format, an array).
     *
     * @return array{look: string, mode: string}|null
     */
    public static function parseLook(mixed $value): ?array
    {
        if (! is_string($value) || strlen($value) > 80 || preg_match(self::LOOK_PATTERN, $value, $match) !== 1) {
            return null;
        }

        return isset(MasterExperienceCatalog::all()[$match[1]]) ? ['look' => $match[1], 'mode' => $match[2]] : null;
    }

    /**
     * The settings a look shows with: what publishing it would write (AppearancePages::lookValues), in the chosen mode.
     *
     * @param  array{look: string, mode: string}  $choice
     * @return array<string, ?string>|null
     */
    public static function lookValues(array $choice): ?array
    {
        $values = AppearancePages::lookValues($choice['look']);

        if ($values === null || ! in_array($choice['mode'], self::MODES, true)) {
            return null;
        }

        $values['theme'] = $choice['mode'];

        return $values;
    }

    /**
     * The 12 looks a visitor can try, in the gallery's order: id, name, the theme they use and, for each mode, the
     * colours of the panel's small preview (page, surface; the look's brand, second and accent colour) and the context
     * of the visitor's own dark/light memory on such a page (data-avd-theme-preset:data-avd-theme-default, the
     * layout's head script).
     *
     * @return list<array{id: string, name: string, theme: string, brand: string, brand2: string, accent: string, modes: array<string, array{bg: string, surface: string, context: string}>}>
     */
    public static function looks(): array
    {
        $looks = [];

        foreach (MasterExperienceCatalog::all() as $id => $look) {
            $settings = (array) ($look['apply_settings'] ?? []);
            $theme = (string) ($settings['theme_preset'] ?? $look['theme'] ?? '');
            $modes = [];

            foreach (self::MODES as $mode) {
                $modes[$mode] = [
                    'bg' => self::hex($settings[$mode.'_bg_color'] ?? null, $mode === 'dark' ? '#0b0e15' : '#f5f6fa'),
                    'surface' => self::hex($settings[$mode.'_surface_color'] ?? null, $mode === 'dark' ? '#12151e' : '#ffffff'),
                    'context' => $theme.':'.$mode,
                ];
            }

            $looks[] = [
                'id' => (string) $id,
                'name' => __((string) $look['label']),
                'theme' => $theme,
                'brand' => self::hex($settings['primary_color'] ?? null, '#ff3d61'),
                'brand2' => self::hex($settings['brand_secondary_color'] ?? null, '#ff8b5c'),
                'accent' => self::hex($settings['accent_color'] ?? null, '#18c8d8'),
                'modes' => $modes,
            ];
        }

        return $looks;
    }

    /**
     * What the layout prints for this request: null when nothing (both settings off, the admin, a page this feature
     * leaves alone, or nothing to switch), else the data of the two controls (layouts/partials/visitor-choices).
     *
     * @return array<string, mixed>|null
     */
    public static function dock(Request $request, string $layoutType, bool $backToTop): ?array
    {
        // Every page of the public layout a visitor reads, whatever its address (the 404 page of /admin too); never the
        // admin's own pages or an embed page, and never the answer to a form (POST, ...): the controls reload the page,
        // which would send the form again (its choices still count there: applyForLayout()).
        if ($layoutType === 'admin' || ! self::anyOn() || self::isEmbed($request) || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        $looks = null;

        if (self::lookPickerOn()) {
            $choice = $request->attributes->get(self::LOOK_ATTRIBUTE);
            $choice = is_array($choice) ? $choice : null;
            $looks = [
                'list' => self::looks(),
                'current' => $choice,
                'site' => self::siteColours(),
            ];
        }

        $ads = null;

        if (self::demoAdsOn()) {
            $state = $request->attributes->get(self::ADS_ATTRIBUTE);
            $siteAds = is_array($state) ? (bool) ($state['site'] ?? false) : (bool) config('site.ads_enabled', false);
            $on = is_array($state) ? (bool) ($state['on'] ?? false) : self::cookie($request, self::ADS_COOKIE) === '1';

            // Nothing to switch on a site that shows no ads.
            if ($siteAds) {
                $premium = (bool) config('site.ads_hide_for_premium', true) && (bool) $request->user()?->is_premium;
                $ads = ['state' => $premium ? 'premium' : ($on ? 'on' : 'off')];
            }
        }

        if ($looks === null && $ads === null) {
            return null;
        }

        return [
            'looks' => $looks,
            'ads' => $ads,
            'path' => self::cookiePath($request),
            'backToTop' => $backToTop,
        ];
    }

    /**
     * The path of the two cookies: the folder the site runs in ("/" at the root of a domain, "/avdb/" in a folder).
     */
    public static function cookiePath(Request $request): string
    {
        $base = trim(str_replace('\\', '/', $request->getBasePath()), '/');

        return $base === '' ? '/' : '/'.$base.'/';
    }

    /**
     * A Set-Cookie line that removes a cookie of this feature (its value was not a valid choice).
     */
    public static function forgetCookie(Request $request, string $name): Cookie
    {
        return Cookie::create($name, '', 1, self::cookiePath($request), null, $request->isSecure(), false, true, Cookie::SAMESITE_LAX);
    }

    /**
     * Headers of a page that may depend on this visitor's cookies: never kept by a CDN or another visitor's cache (the
     * headers of a page for one visitor, App\Support\OneTimePage::makePrivate), and a cache that keeps it at all keys it
     * by the cookies. No noindex: the address is a normal page.
     */
    public static function markPrivate(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
        $response->headers->remove('Expires');

        $vary = array_values(array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', '')))));

        if (! in_array('cookie', array_map('strtolower', $vary), true)) {
            $vary[] = 'Cookie';
            $response->headers->set('Vary', implode(', ', $vary));
        }

        return $response;
    }

    /**
     * Apply a tried look to this request (the config the views read and AppearanceSettings::current()).
     *
     * @param  array{look: string, mode: string}  $choice
     */
    private static function applyLook(Request $request, array $choice): void
    {
        $values = self::lookValues($choice);

        if ($values === null) {
            return;
        }

        try {
            AppearanceSettings::applyToConfig(AppearanceSettings::applyOverrides(Setting::getGroup('appearance'), $values));
            $request->attributes->set(self::APPLIED_ATTRIBUTE, true);
            self::guardMails();
        } catch (Throwable $e) {
            // The page in the site's own look rather than an error page.
            report($e);
        }
    }

    /**
     * A member mail sent while a request shows a tried look (MailTheme reads the site's theme from the config) is made in
     * the site's own look: before a mail notification is sent, the stored appearance is applied again for the rest of the
     * request. Registered once per application.
     */
    private static function guardMails(): void
    {
        try {
            $app = app();

            if ($app->bound(self::MAIL_GUARD)) {
                return;
            }

            $app->instance(self::MAIL_GUARD, true);
            $app['events']->listen(NotificationSending::class, static function (NotificationSending $event): void {
                if ($event->channel !== 'mail') {
                    return;
                }

                try {
                    $request = request();
                } catch (Throwable) {
                    return;
                }

                if ($request->attributes->get(self::APPLIED_ATTRIBUTE) === true) {
                    $request->attributes->set(self::APPLIED_ATTRIBUTE, false);
                    AppearanceSettings::applyToConfig(Setting::getGroup('appearance'));
                }
            });
        } catch (Throwable) {
            // Never break the page: the mail would show the tried look's colour, nothing else.
        }
    }

    /**
     * The site's own colours (its published look, in the mode first-time visitors get) for the "The site's look" preview.
     *
     * @return array{bg: string, brand: string, brand2: string, accent: string}
     */
    private static function siteColours(): array
    {
        try {
            $settings = AppearanceSettings::withThemeColours(Setting::getGroup('appearance'));
        } catch (Throwable) {
            $settings = [];
        }

        $mode = ($settings['theme'] ?? 'dark') === 'light' ? 'light' : 'dark';

        return [
            'bg' => self::hex($settings[$mode.'_bg_color'] ?? null, $mode === 'dark' ? '#0b0e15' : '#f5f6fa'),
            'brand' => self::hex($settings['primary_color'] ?? null, '#ff3d61'),
            'brand2' => self::hex($settings['brand_secondary_color'] ?? null, '#ff8b5c'),
            'accent' => self::hex($settings['accent_color'] ?? null, '#18c8d8'),
        ];
    }

    /**
     * A cookie as the browser sent it: a string, or an array for a name sent as name[...] (which InputBag::get() would
     * answer with a 400), or null.
     */
    private static function cookie(Request $request, string $name): mixed
    {
        return $request->cookies->all()[$name] ?? null;
    }

    private static function hex(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? strtolower($value) : $fallback;
    }
}
