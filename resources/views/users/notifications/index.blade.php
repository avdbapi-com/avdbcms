@extends('layouts.user')

@section('title', __('Notifications'))

{{-- The notifications page /profile/notifications (member design 6.14; mockup notifications-page): in the studio
     menu under Activity and in the account panel. Tabs All / Unread, the rows grouped Today / Yesterday / This week /
     Earlier, newest first, a page at a time; a row = the link (avatar or type icon with a small type badge, the sentence
     with the names and titles in bold, the time, the video's picture) + a sibling "..." menu (Mark as read / unread,
     Delete), which calls the bell's own POST / DELETE (account-40) and updates the row in place. A refusal (the view-only
     demo, a lost session) is said in an alert at the top with the server's own words.

     The page-content budget (4.3): a page of 20 rows (the site's default page
     size, per_page_comments, which Inbox::perPage() uses) stays within the 4.3 row of the closest list page, "Comments,
     20": 24 KB raw / 4 KB gzip / 350 nodes. So:
     - the row menu is printed ONCE: a "..." button's click or key moves it next to that button (place(), a capture
       listener on the window) before Bootstrap's own handler (a capture listener on the document) opens it there, so the
       open menu is the row's, in the row's reading order; it goes back to the end of the list before its row is deleted;
     - a row's addresses are written from the site's root path, a row with a link carries its id once (in the link's
       address: id() reads it there; a row without a link in data-avd-notification), the "..." button takes the studio
       lists' "More actions", and the screen-reader "Unread" is printed only on unread rows (mark() adds or removes it);
     - the page's behaviour is one Alpine component, avdNotifyPage, in the layout's scripts stack (as the studio lists'
       users/partials/studio-scripts; 4.3 counts inline scripts in the layout part): go() is the one request,
       mark() a row's read state, tap() a menu item, place() the menu, act() an action, readAll() the head button. --}}
@php
    $badge = fn (int $count, int $cap = 99): string => $count > $cap ? $cap.'+' : (string) $count;
    $apiRead = route('notifications.read', ['notification' => '__ID__'], false);
    $apiUnread = route('notifications.unread', ['notification' => '__ID__'], false);
    $apiDelete = route('notifications.remove', ['notification' => '__ID__'], false);
    $apiReadAll = route('notifications.read-all', [], false);
    $settingsUrl = locale_url(route('user.edit', ['section' => 'notifications'])).'#notification-settings';
    $tabUrl = fn (string $show): string => locale_url(route('user.notifications.index', $show === 'unread' ? ['show' => 'unread'] : []));
    $kindIcons = [
        'video' => 'fa-circle-play', 'comment' => 'fa-comment', 'subscriber' => 'fa-user-plus', 'ready' => 'fa-circle-check',
        'failed' => 'fa-triangle-exclamation', 'suspended' => 'fa-ban', 'export' => 'fa-file-arrow-down',
        'invoice' => 'fa-file-invoice-dollar', 'message' => 'fa-bullhorn',
    ];
    $kindBadge = ['video' => 'video', 'ready' => 'video', 'comment' => 'comment', 'subscriber' => '', 'failed' => 'video', 'suspended' => 'video'];
    $rowCount = 0;
    foreach ($groups as $group) {
        $rowCount += count($group['rows']);
    }
    $page->withPath(locale_url(route('user.notifications.index')));
    if ($tab === 'unread') {
        $page->appends(['show' => 'unread']);
    }
    // A row's addresses from the domain's root: route(..., false) holds the site's folder and "/index.php" where the
    // links carry it (App\Support\SiteUrlGenerator), and an address on this site's own origin loses only the origin.
    $rowOrigin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', url('/'));
@endphp

