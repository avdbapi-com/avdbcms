@extends('layouts.admin')
@section('title', __('System Information'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-server me-2"></i>{{__('System Information')}}</h2>
    <div class="d-flex gap-2 flex-wrap">
        <form method="POST" action="{{ route('admin.system.clear', 'all') }}" class="d-inline">@csrf<button class="btn btn-sm btn-danger"><i class="fa-solid fa-broom me-1"></i>{{__('Clear All')}}</button></form>
        <form method="POST" action="{{ route('admin.system.clear', 'config') }}" class="d-inline">@csrf<button class="btn btn-sm btn-warning"><i class="fa-solid fa-gear me-1"></i>{{__('Config')}}</button></form>
        <form method="POST" action="{{ route('admin.system.clear', 'route') }}" class="d-inline">@csrf<button class="btn btn-sm btn-warning"><i class="fa-solid fa-route me-1"></i>{{__('Routes')}}</button></form>
        <form method="POST" action="{{ route('admin.system.clear', 'view') }}" class="d-inline">@csrf<button class="btn btn-sm btn-warning"><i class="fa-solid fa-eye me-1"></i>{{__('Views')}}</button></form>
        <form method="POST" action="{{ route('admin.system.clear', 'app') }}" class="d-inline">@csrf<button class="btn btn-sm btn-warning"><i class="fa-solid fa-database me-1"></i>{{__('App Cache')}}</button></form>
    </div>
</div>
<hr>

<div class="row">
    {{-- PHP Settings --}}
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white"><h6 class="mb-0"><i class="fa-brands fa-php me-1"></i>{{__('PHP Settings')}}</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    @foreach($phpSettings as $key => $val)
                    <tr><td class="fw-bold ps-3" style="width:40%">{{ $key }}</td><td><code>{{ Str::limit((string)$val, 80) }}</code></td></tr>
                    @endforeach
                </table>
            </div>
        </div>
    </div>

    {{-- Laravel Info --}}
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-danger text-white"><h6 class="mb-0"><i class="fa-brands fa-laravel me-1"></i>{{__('Laravel Configuration')}}</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    @foreach($laravelInfo as $key => $val)
                    <tr>
                        <td class="fw-bold ps-3" style="width:40%">{{ $key }}</td>
                        <td>
                            @if($key === 'Debug Mode')
                                <span class="badge bg-{{ $val === 'ON' ? 'danger' : 'success' }}">{{ $val }}</span>
                            @elseif($key === 'Environment')
                                <span class="badge bg-{{ $val === 'production' ? 'success' : 'warning' }}">{{ $val }}</span>
                            @elseif($key === 'AVDBCMS version')
                                <code>{{ $val }}</code> <a href="{{ route('admin.updates.index') }}" class="ms-1">{{ __('Updates') }}</a>
                            @else
                                <code>{{ $val }}</code>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </table>
            </div>
        </div>
    </div>

    {{-- Database Info --}}
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white"><h6 class="mb-0"><i class="fa-solid fa-database me-1"></i>{{__('Database')}}</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    @foreach($dbInfo as $key => $val)
                    <tr><td class="fw-bold ps-3" style="width:40%">{{ $key }}</td><td><code>{{ $val }}</code></td></tr>
                    @endforeach
                </table>
            </div>
        </div>
    </div>

    {{-- Disk Info --}}
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white"><h6 class="mb-0"><i class="fa-solid fa-hard-drive me-1"></i>{{__('Disk Usage')}}</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    @foreach($diskInfo as $key => $val)
                    <tr><td class="fw-bold ps-3" style="width:40%">{{ $key }}</td><td><strong>{{ $val }}</strong></td></tr>
                    @endforeach
                </table>
                @php $pct = floatval($diskInfo['Usage']); @endphp
                <div class="px-3 py-2">
                    <div class="progress" style="height:20px">
                        <div class="progress-bar bg-{{ $pct > 90 ? 'danger' : ($pct > 70 ? 'warning' : 'success') }}" style="width:{{ $pct }}%">{{ $pct }}%</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Storage Folders --}}
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-warning text-dark"><h6 class="mb-0"><i class="fa-solid fa-folder-tree me-1"></i>{{__('Storage Folders')}}</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-striped mb-0">
                    @foreach($storageFolders as $dir => $size)
                    <tr><td class="fw-bold ps-3"><code>storage/{{ $dir }}</code></td><td>{{ $size }}</td></tr>
                    @endforeach
                </table>
            </div>
        </div>
    </div>

    {{-- PHP Extensions --}}
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-secondary text-white"><h6 class="mb-0"><i class="fa-solid fa-puzzle-piece me-1"></i>PHP Extensions ({{ count($extensions) }})</h6></div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-1">
                    @foreach($extensions as $ext)
                    <span class="badge bg-{{ in_array($ext, ['pdo_mysql','curl','gd','mbstring','openssl','json','xml','fileinfo','bcmath']) ? 'success' : 'secondary' }}">{{ $ext }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>

{{-- PHP Info Raw --}}
<div class="card shadow-sm mb-4">
    <div class="card-header">
        <h6 class="mb-0 d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-code me-1"></i>{{__('PHP Info (Raw)')}}</span>
            <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#phpinfoRaw">{{__('Toggle')}}</button>
        </h6>
    </div>
    <div class="collapse" id="phpinfoRaw">
        <div class="card-body" style="max-height:500px;overflow:auto">
            <style>.phpinfo-raw table{width:100%;border-collapse:collapse;font-size:12px} .phpinfo-raw td,.phpinfo-raw th{border:1px solid #ddd;padding:4px 8px;text-align:left} .phpinfo-raw tr:nth-child(even){background:#f8f9fa} .phpinfo-raw .h,.phpinfo-raw h1,.phpinfo-raw h2{color:#0d6efd;font-size:14px}</style>
            <div class="phpinfo-raw">{!! str_replace(['<h1', '</h1>'], ['<h2', '</h2>'], $phpinfoBody) !!}</div>
        </div>
    </div>
</div>
@endsection
