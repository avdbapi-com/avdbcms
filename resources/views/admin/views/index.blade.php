@extends('layouts.admin')
@section('title', __('View Analytics'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-eye me-2"></i>{{__('View Analytics')}}</h2>
    <form method="POST" action="{{ route('admin.views.clear') }}" onsubmit='return confirm(@json(__('Clear ALL view history?')))'>
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Clear All')}}</button>
    </form>
</div>
<hr>
<div class="alert alert-info py-2">
    <strong>{{__('Storage mode')}}:</strong>
    {{ \Illuminate\Support\Str::headline($viewLogMode ?? 'authenticated') }}.
    {{__('Public totals come from the video counter. Recent rows below are raw history only and may be empty in counter-only mode.')}}
</div>

{{-- Stats --}}
<div class="row mb-4">
    @foreach([
        ['Total Views', number_format($stats->total ?? 0), 'fa-eye', 'primary'],
        ['Today', number_format($stats->today ?? 0), 'fa-calendar-day', 'success'],
        ['Unique Videos', number_format($stats->unique_videos ?? 0), 'fa-video', 'info'],
        ['Unique IPs', number_format($stats->unique_ips ?? 0), 'fa-globe', 'warning'],
        ['Logged-in', number_format($stats->logged_in_viewers ?? 0), 'fa-user', 'secondary'],
    ] as $s)
    <div class="col mb-3">
        <div class="card shadow-sm border-{{ $s[3] }}"><div class="card-body text-center py-2">
            <i class="fa-solid {{ $s[2] }} text-{{ $s[3] }} fa-lg mb-1"></i>
            <div class="fw-bold fs-5">{{ $s[1] }}</div>
            <small class="text-muted">{{ $s[0] }}</small>
        </div></div>
    </div>
    @endforeach
</div>

<div class="row mb-4">
    <div class="col-md-5">
        <div class="card shadow-sm"><div class="card-header fw-bold"><i class="fa-solid fa-trophy me-1 text-warning"></i>{{__('Top 10 Viewed Videos')}}</div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                @forelse($topVideos as $v)
                <tr><td class="ps-3">{{ Str::limit($v->title, 40) }}</td><td class="text-end pe-3"><span class="badge bg-primary">{{ number_format($v->view_count) }}</span></td></tr>
                @empty
                <tr><td class="text-center text-muted py-3">{{__('No data')}}</td></tr>
                @endforelse
            </table>
        </div></div>
    </div>
    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header fw-bold">{{__('Recent Views')}}</div>
            <div class="table-responsive" tabindex="0" style="max-height:300px;overflow-y:auto">
                <table class="table table-sm table-striped mb-0">
                    <thead><tr><th>{{__('Video')}}</th><th>{{__('User')}}</th><th>IP</th><th>{{__('When')}}</th><th></th></tr></thead>
                    <tbody>
                    @forelse($views as $view)
                    <tr>
                        <td><small>{{ Str::limit($view->video_title ?? 'Deleted', 30) }}</small></td>
                        <td><small>{{ $view->username ?? 'Guest' }}</small></td>
                        <td><small class="text-muted">{{ $view->ip }}</small></td>
                        <td><small>{{ \Carbon\Carbon::parse($view->view_at)->diffForHumans() }}</small></td>
                        <td>
                            <form method="POST" action="{{ route('admin.views.delete', $view->id) }}" class="d-inline">
                                @csrf @method('DELETE')
                                <button class="btn btn-xs btn-outline-danger py-0 px-1" style="font-size:11px">✕</button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">{{__('No view data')}}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<x-admin.pagination :paginator="$views" />
@endsection
