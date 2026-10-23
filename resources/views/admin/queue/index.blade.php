@extends('layouts.admin')
@section('title', __('Queue Monitor'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <{{ $titleTag }} class="h3 mb-0"><i class="fa-solid fa-layer-group me-2"></i>{{__('Queue Monitor')}}</{{ $titleTag }}>
    <div class="d-flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.queue.worker.restart') }}">@csrf
            <button class="btn btn-outline-warning btn-sm"><i class="fa-solid fa-power-off me-1"></i>{{__('Restart Workers')}}</button>
        </form>
        <form method="POST" action="{{ route('admin.queue.cleanup-stale') }}">@csrf
            <input type="hidden" name="realtime_hours" value="24">
            <input type="hidden" name="queued_days" value="30">
            <input type="hidden" name="failed_hours" value="168">
            <input type="hidden" name="batch_hours" value="168">
            <input type="hidden" name="import_run_days" value="30">
            <input type="hidden" name="keep_import_runs" value="50">
            <button class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-broom me-1"></i>{{__('Clean Stale')}}</button>
        </form>
        <form method="POST" action="{{ route('admin.queue.retry-all') }}">@csrf
            <button class="btn btn-success btn-sm"><i class="fa-solid fa-rotate me-1"></i>{{__('Retry All Failed')}}</button>
        </form>
        <form method="POST" action="{{ route('admin.queue.flush') }}" onsubmit='return confirm(@json(__('Delete all deletable failed jobs? Protected jobs will be kept.')))'>@csrf
            <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Flush Deletable')}}</button>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-primary">{{ $stats['queued_total'] }}</div><small class="text-muted">{{__('Queued')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-success">{{ $stats['queued_available'] }}</div><small class="text-muted">{{__('Ready')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-warning">{{ $stats['queued_delayed'] }}</div><small class="text-muted">{{__('Paused / Delayed')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-info">{{ $stats['queued_reserved'] }}</div><small class="text-muted">{{__('Reserved')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0 text-danger">{{ $stats['failed_total'] }}</div><small class="text-muted">{{__('Failed')}}</small></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="h4 mb-0">{{ $stats['queue_connection'] }}</div><small class="text-muted">{{__('Driver')}}</small></div></div>
</div>

<div class="card shadow mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0"><i class="fa-solid fa-play me-1 text-success"></i>{{__('Worker Control')}}</h5>
        @if ($stats['horizon_active'] === null)
            {{-- New admin: Redis is asked after the page appears (status probe "redis"). --}}
            <span class="badge bg-secondary" data-ad-defer="queue-horizon" data-ad-defer-probes="{{ route('admin.status.probe', ['probe' => 'redis']) }}">{{ __('Checking Horizon...') }}</span>
        @else
            <span class="badge {{ $stats['horizon_active'] ? 'bg-success' : 'bg-secondary' }}" data-ad-defer="queue-horizon">{{ $stats['horizon_active'] ? __('Horizon installed') : __('Horizon not active') }}</span>
        @endif
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.queue.worker.start') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-3">
                <label for="ad-queue-queue" class="form-label">{{__('Queue')}}</label>
                <select id="ad-queue-queue" name="queue" class="form-select">
                    @foreach($queues as $queue)
                        <option value="{{ $queue }}">{{ $queue }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="ad-queue-max-jobs" class="form-label">{{__('Max Jobs')}}</label>
                <input id="ad-queue-max-jobs" type="number" name="max_jobs" class="form-control" value="25" min="1" max="500">
            </div>
            <div class="col-6 col-md-2">
                <label for="ad-queue-max-time" class="form-label">{{__('Max Time')}}</label>
                <input id="ad-queue-max-time" type="number" name="max_time" class="form-control" value="300" min="5" max="3600">
            </div>
            <div class="col-6 col-md-2">
                <label for="ad-queue-timeout" class="form-label">{{__('Timeout')}}</label>
                <input id="ad-queue-timeout" type="number" name="timeout" class="form-control" value="300" min="10" max="7200">
            </div>
            <div class="col-6 col-md-1">
                <label for="ad-queue-tries" class="form-label">{{__('Tries')}}</label>
                <input id="ad-queue-tries" type="number" name="tries" class="form-control" value="1" min="1" max="10">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100"><i class="fa-solid fa-play me-1"></i>{{__('Start Worker')}}</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="mb-0"><i class="fa-solid fa-list-check text-primary me-1"></i>{{__('Pending / Reserved Jobs')}}</h5>
        <div class="d-flex flex-wrap gap-2">
            <form method="POST" action="{{ route('admin.queue.jobs.resume-all') }}">@csrf
                <button class="btn btn-outline-success btn-sm"><i class="fa-solid fa-forward me-1"></i>{{__('Continue All')}}</button>
            </form>
            <form method="POST" action="{{ route('admin.queue.jobs.pause-all') }}">@csrf
                <input type="hidden" name="seconds" value="3600">
                <button class="btn btn-outline-warning btn-sm"><i class="fa-solid fa-pause me-1"></i>{{__('Pause All 1h')}}</button>
            </form>
            <form method="POST" action="{{ route('admin.queue.jobs.delete-all') }}" onsubmit='return confirm(@json(__('Delete all deletable queued jobs? Protected jobs will be kept.')))'>
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Deletable')}}</button>
            </form>
        </div>
    </div>
    <div class="table-responsive" tabindex="0" role="region" aria-label="{{ __('Pending / Reserved Jobs') }}">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>{{__('ID')}}</th>
                    <th>{{__('State')}}</th>
                    <th>{{__('Queue')}}</th>
                    <th>{{__('Job')}}</th>
                    <th>{{__('Safety')}}</th>
                    <th>{{__('Attempts')}}</th>
                    <th>{{__('Timing')}}</th>
                    <th>{{__('Actions')}}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($queuedJobs as $job)
                    @php
                        $stateClass = ['available' => 'success', 'delayed' => 'warning', 'reserved' => 'info'][$job->state] ?? 'secondary';
                    @endphp
                    <tr>
                        <td><code>#{{ $job->id }}</code></td>
                        <td><span class="badge bg-{{ $stateClass }}">{{ ucfirst($job->state) }}</span></td>
                        <td><span class="badge bg-secondary">{{ $job->queue }}</span></td>
                        <td>
                            <div class="fw-semibold">{{ Str::limit($job->summary, 80) }}</div>
                            <small class="text-muted">{{ Str::limit($job->display_name, 110) }}</small>
                            @if($job->uuid)
                                <div><small class="text-muted">{{__('UUID')}}: <code>{{ $job->uuid }}</code></small></div>
                            @endif
                        </td>
                        @php
                            $riskClass = ['protected' => 'danger', 'low' => 'secondary'][$job->risk_level] ?? 'warning';
                        @endphp
                        <td style="min-width:150px">
                            <span class="badge bg-{{ $riskClass }}">{{ $job->risk_label }}</span>
                            <small class="d-block text-muted">{{ Str::limit($job->risk_description, 90) }}</small>
                        </td>
                        <td>{{ $job->attempts }}</td>
                        <td>
                            <small class="d-block">{{__('Created')}}: {{ $job->created_label }}</small>
                            <small class="d-block">{{__('Available')}}: {{ $job->available_label }}</small>
                            @if($job->reserved_label)<small class="d-block">{{__('Reserved')}}: {{ $job->reserved_label }}</small>@endif
                        </td>
                        <td>
                            <div class="d-flex flex-wrap gap-1">
                                <form method="POST" action="{{ route('admin.queue.jobs.resume', $job->id) }}">@csrf
                                    <button class="btn btn-outline-success btn-sm" title="{{__('Continue')}}"><i class="fa-solid fa-forward"></i></button>
                                </form>
                                <form method="POST" action="{{ route('admin.queue.jobs.pause', $job->id) }}">@csrf
                                    <input type="hidden" name="seconds" value="3600">
                                    <button class="btn btn-outline-warning btn-sm" title="{{__('Pause 1 hour')}}"><i class="fa-solid fa-pause"></i></button>
                                </form>
                                <form method="POST" action="{{ route('admin.queue.jobs.retry', $job->id) }}">@csrf
                                    <button class="btn btn-outline-primary btn-sm" title="{{__('Reset / Retry')}}"><i class="fa-solid fa-rotate"></i></button>
                                </form>
                                @if($job->delete_protected)
                                    <button class="btn btn-outline-secondary btn-sm" disabled title="{{ $job->risk_description }}"><i class="fa-solid fa-lock"></i></button>
                                @else
                                    <form method="POST" action="{{ route('admin.queue.jobs.delete', $job->id) }}" onsubmit='return confirm(@json(__('Delete this queued job?')))' >
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm" title="{{__('Delete')}}"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">{{__('No queued jobs.')}}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer"><x-admin.pagination :paginator="$queuedJobs" class="mt-0" /></div>
</div>

<div class="card shadow mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-triangle-exclamation text-danger me-1"></i>{{__('Failed Jobs')}}</h5></div>
    <div class="table-responsive" tabindex="0" role="region" aria-label="{{ __('Failed Jobs') }}">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>UUID</th><th>{{__('Connection')}}</th><th>{{__('Queue')}}</th><th>{{__('Safety')}}</th><th>{{__('Exception')}}</th><th>{{__('Failed At')}}</th><th>{{__('Actions')}}</th></tr>
            </thead>
            <tbody>
                @forelse($failedJobs as $job)
                <tr>
                    <td><code style="font-size:11px">{{ Str::limit($job->uuid, 18) }}</code></td>
                    <td>{{ $job->connection }}</td>
                    <td><span class="badge bg-secondary">{{ $job->queue }}</span></td>
                    @php
                        $failedRiskClass = ['protected' => 'danger', 'low' => 'secondary'][$job->risk_level] ?? 'warning';
                    @endphp
                    <td style="min-width:150px">
                        <span class="badge bg-{{ $failedRiskClass }}">{{ $job->risk_label }}</span>
                        <small class="d-block text-muted">{{ Str::limit($job->risk_description, 90) }}</small>
                    </td>
                    <td><small class="text-danger" title="{{ $job->exception }}">{{ Str::limit($job->exception, 100) }}</small></td>
                    <td>{{ \Carbon\Carbon::parse($job->failed_at)->diffForHumans() }}</td>
                    <td>
                        <div class="d-flex gap-1">
                            <form method="POST" action="{{ route('admin.queue.retry', $job->uuid) }}">@csrf
                                <button class="btn btn-outline-success btn-sm" title="{{__('Retry')}}"><i class="fa-solid fa-rotate"></i></button>
                            </form>
                            @if($job->delete_protected)
                                <button class="btn btn-outline-secondary btn-sm" disabled title="{{ $job->risk_description }}"><i class="fa-solid fa-lock"></i></button>
                            @else
                                <form method="POST" action="{{ route('admin.queue.failed.delete', $job->uuid) }}" onsubmit='return confirm(@json(__('Delete this failed job?')))'>
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm" title="{{__('Delete')}}"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">{{__('No failed jobs.')}}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer"><x-admin.pagination :paginator="$failedJobs" class="mt-0" /></div>
</div>

<div class="card shadow mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-boxes-stacked text-primary me-1"></i>{{__('Job Batches')}}</h5></div>
    <div class="table-responsive" tabindex="0" role="region" aria-label="{{ __('Job Batches') }}">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>ID</th><th>{{__('Name')}}</th><th>{{__('Total')}}</th><th>{{__('Pending')}}</th><th>{{__('Failed')}}</th><th>{{__('Progress')}}</th><th>{{__('Created')}}</th><th>{{__('Actions')}}</th></tr>
            </thead>
            <tbody>
                @forelse($batches as $batch)
                <tr>
                    <td><code style="font-size:11px">{{ Str::limit($batch->id, 12) }}</code></td>
                    <td>{{ $batch->name ?? __('Unnamed') }}</td>
                    <td>{{ $batch->total_jobs }}</td>
                    <td>{{ $batch->pending_jobs }}</td>
                    <td class="text-danger">{{ $batch->failed_jobs }}</td>
                    <td><div class="progress" style="height:20px; min-width:100px"><div class="progress-bar bg-{{ $batch->progress >= 100 ? 'success' : 'primary' }}" style="width:{{ $batch->progress }}%">{{ $batch->progress }}%</div></div></td>
                    <td>{{ \Carbon\Carbon::createFromTimestamp($batch->created_at)->diffForHumans() }}</td>
                    <td>
                        @if((int) $batch->pending_jobs > 0 && $batch->finished_at === null && $batch->cancelled_at === null)
                            <button class="btn btn-outline-secondary btn-sm" disabled title="{{__('Batch still has pending jobs')}}"><i class="fa-solid fa-lock"></i></button>
                        @else
                            <form method="POST" action="{{ route('admin.queue.delete-batch', $batch->id) }}" onsubmit='return confirm(@json(__('Delete this batch record?')))'>@csrf @method('DELETE')
                                <button class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">{{__('No job batches found.')}}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer"><x-admin.pagination :paginator="$batches" class="mt-0" /></div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow h-100">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-clipboard-list me-1"></i>{{__('Queue Activity')}}</h5></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                    @forelse($activityLogs as $log)
                        <tr>
                            <td><code>{{ $log->action }}</code><div class="text-muted small">{{ $log->details }}</div></td>
                            <td class="text-end text-muted small">{{ \Carbon\Carbon::parse($log->created_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td class="text-muted text-center py-3">{{__('No queue admin activity yet.')}}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow h-100">
            <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-terminal me-1"></i>{{__('Worker / Queue Logs')}}</h5></div>
            <div class="card-body">
                <pre class="small mb-3" tabindex="0" style="max-height:260px; overflow:auto; white-space:pre-wrap;">{{ implode("\n", $workerLog ?: [__('No queue-worker.log output yet.')]) }}</pre>
                <pre class="small mb-0 text-muted" tabindex="0" style="max-height:220px; overflow:auto; white-space:pre-wrap;">{{ implode("\n", $queueLog ?: [__('No recent queue lines in laravel.log.')]) }}</pre>
            </div>
        </div>
    </div>
</div>
@endsection
