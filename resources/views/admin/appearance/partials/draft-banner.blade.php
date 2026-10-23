{{--
    Draft preview banner on public pages (interface I9). Only the admin who made the draft gets it: the
    ApplyAppearanceDraft middleware sets the request attribute avd.appearance_draft ('ok' or 'stale') for them alone.
    Hidden inside the admin's preview frame; keeps the draft link on same-site links and GET forms (search) so the
    preview follows the admin around the site. Self-contained (inline style and script, no theme CSS): it must look
    the same on every theme and never move the page. On phones it sits above the bottom bar and the ad strip.
--}}
@php
    $draftState = request()->attributes->get('avd.appearance_draft');
    $draftToken = (string) request()->query(\App\Support\AppearanceDraft::QUERY, '');
    $exitUrl = \App\Support\AppearanceDraft::previewUrl(request()->fullUrl(), null);
@endphp
<div id="avd-draft-banner" role="status" data-state="{{ $draftState }}">
    <span>{{ $draftState === 'ok' ? __('Preview of your unpublished changes. Visitors do not see them.') : __('This preview link is out of date. You see the published design.') }}</span>
    <a href="{{ route('admin.appearance.index') }}">{{ $draftState === 'ok' ? __('Back to Appearance') : __('Open Appearance') }}</a>
    <a href="{{ $exitUrl }}">{{ __('Exit preview') }}</a>
</div>
<style>
#avd-draft-banner{position:fixed;inset-inline:12px;inset-block-end:calc(12px + var(--avd-draft-lift,0px) + env(safe-area-inset-bottom,0px));margin-inline:auto;width:fit-content;max-width:calc(100vw - 24px);box-sizing:border-box;z-index:2147483000;display:flex;flex-wrap:wrap;align-items:center;gap:6px 14px;padding:8px 14px;border-radius:10px;background:#16191e;color:#fff;font:500 13px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.35)}
#avd-draft-banner a{color:#fff;text-decoration:underline;text-underline-offset:2px}
#avd-draft-banner a:focus-visible{outline:2px solid #fff;outline-offset:2px}
#avd-draft-banner[hidden]{display:none}
@media (forced-colors:active){#avd-draft-banner{border:1px solid CanvasText}}
@media print{#avd-draft-banner{display:none}}
</style>
<script>
(function () {
    var banner = document.getElementById('avd-draft-banner');
    var token = @json($draftToken);
    var inFrame = false;
    try { inFrame = window.self !== window.top; } catch (e) { inFrame = true; }
    if (!banner) return;
    if (inFrame) banner.hidden = true;
    // Phones: stand on the bars fixed to the bottom of the screen (bottom navigation, ad strip), measured on load
    // and when the window changes size - never while scrolling.
    var lift = inFrame ? function () {} : function () {
        // The bars stack up from the bottom edge (the ad strip sits on the navigation): follow the stack.
        var boxes = ['avdMobileBottomNav', 'ad-slot-mobile'].map(function (id) {
            var bar = document.getElementById(id);
            return bar && getComputedStyle(bar).position === 'fixed' ? bar.getBoundingClientRect() : null;
        }).filter(function (box) { return box && box.height > 0; }).sort(function (a, b) { return b.bottom - a.bottom; });
        var top = window.innerHeight;
        boxes.forEach(function (box) {
            if (box.bottom >= top - 2 && box.top < top) top = box.top;
        });
        banner.style.setProperty('--avd-draft-lift', Math.max(0, Math.round(window.innerHeight - top)) + 'px');
    };
    lift();
    window.addEventListener('load', lift);
    window.addEventListener('resize', lift);
    document.addEventListener('click', function (event) {
        if (event.target && event.target.closest && event.target.closest('.ad-slot-close')) setTimeout(lift, 0);
    });
    if (!token || banner.getAttribute('data-state') !== 'ok') return;
    var sameSite = function (url) {
        return url.origin === location.origin && url.pathname.indexOf('/admin') !== 0;
    };
    // Same-site links and GET forms keep the draft, so the preview follows the admin from page to page.
    document.addEventListener('click', function (event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (!link || link.closest('#avd-draft-banner') || link.target === '_blank') return;
        var url;
        try { url = new URL(link.href, location.href); } catch (e) { return; }
        if (!sameSite(url) || url.searchParams.has('avd_draft')) return;
        url.searchParams.set('avd_draft', token);
        link.href = url.toString();
    }, true);
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.nodeName !== 'FORM' || (form.getAttribute('method') || 'get').toLowerCase() !== 'get' || form.querySelector('input[name="avd_draft"]')) return;
        var url;
        try { url = new URL(form.getAttribute('action') || location.href, location.href); } catch (e) { return; }
        if (!sameSite(url)) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'avd_draft';
        input.value = token;
        form.appendChild(input);
    }, true);
})();
</script>
