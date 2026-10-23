{{--
    Community > Inbox > one message, in the redesigned admin (the old admin keeps contacts/show): the message, who sent
    it and when, "Reply by email", and Delete in the header's More menu with its confirmation. Opening it marks it read.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', $message->subject ?: __('Message from :name', ['name' => $message->name]))

@section('content')
    @php
        $title = $message->subject ?: __('Message from :name', ['name' => $message->name]);
        $received = \Illuminate\Support\Carbon::parse($message->created_at);
    @endphp

    <x-admin.page-header page="community.inbox" :title="$title"
        :description="__('From :name, :when', ['name' => $message->name, 'when' => $received->diffForHumans()])"
        :crumbs="[['label' => __('Community'), 'url' => route('admin.reports.index')], ['label' => __('Inbox'), 'url' => route('admin.contacts.index')], ['label' => \Illuminate\Support\Str::limit($title, 40), 'url' => null]]"
        :tabs="[]">
        <x-slot:actions>
            <x-admin.menu id="ad-contact-more" :label="__('More actions')">
                <form method="POST" action="{{ route('admin.contacts.delete', $message->id) }}"
                      data-ad-confirm="{{ __('Delete this message?') }}" data-ad-confirm-body="{{ __('This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete message') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete message...') }}</button>
                </form>
            </x-admin.menu>
            <a class="ad-btn is-primary" href="mailto:{{ $message->email }}?subject={{ rawurlencode('Re: '.($message->subject ?: __('Your message'))) }}">{{ AdminTheme::icon('mail') }}{{ __('Reply by email') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="ad-record">
        <section class="ad-panel ad-record-main" aria-labelledby="ad-contact-text">
            <div class="ad-panel-h"><h2 id="ad-contact-text">{{ __('Message') }}</h2></div>
            <div class="ad-panel-b ad-message">{{ $message->message }}</div>
        </section>

        <aside class="ad-record-side" aria-label="{{ __('About this message') }}">
            <section class="ad-panel">
                <dl class="ad-facts">
                    <div><dt>{{ __('From') }}</dt><dd>{{ $message->name }}</dd></div>
                    <div><dt>{{ __('Email') }}</dt><dd><a href="mailto:{{ $message->email }}">{{ $message->email }}</a></dd></div>
                    <div><dt>{{ __('Received') }}</dt><dd>{{ $received->translatedFormat('j M Y, H:i') }}</dd></div>
                    @if ($message->ip_address ?? null)
                        <div><dt>{{ __('IP address') }}</dt><dd><code>{{ $message->ip_address }}</code></dd></div>
                    @endif
                </dl>
            </section>
        </aside>
    </div>
@endsection
