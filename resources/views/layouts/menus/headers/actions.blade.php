{{-- Header actions (search toggle, random, upload, notifications, account / sign in + create account).
     $searchIcon: the bar shows a search button at every width instead of the inline search field.

     Member pages (member design 4.1a, 6.13; html[data-avd-member], config/member_pages.php) print the calm member
     chrome: the list carries .avd-mk (the kit's button variables), the Upload is ONE quiet mk-btn (no .btn: no sweep,
     lift, shimmer or magnet), icon-only below 1200px with its label as the accessible name (watch-25), and hidden on
     studio phones where the tab bar carries Upload (body.has-studio-tabbar, _chrome.scss). Guests: no buttons on the
     sign-in pages (the card has the page's one primary and its own switch link; the bottom bar keeps Sign in and Create
     account), Sign in quiet + Create account secondary on the other member pages, the public look elsewhere. Every
     Sign in link carries ?redirect= the page shown (auth-8) and keeps the language (auth-18). Every Upload is the
     server-rendered trigger of the upload window (5.14) and only while members may upload (allow_uploads).

     The guest buttons show their words from 1200px only, like the Upload (member pages: _chrome.scss section 6; public
     pages: the Bootstrap classes below); narrower, they are icon buttons named by their words (aria-label / the hidden
     label, and a title). From 992 to 1199px the header row also holds the inline search and, in the looks whose side
     menu is hidden (Cinema Night, Swiss Bento), the header links: with their words the two buttons ran off the screen
     there ("Create account" at 1010-1165px of a 992px screen). --}}
@php
    $avdRouteName = request()->route()?->getName();
    // A member route, or a page that declares itself one: the error and status pages (errors/minimal sets the request
    // attribute avd.member_page before the layout renders; member design 6.15, the error-* mockups).
    $avdMemberHeader = (
        (is_string($avdRouteName) && \Illuminate\Support\Str::is((array) config('member_pages.routes', []), $avdRouteName))
        || request()->attributes->get('avd.member_page') === true
    ) && (bool) config('member_pages.marker', false);
    $avdAuthRoute = is_string($avdRouteName) && (
        in_array($avdRouteName, ['login', 'registration'], true)
        || \Illuminate\Support\Str::startsWith($avdRouteName, ['password.', 'verification.', 'phone.', 'oauth.'])
    );
    $avdUploadLabel = trim((string) config('site.header_upload_label', 'Upload')) ?: 'Upload';
    $avdLoginLabel = trim((string) config('site.header_login_label', 'Sign In')) ?: 'Sign In';
    $avdRegisterLabel = trim((string) config('site.header_register_label', 'Register')) ?: 'Register';
    // The defaults read "Sign in" / "Create account" (section 7); a label the owner typed is kept as typed.
    $avdLoginText = in_array($avdLoginLabel, ['Sign In', 'Sign in', 'Login', 'Log in'], true) ? __('Sign in') : __($avdLoginLabel);
    $avdRegisterText = in_array($avdRegisterLabel, ['Register', 'Sign Up', 'Sign up', 'Create account'], true) ? __('Create account') : __($avdRegisterLabel);
    $avdUploadText = $avdUploadLabel === 'Upload' ? __('Upload') : __($avdUploadLabel);
    // The Upload's accessible name: "Upload video" for the default word; an owner's own word is the name itself, so the
    // name always contains the words a sighted member reads on the button (WCAG 2.5.3).
    $avdUploadName = $avdUploadLabel === 'Upload' ? __('Upload video') : $avdUploadText;
    $avdUploads = config('site.header_upload_button_enabled', true) && config('site.allow_uploads', true);
    $avdLoginUrl = \App\Http\Controllers\Auth\ReturnPath::loginUrl();
