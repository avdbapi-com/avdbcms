@php
    $currentLocale = App::currentLocale();
    $currentLanguage = config("languages.$currentLocale", ['label' => Str::upper($currentLocale)]);
    $showAccountLocale = config('site.allow_language_switch', true) && config('site.sidebar_locale_switcher_enabled', true);
    $showAccountTheme = config('site.sidebar_theme_switcher_enabled', true);
@endphp

<div class="offcanvas offcanvas-end" tabindex="-1" id="account" aria-labelledby="account-title" style="width: 350px">
    <div class="offcanvas-header bg-body-tertiary border-bottom d-flex justify-content-between align-items-start py-3">
        <div class="d-flex gap-3 align-items-start">
            <img width="45" height="45" class="rounded-circle border" src="{{auth()->user()->avatar_url}}" alt="{{auth()->user()->username}} avatar">
            <div>
                <div id="account-title" class="text-body fw-semibold fs-5 text-wrap">{{auth()->user()->username}}</div>
                @if(auth()->user()->is_premium)
                    <div class="badge bg-warning">
                        <i class="fa-solid fa-star" aria-hidden="true"></i>
                        <span>{{ __('Premium account')}}</span>
                    </div>
                @else
                    <div class="badge bg-secondary">{{ __('Standard account')}}</div>
                @endif
                <a class="d-block text-decoration-none mt-2" href="{{locale_url(auth()->user()->route)}}">{{ __('View your channel')}}</a>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{{__('Close')}}"></button>
    </div>
    <div class="offcanvas-body px-0 pt-0">
        <div class="list-group list-group-flush overflow-auto">
            <a class="list-group-item list-group-item-action py-3" href="{{locale_url(route('user.videos.index'))}}">
                <i style="width: 24px" class="fa-solid fa-video" aria-hidden="true"></i>
                <span>{{ __('Manage videos') }}</span>
            </a>
            <a class="list-group-item list-group-item-action py-3" href="{{locale_url(route('subscription.manage'))}}">
                <i style="width: 24px" class="fa-solid fa-rss" aria-hidden="true"></i>
                <span>{{ __('Manage subscriptions') }}</span>
            </a>
            <a class="list-group-item list-group-item-action py-3" href="{{locale_url(route('user.edit'))}}">
                <i style="width: 24px" class="fa-solid fa-user-cog" aria-hidden="true"></i>
                <span>{{ __('Account settings') }}</span>
            </a>
            @if(auth()->user()->premium_subscription)
                <a class="list-group-item list-group-item-action py-3" href="{{ auth()->user()->premium_subscription->is_canceled ? locale_url(route('user.edit')) : auth()->user()->billingManagementUrl(route('user.edit')) }}">
                    <i style="width: 24px" class="fa-solid fa-dollar" aria-hidden="true"></i>
                    <span>{{ __('Membership') }}</span>
                </a>
            @else
                <a class="list-group-item list-group-item-action list-group-item-warning py-3" href="{{locale_url(route('pages.premium'))}}">
                    <i style="width: 24px" class="fa-solid fa-star" aria-hidden="true"></i>
                    <span>{{__('Premium')}}</span>
                </a>
            @endif
            @if(auth()->user()->is_admin)
                <a class="list-group-item list-group-item-action list-group-item-danger py-3" href="{{locale_url(route('admin.index'))}}">
                    <i style="width: 24px" class="fa-solid fa-toolbox" aria-hidden="true"></i>
                    <span class="">{{__('Admin')}}</span>
                </a>
            @endif
        </div>
    </div>
    <div class="list-group list-group-flush border-1 border-top avd-account-footer">
        @if($showAccountLocale || $showAccountTheme)
        <div class="list-group-item avd-account-controls-item">
            <div @class(['avd-sidebar-controls avd-account-controls', 'avd-account-controls--single' => !$showAccountLocale || !$showAccountTheme])>
                @if($showAccountLocale)
                <div class="dropdown dropup avd-locale-picker avd-account-locale-picker">
                    <button class="avd-locale-toggle" type="button" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false" aria-label="{{__('Locale')}}">
                        <span class="avd-locale-code">{{Str::upper($currentLocale)}}</span>
                        <span class="avd-locale-label">{{$currentLanguage['label'] ?? $currentLocale}}</span>
                        <i class="fa-solid fa-chevron-up" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu avd-locale-menu avd-account-locale-menu">
                        @foreach(config('languages') as $lang => $info)
                            @php $label = $info['label'] ?? Str::upper($lang); @endphp
                            <a href="{{ locale_switch_url($lang) }}" @class(['dropdown-item avd-locale-option', 'is-active' => $currentLocale === $lang]) @if($currentLocale === $lang) aria-current="true" @endif>
                                <span class="avd-locale-option-code">{{Str::upper($lang)}}</span>
                                <span class="avd-locale-option-label">{{$label}}</span>
                                @if($currentLocale === $lang)
                                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
                @endif
                @if($showAccountTheme)
                <theme-switch id="theme-switcher-account"/>
                @endif
            </div>
        </div>
        @endif
        @impersonating($guard = null)
        <a class="text-danger list-group-item py-2" href="{{locale_url(route('admin.impersonate.leave'))}}">
            <i style="width: 24px" class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            <span>{{ __('Leave') }}</span>
        </a>
        @else
        <a class="list-group-item py-2" href="{{locale_url(route('logout'))}}">
            <i style="width: 24px" class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            <span>{{ __('Logout') }}</span>
        </a>
        @endImpersonating
    </div>
</div>
