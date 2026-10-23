@extends('layouts.admin')

@section('page_title', __('Preflight'))

@section('content')
    {{-- One H1 per page: the new admin's shell prints it. --}}
    @php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                <div class="flex-grow-1">
                    <{{ $titleTag }} class="h3 mb-1">{{ __('Environment Preflight') }}</{{ $titleTag }}>
                    <p class="text-muted mb-0">
                        {{ __('Live diagnostics of the host this application is running on. Auto-adjustments are applied on every boot.') }}
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <form action="{{ route('admin.preflight.fix') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> {{ __('Auto-fix') }}
                        </button>
                    </form>
                    <form action="{{ route('admin.preflight.refresh') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary">
                            <i class="fa-solid fa-rotate"></i> {{ __('Re-probe') }}
                        </button>
                    </form>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <div class="p-3 rounded bg-success-subtle border border-success-subtle">
                        <div class="text-success fw-bold fs-2">{{ $report['counts']['ok'] }}</div>
                        <div class="small text-success-emphasis">{{ __('Healthy checks') }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded bg-warning-subtle border border-warning-subtle">
                        <div class="text-warning-emphasis fw-bold fs-2">{{ $report['counts']['warn'] }}</div>
                        <div class="small text-warning-emphasis">{{ __('Warnings') }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded bg-danger-subtle border border-danger-subtle">
                        <div class="text-danger fw-bold fs-2">{{ $report['counts']['danger'] }}</div>
                        <div class="small text-danger-emphasis">{{ __('Blocking issues') }}</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="p-3 rounded bg-body-tertiary border">
                        <div class="fw-bold">{{ $report['snapshot']['web']['label'] }}</div>
                        <div class="small text-muted">{{ $report['snapshot']['web']['panel_label'] }}</div>
                        <div class="small text-muted">{{ $report['snapshot']['os']['family'] }} · PHP {{ $report['snapshot']['php']['version'] }} ({{ $report['snapshot']['php']['sapi'] }})</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if (session('preflight_repairs'))
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5 class="mb-3"><i class="fa-solid fa-screwdriver-wrench"></i> {{ __('Auto-fix results') }}</h5>
                <ul class="list-group list-group-flush">
                    @foreach (session('preflight_repairs') as $repair)
                        @php
                            $severity = $repair['severity'] ?? 'info';
                            $color = $severity === 'warn' ? 'warning' : ($severity === 'success' ? 'success' : 'info');
                        @endphp
                        <li class="list-group-item d-flex gap-3 align-items-start">
                            <span class="badge bg-{{ $color }}-subtle text-{{ $color }}-emphasis">
                                {{ strtoupper($severity) }}
                            </span>
                            <div>
                                <div class="fw-semibold">{{ $repair['scope'] ?? 'runtime' }}</div>
                                <div class="small text-muted">{{ $repair['message'] ?? '' }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if (!empty($report['adjustments']))
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5 class="mb-3"><i class="fa-solid fa-wand-magic-sparkles"></i> {{ __('Auto-configuration applied this request') }}</h5>
                <ul class="list-group list-group-flush">
                    @foreach ($report['adjustments'] as $adjustment)
                        @php $severity = $adjustment['severity'] ?? 'info'; @endphp
                        <li class="list-group-item d-flex gap-3 align-items-start">
                            <span class="badge bg-{{ $severity === 'warn' ? 'warning' : ($severity === 'info' ? 'info' : 'success') }}-subtle text-{{ $severity === 'warn' ? 'warning-emphasis' : ($severity === 'info' ? 'info-emphasis' : 'success-emphasis') }}">
                                {{ strtoupper($severity) }}
                            </span>
                            <div>
                                <div class="fw-semibold">{{ $adjustment['scope'] }}</div>
                                <div class="small text-muted">{{ $adjustment['message'] }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <h5 class="mb-3">{{ __('Runtime checks') }}</h5>
            @php $grouped = collect($report['checks'])->groupBy('category'); @endphp
            @foreach ($grouped as $category => $items)
                <div class="mb-4">
                    <h6 class="text-uppercase text-muted small fw-bold mb-2">{{ $category }}</h6>
                    <div class="list-group">
                        @foreach ($items as $check)
                            @php $color = $check['severity'] === 'ok' ? 'success' : ($check['severity'] === 'warn' ? 'warning' : 'danger'); @endphp
                            <div class="list-group-item d-flex gap-3 align-items-start">
                                <span class="badge bg-{{ $color }}-subtle text-{{ $color }}-emphasis" style="min-width: 3rem; text-align: center;">
                                    {{ strtoupper($check['severity']) }}
                                </span>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold">{{ $check['label'] }}</div>
                                    <div class="small text-muted">{{ $check['detail'] }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <h5 class="mb-3">{{ __('Streaming accelerator') }}</h5>
            <dl class="row mb-0">
                <dt class="col-sm-3">{{ __('Effective mode') }}</dt>
                <dd class="col-sm-9"><code>{{ $report['streaming']['mode'] }}</code></dd>
                <dt class="col-sm-3">{{ __('Internal URI prefix') }}</dt>
                <dd class="col-sm-9"><code>{{ $report['streaming']['prefix'] }}</code></dd>
            </dl>
            <p class="small text-muted mt-3 mb-0">
                {{ __('See docs/deployment/ for per-webserver snippets that wire the accelerator through an internal location.') }}
            </p>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h5 class="mb-3">{{ __('Queue configuration') }}</h5>
            <dl class="row mb-0">
                <dt class="col-sm-3">{{ __('Queue connection') }}</dt>
                <dd class="col-sm-9"><code>{{ $report['queue']['connection'] }}</code></dd>
                <dt class="col-sm-3">{{ __('AVDB import mode') }}</dt>
                <dd class="col-sm-9"><code>{{ $report['queue']['avdb_mode'] }}</code></dd>
            </dl>
        </div>
    </div>
@endsection
