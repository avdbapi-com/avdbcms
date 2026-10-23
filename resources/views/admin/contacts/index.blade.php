@extends('layouts.admin')
@section('title', __('Contact Messages'))
@section('content')
<div x-data="{selected:[]}">
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-envelope me-2"></i>{{__('Contact Messages')}}</h2>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <template x-if="selected.length > 0">
            <div class="d-flex gap-2 align-items-center">
                <span class="badge bg-primary fs-6" x-text="selected.length + ' {{ __("selected") }}'"></span>
                <form method="POST" action="{{ route('admin.bulk.contacts') }}">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('{{ __('Delete selected messages?') }}')"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Selected')}}</button>
                </form>
                <form method="POST" action="{{ route('admin.bulk.contacts') }}">
                    @csrf
                    <template x-for="id in selected" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
                    <input type="hidden" name="action" value="mark_read">
                    <button type="submit" class="btn btn-success btn-sm"><i class="fa-solid fa-check me-1"></i>{{__('Mark Read')}}</button>
                </form>
            </div>
        </template>
        <form method="POST" action="{{ route('admin.contacts.mark-read') }}">
            @csrf
            <button class="btn btn-sm btn-primary"><i class="fa-solid fa-check-double me-1"></i>{{__('Mark All Read')}}</button>
        </form>
    </div>
</div>
<hr>

<div class="row mb-4">
    @foreach([
        ['Total Messages', $stats['total'], 'fa-envelope', 'primary'],
        ['Unread', $stats['unread'], 'fa-envelope-circle-check', 'danger'],
        ['Today', $stats['today'], 'fa-clock', 'success'],
    ] as $s)
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm border-{{ $s[3] }}"><div class="card-body text-center py-2">
            <i class="fa-solid {{ $s[2] }} text-{{ $s[3] }}"></i>
            <div class="fw-bold fs-5">{{ $s[1] }}</div>
            <small class="text-muted">{{ $s[0] }}</small>
        </div></div>
    </div>
    @endforeach
</div>

<div class="table-responsive" tabindex="0">
    <table class="table table-bordered table-striped table-sm">
        <thead><tr style="border-top:3px solid #0d6efd">
            <th scope="col">
                <input type="checkbox" class="form-check-input" aria-label="{{ __('Select all') }}"
                    @change="if($event.target.checked) { selected = [...document.querySelectorAll('.bulk-check')].map(e => e.value) } else { selected = [] }"
                >
            </th>
            <th>{{__('Status')}}</th><th>{{__("Name")}}</th><th>{{__('Email')}}</th><th>{{__('Subject')}}</th><th style="min-width:200px">{{__('Message')}}</th><th>IP</th><th>{{__('Date')}}</th><th>{{__('Actions')}}</th>
        </tr></thead>
        <tbody>
        @forelse($messages as $msg)
        <tr class="{{ !$msg->is_read ? 'table-warning' : '' }}">
            <td class="align-middle">
                <input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $msg->id }}" x-model="selected">
            </td>
            <td class="align-middle">@if(!$msg->is_read)<span class="badge bg-danger">{{__('New')}}</span>@else<span class="badge bg-secondary">{{__('Read')}}</span>@endif</td>
            <td class="align-middle fw-bold">{{ $msg->name }}</td>
            <td class="align-middle"><small><a href="mailto:{{ $msg->email }}">{{ $msg->email }}</a></small></td>
            <td class="align-middle"><small>{{ $msg->subject ?: '—' }}</small></td>
            <td class="align-middle"><small>{{ Str::limit($msg->message, 100) }}</small></td>
            <td class="align-middle"><code class="small">{{ $msg->ip_address ?? '—' }}</code></td>
            <td class="align-middle"><small>{{ \Carbon\Carbon::parse($msg->created_at)->diffForHumans() }}</small></td>
            <td class="align-middle">
                <form method="POST" action="{{ route('admin.contacts.delete', $msg->id) }}" onsubmit='return confirm(@json(__('Delete?')))'>
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger py-0"><i class="fa-solid fa-trash"></i></button>
                </form>
            </td>
        </tr>
        @empty
        <tr><td colspan="9" class="text-center py-4 text-muted"><i class="fa-solid fa-inbox fa-2x mb-2"></i><p>{{__('No messages')}}</p></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<x-admin.pagination :paginator="$messages" />
</div>
@endsection
