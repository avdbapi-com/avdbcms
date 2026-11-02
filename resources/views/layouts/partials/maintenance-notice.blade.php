{{-- Maintenance mode is on and this visitor still sees the site (App\Http\Middleware\CheckMaintenanceMode marks the
     request: an administrator while "Admins can still browse the site" is on, the secret preview link, an allowed
     internet address; the middleware also keeps every such answer out of shared caches). A strip above the header says
     so, so nobody takes the site for open (an administrator browsing the site had no sign of it); administrators get the
     way to the maintenance settings. Server-rendered in the page's flow (no layout shift), only on those requests, in the
     look's own colours (the warning tint the kit's alerts use); one wrapped line of text, the link in it.

     Under a header that is fixed over the page's banner ("Over the banner": html[data-avd-header-style=
     "transparent_overlay"] with body.has-hero-zone, layout-theme-a.css) the strip stays at the top (sticky, above the
     header) and the header sits right under it (--avd-maintenance-h, the strip's height, kept by the script below): the
     fixed header covered the strip and its link. Without the script the strip is still on top. --}}
@if(request()->attributes->get(\App\Http\Middleware\CheckMaintenanceMode::PREVIEW) === true && config('site.maintenance_mode', false))
    <div id="avd-maintenance-notice" class="avd-maintenance-notice" role="status">
        <i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i>
        {{-- the settings link for whoever may open the admin from here (an administrator; the admin IP lock decides) --}}
        @if(\App\Support\AdminIpLock::adminPowersHere(auth()->user()))
            <span>{{ __('Maintenance mode is on. Visitors see the maintenance page.') }}</span>
            <a href="{{ route('admin.settings.page', ['page' => 'maintenance']) }}">{{ __('Maintenance settings') }}</a>
        @else
            <span>{{ __('Maintenance mode is on. Visitors see the maintenance page; this browser sees the site.') }}</span>
        @endif
    </div>
    @once
        <style>
            .avd-maintenance-notice{padding:.5rem 1rem;background:var(--avd-bg,transparent);background-image:linear-gradient(rgba(var(--avd-warning-rgb,180,83,9),.12),rgba(var(--avd-warning-rgb,180,83,9),.12));color:var(--avd-text,inherit);font-size:.875rem;line-height:1.4;text-align:center;border-block-end:2px solid var(--avd-warning,#b45309)}
            .avd-maintenance-notice>i{margin-inline-end:.35rem;color:var(--avd-text,inherit)}
            .avd-maintenance-notice a{margin-inline-start:.25rem;color:inherit;font-weight:700;text-decoration:underline;text-underline-offset:.15em;display:inline-block;padding-block:.25rem;margin-block:-.25rem}
            .avd-maintenance-notice a:focus-visible{outline:2px solid var(--avd-focus,currentColor);outline-offset:2px;border-radius:2px}
            @media (hover:hover) and (pointer:fine){.avd-maintenance-notice a:hover{text-decoration-thickness:2px}}
            @media (pointer:coarse){.avd-maintenance-notice{padding-block:.8rem}.avd-maintenance-notice a{padding-block:.8rem;margin-block:-.8rem}}
            @supports selector(:has(a)){html[data-avd-header-style="transparent_overlay"] body.has-hero-zone .avd-maintenance-notice{position:sticky;top:0;z-index:1036}html[data-avd-header-style="transparent_overlay"] body.has-hero-zone .avd-maintenance-notice~.avd-header{top:var(--avd-maintenance-h,0px)}}
            @media (forced-colors:active){.avd-maintenance-notice{border-block-end-color:CanvasText}}
            @media print{.avd-maintenance-notice{display:none}.avd-maintenance-notice~.avd-header{top:0!important}}
        </style>
        <script>
            (function () {
                var strip = document.getElementById('avd-maintenance-notice'), box = strip && strip.parentElement;
                if (!box) return;
                var keep = function () { box.style.setProperty('--avd-maintenance-h', strip.getBoundingClientRect().height + 'px'); };
                keep();
                if ('ResizeObserver' in window) new ResizeObserver(keep).observe(strip);
            })();
        </script>
    @endonce
@endif
