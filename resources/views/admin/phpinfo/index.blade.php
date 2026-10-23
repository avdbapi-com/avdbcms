@extends('layouts.admin')
@section('title', __('PHP Info'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-brands fa-php me-2"></i>{{__('PHP Information')}}</{{ $titleTag }}>
    <span class="badge bg-primary fs-6">PHP {{ $info['php_version'] }}</span>
</div>

<div class="row mb-4">
    <div class="col-md-3"><div class="card text-center p-3"><div class="h5 mb-0">{{ $info['php_version'] }}</div><small class="text-muted">{{__('PHP Version')}}</small></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><div class="h5 mb-0">{{ $info['memory_limit'] }}</div><small class="text-muted">{{__('Memory Limit')}}</small></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><div class="h5 mb-0">{{ $info['upload_max_filesize'] }}</div><small class="text-muted">{{__('Upload Max')}}</small></div></div>
    <div class="col-md-3"><div class="card text-center p-3"><div class="h5 mb-0">{{ $info['max_execution_time'] }}s</div><small class="text-muted">{{__('Max Execution')}}</small></div></div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card shadow">
            <div class="card-header"><h5 class="mb-0">{{__('Key Settings')}}</h5></div>
            <table class="table table-sm mb-0">
                <tr><td class="fw-bold">SAPI</td><td>{{ $info['php_sapi'] }}</td></tr>
                <tr><td class="fw-bold">OS</td><td>{{ $info['os'] }}</td></tr>
                <tr><td class="fw-bold">{{__('Post Max Size')}}</td><td>{{ $info['post_max_size'] }}</td></tr>
                <tr><td class="fw-bold">{{__('Max File Uploads')}}</td><td>{{ $info['max_file_uploads'] }}</td></tr>
                <tr><td class="fw-bold">{{__('Max Input Time')}}</td><td>{{ $info['max_input_time'] }}s</td></tr>
                <tr><td class="fw-bold">{{__('Display Errors')}}</td><td>{{ $info['display_errors'] ?: 'Off' }}</td></tr>
                <tr><td class="fw-bold">{{__('Timezone')}}</td><td>{{ $info['date_timezone'] }}</td></tr>
            </table>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow">
            <div class="card-header"><h5 class="mb-0">{{__('Loaded Extensions')}} ({{ count($info['extensions']) }})</h5></div>
            <div class="card-body">
                @foreach($info['extensions'] as $ext)
                <span class="badge bg-secondary me-1 mb-1">{{ $ext }}</span>
                @endforeach
            </div>
        </div>
    </div>
</div>

<div class="card shadow">
    <div class="card-header"><h5 class="mb-0">{{__('Full phpinfo() Output')}}</h5></div>
    <div class="card-body p-0">
        <div style="max-height:60vh; overflow:auto; padding:15px;">
            {{-- Admin colours, so the table reads in light and dark mode (the old admin keeps its light colours). --}}
            <style>.phpinfo-wrapper table { width:100%; border-collapse:collapse; font-size:13px; } .phpinfo-wrapper td, .phpinfo-wrapper th { padding:4px 8px; border:1px solid var(--border, #ddd); color:var(--text); } .phpinfo-wrapper .e { background:var(--surface-2, #f0f0f0); font-weight:bold; } .phpinfo-wrapper .h, .phpinfo-wrapper .v { background:var(--surface, transparent); } .phpinfo-wrapper .v { word-break:break-all; } .phpinfo-wrapper font { color:inherit !important; }</style>
            <div class="phpinfo-wrapper">{!! str_replace(['<h1', '</h1>'], ['<h2', '</h2>'], $body) !!}</div>
        </div>
    </div>
</div>
@endsection
