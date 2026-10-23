{{--
    Community > Inbox in the redesigned admin (the old admin keeps contacts/index.blade.php): the contact form's
    messages, Unread / All tabs, bulk "mark as read" and delete, a row menu filled from one template
    (modules/list-rows.ts: mark as read, delete with Undo), the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Inbox'))

@section('content')
    @php ob_start(); @endphp
    @php
        $unreadOnly = request('show') === 'unread';
        $tabs = [
            ['label' => __('All'), 'url' => route('admin.contacts.index', [], false), 'active' => ! $unreadOnly, 'count' => number_format($stats['total'])],
            ['label' => __('Unread'), 'url' => route('admin.contacts.index', ['show' => 'unread'], false), 'active' => $unreadOnly, 'count' => number_format($stats['unread'])],
        ];
    @endphp

    <x-admin.page-header page="community.inbox"
        :description="trans_choice('1 message from your contact form.|:count messages from your contact form.', $stats['total'], ['count' => number_format($stats['total'])]).($stats['today'] > 0 ? ' '.trans_choice('1 came today.|:count came today.', $stats['today'], ['count' => $stats['today']]) : '')"
        :tabs="$tabs">
        <x-slot:actions>
            @if ($stats['unread'] > 0)
                <form method="POST" action="{{ route('admin.contacts.mark-read') }}">
                    @csrf
                    <button type="submit" class="ad-btn">{{ AdminTheme::icon('check') }}{{ __('Mark all as read') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-contacts-bulk" method="POST" action="{{ route('admin.bulk.contacts') }}" hidden>@csrf</form>
    <form id="ad-contact-read" method="POST" action="{{ route('admin.bulk.contacts', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('Marked as read') }}" hidden><input type="hidden" name="action" value="mark_read"></form>
    <form id="ad-contact-delete" method="POST" action="{{ route('admin.contacts.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Message deleted') }}"
          data-ad-confirm="{{ __('Delete this message?') }}" data-ad-confirm-body="{{ __('You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete message') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-sel">{{ __('Select') }}</span><span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-contact-menu">
        <a href="{{ route('admin.contacts.show', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('inbox') }}{{ __('Open message') }}</a>
        <a href="mailto:__EMAIL__">{{ AdminTheme::icon('mail') }}{{ __('Reply by email') }}</a>
        <hr>
        <button type="submit" form="ad-contact-read" name="ids[]" value="__ID__" data-ad-when="unread" data-ad-expect="read">{{ AdminTheme::icon('check') }}{{ __('Mark as read') }}</button>
        <button type="submit" form="ad-contact-delete" formaction="{{ route('admin.contacts.delete', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete message...') }}</button>
    </template>

    <x-admin.table :paginator="$messages" :label="__('Messages')" list="contacts" table-class="is-list" row-menu="ad-contact-menu">
        <x-slot:bulk>
            <button type="submit" class="ad-btn sm" form="ad-contacts-bulk" name="action" value="mark_read">{{ __('Mark as read') }}</button>
            <button type="submit" class="ad-btn is-danger sm" form="ad-contacts-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Delete the selected messages?') }}" data-ad-confirm-body="{{ __('This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete messages') }}">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all messages on this page') }}"></th>
            <th scope="col">{{ __('Message') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('From') }}</th>
            <th scope="col">{{ __('Received') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="inbox" :title="$unreadOnly ? __('Nothing unread') : __('No messages yet')">{{ __('Messages visitors send with your contact form appear here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($messages as $message)
            @php($received = \Illuminate\Support\Carbon::parse($message->created_at))
            <tr>
                <td class="is-check"><input type="checkbox" name="ids[]" value="{{ $message->id }}" form="ad-contacts-bulk" aria-labelledby="ad-l-sel ad-m-{{ $message->id }}"></td>
                <td class="c-main"><p><a id="ad-m-{{ $message->id }}" href="{{ route('admin.contacts.show', $message->id, false) }}" @class(['is-unread' => ! $message->is_read])>{{ $message->subject ?: \Illuminate\Support\Str::limit((string) $message->message, 60) }}</a><small>{{ \Illuminate\Support\Str::limit((string) $message->message, 120) }}</small></p></td>
                <td class="c-meta"><x-admin.status :type="$message->is_read ? 'off' : 'info'">{{ $message->is_read ? __('Read') : __('New') }}</x-admin.status><small class="ad-phone-only">{{ $message->name }}</small></td>
                <td class="hide-tab"><p class="ad-cell-two"><b>{{ $message->name }}</b><small>{{ $message->email }}</small></p></td>
                <td class="is-date" title="{{ $received->translatedFormat('j M Y, H:i') }}">{{ $received->diffForHumans(short: true) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-m-{{ $message->id }}" data-id="{{ $message->id }}" data-state="{{ $message->is_read ? 'read' : 'unread' }}" data-email="{{ $message->email }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
