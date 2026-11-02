{{-- The member back office (studio and account pages; member design 6.1).
     - #main-container is NOT a scroll container any more (perf-1: with overflow-auto a button's hover movement made a
       scrollbar blink and the whole studio content jump); the document scrolls, the studio menu stays put.
     - The studio tab bar on phones (.mk-tabbar, below 768px): Dashboard, Videos, Upload (the only Upload on studio
       phones, a server-rendered trigger of the upload panel: 5.14), Comments, More (the studio menu drawer). It follows
       the admin's "Mobile Bottom Nav Bar" (mobile_bottom_nav_enabled) exactly as the layout reads it
       (App\Support\Studio\StudioChrome); the layout marks the page with body.has-studio-tabbar when it prints it
       so the bar's room, the save bar's offset and the hidden page-head Upload follow it.
     - The member bars: the page root's one Alpine x-effect sets
       html[data-mk-bar] ("save" / "bulk"), the CSS shows the bar from it, and the studio script measures the bar that
       shows and writes its real height into --mk-bars-h of the layers that float above it (a ResizeObserver, like the
       ad strip's): the toast stack, "Back to top", the daily pill, the Upload18 notices and .mk-bars-room, the end room of
       the page printed after the footer. So they always float above the bar, also when it wraps at a large "Text
       size". Never on <body> any more: an inherited custom property changed there made the
       browser restyle the whole document whenever a bar came or went (the first key typed in Video details took
       330-490 ms at CPU x4); see member/_kit.scss.
     - The slim footer (6.20).
     - Lean studio HTML (4.3, studio-20): on the studio pages the content section goes out without its template
       indentation (App\Support\Studio\StudioHtml::squeeze: one line break per run between tags; scripts, textareas and
       <pre> untouched). The sections of a page are collected before this layout runs, so @overwrite replaces it. --}}
@if(\App\Support\Studio\StudioHtml::squeezesThisPage())
@section('content'){!! \App\Support\Studio\StudioHtml::squeeze($__env->yieldContent('content')) !!}@overwrite
@endif
<x-layout sidebar="back" type="user" footer="slim">
    @php
        $studioTabBar = \App\Support\Studio\StudioChrome::tabBarEnabled();
        $studioUploads = \App\Support\Studio\StudioSettings::uploadsAllowed();
        $studioTab = match (true) {
            request()->routeIs('user.index') => 'dashboard',
            request()->routeIs('user.videos.*') => 'videos',
            request()->routeIs('user.comments.*') => 'comments',
            default => 'more',
        };
    @endphp
    @push('scripts')
        @if($studioTabBar)
            <nav @class(['mk-tabbar avd-mk', 'mk-tabbar--4' => ! $studioUploads]) aria-label="{{ __('Studio') }}">
                <a class="mk-tabbar__item" href="{{ locale_url(route('user.index')) }}" @if($studioTab === 'dashboard') aria-current="page" @endif><i class="fa-solid fa-chart-line" aria-hidden="true"></i><span>{{ __('Dashboard') }}</span></a>
                <a class="mk-tabbar__item" href="{{ locale_url(route('user.videos.index')) }}" @if($studioTab === 'videos') aria-current="page" @endif><i class="fa-solid fa-clapperboard" aria-hidden="true"></i><span>{{ __('Videos') }}</span></a>
                @if($studioUploads)
                    <button type="button" class="mk-tabbar__item mk-tabbar__upload" data-bs-toggle="modal" data-bs-target="#video_create"><i class="fa-solid fa-plus" aria-hidden="true"></i><span>{{ __('Upload') }}</span></button>
                @endif
                <a class="mk-tabbar__item" href="{{ locale_url(route('user.comments.index')) }}" @if($studioTab === 'comments') aria-current="page" @endif><i class="fa-solid fa-comments" aria-hidden="true"></i><span>{{ __('Comments') }}</span></a>
                <button type="button" class="mk-tabbar__item" data-bs-toggle="offcanvas" data-bs-target="#responsive-sidebar" aria-controls="responsive-sidebar" @if($studioTab === 'more') aria-current="page" @endif><i class="fa-solid fa-bars" aria-hidden="true"></i><span>{{ __('More') }}</span></button>
            </nav>
        @endif
        {{-- The end room of the page while a member bar shows (member/_kit.scss .mk-bars-room): after the footer. --}}
        <div class="mk-bars-room" aria-hidden="true"></div>
        {{-- The member bars' script (--mk-bars-h: the real height of the bar that shows) and the studio parts the page
             asked for: resources/js/member/studio-pages.js, one cached file (1.2.3 round 1b, R1R-01), printed here once. --}}
        @include('users.partials.studio-script-tag')
    @endpush
</x-layout>
