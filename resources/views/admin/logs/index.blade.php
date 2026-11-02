@extends('layouts.admin')
@section('title', __('Log Viewer'))
@section('content')
{{-- One H1 per page: the new admin's shell prints it. --}}
@php $titleTag = \App\Support\AdminPreferences::usesNewAdmin() ? 'h2' : 'h1'; @endphp
<div class="d-flex justify-content-between align-items-center mb-4">
    <{{ $titleTag }} class="h3"><i class="fa-solid fa-file-lines me-2"></i>{{__('Laravel Log Viewer')}}</{{ $titleTag }}>
    <form method="POST" action="{{ route('admin.logs.clear-all') }}" onsubmit='return confirm(@json(__('Delete ALL log files?')))'>
        @csrf
        <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Clear All Logs')}}</button>
    </form>
</div>

<div class="row">
    {{-- File List --}}
    <div class="col-md-3">
        <div class="card shadow">
            <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-folder-open me-1"></i>{{__('Log Files')}}</h6></div>
            <div class="list-group list-group-flush">
                @forelse($files as $file)
                {{-- One row per file: the file's link, then its download and delete actions beside it (not inside it). --}}
                <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-start {{ $selectedFile === $file['name'] ? 'active' : '' }}">
                    <a href="{{ route('admin.logs.index', ['file' => $file['name']]) }}" class="text-reset text-decoration-none flex-grow-1" @if ($selectedFile === $file['name']) aria-current="true" @endif>
                        <div class="fw-bold" style="font-size:0.85em">{{ $file['name'] }}</div>
                        <small>{{ $file['modified'] }}</small>
                    </a>
                    <div class="text-end">
                        <span class="badge bg-{{ $selectedFile === $file['name'] ? 'light text-dark' : 'secondary' }}" dir="ltr">{{ $file['size'] }}</span>
                        {{-- Download and Delete: 24 x 24 px targets 4 px apart (WCAG 2.5.8; they were 12 x 19 and 10.5 x 21.5 px side by side, R3-076). --}}
                        <div class="mt-1" style="display:flex;gap:4px;justify-content:flex-end">
                            <a href="{{ route('admin.logs.download', $file['name']) }}" class="text-{{ $selectedFile === $file['name'] ? 'white' : 'primary' }}" style="display:inline-flex;align-items:center;justify-content:center;min-width:24px;min-height:24px" title="{{__('Download')}}" aria-label="{{ __('Download :name', ['name' => $file['name']]) }}"><i class="fa-solid fa-download fa-sm" aria-hidden="true"></i></a>
                            <form method="POST" action="{{ route('admin.logs.delete', $file['name']) }}" class="d-inline-flex" onsubmit='return confirm(@json(__('Delete this log?')))'>
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-link btn-sm p-0 text-{{ $selectedFile === $file['name'] ? 'white' : 'danger' }}" style="display:inline-flex;align-items:center;justify-content:center;min-width:24px;min-height:24px" title="{{__('Delete')}}" aria-label="{{ __('Delete :name', ['name' => $file['name']]) }}"><i class="fa-solid fa-times fa-sm" aria-hidden="true"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                @empty
                <div class="list-group-item text-muted text-center py-3">{{__('No log files found')}}</div>
                @endforelse
                {{-- Files the site may not read (another user's, e.g. root's cron) are not listed: say so, the list is not the whole folder. --}}
                @if (($unreadable ?? 0) > 0)
                <div class="list-group-item small text-muted">{{ trans_choice('1 log file is not shown: the site has no permission to read it.|:count log files are not shown: the site has no permission to read them.', (int) $unreadable, ['count' => number_format((int) $unreadable)]) }}</div>
                @endif
            </div>
            <div class="card-footer">
                <x-admin.pagination :paginator="$files" class="mt-0 border-0 bg-transparent px-0 py-0" />
            </div>
        </div>
    </div>

    {{-- Log Content --}}
    <div class="col-md-9">
        <div class="card shadow">
            <div class="card-header d-flex justify-content-between">
                <h6 class="mb-0"><i class="fa-solid fa-file-code me-1"></i>{{ $selectedFile ?? __('Select a file') }}</h6>
                <span class="badge bg-secondary">{{ trans_choice('1 line|:count lines', (int) $lineCount, ['count' => number_format((int) $lineCount)]) }}</span>
            </div>
            <div class="card-body p-0">
                @if (! empty($readError))
                <div class="alert alert-warning m-3" role="alert">{{ $readError }}</div>
                @elseif ($content === '')
                <p class="text-muted m-3">{{ __('Choose a log file from the list.') }}</p>
                @else
                @if (! empty($cut))
                <p class="small text-muted mx-3 my-2">{{ __('Only the last 200 KB of this file are shown.') }}</p>
                @endif
                {{-- The log's own lines read left to right on a right-to-left page too. --}}
                <pre class="mb-0 p-3" dir="ltr" tabindex="0" aria-label="{{ __('Log file content') }}" style="max-height:70vh; overflow:auto; font-size:12px; line-height:1.5; background:#1e1e2e; color:#cdd6f4; white-space:pre-wrap; word-break:break-all;">{{ $content }}</pre>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
