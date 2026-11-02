<?php

namespace App\Http\Middleware;

use App\Support\AdminIpLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The owner's maintenance mode (Settings > Maintenance): visitors get the maintenance page (503 + Retry-After), scripts
 * and apps the same as JSON. It runs in the web group and, since 1.2.3, in the api group too (the API kept answering
 * while the pages were closed).
 *
 * Always open: the admin pages and the admin's own API, signing in (web and app) and the password pages, the payment
 * providers' notifications (Stripe, PayPal: servers, not visitors; a refused one only comes back later), and the
 * Upload18 routes of the members' upload window (an upload that is already running finishes; those routes work exactly
 * as before). Administrators pass while "Admins can still browse the site" is on, and so do the secret preview link
 * and the allowed internet addresses.
 *
 * Whoever passes that way sees the closed site (self::passWhileClosed()): a strip on the pages says that maintenance is
 * on (request attribute avd.maintenance_preview, read by layouts/partials/maintenance-notice), and no answer is kept by
 * a CDN or a shared cache.
 */
class CheckMaintenanceMode
{
    /** The request attribute set while a request passes the closed site (an administrator, the preview link, an allowed address). */
    public const PREVIEW = 'avd.maintenance_preview';

    /** The default text of "Message for visitors": the pages and the API say their own translated sentence instead. */
    public const DEFAULT_MESSAGE = 'We are currently performing maintenance. Please check back soon.';

    /**
     * The routes of an upload that is already running, open during maintenance exactly as before: the members' Upload18
     * window (its settings, the status checks, the announcement, the pieces of a file stored on this server, the finish)
     * and the draft page's checks and pictures. Opened by name (round 2a R2A-063): the whole video controller of the API
     * was open, its "delete a video" included, while every other API call answered 503.
     */
    public const UPLOAD_ROUTES = [
        'videos.upload18.config',
        'videos.upload18.status',
        'videos.upload18.pending',
        'videos.upload18.chunk',
        'videos.upload18.complete',
        'videos.generate-thumbnails',
        'videos.generate-server-thumbnails',
        'videos.create-status',
    ];

    /**
     * Handle an incoming request.
     * If admin has enabled maintenance mode in Settings, block non-admin users.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if maintenance mode is active
        if (!config('site.maintenance_mode', false)) {
            return $next($request);
        }

        // Always allow login and admin routes (the admin's own API too), and the servers that are not visitors
        if ($this->alwaysOpen($request)) {
            return $next($request);
        }
        // Every way through below (an administrator, the preview link, an allowed address) sees the closed site.
        $next = fn (Request $request): Response => $this->passWhileClosed($request, $next);

        // Allow admin users through (if setting permits); outside the admin IP lock an administrator is a visitor (R1-089)
        if (config('site.maintenance_allow_admins', true) && auth()->check() && AdminIpLock::adminPowersHere(auth()->user())) {
            return $next($request);
        }

        // Allow secret key bypass: /secret-key appended to any URL
        $secret = config('site.maintenance_secret', '');
        if ($secret && $request->is($secret)) {
            session()->put('maintenance_bypass', true);
            return redirect('/');
        }
        if ($secret && $request->hasSession() && session('maintenance_bypass')) {
            return $next($request);
        }

        // Allow whitelisted IPs
        $allowedIps = config('site.maintenance_allowed_ips', '');
        if ($allowedIps) {
            $ips = array_map('trim', preg_split('/[\n,]+/', $allowedIps));
            if (in_array($request->ip(), $ips)) {
                return $next($request);
            }
        }

        // Block with maintenance page
        $message = (string) config('site.maintenance_message', self::DEFAULT_MESSAGE);
        $retryAfter = config('site.maintenance_retry_after', 3600);

        if ($request->expectsJson() || $request->is('api/*')) {
            // The owner's own message, or the site's translated sentence for the setting's default one.
            $jsonMessage = trim($message) !== '' && trim($message) !== self::DEFAULT_MESSAGE
                ? $message
                : __('We are updating the site. Try again soon.');

            return response()->json(['message' => $jsonMessage, 'maintenance' => true], 503)
                ->header('Retry-After', $retryAfter);
        }

        // Try dedicated maintenance view, fall back to 503 error
        $view = view()->exists('maintenance') ? 'maintenance' : 'errors.503';

        return response()->view($view, [
            'message' => $message,
            'title'   => 'Site Under Maintenance',
        ], 503)->header('Retry-After', $retryAfter);
    }

    /**
     * A request that passes while the site is closed to visitors: its pages show the maintenance strip (self::PREVIEW),
     * and its answer is that one browser's, never kept by a CDN or a shared cache (App\Support\OneTimePage: private,
     * no-store, whatever EdgeCacheVideoHtml or a controller decided). The home page of a guest with the preview link was
     * public and edge-cacheable: a CDN could hand the open site, strip included, to every visitor during maintenance and
     * for minutes after it. Pictures, video, sound, fonts and files are the same for everybody and keep their own cache
     * headers (an avatar stays cacheable for the administrator browsing the closed site).
     */
    private function passWhileClosed(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::PREVIEW, true);
        $response = $next($request);

        $type = strtolower((string) $response->headers->get('Content-Type', ''));
        if (! $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse && preg_match('#^(image|video|audio|font)/#', $type) !== 1) {
            \App\Support\OneTimePage::mark($request);
        }

        return $response;
    }

    /**
     * Requests maintenance never stops: the admin (pages and API), signing in, the password pages, the payment
     * providers' notifications and an upload that is already running (self::UPLOAD_ROUTES, unchanged).
     */
    private function alwaysOpen(Request $request): bool
    {
        // "Forgot password" and "Choose a new password" are /forgot-password and /reset-password: the old "password/*"
        // never matched them, so an administrator who forgot the password could not get back in.
        if ($request->is('admin', 'admin/*', 'api/admin/*', 'login', 'api/login', 'password/*', 'forgot-password', 'reset-password', 'reset-password/*')) {
            return true;
        }

        $route = $request->route();
        if ($route === null) {
            return false;
        }

        if (in_array($route->getName(), ['stripe_webhook', 'paypal_webhook'], true)) {
            return true;
        }

        return in_array($route->getName(), self::UPLOAD_ROUTES, true);
    }
}