@section('content')
<div class="avd-mk mk-page mk-page--narrow" x-data="avdNotifyPage({{ (int) $unread }})">
<x-mk.head :title="__('Notifications')" :kicker="__('Activity')" icon="bell">
<x-slot:below>
<p class="mk-head__desc"><span x-show="unread > 0" @if((int) $unread === 0) style="display:none" @endif>{!! __(':count new.', ['count' => '<span x-text="unread > 99 ? &quot;99+&quot; : unread">'.e($badge((int) $unread)).'</span>']) !!}</span> {{ __('Read notifications are kept for :days days.', ['days' => $keptDays]) }}</p>
</x-slot:below>
<x-slot:actions>
<button type="button" class="mk-btn mk-btn--secondary" @click="readAll()" :disabled="busy || unread === 0" @if((int) $unread === 0) disabled @endif><i class="fa-solid fa-check-double" aria-hidden="true"></i><span>{{ __('Mark all as read') }}</span></button>
<a class="mk-btn mk-btn--quiet" href="{{ $settingsUrl }}"><i class="fa-solid fa-gear" aria-hidden="true"></i><span>{{ __('Settings') }}</span></a>
</x-slot:actions>
</x-mk.head>
<x-mk.flash />
<div class="mk-alert mk-alert--danger" role="alert" tabindex="-1" x-ref="problem" x-show="problem !== ''" style="display:none">
<i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
<div class="mk-alert__body"><span x-text="problem"></span></div>
<button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--sm" aria-label="{{ __('Close') }}" title="{{ __('Close') }}" @click="problem = ''"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
</div>
<section class="mk-panel mk-notify-page" aria-labelledby="notify-page-tabs">
<nav class="mk-tabs mk-notify-page__tabs" id="notify-page-tabs" aria-label="{{ __('Show') }}">
<a @class(['mk-tab', 'is-active' => $tab === 'all']) href="{{ $tabUrl('all') }}" @if($tab === 'all') aria-current="page" @endif>{{ __('All') }}<span class="mk-count">{{ $badge((int) $total, 999) }}</span></a>
<a @class(['mk-tab', 'is-active' => $tab === 'unread']) href="{{ $tabUrl('unread') }}" @if($tab === 'unread') aria-current="page" @endif>{{ __('Unread') }}<span class="mk-count" x-text="unread > 99 ? '99+' : unread">{{ $badge((int) $unread) }}</span></a>
</nav>
@if($rowCount === 0 && ! (method_exists($page, 'isPartial') && $page->isPartial()))
<x-mk.empty icon="bell" :title="$tab === 'unread' ? __('Nothing unread') : __('No notifications yet')" :text="$tab === 'unread' ? __('You have read everything. New notifications show here.') : __('New subscribers, comments on your videos and new videos from channels you follow show here.')" />
@elseif($rowCount === 0)
{{-- An Unread page that its time budget ended before it found a row (before the Unread index exists, a long run of
     read notifications), reached by Next (older rows) or by Previous (newer rows): the line names no direction, and the
     pager goes on from where it stopped either way (UnreadPaginator). --}}
<div class="mk-endline"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>{{ __('More unread notifications may follow.') }}</div>
<div class="mk-notify-page__pager">{{ $page->links('vendor.pagination.mk') }}</div>
@else
@php
    // the row partial's words, translated once for the page (not once per row)
    $rowMenuLabel = __('More actions');
    $rowUnread = __('Unread');