@endphp
<ul @class(['navbar-nav flex-row d-flex align-items-center avd-header-actions', 'avd-mk' => $avdMemberHeader])>
    @if(config('site.header_random_button_enabled', true))
        <li class="nav-item {{ ($mobileBottomNavEnabled ?? true) ? 'd-none d-sm-block' : 'd-block' }}">
            <a href="{{ (function_exists('locale_url') ? locale_url(route('video.random')) : route('video.random')) }}" class="avd-icon-button" title="{{ __('Random video') }}" aria-label="{{ __('Random video') }}">
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
        @if($avdUploads)
            <li class="nav-item align-items-center d-flex avd-upload-item">
                @if($avdMemberHeader)
                    <button class="mk-btn mk-btn--secondary mk-btn--sm avd-upload-action" type="button" data-bs-toggle="modal" data-bs-target="#video_create" aria-label="{{ $avdUploadName }}" title="{{ $avdUploadName }}">
                        <i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i>
                        <span class="avd-upload-action__label">{{ $avdUploadText }}</span>
                    </button>
                @else
                    <button class="btn btn-primary btn-sm avd-upload-btn d-flex align-items-center gap-2" type="button" data-bs-toggle="modal" data-bs-target="#video_create" aria-label="{{ $avdUploadName }}" title="{{ $avdUploadName }}">
                        <i class="fa-solid fa-upload" aria-hidden="true"></i>
                        <span class="d-none d-xl-inline">{{ $avdUploadText }}</span>
                    </button>
                @endif
            </li>
        @endif
        @if(config('site.header_notifications_enabled', true))
            <li class="nav-item align-items-center">
                <site-notifications count="{{$unread_notifications}}" page-url="{{ locale_url(route('user.notifications.index')) }}" settings-url="{{ locale_url(route('user.edit', ['section' => 'notifications'])) }}#notification-settings" />
            </li>
        @endif
        <li class="nav-item avd-account-action d-block">
            <button class="avd-avatar-button" type="button" data-bs-toggle="offcanvas" data-bs-target="#account" aria-controls="account" aria-expanded="false" aria-label="{{__('Open account menu')}}">
                <img src="{{auth()->user()->avatar_url}}" alt="" width="40" height="40" decoding="async">
            </button>
        </li>
    @else
        @if($avdAuthRoute)
            {{-- The sign-in pages: no guest buttons (one primary per view). --}}
        @elseif($avdMemberHeader)
            @if(config('site.header_login_button_enabled', true))
                <li class="nav-item d-flex align-items-center avd-auth-action avd-auth-action--signin">
                    <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ $avdLoginUrl }}" title="{{ $avdLoginText }}">
                        <i class="fa-solid fa-arrow-right-to-bracket mk-flip-rtl" aria-hidden="true"></i>
                        <span>{{ $avdLoginText }}</span>
                    </a>
                </li>
            @endif
            @if(config('site.header_register_button_enabled', true) && config('site.registration_enabled', true))
                <li class="nav-item d-flex align-items-center avd-auth-action avd-auth-action--register">
                    <a class="mk-btn mk-btn--secondary mk-btn--sm" href="{{ locale_url(route('registration')) }}" title="{{ $avdRegisterText }}">
                        <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                        <span>{{ $avdRegisterText }}</span>
                    </a>
                </li>
            @endif
        @else
            {{-- Public pages: the public look, the words from 1200px (was 576px), an icon below. --}}
            @if(config('site.header_login_button_enabled', true))
            <li class="nav-item d-block">
                <a class="btn btn-sm btn-outline-secondary px-2 px-xl-3 avd-auth-btn" href="{{ $avdLoginUrl }}" aria-label="{{ $avdLoginText }}" title="{{ $avdLoginText }}">
                    <i class="fa-solid fa-arrow-right-to-bracket d-inline d-xl-none" aria-hidden="true"></i>
                    <span class="d-none d-xl-inline">{{ $avdLoginText }}</span>
                </a>
            </li>
            @endif
            @if(config('site.header_register_button_enabled', true) && config('site.registration_enabled', true))
                <li class="nav-item avd-guest-action d-flex align-items-center">
                    <a class="btn btn-sm btn-primary px-2 px-xl-3 avd-auth-btn" href="{{ locale_url(route('registration')) }}" aria-label="{{ $avdRegisterText }}" title="{{ $avdRegisterText }}">
                        <i class="fa-solid fa-user-plus d-inline d-xl-none" aria-hidden="true"></i>
                        <span class="d-none d-xl-inline">{{ $avdRegisterText }}</span>
                    </a>
                </li>
            @endif
        @endif
    @endauth
</ul>
