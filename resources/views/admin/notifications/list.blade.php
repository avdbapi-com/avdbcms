{{--
    Community > Send a notification > Sent, in the redesigned admin (the old admin keeps notifications/index.blade.php):
    "New notification" (to every member, the premium members or those with an unconfirmed email), the notifications
    members got with all / unread, bulk "mark as read" and delete, a row menu filled from one template
    (modules/list-rows.ts: mark as read, delete with Undo), clearing them all, the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Send a notification'))

@section('content')
    <x-admin.list-page>
    @php
        $targets = ['all' => __('Every member'), 'premium' => __('Premium members'), 'unverified' => __('Members who did not confirm their email')];
        $summary = function (object $notification): array {
            $data = json_decode((string) $notification->data, true);
            $data = is_array($data) ? $data : [];
            $title = $data['title'] ?? $data['subject'] ?? null;
            $kind = class_basename((string) $notification->type);

            return [
                is_string($title) && $title !== '' ? $title : ($kind === 'AdminBroadcast' ? __('Message from you') : \Illuminate\Support\Str::headline($kind)),
                is_string($data['message'] ?? null) ? $data['message'] : (is_string($data['body'] ?? null) ? $data['body'] : ''),
            ];
        };
    @endphp

    <x-admin.page-header page="community.notify"
        :description="trans_choice('1 notification was sent.|:count notifications were sent.', $stats['total'], ['count' => number_format($stats['total'])]).($stats['unread'] > 0 ? ' '.trans_choice('1 is not read yet.|:count are not read yet.', $stats['unread'], ['count' => number_format($stats['unread'])]) : '')">
        <x-slot:actions>
            @if ($stats['total'] > 0)
                <form method="POST" action="{{ route('admin.notifications.clear') }}"
                      data-ad-confirm="{{ __('Delete every notification?') }}" data-ad-confirm-body="{{ __("They disappear from every member's bell. This cannot be undone.") }}" data-ad-confirm-label="{{ __('Delete all') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete all...') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <details class="ad-panel ad-console-block ad-fold" @if ($stats['total'] === 0 || $errors->any()) open @endif>
        <summary class="ad-panel-h"><h2>{{ __('New notification') }}</h2></summary>
        <form method="POST" action="{{ route('admin.notifications.broadcast') }}" class="ad-panel-b ad-record-fields"
              data-ad-confirm="{{ __('Send this notification?') }}" data-ad-confirm-body="{{ __('It appears at once in the bell of every member you picked.') }}" data-ad-confirm-label="{{ __('Send') }}" data-ad-confirm-tone="primary">
            @csrf
            <x-admin.field name="target" :label="__('Send to')">
                <select class="ad-select" id="target" name="target">
                    @foreach ($targets as $value => $label)
                        <option value="{{ $value }}" @selected(old('target', 'all') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field name="title" :label="__('Title')" />
            <x-admin.field name="message" type="textarea" :label="__('Message')" />
            <div class="ad-record-actions"><button type="submit" class="ad-btn is-primary">{{ AdminTheme::icon('send') }}{{ __('Send notification...') }}</button></div>
        </form>
    </details>

    <form id="ad-notifications-bulk" method="POST" action="{{ route('admin.bulk.notifications') }}" hidden>@csrf</form>
    <form id="ad-notification-read" method="POST" action="{{ route('admin.bulk.notifications', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('Marked as read') }}" hidden><input type="hidden" name="action" value="mark_read"></form>
    <form id="ad-notification-delete" method="POST" action="{{ route('admin.notifications.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Notification deleted') }}"
          data-ad-confirm="{{ __('Delete this notification?') }}" data-ad-confirm-body="{{ __("It disappears from the member's bell. You can undo for a few seconds after this.") }}" data-ad-confirm-label="{{ __('Delete') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-sel">{{ __('Select') }}</span><span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-notification-menu">
        <a href="{{ route('admin.users.edit', ['user' => '__USER__'], false) }}" data-ad-if="user">{{ AdminTheme::icon('user') }}{{ __('Open member') }}</a>
        <button type="submit" form="ad-notification-read" name="ids[]" value="__ID__" data-ad-when="new" data-ad-expect="read">{{ AdminTheme::icon('check') }}{{ __('Mark as read') }}</button>
        <hr>
        <button type="submit" form="ad-notification-delete" formaction="{{ route('admin.notifications.delete', ['notification' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
    </template>

    <x-admin.table :paginator="$notifications" :label="__('Notifications')" list="notifications" table-class="is-list" row-menu="ad-notification-menu">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.notifications.index') }}" class="ad-filter" aria-label="{{ __('Filter notifications') }}">
                <select class="ad-select" name="show" aria-label="{{ __('Show') }}" data-ad-autosubmit>
                    <option value="">{{ __('All notifications') }}</option>
                    <option value="unread" @selected($unreadOnly)>{{ __('Not read yet') }}</option>
                </select>
                <noscript><button type="submit" class="ad-btn">{{ __('Show') }}</button></noscript>
            </form>
        </x-slot:toolbar>

        <x-slot:bulk>
            <button type="submit" class="ad-btn sm" form="ad-notifications-bulk" name="action" value="mark_read">{{ __('Mark as read') }}</button>
            <button type="submit" class="ad-btn is-danger sm" form="ad-notifications-bulk" name="action" value="delete"
                    data-ad-confirm="{{ __('Delete the selected notifications?') }}" data-ad-confirm-body="{{ __('This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete') }}">{{ AdminTheme::icon('trash') }}{{ __('Delete...') }}</button>
        </x-slot:bulk>

        <x-slot:head>
            <th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="{{ __('Select all notifications on this page') }}"></th>
            <th scope="col">{{ __('Notification') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('Recipient') }}</th>
            <th scope="col">{{ __('Sent') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="bell" :title="$unreadOnly ? __('Nothing unread') : __('No notifications yet')">{{ __('Notifications you send, and the ones your site sends to members, are listed here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($notifications as $notification)
            @php
                [$title, $message] = $summary($notification);
                $sent = \Illuminate\Support\Carbon::parse($notification->created_at);
                $key = 'ad-n-'.substr(md5((string) $notification->id), 0, 10);
            @endphp
            <tr>
                <td class="is-check"><input type="checkbox" name="ids[]" value="{{ $notification->id }}" form="ad-notifications-bulk" aria-labelledby="ad-l-sel {{ $key }}"></td>
                <td class="c-main"><p><b id="{{ $key }}">{{ \Illuminate\Support\Str::limit($title, 80) }}</b>@if ($message !== '')<small>{{ \Illuminate\Support\Str::limit($message, 120) }}</small>@endif</p></td>
                <td class="c-meta"><x-admin.status :type="$notification->read_at ? 'off' : 'info'">{{ $notification->read_at ? __('Read') : __('New') }}</x-admin.status><small class="ad-phone-only">{{ $notification->username ?? '#'.$notification->notifiable_id }}</small></td>
                <td class="hide-tab">{{ $notification->username ?? '#'.$notification->notifiable_id }}</td>
                <td class="is-date" title="{{ $sent->translatedFormat('j M Y, H:i') }}">{{ $sent->diffForHumans(short: true) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act {{ $key }}" data-id="{{ $notification->id }}" data-state="{{ $notification->read_at ? 'read' : 'new' }}" @if ($notification->username !== null) data-user="{{ $notification->notifiable_id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
