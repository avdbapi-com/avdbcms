{{--
    The 403 page of App\Http\Middleware\EnsureDeveloperMode: a developer page opened without Developer mode.
    It explains what the page is for and turns Developer mode on in one click.
--}}
@extends('layouts.admin')
@section('title', __('Developer mode is off'))
@section('content')
    <x-admin.callout type="warn" :title="__('Developer mode is off')">
        {{ $message ?? __('This tool is for developers. Turn on Developer mode in your account menu to use it.') }}
        <x-slot:actions>
            <form method="POST" action="{{ route('admin.shell.preferences') }}">
                @csrf
                <input type="hidden" name="key" value="developer_mode">
                <input type="hidden" name="value" value="1">
                <x-admin.button type="submit" variant="primary" icon="code">{{ __('Turn on Developer mode') }}</x-admin.button>
            </form>
            <x-admin.button :href="route('admin.index')">{{ __('Back to Home') }}</x-admin.button>
        </x-slot:actions>
    </x-admin.callout>
    <p class="ad-muted">{{ __('Developer mode shows the custom code, database and settings file tools. A wrong change there can take your site offline, so they stay hidden until you need them.') }}</p>
@endsection
