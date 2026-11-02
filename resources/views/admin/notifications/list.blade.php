{{--
    Community > Send a notification, in the redesigned admin (the old admin keeps notifications/index.blade.php):
    "New notification", the site's one form to notify members (judge R1-161: every member, those with a confirmed or an
    unconfirmed email, the premium members or chosen member numbers; /admin/broadcast, the old To everyone tab, opens
    it here with ?new=1), the notifications
    members got with all / unread, bulk "mark as read" and delete, a row menu filled from one template
    (modules/list-rows.ts: mark as read, delete with Undo), clearing them all, the pager.
    Quick with millions of rows: the pager goes newer / older by key (no page numbers, no count of every row), the counts
    in the header stop at "more than 100,000", each row's text is translated here (App\Notifications\Support\NotificationText).
--}}
@use('App\Support\AdminTheme')
@use('App\Notifications\Support\NotificationText')
@use('App\Notifications\Support\NotificationRetention')
@extends('layouts.admin')

@section('title', __('Send a notification'))

@section('content')
    <x-admin.list-page>
    @php
        // the one "send a notification" form (judge R1-161; /admin/broadcast opens it): every choice, with how many it reaches
        $count = fn (string $key): string => number_format((int) (($audience ?? [])[$key] ?? 0));
        $targets = [
            'all' => __('Every member (:count)', ['count' => $count('all')]),
            'verified' => __('Members with a confirmed email (:count)', ['count' => $count('verified')]),
            'unverified' => __('Members who did not confirm their email (:count)', ['count' => $count('unverified')]),
            'premium' => __('Premium members (:count)', ['count' => $count('premium')]),
            'specific' => __('Chosen members'),
        ];
        // a notification sent from here: its title and message as written; the site's own: its sentence, translated
        $summary = fn (object $notification): array => NotificationText::summary($notification->type, $notification->data);
        $total = $stats['total_capped']
            ? __('More than :count notifications were sent.', ['count' => number_format($stats['total'])])
            : trans_choice('1 notification was sent.|:count notifications were sent.', $stats['total'], ['count' => number_format($stats['total'])]);
        $unread = $stats['unread'] <= 0 ? '' : ($stats['unread_capped']
            ? __('More than :count are not read yet.', ['count' => number_format($stats['unread'])])
            : trans_choice('1 is not read yet.|:count are not read yet.', $stats['unread'], ['count' => number_format($stats['unread'])]));
        $anySent = $stats['total'] > 0 || $stats['total_capped'];
    @endphp

    <x-admin.page-header page="community.notify" :description="trim($total.' '.$unread)">
        <x-slot:actions>
            @if ($anySent)
                <form method="POST" action="{{ route('admin.notifications.clear') }}"
                      data-ad-confirm="{{ __('Delete every notification?') }}" data-ad-confirm-body="{{ __("They disappear from every member's bell. This cannot be undone.") }}" data-ad-confirm-label="{{ __('Delete all') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete all...') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <details class="ad-panel ad-console-block ad-fold" id="new-notification" @if (! $anySent || $errors->any() || request()->boolean('new')) open @endif>
        <summary class="ad-panel-h"><h2>{{ __('New notification') }}</h2></summary>
        <form method="POST" action="{{ route('admin.notifications.broadcast') }}" class="ad-panel-b ad-record-fields ad-notify-form"
              data-ad-confirm="{{ __('Send this notification?') }}" data-ad-confirm-body="{{ __('It appears at once in the bell of every member you picked.') }}" data-ad-confirm-label="{{ __('Send') }}" data-ad-confirm-tone="primary">
            @csrf
            <x-admin.field name="target" :label="__('Send to')">
                <select class="ad-select" id="target" name="target">
                    @foreach ($targets as $value => $label)
                        <option value="{{ $value }}" @selected(old('target', 'all') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </x-admin.field>
            {{-- shown only while "Chosen members" is picked (resources/admin/components/_list.scss, .ad-notify-form); browsers without :has() show it with its help.
                 The numbers run left to right in every language (dir="ltr": an Arabic page showed "1, 2, 5" as "5 ,2 ,1"); the
                 Arabic comma and digits are read too (NotificationController::memberNumbers()). --}}
            <x-admin.field name="user_ids" class="ad-notify-chosen" :label="__('Member numbers')" :help="__('Only for Chosen members: their numbers, separated by commas, for example 1, 2, 5.')">
                <input class="ad-input" id="user_ids" name="user_ids" value="{{ old('user_ids') }}" placeholder="1, 2, 5" dir="ltr" spellcheck="false" autocomplete="off" aria-describedby="user_ids-help{{ $errors->has('user_ids') ? ' user_ids-error' : '' }}" @if ($errors->has('user_ids')) aria-invalid="true" @endif>
            </x-admin.field>
            <x-admin.field name="title" :label="__('Title')">
                <input class="ad-input" id="title" name="title" value="{{ old('title') }}" maxlength="255" required>
            </x-admin.field>
            <x-admin.field name="message" :label="__('Message')">
                <textarea class="ad-textarea" id="message" name="message" rows="5" maxlength="5000" required>{{ old('message') }}</textarea>
            </x-admin.field>
            <p class="ad-help">{{ __('Old notifications are tidied up by themselves: read ones after :days days, and each member keeps their newest :keep. Unread ones from the last :unread days always stay.', ['days' => NotificationRetention::READ_DAYS, 'keep' => number_format(NotificationRetention::KEEP_PER_MEMBER), 'unread' => NotificationRetention::KEEP_UNREAD_DAYS]) }}</p>
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
                @if ((int) request()->query('per_page', 0) > 0)
                    <input type="hidden" name="per_page" value="{{ (int) request()->query('per_page') }}">
                @endif
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
                <td class="is-date" title="{{ $sent->translatedFormat('j M Y, H:i') }}">{{ $sent->diffForHumans(short: \App\Support\AdminText::shortTimes()) }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act {{ $key }}" data-id="{{ $notification->id }}" data-state="{{ $notification->read_at ? 'read' : 'new' }}" @if ($notification->username !== null) data-user="{{ $notification->notifiable_id }}" @endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>

    {{-- The pager: newer / older by key, the newest first (quick on any page, however many rows there are). --}}
    @if ($notifications->hasPages() || $notifications->count() > 0)
        @php
            $pageName = $notifications->getCursorName();
            $choices = collect([25, 50, 100, $perPage])->map(fn ($n) => (int) $n)->filter(fn ($n) => $n > 0)->unique()->sort()->values();
        @endphp
        <div class="ad-pager">
            <span>{{ __('Newest first') }}</span>
            @if ($notifications->hasPages() || $perPage !== 25)
                <form method="GET" action="{{ url()->current() }}" class="ad-pager-size">
                    @if ($unreadOnly)<input type="hidden" name="show" value="unread">@endif
                    <label class="ad-vh" for="ad-per-page-notifications">{{ __('Rows per page') }}</label>
                    <select class="ad-select" id="ad-per-page-notifications" name="per_page" data-ad-autosubmit>
                        @foreach ($choices as $option)
                            <option value="{{ $option }}" @selected($option === $perPage)>{{ __(':count per page', ['count' => $option]) }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="ad-btn sm">{{ __('Show') }}</button></noscript>
                </form>
            @endif
            @if ($notifications->hasPages())
                <nav class="ad-pages" aria-label="{{ __('Pages') }}">
                    @if ($notifications->onFirstPage())
                        <span class="is-disabled" aria-hidden="true">{{ AdminTheme::icon('chev-left', 'flip-rtl') }}</span>
                    @else
                        <a href="{{ \App\Support\CleanUrls::requestForm(request()->fullUrlWithoutQuery([$pageName])) }}">{{ __('Newest') }}</a>
                        <a href="{{ $notifications->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous page') }}">{{ AdminTheme::icon('chev-left', 'flip-rtl') }}</a>
                    @endif
                    @if ($notifications->hasMorePages())
                        <a href="{{ $notifications->nextPageUrl() }}" rel="next" aria-label="{{ __('Next page') }}">{{ AdminTheme::icon('chev-right', 'flip-rtl') }}</a>
                    @else
                        <span class="is-disabled" aria-hidden="true">{{ AdminTheme::icon('chev-right', 'flip-rtl') }}</span>
                    @endif
                </nav>
            @endif
        </div>
    @endif
    </x-admin.list-page>
@endsection
