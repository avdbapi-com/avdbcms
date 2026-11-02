<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureHorizonAvailable
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $connection = (string) config('horizon.use', 'default');

        try {
            Redis::connection($connection)->command('PING');
        } catch (Throwable $e) {
            $message = 'Horizon needs a working Redis connection. This installation can still use the database queue and the built-in Queue Monitor.';

            if ($request->expectsJson() || str_contains($request->path(), '/api/')) {
                return response()->json([
                    'message' => $message,
                    'connection' => $connection,
                    'queue_driver' => config('queue.default'),
                    'error' => class_basename($e).': '.$e->getMessage(),
                ], Response::HTTP_SERVICE_UNAVAILABLE);
            }

            return response()->view('admin.horizon-unavailable', [
                'connection' => $connection,
                'queueDriver' => config('queue.default'),
                'error' => class_basename($e).': '.$e->getMessage(),
                'message' => $message,
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return self::quietWhenLeft($request, $next($request));
    }

    /**
     * The Horizon dashboard (the package's own page) asks its API every few seconds. When the administrator left it,
     * Safari cancelled the requests under way (and the ones its timer sent while the next page loaded) and reported
     * each as "XMLHttpRequest cannot load ... due to access control checks." and "Unhandled Promise Rejection:
     * AxiosError: Network Error" (R2A-057). Its page gets a guard: once the page is being left, the reads (GET) under
     * way are stopped by the page itself (Safari reports nothing for those), new reads wait, and the rejections of the
     * stopped ones are not reported; an action the administrator started (a POST: retry, delete) goes out as before.
     * When the page stays after all (a leave that did not happen, the back button's cache), the newest waiting read of
     * each address goes out and the polling goes on; an error while the dashboard is open is reported as before.
     */
    private static function quietWhenLeft(Request $request, mixed $response): mixed
    {
        if (! $response instanceof Response || $response->getStatusCode() !== 200 || str_contains($request->path(), '/api/')
            || ! str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return $response;
        }

        $content = $response->getContent();

        if (! is_string($content) || ! str_contains($content, '<head>') || str_contains($content, 'data-avd-left-guard')) {
            return $response;
        }

        $response->setContent(preg_replace('/<head>/', '<head>'.self::LEFT_GUARD, $content, 1) ?? $content);

        return $response;
    }

    /**
     * The guard (see quietWhenLeft()): XMLHttpRequest is what the dashboard's axios uses. "Stays" = shown again from the
     * back button's cache (pageshow), or still there 30 s after the leave began (longer than a host lets a page load:
     * when the leave happened, the next page has replaced this one by then).
     */
    private const LEFT_GUARD = '<script data-avd-left-guard>(function(){'
        .'var X=window.XMLHttpRequest;if(!X||!X.prototype)return;'
        .'var open=X.prototype.open,send=X.prototype.send,left=false,live=[],held={},timer=0;'
        .'X.prototype.open=function(m,u){this.__avdRead=String(m).toUpperCase()==="GET";this.__avdUrl=String(u);return open.apply(this,arguments);};'
        .'X.prototype.send=function(){var x=this,a=arguments;'
        .'if(x.__avdRead){if(left){held[x.__avdUrl]=[x,a];return;}'
        .'live.push(x);x.addEventListener("loadend",function(){var i=live.indexOf(x);if(i>=0)live.splice(i,1);});}'
        .'return send.apply(x,a);};'
        .'function leave(){left=true;live.splice(0).forEach(function(x){try{x.abort();}catch(e){}});'
        .'clearTimeout(timer);timer=setTimeout(stay,30000);}'
        .'function stay(){if(!left)return;left=false;clearTimeout(timer);var h=held;held={};'
        .'Object.keys(h).forEach(function(k){try{X.prototype.send.apply(h[k][0],h[k][1]);}catch(e){}});}'
        .'addEventListener("beforeunload",leave);addEventListener("pagehide",leave);'
        .'addEventListener("pageshow",function(e){if(e.persisted)stay();});'
        .'addEventListener("unhandledrejection",function(event){var r=event.reason||{};'
        .'if(left&&(r.isAxiosError||r.name==="AxiosError"||/network|abort|cancel|load failed/i.test(String(r.message||r)))){event.preventDefault();}});'
        .'})();</script>';
}
