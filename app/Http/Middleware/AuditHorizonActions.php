<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\AuditLogController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tools > Logs > Admin actions for Horizon's dashboard (config/horizon.php 'middleware'; only on sites with Redis): a
 * failed job or the failed jobs of a batch sent back to the queue there are written like the same action of Tools >
 * Background jobs (R3-019). Its tag monitoring only changes what the dashboard itself watches: no row. Every other
 * request (the dashboard's polling) passes untouched.
 */
class AuditHorizonActions
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('POST') || $response->getStatusCode() >= 400) {
            return $response;
        }

        $id = (string) ($request->route('id') ?? '');

        match ($request->route()?->getName()) {
            'horizon.retry-jobs.show' => AuditLogController::log('queue.failed.retry', 'Retried failed job '.$id.'.'),
            'horizon.jobs-batches.retry' => AuditLogController::log('queue.batch.retry', 'Retried the failed jobs of batch '.$id.'.'),
            default => null,
        };

        return $response;
    }
}
