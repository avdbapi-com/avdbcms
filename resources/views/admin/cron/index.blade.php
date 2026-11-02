@extends('layouts.admin')
@section('title', __('Scheduled Tasks & Jobs'))
@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 my-3">
    <h2 class="mb-0"><i class="fa-solid fa-clock me-2"></i>{{__('Cron & Scheduled Tasks')}}</h2>
    <div class="d-flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.cron.run') }}">@csrf
            <button class="btn btn-primary btn-sm"><i class="fa-solid fa-play me-1"></i>{{__('Run Scheduler Now')}}</button>
        </form>
        <form method="POST" action="{{ route('admin.cron.interrupt') }}">@csrf
            <button class="btn btn-outline-warning btn-sm"><i class="fa-solid fa-stop me-1"></i>{{__('Interrupt Scheduler')}}</button>
        </form>
        <form method="POST" action="{{ route('admin.cron.logs.clear') }}" onsubmit='return confirm(@json(__('Clear cron logs?')))'>@csrf
            <button class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Clear Logs')}}</button>
        </form>
    </div>
</div>

{{-- In the new admin the task counts and the task list fill in after the page appears when the scheduler's list is
     not kept yet ($deferred; modules/list-probes.ts). --}}
<div class="row g-3 mb-4" @if (! empty($newAdmin)) data-ad-defer="cron-stats"@endif @if (! empty($deferred)) data-ad-defer-probes="{{ route('admin.status.probe', ['probe' => 'schedule']) }} {{ route('admin.status.probe', ['probe' => 'php']) }}" aria-busy="true"@endif>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-info">{{ ! empty($deferred) ? '…' : $stats['scheduled'] }}</div><small class="text-muted">{{__('Scheduled')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-warning">{{ ! empty($deferred) ? '…' : $stats['paused'] }}</div><small class="text-muted">{{__('Paused')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-primary">{{ $stats['pending'] }}</div><small class="text-muted">{{__('Queued Jobs')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-danger">{{ $stats['failed'] }}</div><small class="text-muted">{{__('Failed Jobs')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0">{{ $stats['queue_connection'] }}</div><small class="text-muted">{{__('Queue Driver')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-nowrap" dir="ltr">{{ number_format($stats['cron_log_size'] / 1024, 1) }} KB</div><small class="text-muted">{{__('Cron Log')}}</small></div></div>
</div>

{{-- Who starts the scheduled tasks (the server's cron, only the site after visits, nobody), what this account's crontab
     holds for this site, and the line to add (App\Support\LaravelSchedulerCron). In the new admin the line fills in
     after the page appears when the PHP check is not kept yet ($deferred). --}}
@php($schedulerRun = $scheduler['run'] ?? ['source' => 'none', 'last_cron' => null, 'last_site' => null])
<div class="card shadow-sm mb-4" id="ad-cron-start" @if (! empty($newAdmin)) data-ad-defer="cron-start"@endif @if (! empty($deferred)) data-ad-defer-probes="{{ route('admin.status.probe', ['probe' => 'php']) }}" aria-busy="true"@endif>
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-play me-1"></i>{{ __('How the scheduled tasks start') }}</h5></div>
    <div class="card-body">
        @if ($schedulerRun['source'] === 'cron')
            <div class="alert alert-success py-2 mb-2">{{ __("The server's cron starts them every minute. Last run: :time.", ['time' => \Carbon\Carbon::createFromTimestamp((int) $schedulerRun['last_cron'])->diffForHumans()]) }}</div>
        @elseif ($schedulerRun['source'] === 'site')
            <div class="alert alert-warning py-2 mb-2">{{ __('Only visits to the site start them now (last run: :time). Without visitors they start late or not at all: add the cron line below.', ['time' => \Carbon\Carbon::createFromTimestamp((int) $schedulerRun['last_site'])->diffForHumans()]) }}</div>
        @else
            <div class="alert alert-danger py-2 mb-2">{{ __('They are not running. Add the cron line below.') }}</div>
        @endif
        @if (empty($scheduler['windows']))
            @switch($scheduler['crontab']['state'] ?? 'unknown')
                @case('current')
                    <p class="small text-muted mb-2">{{ __("This account's crontab has this site's line.") }}</p>
                    @break
                @case('old')
                    <p class="small text-warning-emphasis mb-2">{{ __("This account's crontab has an older line of this site. The site replaces it by itself; if it is still there in a few minutes, replace it with the line below.") }}</p>
                    @break
                @case('off')
                    <p class="small text-warning-emphasis mb-2">{{ __("This account's crontab has this site's line, switched off (it starts with #). The site leaves it as it is: to run the tasks every minute, remove the # or put the line below in its place.") }}</p>
                    @break
                @case('custom')
                    <p class="small text-warning-emphasis mb-2">{{ __("This account's crontab has a line of this site that was changed by hand. The site leaves it as it is; if the tasks do not run, replace it with the line below.") }}</p>
                    @break
                @case('absent')
                    <p class="small text-muted mb-2">{{ __("This account's crontab has no line of this site. A cron job set up in your hosting panel works as well: use the command for it below.") }}</p>
                    @break
            @endswitch
        @endif
        @if (! empty($scheduler['line']))
            <div class="small fw-bold mb-1">{{ ! empty($scheduler['windows']) ? __("Create this site's task in the Windows Task Scheduler with this command (once, in a Command Prompt):") : __('Cron line (every minute):') }}</div>
            <pre class="small mb-2 p-2 border rounded" dir="ltr" tabindex="0" style="white-space:pre-wrap; word-break:break-all;"><code>{{ $scheduler['line'] }}</code></pre>
            @if (! empty($scheduler['command']))
                {{-- A hosting panel's cron job takes the command alone: the panel has fields of its own for "every minute",
                     and its shell read the "* * * * *" of the line above as file names (round 3 IN-RV-01). --}}
                <div class="small fw-bold mb-1">{{ __('Command for a cron job in your hosting panel (every minute):') }}</div>
                <pre class="small mb-2 p-2 border rounded" dir="ltr" tabindex="0" style="white-space:pre-wrap; word-break:break-all;"><code>{{ $scheduler['command'] }}</code></pre>
            @endif
            @if (! empty($scheduler['panel_line']))
                <div class="small fw-bold mb-1">{{ __('If your panel runs cron jobs as root, use this command instead:') }}</div>
                <pre class="small mb-0 p-2 border rounded" dir="ltr" tabindex="0" style="white-space:pre-wrap; word-break:break-all;"><code>{{ $scheduler['panel_line'] }}</code></pre>
            @endif
        @else
            <p class="small text-muted mb-0">{{ ! empty($deferred) ? __('Checking...') : __('No PHP command line was found on this server, so the cron line cannot be shown.') }}</p>
        @endif
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h5 class="mb-1"><i class="fa-solid fa-stopwatch me-1 text-info"></i>{{__('AVDB Duration Backfill')}}</h5>
            <div class="text-muted small">{{__('Manual fallback for hosts where cron, background PHP, or queue workers are not running.')}}</div>
        </div>
        <span class="badge bg-{{ $durationBackfill['missing'] > 0 ? 'warning' : 'success' }}">
            {{ trans_choice('1 missing|:count missing', (int) $durationBackfill['missing'], ['count' => number_format((int) $durationBackfill['missing'])]) }}
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-6 col-md-2">
                <div class="border rounded p-3 h-100">
                    <div class="h5 mb-0">{{ number_format($durationBackfill['total']) }}</div>
                    <small class="text-muted">{{__('AVDB videos')}}</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="border rounded p-3 h-100">
                    <div class="h5 mb-0 text-success">{{ number_format($durationBackfill['synced']) }}</div>
                    <small class="text-muted">{{__('With duration')}}</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="border rounded p-3 h-100">
                    <div class="h5 mb-0 text-warning">{{ number_format($durationBackfill['waiting_retry']) }}</div>
                    <small class="text-muted">{{__('Waiting retry')}}</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="border rounded p-3 h-100">
                    <div class="h5 mb-0 text-danger">{{ number_format($durationBackfill['failed_attempts']) }}</div>
                    <small class="text-muted">{{__('Had errors')}}</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="border rounded p-3 h-100">
                    <div class="h5 mb-0 text-primary">{{ number_format($durationBackfill['queued_jobs']) }}</div>
                    <small class="text-muted">{{__('Queued duration jobs')}}</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="border rounded p-3 h-100">
                    <div class="h5 mb-0">{{ $durationBackfill['mode'] }}</div>
                    <small class="text-muted">{{__('Backfill mode')}}</small>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-8">
                <form method="POST" action="{{ route('admin.cron.duration.start') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-3">
                        <label for="ad-cron-mode" class="form-label fw-bold">{{__('Run Mode')}}</label>
                        <select id="ad-cron-mode" name="mode" class="form-select">
                            <option value="smart">{{__('Smart Auto / Run Now')}}</option>
                            <option value="background">{{__('Background Process')}}</option>
                            <option value="queue">{{__('Queue Jobs')}}</option>
                            <option value="inline">{{__('Run One Batch Now')}}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="ad-cron-limit" class="form-label fw-bold">{{__('Scan Limit')}}</label>
                        <input id="ad-cron-limit" type="number" name="limit" class="form-control" value="5000" min="1" max="10000">
                    </div>
                    <div class="col-md-3">
                        <label for="ad-cron-chunk" class="form-label fw-bold">{{__('Batch Size')}}</label>
                        <input id="ad-cron-chunk" type="number" name="chunk" class="form-control" value="100" min="1" max="200">
                    </div>
                    <div class="col-md-3 d-grid">
                        <button class="btn btn-primary">
                            <i class="fa-solid fa-play me-1"></i>{{__('Start Duration Sync')}}
                        </button>
                    </div>
                </form>
                <div class="text-muted small mt-2">
                    {{__('Smart Auto tries a detached PHP process first. If the host blocks that, it runs a real inline batch immediately. Queue Jobs is only for installs with an active queue worker.')}}
                </div>
            </div>
            <div class="col-lg-4">
                <div class="border rounded p-3 h-100">
                    <div class="small mb-2">
                        <div @if (! empty($newAdmin)) data-ad-defer="cron-process"@endif @if (! empty($deferred)) data-ad-defer-probes="{{ route('admin.status.probe', ['probe' => 'php']) }}"@endif><strong>{{ rtrim(\App\Support\AdminText::labelled(__('Process support'), '')) }}</strong>{{ \App\Support\LabelGap::admin() }}{{ $durationBackfill['php_binary_ok'] === null ? __('Checking...') : ($durationBackfill['process_available'] && $durationBackfill['php_binary_ok'] ? __('Ready') : __('Unavailable')) }}</div>
                        <div><strong>{{ rtrim(\App\Support\AdminText::labelled(__('Async queue'), '')) }}</strong>{{ \App\Support\LabelGap::admin() }}{{ $durationBackfill['queue_async'] ? __('Ready') : __('Not active') }}</div>
                        <div><strong>{{ rtrim(\App\Support\AdminText::labelled(__('Remote lookup'), '')) }}</strong>{{ \App\Support\LabelGap::admin() }}<wbr><span dir="ltr">{{ $durationBackfill['remote_concurrency'] }}x / {{ $durationBackfill['remote_timeout'] }}s</span></div>
                        <div><strong>{{ rtrim(\App\Support\AdminText::labelled(__('Last attempt'), '')) }}</strong>{{ \App\Support\LabelGap::admin() }}{{ $durationBackfill['latest_attempt'] ? \Carbon\Carbon::parse($durationBackfill['latest_attempt'])->diffForHumans() : '-' }}</div>
                    </div>
                    <form method="POST" action="{{ route('admin.cron.duration.reset-retries') }}" onsubmit='return confirm(@json(__('Reset retry waiting state for all AVDB videos still missing duration?')))'>
                        @csrf
                        <button class="btn btn-outline-warning btn-sm w-100">
                            <i class="fa-solid fa-rotate-left me-1"></i>{{__('Retry Missing Again Now')}}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <details class="mt-3">
            <summary class="fw-bold">{{__('Duration Sync Log')}}</summary>
            <pre class="small mt-2 mb-0" tabindex="0" style="max-height:260px; overflow:auto; white-space:pre-wrap;">{{ implode("\n", $durationLog ?: [__('No avdb-duration-sync.log output yet.')]) }}</pre>
        </details>
    </div>
</div>

<div class="card shadow-sm mb-4" @if (! empty($newAdmin)) data-ad-defer="cron-tasks"@endif @if (! empty($deferred)) data-ad-defer-probes="{{ route('admin.status.probe', ['probe' => 'schedule']) }} {{ route('admin.status.probe', ['probe' => 'php']) }}" aria-busy="true"@endif>
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0"><i class="fa-solid fa-calendar-check me-1"></i>{{__('Registered Scheduled Tasks')}}</h5>
        <span class="text-muted small">{{__('Pause stops future scheduler runs. Run starts that task immediately in the background.')}}</span>
    </div>
    <div class="table-responsive" tabindex="0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{__('Status')}}</th>
                    <th>{{__('Expression')}}</th>
                    <th>{{__('Command')}}</th>
                    <th>{{__('Next Due')}}</th>
                    <th>{{__('Timezone')}}</th>
                    <th>{{__('Actions')}}</th>
                </tr>
            </thead>
            <tbody>
            @forelse($tasks as $task)
                <tr>
                    <td>
                        <span class="badge bg-{{ $task['paused'] ? 'warning' : 'success' }}">{{ $task['paused'] ? __('Paused') : __('Active') }}</span>
                    </td>
                    {{-- codes, times and zones read left to right on an Arabic page, on one line (R2A-052) --}}
                    <td><code dir="ltr" class="text-nowrap">{{ $task['expression'] ?: '-' }}</code></td>
                    <td>
                        <code dir="ltr">{{ $task['key'] ?: $task['command'] }}</code>
                        @if(($taskDescription = \App\Http\Controllers\Admin\CronController::describe($task)) !== null)
                            <div class="text-muted small">{{ $taskDescription }}</div>
                        @endif
                    </td>
                    <td>
                        <div>{{ $task['next_due_date_human'] ?? '-' }}</div>
                        <small class="text-muted text-nowrap" dir="ltr">{{ $task['next_due_date'] ?? '' }}</small>
                    </td>
                    <td><small dir="ltr">{{ $task['timezone'] ?? config('app.timezone') }}</small></td>
                    <td>
                        @if($task['key'])
                            <div class="d-flex flex-wrap gap-1">
                                <form method="POST" action="{{ route('admin.cron.task.run') }}">@csrf
                                    <input type="hidden" name="command" value="{{ $task['key'] }}">
                                    <button class="btn btn-outline-primary btn-sm" title="{{__('Run now')}}"><i class="fa-solid fa-play"></i></button>
                                </form>
                                @if($task['paused'])
                                    <form method="POST" action="{{ route('admin.cron.task.resume') }}">@csrf
                                        <input type="hidden" name="command" value="{{ $task['key'] }}">
                                        <button class="btn btn-outline-success btn-sm" title="{{__('Resume')}}"><i class="fa-solid fa-forward"></i></button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.cron.task.pause') }}">@csrf
                                        <input type="hidden" name="command" value="{{ $task['key'] }}">
                                        <button class="btn btn-outline-warning btn-sm" title="{{__('Pause')}}"><i class="fa-solid fa-pause"></i></button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center py-4 text-muted">{{ ! empty($deferred) ? __('Listing the scheduled tasks...') : __('No scheduled tasks registered') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fa-solid fa-list-check me-1 text-primary"></i>{{__('Recent Queued Jobs')}}</h5>
                <a href="{{ route('admin.queue.index') }}" class="btn btn-sm btn-outline-primary">{{__('Open Queue')}}</a>
            </div>
            <div class="table-responsive" tabindex="0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>ID</th><th>{{__('Queue')}}</th><th>{{__('Attempts')}}</th><th>{{__('Available')}}</th></tr></thead>
                    <tbody>
                    @forelse($queuedJobs as $job)
                        <tr>
                            <td><code>#{{ $job->id }}</code></td>
                            <td><span class="badge bg-secondary">{{ $job->queue }}</span></td>
                            <td>{{ $job->attempts }}</td>
                            <td><small>{{ \Carbon\Carbon::createFromTimestamp($job->available_at)->diffForHumans() }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-3 text-muted">{{__('No queued jobs')}}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fa-solid fa-triangle-exclamation me-1 text-danger"></i>{{__('Recent Failed Jobs')}}</h5>
                <a href="{{ route('admin.failed-jobs.index') }}" class="btn btn-sm btn-outline-danger">{{__('View Failed')}}</a>
            </div>
            <div class="table-responsive" tabindex="0">
                <table class="table table-sm mb-0">
                    <thead><tr><th>ID</th><th>{{__('Queue')}}</th><th>{{__('Exception')}}</th><th>{{__('Failed')}}</th></tr></thead>
                    <tbody>
                    @forelse($failedJobs as $job)
                        <tr>
                            <td>{{ $job->id }}</td>
                            <td><code>{{ $job->queue }}</code></td>
                            <td><small class="text-danger">{{ Str::limit($job->exception, 90) }}</small></td>
                            <td><small>{{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center py-3 text-muted">{{__('No failed jobs')}}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-clipboard-list me-1"></i>{{__('Cron Activity')}}</h5></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    @forelse($activityLogs as $log)
                        <tr>
                            {{-- the action in plain words, its key in the tooltip; the details in the admin's language (R2A-051) --}}
                            <td><b title="{{ $log->action }}">{{ \App\Support\AuditActions::label((string) $log->action) }}</b><div class="text-muted small" style="overflow-wrap:anywhere">{{ \App\Support\AuditDetails::html((string) $log->action, $log->details) }}</div></td>
                            <td class="text-end text-muted small">{{ \Carbon\Carbon::parse($log->created_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td class="text-muted text-center py-3">{{__('No cron admin activity yet.')}}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-terminal me-1"></i>{{__('Cron Log')}}</h5></div>
            <div class="card-body">
                <pre class="small mb-0" tabindex="0" style="max-height:360px; overflow:auto; white-space:pre-wrap;">{{ implode("\n", $cronLog ?: [__('No cron.log output yet.')]) }}</pre>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-rotate me-1"></i>{{__('Schedule Run Log')}}</h5></div>
            <div class="card-body">
                <div class="small fw-bold mb-1">{{ __("Started by the server's cron") }}</div>
                <pre class="small mb-3" dir="ltr" tabindex="0" style="max-height:360px; overflow:auto; white-space:pre-wrap;">{{ implode("\n", $scheduleRunLog ?: [__('No schedule-run.log output yet.')]) }}</pre>
                <div class="small fw-bold mb-1">{{ __('Started by the site (after visits and by Run Scheduler Now)') }}</div>
                <pre class="small mb-0" dir="ltr" tabindex="0" style="max-height:360px; overflow:auto; white-space:pre-wrap;">{{ implode("\n", ($siteRunLog ?? []) ?: [__('No schedule-web.log output yet.')]) }}</pre>
            </div>
        </div>
    </div>
</div>
@endsection
