{{--
    Account menu of the top bar: profile, the admin's own look (system/light/dark, admin colour), advanced
    pages, Developer mode, the old admin for one release, view site, log out. Every choice is per admin
    (App\Support\AdminPreferences) and saves through admin.shell.preferences. Before "Finish update" has added
    the preferences column the choices are shown switched off, with a note.
    Props: user, prefs (AdminPreferences::all()).
--}}
@use('App\Support\AdminTheme')
@props(['user' => null, 'prefs' => []])
@php
    $user ??= auth()->user();
    $prefs = $prefs ?: \App\Support\AdminPreferences::all($user);
    $canSave = \App\Support\AdminPreferences::available($user);
    $name = (string) ($user?->username ?? $user?->name ?? '');
    $initials = strtoupper(mb_substr(preg_replace('/[^\pL\pN]/u', '', $name) ?: 'A', 0, 2));
    $prefsUrl = route('admin.shell.preferences');
    $accentModes = [
        'auto' => __('Automatic'),
        'site' => __('My site colour'),
        'neutral' => __('Neutral blue'),
        'ink' => __('Black and white'),
    ];
@endphp
<div class="ad-popwrap">
    <button type="button" class="ad-iconbtn" data-ad-menu aria-controls="ad-account-menu" aria-expanded="false" aria-haspopup="true">
        <span class="ad-avatar" aria-hidden="true">{{ $initials }}</span>
        <span class="ad-vh">{{ __('Account menu') }}</span>
    </button>
    <div class="ad-menu ad-account-menu" id="ad-account-menu" hidden data-prefs-url="{{ $prefsUrl }}">
        <div class="ad-menu-h">
            <b>{{ $name }}</b>
            <span>{{ __('Administrator') }}</span>
        </div>
        <hr>
        <a href="{{ locale_url(route('user.edit')) }}">{{ AdminTheme::icon('user') }}{{ __('Your profile') }}</a>

        @unless ($canSave)
            <p class="ad-menu-note">{{ __('These choices can be saved after the update is finished. Open Home and click Finish update.') }}</p>
        @endunless
        <div class="ad-menu-label" id="ad-pref-theme-label">{{ __('Admin appearance') }}</div>
        <div class="ad-seg" role="radiogroup" aria-labelledby="ad-pref-theme-label">
            @foreach (['system' => [__('System'), 'monitor'], 'light' => [__('Light'), 'sun'], 'dark' => [__('Dark'), 'moon']] as $value => [$label, $symbol])
                <label>
                    <input type="radio" name="admin_theme" value="{{ $value }}" @checked($prefs['admin_theme'] === $value) @disabled(! $canSave)>
                    <span>{{ AdminTheme::icon($symbol, 'sm') }}{{ $label }}</span>
                </label>
            @endforeach
        </div>

        <label class="ad-menu-label" for="ad-pref-accent">{{ __('Admin colour') }}</label>
        <div class="px-2 pb-2">
            <select id="ad-pref-accent" class="ad-select" data-ad-pref="accent_mode" @disabled(! $canSave)>
                @foreach ($accentModes as $value => $label)
                    <option value="{{ $value }}" @selected($prefs['accent_mode'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <label class="ad-toggle-row">
            <span class="ad-toggle-text"><b>{{ __('Show advanced pages') }}</b></span>
            <span class="ad-switch"><input type="checkbox" role="switch" data-ad-pref="show_advanced" @checked($prefs['show_advanced']) @disabled(! $canSave)><i></i></span>
        </label>
        <label class="ad-toggle-row">
            <span class="ad-toggle-text">
                <b>{{ __('Developer mode') }}</b>
                <span class="ad-help">{{ __('Shows custom code, the database and the settings file tools.') }}</span>
            </span>
            <span class="ad-switch"><input type="checkbox" role="switch" data-ad-pref="developer_mode" @checked($prefs['developer_mode']) @disabled(! $canSave)><i></i></span>
        </label>
        <hr>
        <a href="{{ locale_url(route('pages.home')) }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('View site') }}</a>
        <form method="POST" action="{{ $prefsUrl }}">
            @csrf
            <input type="hidden" name="key" value="admin_ui">
            <input type="hidden" name="value" value="v1">
            <button type="submit" @disabled(! $canSave)>{{ AdminTheme::icon('undo', 'flip-rtl') }}{{ __('Use the old admin for now') }}</button>
        </form>
        <a href="{{ locale_url(route('logout')) }}">{{ AdminTheme::icon('logout', 'flip-rtl') }}{{ __('Log out') }}</a>
    </div>
</div>
