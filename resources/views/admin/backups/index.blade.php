@extends('layouts.admin')
@section('title', __('Backups'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-database me-2"></i>{{__('Backup Management')}}</h2>
    <form method="POST" action="{{ route('admin.backups.create') }}">
        @csrf
        <button class="btn btn-success"><i class="fa-solid fa-plus me-1"></i>{{__('Create Database Backup')}}</button>
    </form>
</div>
<hr>

<div class="row mb-4">
    <div class="col-md-4"><div class="card shadow-sm border-info"><div class="card-body text-center py-3">
        <i class="fa-solid fa-database text-info fa-2x mb-2"></i>
        <div class="fw-bold fs-5">{{ $dbSize ?? '—' }}</div>
        <small class="text-muted">{{__('Database Size')}}</small>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm border-primary"><div class="card-body text-center py-3">
        <i class="fa-solid fa-file-archive text-primary fa-2x mb-2"></i>
        <div class="fw-bold fs-5">{{ $backupCount }}</div>
        <small class="text-muted">{{__('Stored Backups')}}</small>
    </div></div></div>
    <div class="col-md-4"><div class="card shadow-sm border-success"><div class="card-body text-center py-3">
        <i class="fa-solid fa-hard-drive text-success fa-2x mb-2"></i>
        <div class="fw-bold fs-5">{{ $backupTotalText }}</div>
        <small class="text-muted">{{__('Total Backup Size')}}</small>
    </div></div></div>
</div>

<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #198754"><th>{{__('Filename')}}</th><th>{{__("Size")}}</th><th>{{__('Date')}}</th><th>{{__('Actions')}}</th></tr></thead>
        <tbody>
        @forelse($backups as $backup)
        <tr>
            <td class="align-middle"><i class="fa-solid fa-file-zipper text-info me-1"></i><code>{{ $backup['name'] }}</code></td>
            <td class="align-middle text-nowrap">{{ $backup['size_text'] }}</td>
            <td class="align-middle"><small>{{ date('M d, Y H:i:s', $backup['date']) }}</small></td>
            <td class="align-middle">
                <div class="d-flex gap-1">
                    <a href="{{ route('admin.backups.download', $backup['name']) }}" class="btn btn-sm btn-primary py-0" aria-label="{{ __('Download :name', ['name' => $backup['name']]) }}"><i class="fa-solid fa-download" aria-hidden="true"></i></a>
                    <form method="POST" action="{{ route('admin.backups.delete', $backup['name']) }}" onsubmit='return confirm(@json(__('Delete this backup?')))'>
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-danger py-0" aria-label="{{ __('Delete :name', ['name' => $backup['name']]) }}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                    </form>
                </div>
            </td>
        </tr>
        @empty
        <tr><td colspan="4" class="text-center py-4 text-muted"><i class="fa-solid fa-box-open fa-2x mb-2"></i><p>{{__('No backups yet')}}</p></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$backups" />
@endsection
