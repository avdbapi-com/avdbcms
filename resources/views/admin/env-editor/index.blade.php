@extends('layouts.admin')
@section('title', __('.env Editor'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-file-code me-2"></i>{{__('.env File Editor')}}</{{ $titleTag }}>
    <div>
        <span class="badge bg-secondary me-2"><i class="fa-solid fa-lines-leaning me-1"></i>{{ $lineCount }} {{__('lines')}}</span>
        <span class="badge bg-secondary me-2"><i class="fa-solid fa-hard-drive me-1"></i>{{ number_format($fileSize) }} {{__('bytes')}}</span>
        <span class="badge bg-info"><i class="fa-solid fa-clock me-1"></i>{{__('Modified')}}: {{ $lastModified }}</span>
    </div>
</div>

<div class="alert alert-warning">
    <i class="fa-solid fa-triangle-exclamation me-1"></i>
    <strong>{{__('Caution')}}:</strong> {{__('Editing this file directly can break your application. A backup is created automatically on each save. Changes take effect immediately after config cache is cleared.')}}
</div>

<div class="card shadow">
    <form method="POST" action="{{ route('admin.env-editor.update') }}">
        @csrf @method('PUT')
        <div class="card-body p-0">
            <textarea name="content" aria-label="{{ __('Settings file (.env)') }}" class="form-control font-monospace border-0 rounded-0" 
                      rows="35" style="resize:vertical; font-size:13px; line-height:1.6; tab-size:4; background:#1e1e2e; color:#cdd6f4; padding:20px;"
                      spellcheck="false">{{ $content }}</textarea>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <small class="text-muted">{{__('Backup will be saved automatically before changes are applied.')}}</small>
            <button type="submit" class="btn btn-primary" onclick='return confirm(@json(__('Save changes to .env file? A backup will be created.')))'>
                <i class="fa-solid fa-save me-1"></i>{{__('Save & Clear Config Cache')}}
            </button>
        </div>
    </form>
</div>
@endsection
