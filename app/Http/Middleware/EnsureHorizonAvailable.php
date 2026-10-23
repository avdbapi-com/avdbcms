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

        return $next($request);
    }
}