@endphp
{{-- One handler for every row: a "..." button takes the one menu (place(), on the window in the capture phase: Bootstrap's
     own dropdown handler is a capture listener on the document, and the menu must sit next to the button before it
     creates the dropdown); a menu item's data-act names the action (tap(), on the list). --}}
<div class="mk-notify-list" @click="tap($event)" @click.window.capture="place($event)" @keydown.window.capture="place($event)">
@foreach($groups as $group)
<div class="mk-notify__block" data-avd-group="{{ $group['key'] }}">
<div class="mk-notify__group">{{ $group['title'] }}</div>
@foreach($group['rows'] as $row)
@include('users.notifications.row', compact('row', 'kindIcons', 'kindBadge', 'rowMenuLabel', 'rowUnread', 'rowOrigin'))
@endforeach
</div>
@endforeach
<div class="dropdown-menu dropdown-menu-end mk-menu" role="menu" data-avd-row-menu>
<button type="button" class="dropdown-item mk-menu__item" role="menuitem" data-act="read"><i class="fa-solid fa-check" aria-hidden="true"></i>{{ __('Mark as read') }}</button>
<button type="button" class="dropdown-item mk-menu__item" role="menuitem" data-act="unread" hidden><i class="fa-regular fa-envelope" aria-hidden="true"></i>{{ __('Mark as unread') }}</button>
<hr class="dropdown-divider">
<button type="button" class="dropdown-item mk-menu__item mk-menu__item--danger" role="menuitem" data-act="delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i>{{ __('Delete') }}</button>
</div>
</div>
@if($page->hasMorePages() || ! $page->onFirstPage())
<div class="mk-notify-page__pager">{{ $page->links('vendor.pagination.mk') }}</div>
@endif
@if(! $page->hasMorePages())
<div class="mk-endline"><i class="fa-solid fa-check" aria-hidden="true"></i>{{ __('You are all caught up') }}</div>
@endif
@endif
</section>
</div>
@endsection

@push('scripts')
{{-- The page's behaviour (one Alpine component, registered before Alpine starts: app.ts is a deferred module). --}}
<script>
    document.addEventListener('alpine:init', function () {
        window.Alpine.data('avdNotifyPage', function (unread) {
            return {
                unread: unread,
                problem: '',
                busy: false,
                urls: @js(['read' => $apiRead, 'unread' => $apiUnread, 'delete' => $apiDelete, 'all' => $apiReadAll]),
                // the one request: the CSRF token (the addresses are paths from the domain's root: the folder of a site
                // installed in one and /index.php where the site's links carry it are in them), the server's own refusal
                // (the view-only demo, a lost session) or one translated sentence
                async go(url, method, done) {
                    if (this.busy) { return; }
                    this.busy = true;
                    this.problem = '';
                    try {
                        const token = document.querySelector('meta[name=csrf-token]');
                        const response = await fetch(url, {
                            method: method,
                            headers: {Accept: 'application/json', 'X-CSRF-TOKEN': token ? token.content : '', 'X-Locale': window.LANG || 'en'},
                        });
                        let data = null;
                        try { data = await response.json(); } catch (e) {}
                        if (!response.ok) { throw {said: data && data.message}; }
                        if (data && typeof data.unread === 'number') { this.unread = data.unread; }
                        done();
                    } catch (e) {
                        this.problem = e && e.said ? e.said : @js(__('Something went wrong. Try again.'));
                        this.$nextTick(() => this.$refs.problem.focus());
                    } finally {
                        this.busy = false;
                    }
                },
                drop(button) { return button && window.bootstrap ? window.bootstrap.Dropdown.getInstance(button) : null; },
                // a row with a link carries its id in the link's address (/profile/notifications/<id>/click)
                id(row) {
                    const link = row.querySelector('a.mk-notif__link');
                    return row.dataset.avdNotification || (link ? link.pathname.split('/').slice(-2, -1)[0] : '');
                },
                // a row's read state, with its screen-reader word
                mark(row, read) {
                    row.classList.toggle('is-unread', !read);
                    const time = row.querySelector('time');
                    const said = time.nextElementSibling;
                    if (read && said) { said.remove(); }
                    if (!read && !said) {
                        const word = document.createElement('span');
                        word.className = 'mk-sr';
                        word.textContent = ', ' + @js(__('Unread'));
                        time.after(word);
                    }
                },
                // a click on an item of the row menu (on the list)
                tap(event) {
                    const item = event.target.closest('[data-avd-row-menu] [data-act]');
                    if (item) { this.act(item.closest('.mk-notif'), item.dataset.act); }
                },
                // a click or a key on a "..." (on the window, in the capture phase: before Bootstrap's own handler, a
                // capture listener on the document, creates the dropdown): the one menu moves next to that button
                place(event) {
                    const button = event.target.closest ? event.target.closest('.mk-notify-list .mk-notif__more') : null;
                    const menu = document.querySelector('[data-avd-row-menu]');
                    if (!button || !menu) { return; }
                    const unread = button.closest('.mk-notif').classList.contains('is-unread');
                    if (menu.previousElementSibling !== button) {
                        const open = this.drop(menu.previousElementSibling);
                        if (open && menu.classList.contains('show')) { open.hide(); }
                        button.after(menu);
                    }
                    menu.querySelector('[data-act=read]').hidden = !unread;
                    menu.querySelector('[data-act=unread]').hidden = unread;
                },
                act(row, action) {
                    const id = encodeURIComponent(this.id(row));
                    if (action !== 'delete') {
                        this.go(this.urls[action].replace('__ID__', id), 'POST', () => this.mark(row, action === 'read'));
                        return;
                    }
                    this.go(this.urls.delete.replace('__ID__', id), 'DELETE', () => {
                        const list = row.closest('.mk-notify-list');
                        const group = row.closest('[data-avd-group]');
                        const menu = row.querySelector('[data-avd-row-menu]');
                        const open = this.drop(row.querySelector('.mk-notif__more'));
                        const rows = [...list.querySelectorAll('.mk-notif')];
                        const next = rows[rows.indexOf(row) + 1] || rows[rows.indexOf(row) - 1];
                        if (open) { open.dispose(); }
                        // the menu goes back to the end of the list before its row goes
                        if (menu) { menu.classList.remove('show'); list.append(menu); }
                        row.remove();
                        if (!group.querySelector('.mk-notif')) { group.remove(); }
                        if (next) { next.querySelector('.mk-notif__more').focus(); }
                    });
                },
                readAll() {
                    this.go(this.urls.all, 'POST', () => {
                        this.unread = 0;
                        document.querySelectorAll('.mk-notify-list .is-unread').forEach((row) => this.mark(row, true));
                    });
                },
            };
        });
    });
</script>
@endpush
