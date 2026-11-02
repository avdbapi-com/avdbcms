{{-- The account panel (avatar menu, member design 6.13): a side panel at the one panel width (420px), full height on
     phones (account-53), the same on public and member pages (the member CSS is on every page of a signed-in visitor).
     Head: avatar, name, @handle + the account badge, "View your channel". Groups Studio / Library / Account,
     then Language (a native select) and Theme (Light / Dark / Device, three equal segments) on two full-width rows, then
     Sign out: a POST form (auth-22); while an administrator is signed in as this member, "Leave" replaces it.
     Calm hover (4.1a, _chrome.scss). "Upload video" is the server-rendered trigger of the upload window (5.14): the panel
     closes first, then the window opens. --}}
@php
    $accountUser = auth()->user();
    $currentLocale = App::currentLocale();
    $showAccountLocale = config('site.allow_language_switch', true) && config('site.sidebar_locale_switcher_enabled', true) && count((array) config('languages', [])) > 1;
    $showAccountTheme = config('site.sidebar_theme_switcher_enabled', true);
    $accountUploads = config('site.allow_uploads', true);
    $accountCanSell = class_exists(\App\Support\PremiumAvailability::class) && method_exists(\App\Support\PremiumAvailability::class, 'canSell')
        ? (bool) \App\Support\PremiumAvailability::canSell()
        : (bool) config('site.premium_enabled', false);
    $accountSubscription = $accountUser->premium_subscription;
    $accountRoute = request()->route()?->getName();
    // Counts of the menu (one small query per member and minute: the panel is on every page).
    $accountCounts = \Illuminate\Support\Facades\Cache::remember('account-panel:counts:'.$accountUser->getKey(), 60, function () use ($accountUser): array {
        try {
            $watchLater = (int) \Illuminate\Support\Facades\DB::table('playlist_has_videos')
                ->join('playlists', 'playlists.id', '=', 'playlist_has_videos.playlist_id')
                ->where('playlists.user_id', $accountUser->getKey())
                ->where('playlists.title', \App\Playlists\Types\WatchLaterPlaylist::getName())
                ->where('playlists.is_deletable', false)
                ->count();
        } catch (\Throwable) {
            $watchLater = 0;
        }

        return ['videos' => (int) $accountUser->videos()->count(), 'watch_later' => $watchLater];
    });
    // The unread count of "Notifications" (6.13): the header's own cached count (one cache read; HeaderComposer only
    // binds it to the header's view), shown while the owner keeps the header's notifications on, like the bell.
    $accountUnread = config('site.header_notifications_enabled', true)
        ? \App\Notifications\Support\Inbox::cachedUnreadCount($accountUser)
        : 0;
    $accountBadge = fn (int $count): string => $count > 99 ? '99+' : (string) $count;
    $accountLink = fn (string $name, array $parameters = []): string => locale_url(route($name, $parameters));
    $accountCurrent = fn (string ...$names): bool => is_string($accountRoute) && \Illuminate\Support\Str::is($names, $accountRoute);
@endphp

