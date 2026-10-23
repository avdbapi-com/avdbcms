{{--
    Community > Send a notification > To everyone, in the redesigned admin (the old admin keeps
    broadcast/index.blade.php): one notification to every member, the members with a confirmed email, the premium
    members or chosen member numbers (same form fields and address as before, asked to confirm), and the 10 latest
    notifications.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Send a notification'))

@section('content')
    <x-admin.list-page>
    @php
        $targets = [
            'all' => __('Every member (:count)', ['count' => number_format($audience['all'])]),
            'verified' => __('Members with a confirmed email (:count)', ['count' => number_format($audience['verified'])]),
            'premium' => __('Premium members (:count)', ['count' => number_format($audience['premium'])]),
            'specific' => __('Chosen members'),
        ];
    @endphp

    <x-admin.page-header page="community.notify" :description="__('Send one notification to many members at once. It appears in their bell.')" />

    <section class="ad-panel ad-console-block" aria-labelledby="ad-broadcast-h">
        <div class="ad-panel-h"><h2 id="ad-broadcast-h">{{ __('New notification') }}</h2></div>
        <form method="POST" action="{{ route('admin.broadcast.send') }}" class="ad-panel-b ad-record-fields"
              data-ad-confirm="{{ __('Send this notification to the members you picked?') }}" data-ad-confirm-body="{{ __('It appears at once in their bell and cannot be taken back from there one by one.') }}" data-ad-confirm-label="{{ __('Send') }}" data-ad-confirm-tone="primary">
            @csrf
            <x-admin.field name="target" :label="__('Send to')">
                <select class="ad-select" id="target" name="target">
                    @foreach ($targets as $value => $label)
                        <option value="{{ $value }}" @selected(old('target', 'all') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field name="user_ids" :label="__('Member numbers')" :help="__('Only for Chosen members: their numbers, separated by commas, for example 1, 2, 5.')" placeholder="1, 2, 5" />
            <x-admin.field name="title" :label="__('Title')">
                <input class="ad-input" id="title" name="title" value="{{ old('title') }}" maxlength="200" required>
            </x-admin.field>
            <x-admin.field name="message" :label="__('Message')">
                <textarea class="ad-textarea" id="message" name="message" rows="5" maxlength="5000" required>{{ old('message') }}</textarea>
            </x-admin.field>
            <div class="ad-record-actions"><button type="submit" class="ad-btn is-primary">{{ AdminTheme::icon('send') }}{{ __('Send notification...') }}</button></div>
        </form>
    </section>

    <section class="ad-panel" aria-labelledby="ad-broadcast-recent">
        <div class="ad-panel-h"><h2 id="ad-broadcast-recent">{{ __('Sent lately') }}</h2></div>
        @if ($recent->isEmpty())
            <x-admin.empty icon="bell" :title="__('Nothing sent yet')">{{ __('The latest notifications appear here.') }}</x-admin.empty>
        @else
            <ul class="ad-list">
                @foreach ($recent as $notification)
                    @php
                        $data = json_decode((string) $notification->data, true);
                        $title = is_array($data) && is_string($data['title'] ?? null) ? $data['title'] : \Illuminate\Support\Str::headline(class_basename((string) $notification->type));
                        $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : '';
                    @endphp
                    <li>
                        <span class="ad-li-text"><b>{{ \Illuminate\Support\Str::limit($title, 60) }}</b><span>{{ \Illuminate\Support\Str::limit($message, 100) }}</span></span>
                        <small class="ad-home-when">{{ \Illuminate\Support\Carbon::parse($notification->created_at)->diffForHumans(short: true) }}</small>
                    </li>
                @endforeach
            </ul>
            <div class="ad-panel-f"><a class="ad-link" href="{{ route('admin.notifications.index') }}">{{ __('See every notification') }}</a></div>
        @endif
    </section>
    </x-admin.list-page>
@endsection
