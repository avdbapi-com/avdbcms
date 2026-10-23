@extends('layouts.admin')
@section('title', __('Media Library'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-photo-film me-2"></i>{{__('Media Library')}}</h2>
    <div class="d-flex gap-2 align-items-center">
        <small class="text-muted">{{ $totalDirs }} folders, {{ $totalFiles }} files, {{ round($totalSize / 1048576, 2) }} MB</small>
    </div>
</div>
<hr>

{{-- Breadcrumbs --}}
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('admin.media.index') }}"><i class="fa-solid fa-home"></i> storage/public</a></li>
        @foreach($breadcrumbs as $crumb)
        <li class="breadcrumb-item {{ $loop->last ? 'active' : '' }}">
            @if($loop->last)
                {{ $crumb['name'] }}
            @else
                <a href="{{ route('admin.media.index', ['path' => $crumb['path']]) }}">{{ $crumb['name'] }}</a>
            @endif
        </li>
        @endforeach
    </ol>
</nav>

{{-- Directories --}}
@if(count($directories) > 0)
<div class="row mb-3">
    @foreach($directories as $dir)
    <div class="col-md-2 col-4 mb-3">
        <a href="{{ route('admin.media.index', ['path' => $dir['path']]) }}" class="text-decoration-none">
            <div class="card shadow-sm h-100 text-center py-3">
                <i class="fa-solid fa-folder fa-3x text-warning mb-2"></i>
                <small class="fw-bold text-dark">{{ $dir['name'] }}</small>
            </div>
        </a>
    </div>
    @endforeach
</div>
@endif

{{-- Files --}}
@if($files->count() > 0)
<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #0d6efd"><th>{{__('Preview')}}</th><th>{{__('Name')}}</th><th>{{__('Size')}}</th><th>{{__('Modified')}}</th><th>{{__('Actions')}}</th></tr></thead>
        <tbody>
        @foreach($files as $file)
        <tr>
            <td class="align-middle text-center" style="width:80px">
                @if($file['is_image'])
                    <img src="{{ $file['url'] }}" style="max-width:60px;max-height:40px" alt="">
                @elseif($file['is_video'])
                    <i class="fa-solid fa-film fa-2x text-info"></i>
                @else
                    <i class="fa-solid fa-file fa-2x text-secondary"></i>
                @endif
            </td>
            <td class="align-middle">
                <a href="{{ $file['url'] }}" target="_blank" class="text-decoration-none">{{ $file['name'] }}</a>
            </td>
            <td class="align-middle">
                @if($file['size'] > 1048576)
                    {{ round($file['size'] / 1048576, 2) }} MB
                @else
                    {{ round($file['size'] / 1024, 1) }} KB
                @endif
            </td>
            <td class="align-middle"><small>{{ date('M d, Y H:i', $file['modified']) }}</small></td>
            <td class="align-middle">
                <div class="d-flex gap-1">
                    <a href="{{ $file['url'] }}" target="_blank" class="btn btn-sm btn-info py-0" aria-label="{{ __('Open :name', ['name' => $file['name']]) }}"><i class="fa-solid fa-external-link" aria-hidden="true"></i></a>
                    <form method="POST" action="{{ route('admin.media.delete') }}" onsubmit='return confirm(@json(__('Delete :name?', ['name' => $file['name']])))'>
                        @csrf @method('DELETE')
                        <input type="hidden" name="path" value="{{ $file['path'] }}">
                        <button class="btn btn-sm btn-danger py-0" aria-label="{{ __('Delete :name', ['name' => $file['name']]) }}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                    </form>
                </div>
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if(count($directories) === 0 && $files->total() === 0)
<div class="text-center py-5 text-muted">
    <i class="fa-solid fa-folder-open fa-3x mb-3"></i>
    <p>{{__('This folder is empty')}}</p>
</div>
@endif
<x-admin.pagination :paginator="$files" />
@endsection