<div class="offcanvas offcanvas-end mk-account mk-drawer avd-mk" tabindex="-1" id="account" aria-labelledby="account-title">
    <div class="mk-account__head">
        <img class="mk-avatar mk-avatar--48" src="{{ $accountUser->avatar_url }}" alt="" width="48" height="48" decoding="async">
        <div class="mk-account__who">
            <div class="mk-account__name" id="account-title">{{ $accountUser->username }}</div>
            <div class="mk-account__handle">
                @if(filled($accountUser->slug))
                    <span>{{ '@'.$accountUser->slug }}</span>
                @endif
                <x-account-badge :user="$accountUser" class="mk-account__badge" />
            </div>
            <a class="mk-account__channel" href="{{ locale_url($accountUser->route) }}">{{ __('View your channel') }}</a>
        </div>
        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="offcanvas" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>

    <nav class="mk-account__body" aria-label="{{ __('Account') }}">
        <div class="mk-account__group">{{ __('Studio') }}</div>
        <a class="mk-account__link" href="{{ $accountLink('user.index') }}" @if($accountCurrent('user.index')) aria-current="page" @endif><i class="fa-solid fa-chart-line" aria-hidden="true"></i><span>{{ __('Dashboard') }}</span></a>
        <a class="mk-account__link" href="{{ $accountLink('user.videos.index') }}" @if($accountCurrent('user.videos.*')) aria-current="page" @endif><i class="fa-solid fa-clapperboard" aria-hidden="true"></i><span>{{ __('My videos') }}</span>@if($accountCounts['videos'] > 0)<span class="mk-count">{{ $accountBadge($accountCounts['videos']) }}</span>@endif</a>
        @if($accountUploads)
            {{-- The panel is hidden while the window is open, so Bootstrap cannot give the focus back to this button:
                 when the window closes, the header's account button (which opened the panel) takes it. --}}
            <button type="button" class="mk-account__link" data-bs-toggle="modal" data-bs-target="#video_create"
                    @click="window.bootstrap && window.bootstrap.Offcanvas.getInstance($el.closest('.offcanvas')) && window.bootstrap.Offcanvas.getInstance($el.closest('.offcanvas')).hide(); const avdUpload = document.getElementById('video_create'); if (avdUpload) avdUpload.addEventListener('hidden.bs.modal', () => { const avdBack = document.querySelector('.avd-avatar-button'); if (avdBack && avdBack.getClientRects().length) avdBack.focus(); }, { once: true })"
                    x-data><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i><span>{{ __('Upload video') }}</span></button>
        @endif

        <div class="mk-account__group">{{ __('Library') }}</div>
        {{-- The library page (/library) itself: before, only the phone bottom bar led there. --}}
        <a class="mk-account__link" href="{{ $accountLink('pages.library') }}" @if($accountCurrent('pages.library')) aria-current="page" @endif><i class="fa-solid fa-folder-open" aria-hidden="true"></i><span>{{ __('Library') }}</span></a>
        @if(config('site.show_history', true))
            <a class="mk-account__link" href="{{ $accountLink('pages.history') }}" @if($accountCurrent('pages.history')) aria-current="page" @endif><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i><span>{{ __('History') }}</span></a>
        @endif
        <a class="mk-account__link" href="{{ $accountLink('custom-playlist.show', ['type' => 'watch-later']) }}" @if(request()->routeIs('custom-playlist.show') && request()->route('type') === 'watch-later') aria-current="page" @endif><i class="fa-regular fa-clock" aria-hidden="true"></i><span>{{ __('Watch later') }}</span>@if($accountCounts['watch_later'] > 0)<span class="mk-count">{{ $accountBadge($accountCounts['watch_later']) }}</span>@endif</a>
        <a class="mk-account__link" href="{{ $accountLink('custom-playlist.show', ['type' => 'liked-videos']) }}" @if(request()->routeIs('custom-playlist.show') && request()->route('type') === 'liked-videos') aria-current="page" @endif><i class="fa-solid fa-thumbs-up" aria-hidden="true"></i><span>{{ __('Liked videos') }}</span></a>
        <a class="mk-account__link" href="{{ $accountLink('playlist.manage') }}" @if($accountCurrent('playlist.manage')) aria-current="page" @endif><i class="fa-solid fa-rectangle-list" aria-hidden="true"></i><span>{{ __('Playlists') }}</span></a>
        <a class="mk-account__link" href="{{ $accountLink('subscription.index') }}" @if($accountCurrent('subscription.index', 'subscription.manage')) aria-current="page" @endif><i class="fa-solid fa-user-check" aria-hidden="true"></i><span>{{ __('Subscriptions') }}</span></a>

        <div class="mk-account__group">{{ __('Account') }}</div>
        <a class="mk-account__link" href="{{ $accountLink('user.edit') }}" @if($accountCurrent('user.edit')) aria-current="page" @endif><i class="fa-solid fa-user-gear" aria-hidden="true"></i><span>{{ __('Account settings') }}</span></a>
        <a class="mk-account__link" href="{{ $accountLink('user.notifications.index') }}" @if($accountCurrent('user.notifications.*')) aria-current="page" @endif><i class="fa-regular fa-bell" aria-hidden="true"></i><span>{{ __('Notifications') }}</span>@if($accountUnread > 0)<span class="mk-count" aria-label="{{ trans_choice('{1} :count new|[2,*] :count new', $accountUnread, ['count' => $accountBadge($accountUnread)]) }}">{{ $accountBadge($accountUnread) }}</span>@endif</a>
        @if($accountSubscription)
            <a class="mk-account__link" href="{{ $accountLink('user.invoices.index') }}" @if($accountCurrent('user.invoices.*')) aria-current="page" @endif><i class="fa-solid fa-file-invoice" aria-hidden="true"></i><span>{{ __('Invoices') }}</span></a>
        @elseif($accountCanSell && ! $accountUser->is_premium)
            <a class="mk-account__link" href="{{ $accountLink('pages.premium') }}" @if($accountCurrent('pages.premium')) aria-current="page" @endif><i class="fa-regular fa-star" aria-hidden="true"></i><span>{{ __('Premium') }}</span></a>
        @endif
        @if($accountUser->is_admin)
            <a class="mk-account__link" href="{{ $accountLink('admin.index') }}"><i class="fa-solid fa-toolbox" aria-hidden="true"></i><span>{{ __('Admin') }}</span></a>
        @endif
    </nav>

    <div class="mk-account__foot">
        @if($showAccountLocale || $showAccountTheme)
            <div class="mk-account__prefs">
                @if($showAccountLocale)
                    {{-- A POST with the token: it also saves the member's language (that of their e-mails), which a GET
                         never does (a link on another site could, R1B-025). data-avd-apply: sent on an explicit choice
                         only, never on the first arrow key (R2A-003; components/layout.blade.php). --}}
                    <form class="mk-account__pref" method="POST" action="{{ route('lang.update') }}">
                        @csrf
                        <label class="mk-label" for="account-language">{{ __('Language') }}</label>
                        <input type="hidden" name="redirect" value="{{ request()->getRequestUri() }}">
                        <select class="form-select" id="account-language" name="locale" data-native-select data-avd-apply>
                            @foreach(config('languages') as $lang => $info)
                                <option value="{{ $lang }}" @selected($currentLocale === $lang) lang="{{ $lang }}">{{ $info['label'] ?? Str::upper($lang) }}</option>
                            @endforeach
                        </select>
                        <noscript><button type="submit" class="mk-btn mk-btn--secondary mk-btn--sm">{{ __('Change language') }}</button></noscript>
                    </form>
                @endif
                @if($showAccountTheme)
                    {{-- Light / Dark keep the choice on this device (like the site's other theme switches, which follow
                         it at once: the "theme-changed" event); Device follows the device's setting. The event carries
                         the choice (mode: light, dark or device), so this switch and the Theme of Account settings
                         always show the same one (R1-029). --}}
                    <div class="mk-account__pref"
                         x-data="{
                             pref: 'device',
                             read() {
                                 const root = document.documentElement;
                                 try {
                                     const context = root.dataset.avdThemePreset + ':' + root.dataset.avdThemeDefault;
                                     const stored = localStorage.getItem('avd_manual_theme');
                                     if (localStorage.getItem('avd_manual_theme_context') === context && ['light', 'dark', 'device'].includes(stored)) {
                                         return stored;
                                     }
                                 } catch (e) {}
                                 return root.dataset.avdThemeDefault === 'auto' ? 'device' : (root.getAttribute('data-bs-theme') || 'dark');
                             },
                             choose(value) {
                                 const root = document.documentElement;
                                 const remember = !(window.AVD_SETTINGS && window.AVD_SETTINGS.themeMemory === false);
                                 const theme = value === 'device'
                                     ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                                     : value;
                                 if (remember) {
                                     try {
                                         localStorage.setItem('avd_manual_theme', value);
                                         localStorage.setItem('avd_manual_theme_context', root.dataset.avdThemePreset + ':' + root.dataset.avdThemeDefault);
                                     } catch (e) {}
                                 }
                                 this.pref = value;
                                 root.setAttribute('data-bs-theme', theme);
                                 window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme: theme, mode: value, id: 'account-theme-seg' } }));
                             }
                         }"
                         x-init="pref = read(); if (pref === 'device' && window.matchMedia) { const wanted = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; if (document.documentElement.getAttribute('data-bs-theme') !== wanted) { document.documentElement.setAttribute('data-bs-theme', wanted); window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme: wanted, mode: 'device', id: 'account-theme-seg' } })); } }"
                         @theme-changed.window="const d = $event.detail; if (d && d.id !== 'account-theme-seg') { if (d.mode === 'light' || d.mode === 'dark' || d.mode === 'device') { pref = d.mode; } else if ((d.theme === 'light' || d.theme === 'dark') && d.theme !== document.documentElement.getAttribute('data-bs-theme')) { pref = d.theme; } }">
                        <span class="mk-label" id="account-theme-label">{{ __('Theme') }}</span>
                        <div class="mk-seg mk-seg--block" role="radiogroup" aria-labelledby="account-theme-label">
                            <label class="mk-seg__item"><input type="radio" name="account_theme" value="light" :checked="pref === 'light'" @change="choose('light')"><span class="mk-seg__label">{{ __('Light') }}</span></label>
                            <label class="mk-seg__item"><input type="radio" name="account_theme" value="dark" :checked="pref === 'dark'" @change="choose('dark')"><span class="mk-seg__label">{{ __('Dark') }}</span></label>
                            <label class="mk-seg__item"><input type="radio" name="account_theme" value="device" :checked="pref === 'device'" @change="choose('device')"><span class="mk-seg__label">{{ __('Device') }}</span></label>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @impersonating($guard = null)
            <a class="mk-btn mk-btn--secondary mk-btn--block" href="{{ locale_url(route('admin.impersonate.leave')) }}">
                <i class="fa-solid fa-right-from-bracket mk-flip-rtl" aria-hidden="true"></i><span>{{ __('Leave') }}</span>
            </a>
        @else
            <form method="POST" action="{{ locale_url(route('logout')) }}" class="mk-account__signout">
                @csrf
                <button class="mk-btn mk-btn--secondary mk-btn--block" type="submit">
                    <i class="fa-solid fa-right-from-bracket mk-flip-rtl" aria-hidden="true"></i><span>{{ __('Sign out') }}</span>
                </button>
            </form>
        @endImpersonating
    </div>
</div>
