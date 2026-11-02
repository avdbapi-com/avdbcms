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
                        {{-- the generic labels in the admin's language, product names as they are (R1-080) --}}
                        <div class="fw-bold">{{ \App\Support\Environment::shownLabel((string) $report['snapshot']['web']['label']) }}</div>
                        <div class="small text-muted">{{ \App\Support\Environment::shownLabel((string) $report['snapshot']['web']['panel_label']) }}</div>
                        <div class="small text-muted">{{ $report['snapshot']['os']['family'] }} · PHP {{ $report['snapshot']['php']['version'] }} ({{ $report['snapshot']['php']['sapi'] }})</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php
        // The badge of a line in the admin's language (it showed OK / WARN / DANGER / INFO / SUCCESS in English).
        $severityLabel = fn (string $severity): string => match ($severity) {
            'ok' => __('OK'),
            'warn' => __('Warning'),
            'danger', 'fail' => __('Problem'),
            'success' => __('Done'),
            default => __('Info'),
        };
    @endphp

    @if (session('preflight_repairs'))
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5 class="mb-3"><i class="fa-solid fa-screwdriver-wrench"></i> {{ __('Auto-fix results') }}</h5>
                <ul class="list-group list-group-flush">
                    @foreach ((array) session('preflight_repairs') as $repair)
                        @php
                            $repair = (array) $repair;
                            $severity = (string) ($repair['severity'] ?? 'info');
                            $color = $severity === 'warn' ? 'warning' : ($severity === 'success' ? 'success' : 'info');
                        @endphp
                        <li class="list-group-item d-flex gap-3 align-items-start">
                            <span class="badge bg-{{ $color }}-subtle text-{{ $color }}-emphasis">
                                {{ $severityLabel($severity) }}
                            </span>
                            <div>
                                <div class="fw-semibold">{{ \App\Http\Controllers\Admin\PreflightController::scopeHtml((string) ($repair['scope'] ?? 'runtime')) }}</div>
                                {{-- Stored in English (EnvironmentRepairer), shown in the admin's language, the folder isolated. --}}
                                <div class="small text-muted">{{ \App\Http\Controllers\Admin\PreflightController::lineHtml($repair) }}</div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @php $adjustmentLines = $adjustmentLines ?? array_map(fn ($adjustment) => ['scope' => $adjustment['scope'] ?? '', 'severity' => $adjustment['severity'] ?? 'info', 'html' => new \Illuminate\Support\HtmlString(e($adjustment['message'] ?? ''))], $report['adjustments'] ?? []); @endphp
    @if (!empty($adjustmentLines))
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5 class="mb-3"><i class="fa-solid fa-wand-magic-sparkles"></i> {{ __('Auto-configuration applied this request') }}</h5>
                <ul class="list-group list-group-flush">
                    @foreach ($adjustmentLines as $adjustment)
                        @php $severity = $adjustment['severity'] ?? 'info'; @endphp
                        <li class="list-group-item d-flex gap-3 align-items-start">
                            <span class="badge bg-{{ $severity === 'warn' ? 'warning' : ($severity === 'info' ? 'info' : 'success') }}-subtle text-{{ $severity === 'warn' ? 'warning-emphasis' : ($severity === 'info' ? 'info-emphasis' : 'success-emphasis') }}">
                                {{ $severityLabel($severity) }}
                            </span>
                            <div>
                                <div class="fw-semibold">{{ \App\Http\Controllers\Admin\PreflightController::scopeHtml((string) $adjustment['scope']) }}</div>
                                <div class="small text-muted">{{ $adjustment['html'] }}</div>
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
                    <h6 class="text-uppercase text-muted small fw-bold mb-2">{{ in_array($category, \App\Http\Controllers\Admin\PreflightController::CATEGORIES, true) ? __($category) : $category }}</h6>
                    <div class="list-group">
                        @foreach ($items as $check)
                            @php $color = $check['severity'] === 'ok' ? 'success' : ($check['severity'] === 'warn' ? 'warning' : 'danger'); @endphp
                            <div class="list-group-item d-flex gap-3 align-items-start">
                                <span class="badge bg-{{ $color }}-subtle text-{{ $color }}-emphasis" style="min-width: 3rem; text-align: center;">
                                    {{ $severityLabel($check['severity']) }}
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

    @php
        // What sends the video files on this web server (App\Support\SendFile): nginx after the site checked its internal
        // location, Apache with mod_xsendfile, LiteSpeed always, any other server never (R3-041: the nginx sentence was
        // shown on Apache too, under a line that said PHP streams the files). The internal address prefix is only
        // used by nginx and LiteSpeed.
        $streamServer = (string) ($report['streaming']['server'] ?? '');
        $streamPrefixUsed = in_array($streamServer, [\App\Support\Environment::WEB_NGINX, \App\Support\Environment::WEB_LITESPEED, \App\Support\Environment::WEB_OPENLITESPEED], true);
    @endphp
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <h5 class="mb-3">{{ __('Streaming accelerator') }}</h5>
            <dl class="row mb-0">
                <dt class="col-sm-3">{{ __('Effective mode') }}</dt>
                <dd class="col-sm-9"><code>{{ $report['streaming']['mode'] }}</code></dd>
                @if ($streamPrefixUsed)
                <dt class="col-sm-3">{{ __('Internal URI prefix') }}</dt>
                <dd class="col-sm-9"><code>{{ $report['streaming']['prefix'] }}</code></dd>
                @endif
            </dl>
            <p class="small text-muted mt-3 mb-0">
                @switch ($streamServer)
                    @case (\App\Support\Environment::WEB_NGINX)
                        {{ __('nginx sends the video files itself when its configuration has the internal location of docs/server-configs/README.md ("Video files"); the site checks it and otherwise streams them with PHP.') }}
                        @break
                    @case (\App\Support\Environment::WEB_APACHE)
                        {{ __('Apache sends the video files itself when its module mod_xsendfile is on (X-Sendfile); otherwise the site streams them with PHP.') }}
                        @break
                    @case (\App\Support\Environment::WEB_LITESPEED)
                    @case (\App\Support\Environment::WEB_OPENLITESPEED)
                        {{ __('LiteSpeed sends the video files itself: the site hands each file to it (X-LiteSpeed-Location).') }}
                        @break
                    @default
                        {{ __('Only nginx, Apache with mod_xsendfile and LiteSpeed can send the video files themselves: on this web server the site streams them with PHP.') }}
                @endswitch
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
