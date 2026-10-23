@extends('layouts.admin')
@section('title', __('Storage Manager'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-hard-drive me-2"></i>{{__('Storage Manager')}}</{{ $titleTag }}>
</div>

{{-- Disk Usage --}}
<div class="card shadow mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-chart-pie me-1"></i>{{__('Disk Usage')}}</h5></div>
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="progress" style="height:30px">
                    <div class="progress-bar bg-{{ $disk['percent'] > 90 ? 'danger' : ($disk['percent'] > 70 ? 'warning' : 'success') }}"
                         style="width:{{ $disk['percent'] }}%">{{ $disk['percent'] }}% {{__('Used')}}</div>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <strong>{{ number_format($disk['used'] / 1073741824, 2) }} GB</strong> {{__('used of')}}
                <strong>{{ number_format($disk['total'] / 1073741824, 2) }} GB</strong>
                <br><small class="text-muted">{{ number_format($disk['free'] / 1073741824, 2) }} GB {{__('free')}}</small>
            </div>
        </div>
    </div>
</div>

{{-- Directories --}}
<div class="card shadow">
    <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-folder-tree me-1"></i>{{__('Directory Breakdown')}}</h5></div>
    <div class="table-responsive" tabindex="0">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr><th>{{__('Directory')}}</th><th>{{__("Files")}}</th><th>{{__('Size')}}</th><th>{{__('Status')}}</th><th>{{__('Actions')}}</th></tr>
            </thead>
            <tbody>
                @foreach($directories as $name => $dir)
                <tr>
                    <td><i class="fa-solid fa-folder text-warning me-1"></i><code>{{ $name }}</code></td>
                    <td><span class="badge bg-secondary">{{ number_format($dir['files']) }}</span></td>
                    <td><strong>{{ $dir['formatted_size'] ?? '0 B' }}</strong></td>
                    <td>
                        @if($dir['exists'])
                            <span class="badge bg-success">{{__('Exists')}}</span>
                        @else
                            <span class="badge bg-danger">{{__('Missing')}}</span>
                        @endif
                    </td>
                    <td>
                        @if(str_contains($name, 'cache') || str_contains($name, 'sessions') || str_contains($name, 'views') || str_contains($name, 'logs'))
                        @php
                            $type = match(true) {
                                str_contains($name, 'cache') => 'cache',
                                str_contains($name, 'sessions') => 'sessions',
                                str_contains($name, 'views') => 'views',
                                str_contains($name, 'logs') => 'logs',
                                default => null,
                            };
                        @endphp
                        @if($type)
                        <form method="POST" action="{{ route('admin.storage.clear', $type) }}" onsubmit='return confirm(@json(__('Clear all files in :name?', ['name' => $name])))'>
                            @csrf
                            <button class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-broom me-1"></i>{{__('Clear')}}</button>
                        </form>
                        @endif
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
