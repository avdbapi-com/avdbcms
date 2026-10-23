{{-- Header actions (search toggle, random, upload, notifications, account / sign in + register).
     $searchIcon: the bar shows a search button at every width instead of the inline search field. --}}
<ul class="navbar-nav flex-row d-flex align-items-center avd-header-actions">
    @if(config('site.header_random_button_enabled', true))
        <li class="nav-item {{ ($mobileBottomNavEnabled ?? true) ? 'd-none d-sm-block' : 'd-block' }}">
            <a href="{{ (function_exists('locale_url') ? locale_url(route('video.random')) : route('video.random')) }}" class="avd-icon-button" title="{{ __('Surprise Me / Random Video') }}" aria-label="{{ __('Random Video') }}">
                <i class="fa-solid fa-shuffle" aria-hidden="true"></i>
            </a>
        </li>
    @endif
    @if(config('site.header_search_enabled', true))
        <li @class(['nav-item', 'd-block', 'd-lg-none' => ! $searchIcon])>
            <button id="mobile-search-toggle" @click="search = true" class="avd-icon-button" type="button" aria-controls="mobile-search-panel" aria-expanded="false" :aria-expanded="search" aria-label="{{__('Search')}}">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            </button>
        </li>
    @endif
    @auth
        @if(config('site.header_upload_button_enabled', true))
            <li class="nav-item align-items-center d-flex">
                <button class="btn btn-primary btn-sm avd-upload-btn d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#video_create">
                    <i class="fa-solid fa-upload" aria-hidden="true"></i>
                    <span class="d-none d-xl-inline">{{ __(trim((string) config('site.header_upload_label', 'Upload')) ?: 'Upload') }}</span>
                </button>
            </li>
        @endif
        @if(config('site.header_notifications_enabled', true))
            <li class="nav-item align-items-center">
                <site-notifications count="{{$unread_notifications}}" />
            </li>
        @endif
        <li class="nav-item avd-account-action d-block">
            <button class="avd-avatar-button" type="button" data-bs-toggle="offcanvas" data-bs-target="#account" aria-controls="account" aria-expanded="false" aria-label="{{__('Open account menu')}}">
                <img src="{{auth()->user()->avatar_url}}" alt="{{auth()->user()->username}} avatar">
            </button>
        </li>
    @else
        @if(config('site.header_login_button_enabled', true))
        <li class="nav-item d-block">
            <a class="btn btn-sm btn-outline-secondary px-3 avd-auth-btn" href="{{locale_url(route('login'))}}" aria-label="{{ __('Sign In') }}">
                <i class="fa-solid fa-arrow-right-to-bracket d-inline d-sm-none" aria-hidden="true"></i>
                <span class="d-none d-sm-inline">{{ __(trim((string) config('site.header_login_label', 'Sign In')) ?: 'Sign In') }}</span>
            </a>
        </li>
        @endif
        @if(config('site.header_register_button_enabled', true) && config('site.registration_enabled', true))
            <li class="nav-item avd-guest-action d-flex align-items-center">
                <a class="btn btn-sm btn-primary px-3 avd-auth-btn" href="{{locale_url(route('registration'))}}" aria-label="{{ __('Register') }}">
                    <i class="fa-solid fa-user-plus d-inline d-sm-none" aria-hidden="true"></i>
                    <span class="d-none d-sm-inline">{{ __(trim((string) config('site.header_register_label', 'Register')) ?: 'Register') }}</span>
                </a>
            </li>
        @endif
    @endauth
</ul>
