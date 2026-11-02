{{-- Error and status pages (member design 6.15, auth-17): the kit's status card (.mk-status-page) with an icon tile
     instead of a picture (the old images/errors/*.jpg had no 401/419/429 files: a broken image), a code line, a title,
     one sentence and at most two actions, in the site's own header and the slim footer.
     A page sets: title, code (401...503), code-line (optional, "Error 404" by default), icon (a Font Awesome name),
     message (the h1), text (one sentence), actions (mk-btn links or buttons).
     The member CSS: the layout prints it for signed-in visitors and on member routes; an error page prints it itself
     otherwise (5.13: config('member_pages.routes'), never config('member_pages') as a pattern list).
     The page declares itself a member page (request attribute avd.member_page) before the layout renders, so the header
     prints the member actions (Sign in quiet + Create account secondary, the kit Upload) as in the error-* mockups.
     When this page prints the member CSS itself it says so (request attribute avd.member_css_pushed, set while the slot
     renders, before the layout): a layout that also links the entry for member pages skips it, so it is linked once.
     The chrome rests on an error page too (4.1a: 0 infinite animations at rest): the rule of _chrome.scss section 5 is
     keyed on html[data-avd-member], which the layout prints for member routes only, so the page adds it for itself,
     with section 6's header row rule (from 992px the inline search gives way, so the header's actions never run off
     the screen: Cinema Night and Swiss Bento at 992-1024px). --}}
@php
    request()->attributes->set('avd.member_page', true);
    $errorRouteName = request()->route()?->getName();
    $errorLayoutPrintsMemberCss = (
        (is_string($errorRouteName) && \Illuminate\Support\Str::is((array) config('member_pages.routes', []), $errorRouteName))
        || auth()->check()
    ) && ! request()->routeIs('admin.*');
    $errorCode = trim($__env->yieldContent('code'));
    $errorCodeLine = trim($__env->yieldContent('code-line'));
    if ($errorCodeLine === '') {
        $errorCodeLine = $errorCode !== '' ? __('Error :code', ['code' => $errorCode]) : '';
    }
@endphp
<x-layout sidebar="front" footer="slim">
    @unless($errorLayoutPrintsMemberCss)
        @php
            request()->attributes->set('avd.member_css_pushed', true);
        @endphp
        @push('seo-head')
            @php
                try {
                    $errorMemberCss = app(\Illuminate\Foundation\Vite::class)('resources/sass/member.scss');
                } catch (\Throwable) {
                    $errorMemberCss = null;
                }
            @endphp
            {{ $errorMemberCss }}
        @endpush
    @endunless
    @push('seo-head')
        <style id="avd-error-calm">body.avd-app::before,body.avd-app::after,.avd-footer::before,.avd-footer-accent,.avd-footer-accent::before,.avd-border-beam::before,.avd-border-beam::after{animation:none!important}@media (min-width:992px){.avd-header-inner>search-bar{flex-shrink:1;min-inline-size:7.5rem}.avd-header-inner>.avd-header-brand-zone{flex-shrink:.05}}</style>
    @endpush
    <div class="avd-mk">
        <section class="mk-status-page" aria-labelledby="error-title">
            <span class="mk-empty__icon" aria-hidden="true"><i class="fa-solid fa-@yield('icon', 'circle-info')"></i></span>
            @if($errorCodeLine !== '')
                <span class="mk-status-page__code">{{ $errorCodeLine }}</span>
            @endif
            <h1 class="mk-empty__title mk-status-page__title" id="error-title">@yield('message')</h1>
            @hasSection('text')
                <p class="mk-empty__text">@yield('text')</p>
            @endif
            @hasSection('actions')
                <div class="mk-empty__actions">@yield('actions')</div>
            @else
                <div class="mk-empty__actions">
                    <a class="mk-btn mk-btn--primary" href="{{ locale_url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i><span>{{ __('Go home') }}</span></a>
                </div>
            @endif
        </section>
    </div>
</x-layout>
