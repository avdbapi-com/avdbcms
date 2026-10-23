@extends('layouts.admin')

@section('title', __('Horizon Unavailable'))

@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <{{ $titleTag }} class="h3 mb-1"><i class="fa-solid fa-bars-progress me-2"></i>{{ __('Horizon Unavailable') }}</{{ $titleTag }}>
        <p class="text-muted mb-0">{{ __('The admin panel is running, but Horizon cannot connect to Redis on this server.') }}</p>
    </div>
    <a href="{{ route('admin.queue.index') }}" class="btn btn-primary">
        <i class="fa-solid fa-layer-group me-1"></i>{{ __('Open Queue Monitor') }}
    </a>
</div>

<div class="card shadow">
    <div class="card-body">
        <div class="alert alert-warning mb-4">
            <div class="fw-semibold">{{ __($message) }}</div>
            <div class="small mt-2">
                {{ __('Queue driver') }}: <code>{{ $queueDriver }}</code>
                <span class="mx-2">|</span>
                {{ __('Horizon Redis connection') }}: <code>{{ $connection }}</code>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <h2 class="h5">{{ __('What to use now') }}</h2>
                <p class="text-muted">
                    {{ __('For shared hosts and aaPanel installs using the database queue, use the built-in Queue Monitor and Laravel scheduler cron. Horizon is only needed when Redis is configured correctly for queue workers.') }}
                </p>
                <a href="{{ route('admin.cron.index') }}" class="btn btn-outline-primary btn-sm">
                    <i class="fa-solid fa-clock me-1"></i>{{ __('Open Cron / Jobs') }}
                </a>
            </div>
            <div class="col-lg-5">
                <h2 class="h5">{{ __('Detected error') }}</h2>
                <pre class="small mb-0 p-3 rounded bg-dark text-light" style="white-space:pre-wrap;">{{ $error }}</pre>
            </div>
        </div>
    </div>
</div>
@endsection
