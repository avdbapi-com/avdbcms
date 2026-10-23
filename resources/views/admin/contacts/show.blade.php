@extends('layouts.admin')
@section('title', __('Contact Message'))
@section('content')
<div class="d-flex justify-content-between align-items-center my-3">
    <h2><i class="fa-solid fa-envelope-open me-2"></i>{{__('Contact Message')}}</h2>
    <a href="{{ route('admin.contacts.index') }}" class="btn btn-sm btn-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i>{{__('Back')}}
    </a>
</div>
<hr>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>{{__('Name')}}:</strong>
                <p class="mb-0">{{ $message->name }}</p>
            </div>
            <div class="col-md-6">
                <strong>{{__('Email')}}:</strong>
                <p class="mb-0"><a href="mailto:{{ $message->email }}">{{ $message->email }}</a></p>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <strong>{{__('Subject')}}:</strong>
                <p class="mb-0">{{ $message->subject ?: '—' }}</p>
            </div>
            <div class="col-md-6">
                <strong>{{__('Date')}}:</strong>
                <p class="mb-0">{{ \Carbon\Carbon::parse($message->created_at)->translatedFormat('M d, Y H:i') }} ({{ \Carbon\Carbon::parse($message->created_at)->diffForHumans() }})</p>
            </div>
        </div>

        @if($message->ip_address ?? null)
        <div class="row mb-3">
            <div class="col-md-6">
                <strong>{{__('IP Address')}}:</strong>
                <p class="mb-0"><code>{{ $message->ip_address }}</code></p>
            </div>
        </div>
        @endif

        <hr>

        <div class="mb-3">
            <strong>{{__('Message')}}:</strong>
            <div class="mt-2 p-3 bg-light rounded" style="white-space: pre-wrap;">{{ $message->message }}</div>
        </div>

        <hr>

        <div class="d-flex gap-2">
            <a href="mailto:{{ $message->email }}?subject=Re: {{ urlencode($message->subject ?? 'Contact') }}" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-reply me-1"></i>{{__('Reply')}}
            </a>
            <form method="POST" action="{{ route('admin.contacts.delete', $message->id) }}" onsubmit='return confirm(@json(__('Are you sure?')))'>
                @csrf @method('DELETE')
                <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash me-1"></i>{{__('Delete')}}</button>
            </form>
        </div>
    </div>
</div>
@endsection
