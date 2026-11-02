{{--
    A demo site's visitor buttons (App\Support\VisitorChoices; Appearance > Themes > "Let visitors try the looks" and
    Money > Ads > "Demo site: ads are off until a visitor turns them on", both off by default). Printed by the layout only
    while one of them is on, on the pages of the public site a visitor reads (never the admin, an embed page, the
    installer or the answer to a form): $part 'head' is the CSS (in <head>), 'body' the controls and their script (at the
    end of <body>); both get $dock (VisitorChoices::dock()).

    - The "Demo · Ads off" pill turns ads on or off for this browser (the avd_demo_ads cookie) and reloads the page; a
      visitor who sees no ads anyway (Premium) is told so instead. The round palette button opens "Try a look": the
      site's look and the 12 looks in dark or light, each with a small preview of its own colours; a choice is the
      avd_try_look cookie and the page reloads in that look (the server renders it like a preview). The panel marks the
      choice the page shows (the visitor's own dark/light switch included).
    - Fixed at the bottom-end corner (the bottom-left one in Arabic), stacked like one dock (the pill above the round
      button), above every bottom layer of the page (--avd-bottom-chrome: the phone bottom bar, the ad strip or the studio
      tab bar; --mk-bars-h: a save or bulk bar), above "Back to top" and above the view-only demo account's note where they
      would meet (--avd-try-note, measured once by the script); steps aside while a toast shows; hidden while the watch
      page's player (or its mini player) is under it, in full screen and with the lights out; never in a frame.
      One level under the header in the page's stacking order (1034 against the header's 1035), so the header, its
      menus and its search are drawn over the dock where they meet. The dock rises over the header (1036) while the
      panel is open, while a control holds the keyboard focus (so the focus is never obscured behind the header, WCAG
      2.2 2.4.11) and while a control lies over the header's bare box with none of the header's own controls under it
      (so the header's background never cuts it - the two-row header of some looks reaches 104-113px); never while one
      of the header's own popups is open (its phone search, the search's suggestions, a menu: a control of the header
      then says aria-expanded="true"; a MutationObserver on the header's aria-expanded sets is-low in the same frame,
      not a :has() on the body, which every appended feed page is inserted under: the 2026-10 performance contract),
      so they always stay over the dock; only the open panel, the visitor's own dialog, stays over the header then.
      While one of the header's own controls, the maintenance notice's link or the view-only account's note lies under
      a control, that control steps aside: the round button turns see-through
      (clicks go through to what is under it; shown again while it has the focus); the pill moves to the corner beside
      the round button, so it stays visible, but only while the round button's own place is wholly free (that corner is
      in the round button's band, and the header, its controls and the note's banner span both: the corner is free
      exactly when that place is), else it turns see-through in its place like the button. So it never covers a header
      control or the note's text, and a pill nobody can see never takes a tap: a moved pill keeps the dock's own
      pointer-events switch (--try-pe), so it lets the clicks through whenever the dock does (a toast, the keyboard
      safeguard). The view-only demo account, whose note sits at the page's top on a landscape phone, keeps a working
      pill. On a landscape phone with the demo's ads on, the header ad pushes the header down into the dock's corner at
      the page's top. One IntersectionObserver per control (its resting box as the root, watching the header's
      controls, the maintenance notice, the note and the header's own box; the width of the box the dock is fixed in is
      measured on the dock itself, cw(): html.clientWidth counted the page's scrollbar gutter in desktop Chromium, so each
      observed box missed its control's last 11px), armed after the first layout, again on
      resize, when the bottom layers change (the ad strip's height, a save bar) and when the note moves: no work on
      scroll; each run measures the controls from their resting position first (calm()). The whole dock is see-through
      while every control has stepped aside. The dock's own box takes no clicks (only its controls and the panel do:
      the corner beside the round button under the pill's width and the gap between the two were dead spots of the
      page).
      The footer ends with room as tall as the dock needs (its own end padding, the way the site keeps room for "Back to
      top"), so the page's last row scrolls clear of the dock in the footer's own colours; while the keyboard focus is
      on something one of the dock's controls covers (a moved pill too: zone()), the dock turns transparent and lets
      clicks through, for as long as it lies there: an IntersectionObserver of the focused element against the dock's
      zone (see(), the player rule's helper too) follows it through every scroll, the smooth one a Tab starts included
      (the page scrolls smoothly: a measure one frame after the focus saw the element still moving), with no scroll
      handler; measured again on every focus, when the pill moves, on resize and whenever the controls are.
    - The panel opens above the dock (on screens 600px or taller below the header, where it really is and as tall as it
      really is: a header ad or the view-only account's note at the page's top moves it down, the two-row header of
      some looks is 104-113px tall), or beside the dock on short screens (landscape
      phones), only where its list keeps at least two rows of looks in view (measured in that place: its title, hint and
      "The site's look" in that width and the two tallest neighbouring rows, so in every language); else as a sheet over
      the screen's height, so its title and its close button are always on the screen. Only its list of looks scrolls
      (with room for the focus ring), so opening it on a look far down the list, End and Home keep the title, the close
      button, the hint and "The site's look" in view. It opens with the current choice brought into the list's view the
      nearest way (focused without the browser's own scroll, which centres a hidden choice: a list with room for two rows
      of looks showed one and two halves); on a screen too short even for that the whole panel scrolls under
      its title and close button. It closes when the focus leaves the dock (at once when the Tab key takes it out, past
      its last choice too, out of the page; else once the browser has settled the focus: not when a press inside the
      dock blurs the focus, as Safari does for buttons, nor when the window loses the focus to another app), on Escape,
      on its close button and on a click outside.
    - 44 x 44 px controls; the look's own surface, text and focus ring in both modes; logical CSS, so it mirrors in
      Arabic. Nothing is fetched: the CSS and the script are inline (ES5) and the panel is a <template> until opened.
    - The cookies: 30 days, the site's path, SameSite=Lax, Secure on https. Blocked cookies are said instead of nothing.
--}}
@php
    // The dock's height (the pill above the round button: two 44px controls and their 8px gap, or one control) and the
    // room "Back to top" takes under it (44px and its 12px gap): the footer's end room is computed from them.
    $tryHeight = (($dock['ads'] ?? null) !== null && ($dock['looks'] ?? null) !== null) ? 96 : 44;
    $tryBttRoom = ($dock['backToTop'] ?? false) ? 56 : 0;
@endphp
@if (($part ?? 'body') === 'head')
<style id="avd-try-css">
.avd-try{position:fixed;z-index:1034;inset-inline-end:calc(1rem + env(safe-area-inset-right,0px));bottom:max(calc(var(--avd-bottom-chrome,0px) + var(--mk-bars-h,0px) + 12px),calc(1rem + env(safe-area-inset-bottom,0px)),var(--avd-try-note,0px));display:flex;flex-direction:column;align-items:flex-end;gap:8px;color:var(--avd-text);transition:opacity .12s ease;pointer-events:none}
html[dir=rtl] .avd-try{inset-inline-end:calc(1rem + env(safe-area-inset-left,0px))}
.avd-try--btt{bottom:max(calc(max(calc(var(--avd-bottom-chrome,0px) + var(--mk-bars-h,0px) + 12px),calc(1rem + env(safe-area-inset-bottom,0px))) + 56px),var(--avd-try-note,0px))}
html[data-avd-member] .avd-try--btt{bottom:max(calc(var(--avd-bottom-chrome,0px) + var(--mk-bars-h,0px) + 12px + env(safe-area-inset-bottom,0px) + 56px),var(--avd-try-note,0px))}
html[data-mk-bar=save] .avd-try{--mk-bars-h:calc(max(40px,var(--avd-control-h,40px)) + 1.5rem + 1px)}
@media (pointer:coarse){html[data-mk-bar=save] .avd-try{--mk-bars-h:calc(max(44px,var(--avd-control-h,44px)) + 1.5rem + 1px)}}
@media (max-width:575.98px){html[data-mk-bar=save] .avd-try{--mk-bars-h:calc(max(44px,var(--avd-control-h,44px)) + 1.5rem + 1px + 1.3125rem + .5rem)}html[data-mk-bar=bulk] .avd-try{--mk-bars-h:calc(4px + 2*max(44px,var(--avd-control-h,44px)) + .8rem + 1rem)}}
@media screen{#site-footer,#site-footer~#ad-slot-footer{--avd-try-room:calc(max(calc(max(calc(var(--avd-bottom-chrome,0px) + 12px),calc(1rem + env(safe-area-inset-bottom,0px))) + {{ $tryBttRoom }}px),var(--avd-try-note,0px)) + {{ $tryHeight + 4 }}px - var(--avd-bottom-chrome,0px))}
@if ($tryBttRoom > 0)
html[data-avd-member] #site-footer,html[data-avd-member] #site-footer~#ad-slot-footer{--avd-try-room:calc(max(calc(var(--avd-bottom-chrome,0px) + 12px + env(safe-area-inset-bottom,0px) + 56px),var(--avd-try-note,0px)) + {{ $tryHeight + 4 }}px - var(--avd-bottom-chrome,0px))}
@endif
#site-footer{padding-block-end:var(--avd-try-room)}
html[data-mk-toast] #site-footer{padding-block-end:max(var(--avd-try-room),var(--mk-toast-room,6rem))}
#site-footer~#ad-slot-footer{margin-block-end:var(--avd-try-room)}
html[data-mk-toast] #site-footer~#ad-slot-footer{margin-block-end:max(var(--avd-try-room),var(--mk-toast-room,6rem))}}
.avd-try[hidden],.avd-try [hidden]{display:none!important}
html[data-mk-toast] .avd-try:not(.is-open),.avd-try.is-under:not(.is-open){opacity:0;pointer-events:none;--try-pe:none}
.avd-try.is-clear:not(.is-open),.avd-try.is-away,body.avd-lights-out .avd-try{visibility:hidden;opacity:0}
.avd-try.is-open,.avd-try.is-raise,.avd-try:focus-within{z-index:1036}
.avd-try.is-low:not(.is-open){z-index:1034}
.avd-try__ads,.avd-try__btn,.avd-try__panel{pointer-events:var(--try-pe,auto)}
.avd-try:not(.is-open) .is-shy:not(:focus){opacity:0;pointer-events:none}
.avd-try:not(.is-open) .is-moved{position:absolute;inset-block-end:0;inset-inline-end:calc(100% + 8px)}
.avd-try.is-shy:not(.is-open):not(:focus-within){opacity:0}
:where(.avd-try) button{margin:0;font:inherit;color:inherit;cursor:pointer;-webkit-tap-highlight-color:transparent}
.avd-try svg{flex:none;inline-size:22px;block-size:22px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.avd-try__ads,.avd-try__btn{--try-bg:var(--avd-surface-solid,var(--avd-surface-1,#fff));display:inline-flex;align-items:center;justify-content:center;min-block-size:44px;border:1px solid var(--avd-muted,currentColor);background:var(--try-bg);color:var(--avd-text);box-shadow:0 8px 22px -8px rgba(var(--avd-shade-rgb,0,0,0),.45);transition:background-color .12s ease,border-color .12s ease}
.avd-try__btn{inline-size:44px;padding:0;border-radius:50%}
.avd-try__ads{gap:8px;padding-block:0;padding-inline:16px 12px;border-radius:999px;text-align:start;line-height:1.15}
.avd-try__kicker{display:block;font-size:.6875rem;font-weight:600;color:var(--avd-muted,inherit)}
.avd-try__state{display:block;font-size:.8125rem;font-weight:700;white-space:nowrap}
.avd-try__halo{stroke:var(--try-bg);stroke-width:5}
.avd-try__ads[data-state=on] .avd-try__halo,.avd-try__ads[data-state=on] .avd-try__slash{display:none}
.avd-try__dots{fill:currentColor;stroke:none}
@media (hover:hover){.avd-try__ads:hover,.avd-try__btn:hover{--try-bg:var(--avd-surface-2,var(--avd-surface-solid))}.avd-try__panel button:hover{background:var(--avd-surface-2,var(--avd-surface-solid))}}
.avd-try__panel{position:absolute;inset-block-end:calc(100% + 8px);inset-inline-end:0;box-sizing:border-box;display:flex;flex-direction:column;inline-size:min(22rem,calc(100vw - 2rem));max-block-size:60vh;overflow:auto;overscroll-behavior:contain;scroll-padding-block-start:52px;padding:8px 12px 12px;border:1px solid var(--avd-muted,currentColor);border-radius:16px;background:var(--avd-surface-solid,var(--avd-surface-1,#fff));color:var(--avd-text);box-shadow:0 18px 40px -12px rgba(var(--avd-shade-rgb,0,0,0),.55)}
.avd-try__panel.is-side{inset-block-end:0;inset-inline-end:calc(100% + 8px)}
.avd-try__panel.is-sheet{position:fixed;inset-block:8px auto;inset-inline:calc(1rem + env(safe-area-inset-left,0px)) calc(1rem + env(safe-area-inset-right,0px));inline-size:auto}
.avd-try__head{position:sticky;inset-block-start:0;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:8px;margin-inline:-12px;padding-inline:12px;background:inherit}
.avd-try__title{margin:0;font-size:1rem;font-weight:700;line-height:1.3}
.avd-try__close{display:inline-flex;align-items:center;justify-content:center;inline-size:44px;block-size:44px;margin-inline-end:-8px;padding:0;border:0;border-radius:50%;background:none}
.avd-try__hint,.avd-try__msg{margin:0 0 10px;font-size:.8125rem;line-height:1.4}
.avd-try__hint{color:var(--avd-muted,inherit)}
.avd-try__msg{font-weight:600}
.avd-try__list{display:grid;gap:4px;flex:0 1 auto;min-block-size:4rem;overflow:auto;overscroll-behavior:contain;margin:2px -6px -6px;padding:6px;list-style:none}
.avd-try__list button{scroll-margin-block:6px}
.avd-try__row{display:flex;align-items:center;gap:8px}
.avd-try__name{flex:1;min-inline-size:0;font-size:.875rem;font-weight:600;line-height:1.25;overflow-wrap:anywhere}
.avd-try__sw{flex:none;inline-size:26px;block-size:26px;box-sizing:border-box;border:1px solid var(--avd-muted,currentColor);border-radius:8px;background:linear-gradient(135deg,var(--a) 0 45%,var(--b) 45% 72%,var(--c) 72%)}
.avd-try__site,.avd-try__mode{display:inline-flex;align-items:center;gap:6px;min-block-size:44px;padding:0 10px;border:1px solid var(--avd-border-strong,var(--avd-muted));border-radius:10px;background:none;font-size:.8125rem;font-weight:600;line-height:1.2}
.avd-try__site{box-sizing:border-box;inline-size:100%;gap:8px;text-align:start}
.avd-try__dot{flex:none;inline-size:14px;block-size:14px;box-sizing:border-box;border:1px solid var(--avd-muted,currentColor);border-radius:50%;background:linear-gradient(135deg,var(--m) 0 55%,var(--a) 55%)}
.avd-try__check{display:none;margin-inline-start:auto;inline-size:18px!important;block-size:18px!important}
.avd-try__panel [aria-pressed=true]{border-color:var(--avd-text);box-shadow:inset 0 0 0 1px var(--avd-text)}
.avd-try__panel [aria-pressed=true] .avd-try__check{display:block}
.avd-try__say{position:absolute;inset-block-end:calc(100% + 8px);inset-inline-end:0;box-sizing:border-box;inline-size:max-content;max-inline-size:min(18rem,calc(100vw - 2rem));margin:0;padding:8px 12px;border-radius:12px;background:var(--avd-text);color:var(--avd-bg);font-size:.8125rem;font-weight:600;line-height:1.4;box-shadow:0 10px 24px -10px rgba(var(--avd-shade-rgb,0,0,0),.5)}
@media (pointer:fine){.avd-try__site,.avd-try__mode{min-block-size:36px}}
@media (prefers-reduced-motion:reduce){.avd-try,.avd-try *{transition:none!important}}
@media print{.avd-try{display:none!important}}
</style>
@else
@php
    $tryLooks = $dock['looks'] ?? null;
    $tryAds = $dock['ads'] ?? null;
    $tryCurrent = is_array($tryLooks['current'] ?? null) ? $tryLooks['current'] : null;
    $tryAdsState = (string) ($tryAds['state'] ?? '');
    $tryModes = ['dark' => __('Dark'), 'light' => __('Light')];
    $tryCheck = '<svg class="avd-try__check" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';
@endphp
<div @class(['avd-try', 'avd-try--btt' => $dock['backToTop'] ?? false, 'avd-try--both' => $tryAds !== null && $tryLooks !== null]) id="avdTry" hidden
    data-path="{{ $dock['path'] }}"
    data-look-now="{{ $tryCurrent ? $tryCurrent['look'].'.'.$tryCurrent['mode'] : '' }}"
    data-ads-now="{{ $tryAdsState }}"
    data-t-on="{{ __('Ads on') }}" data-t-off="{{ __('Ads off') }}"
    data-t-do-on="{{ __('Turn ads on') }}" data-t-do-off="{{ __('Turn ads off') }}"
    data-t-ads-on="{{ __('Ads are on in this browser.') }}" data-t-ads-off="{{ __('Ads are off again.') }}"
    data-t-premium="{{ __('You see no ads: you have Premium.') }}"
    data-t-blocked="{{ __("Cookies are blocked in this browser, so this switch can't work.") }}">
    <p class="avd-try__say" id="avdTrySay" aria-hidden="true" hidden></p>
    <p class="visually-hidden" id="avdTryLive" role="status" aria-live="polite"></p>
    @if ($tryAds !== null)
    <button type="button" class="avd-try__ads" id="avdTryAds" data-state="{{ $tryAdsState }}" aria-labelledby="avdTryAdsKicker avdTryAdsState avdTryAdsDo">
        <span><span class="avd-try__kicker" id="avdTryAdsKicker">{{ __('Demo') }}</span><span class="avd-try__state" id="avdTryAdsState">{{ $tryAdsState === 'on' ? __('Ads on') : __('Ads off') }}</span></span>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="2.75" y="5.75" width="18.5" height="12.5" rx="2.5"/><path d="M6.4 15l2.3-6 2.3 6M7.3 13h2.8M13.6 9h1.7a3 3 0 0 1 0 6h-1.7z"/><path class="avd-try__halo" d="M4 4l16 16"/><path class="avd-try__slash" d="M4 4l16 16"/></svg>
        <span class="visually-hidden" id="avdTryAdsDo">{{ $tryAdsState === 'premium' ? __('You see no ads: you have Premium.') : ($tryAdsState === 'on' ? __('Turn ads off') : __('Turn ads on')) }}</span>
    </button>
    @endif
    @if ($tryLooks !== null)
    <button type="button" class="avd-try__btn" id="avdTryLook" aria-haspopup="dialog" aria-expanded="false" aria-label="{{ __('Try another look') }}" title="{{ __('Try another look') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3.2c-4.9 0-8.8 3.7-8.8 8.4 0 4.8 3.9 8.6 8.7 8.6 1.2 0 2-.8 2-1.9 0-.5-.2-.9-.5-1.2-.3-.3-.5-.7-.5-1.2 0-1 .8-1.8 1.9-1.8h2c2.9 0 5.2-2.3 5.2-5.1 0-3.2-4-5.8-10-5.8z"/><g class="avd-try__dots"><circle cx="7.6" cy="11.4" r="1.35"/><circle cx="10.2" cy="7.6" r="1.35"/><circle cx="14.8" cy="7.6" r="1.35"/></g></svg>
    </button>
    <template id="avdTryTpl">
        <div class="avd-try__panel" id="avdTryPanel" role="dialog" aria-modal="false" aria-labelledby="avdTryTitle" hidden>
            <div class="avd-try__head">
                <h2 class="avd-try__title" id="avdTryTitle">{{ __('Try a look') }}</h2>
                <button type="button" class="avd-try__close" data-avd-try-close aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
            </div>
            <p class="avd-try__hint">{{ __("Only in this browser. Everyone else sees the site's look.") }}</p>
            <p class="avd-try__msg" data-avd-try-msg hidden></p>
            <button type="button" class="avd-try__site" data-look="" data-ctx="" aria-pressed="{{ $tryCurrent === null ? 'true' : 'false' }}" style="--a:{{ $tryLooks['site']['brand'] }};--b:{{ $tryLooks['site']['brand2'] }};--c:{{ $tryLooks['site']['accent'] }}"><span class="avd-try__sw"></span><span>{{ __("The site's look") }}</span>{!! $tryCheck !!}</button>
            {{-- One line per look (the template is sent with every page while the setting is on). The swatch and dots are
                 empty spans, so nothing of them is read out. The list is the panel's scrolling part: tabindex -1 keeps
                 Firefox from making it a Tab stop of its own (it puts every scrolling box in the Tab order). --}}
            <ul class="avd-try__list" role="list" tabindex="-1">
@foreach ($tryLooks['list'] as $tryIndex => $tryLook)
<li class="avd-try__row" style="--a:{{ $tryLook['brand'] }};--b:{{ $tryLook['brand2'] }};--c:{{ $tryLook['accent'] }}"><span class="avd-try__sw"></span><span class="avd-try__name" id="avdTryN{{ $tryIndex }}">{{ $tryLook['name'] }}</span>@foreach ($tryModes as $tryMode => $tryModeLabel)<button type="button" class="avd-try__mode" id="avdTryM{{ $tryIndex }}{{ $tryMode }}" data-look="{{ $tryLook['id'] }}" data-mode="{{ $tryMode }}" data-ctx="{{ $tryLook['modes'][$tryMode]['context'] }}" aria-pressed="{{ $tryCurrent !== null && $tryCurrent['look'] === $tryLook['id'] && $tryCurrent['mode'] === $tryMode ? 'true' : 'false' }}" aria-labelledby="avdTryN{{ $tryIndex }} avdTryM{{ $tryIndex }}{{ $tryMode }}" style="--m:{{ $tryLook['modes'][$tryMode]['bg'] }}"><span class="avd-try__dot"></span>{{ $tryModeLabel }}</button>@endforeach</li>
@endforeach
            </ul>
        </div>
    </template>
    @endif
</div>
{{-- ES5, no request: cookies, the panel (its place, the keyboard, the focus), the view-only note, the overlap with the
     watch page's player, full screen. No work on scroll; one debounced resize handler. --}}
<script>(function(d,w){var root=d.getElementById('avdTry');if(!root)return;var alone=true;try{alone=w.self===w.top}catch(e){alone=false}
if(!alone){root.parentNode.removeChild(root);var css=d.getElementById('avd-try-css');if(css)css.parentNode.removeChild(css);return}
var LOOK='avd_try_look',ADS='avd_demo_ads',AGE=2592000,path=root.getAttribute('data-path')||'/',sec=w.location.protocol==='https:'?';Secure':'',html=d.documentElement,
ads=d.getElementById('avdTryAds'),btn=d.getElementById('avdTryLook'),tpl=d.getElementById('avdTryTpl'),say=d.getElementById('avdTrySay'),live=d.getElementById('avdTryLive'),
note=d.querySelector('.avd-view-only-note'),hd=d.querySelector('[data-avd-header]'),ctl=[ads,btn].filter(function(c){return c}),panel=null,open=false,busy=false,press=false,tab=false,moved=0,timer=0,later=0,watch=null,place=null,shy=null,calm=null,fo=null,uo=null,po=null,t=function(k){return root.getAttribute('data-t-'+k)||''},
put=function(n,v,a){d.cookie=n+'='+v+';path='+path+';max-age='+a+';SameSite=Lax'+sec},
get=function(n){var m=d.cookie.match(new RegExp('(?:^|;)\\s*'+n+'=([^;]*)'));return m?m[1]:null},
works=function(){if(w.navigator.cookieEnabled===false)return false;try{put('avd_try_probe','1',60);var ok=get('avd_try_probe')==='1';put('avd_try_probe','',0);return ok}catch(e){return false}},
tell=function(text){var m=open&&panel?panel.querySelector('[data-avd-try-msg]'):say;live.textContent='';w.setTimeout(function(){live.textContent=text},60);
m.textContent=text;m.hidden=false;w.clearTimeout(timer);if(m===say)timer=w.setTimeout(function(){say.hidden=true},5000)},
again=function(what){try{w.sessionStorage.setItem('avd_try_focus',what)}catch(e){}w.location.reload()},
fit=function(){if(!note)return;var v=0,a,b,s=w.getComputedStyle(note),at=[root,d.getElementById('site-footer'),d.getElementById('ad-slot-footer')];
if(s.position==='fixed'&&s.display!=='none'){a=note.getBoundingClientRect();b=root.getBoundingClientRect();if(a.height&&a.left<b.right&&a.right>b.left)v=Math.ceil(w.innerHeight-a.top+12)}
at.forEach(function(el){if(el)el.style.setProperty('--avd-try-note',v+'px')})},
decide=function(){var r=0,m=0,s=1,k=root.classList;ctl.forEach(function(c){var a=c._a,b=a>1,v=b&&c===ads&&btn&&btn._a===0;c.classList.toggle('is-shy',b&&!v);c.classList.toggle('is-moved',!!v);
if(v)m=1;if(a==1)r=1;if(!b||v)s=0});k.toggle('is-shy',!!s);k.toggle('is-raise',!!r);k.toggle('is-low',!!(hd&&hd.querySelector('[aria-expanded=true]')));if(m!=moved){moved=m;w.requestAnimationFrame(re2)}},
zone=function(){var r=root.getBoundingClientRect(),p=moved?ads.getBoundingClientRect():r;return{top:r.top,bottom:r.bottom,left:Math.min(r.left,p.left),right:Math.max(r.right,p.right)}},
re=function(){if(calm)calm();fit();if(open&&place)place();re2();if(shy)shy()},
re2=function(){if(watch)watch();uo=see(uo,fo,1,'is-under')},
cw=function(){return root.getBoundingClientRect().right+parseFloat(w.getComputedStyle(root).right)},
see=function(o,el,p,c){var r=zone(),vw=cw(),vh=w.innerHeight;if(o)o.disconnect();if(!el||!r.right||!w.IntersectionObserver){root.classList.remove(c);return null}
o=new w.IntersectionObserver(function(es){root.classList.toggle(c,es[es.length-1].isIntersecting)},
{rootMargin:[-(r.top+p),-(vw-r.right+p),-(vh-r.bottom+p),-(r.left+p)].map(Math.round).join('px ')+'px'});o.observe(el);return o};
if(ads)ads.addEventListener('click',function(){var s=ads.getAttribute('data-state'),on=s!=='on';if(busy)return;
if(s==='premium'){tell(t('premium'));return}
if(!works()){tell(t('blocked'));return}
if(on)put(ADS,'1',AGE);else put(ADS,'',0);
if((get(ADS)==='1')!==on){tell(t('blocked'));return}
busy=true;ads.setAttribute('data-state',on?'on':'off');d.getElementById('avdTryAdsState').textContent=t(on?'on':'off');
d.getElementById('avdTryAdsDo').textContent=t(on?'do-off':'do-on');tell(t(on?'ads-on':'ads-off'));w.setTimeout(function(){again('ads')},700)});
if(btn&&tpl){var outside=function(e){if(!root.contains(e.target))hide(false)},
escape=function(e){if(open&&(e.key==='Escape'||e.key==='Esc')&&!root.contains(e.target))hide(false)},
hide=function(back){if(!open)return;open=false;press=tab=false;panel.hidden=true;root.classList.remove('is-open');btn.setAttribute('aria-expanded','false');
d.removeEventListener('pointerdown',outside,true);d.removeEventListener('keydown',escape);panel.querySelector('[data-avd-try-msg]').hidden=true;if(back)btn.focus()},
mark=function(){var now=(root.getAttribute('data-look-now')||'').split('.'),shown=html.getAttribute('data-bs-theme'),all,i;
if(now.length!==2||(shown!=='dark'&&shown!=='light'))return;all=panel.querySelectorAll('button[data-look="'+now[0]+'"]');
for(i=0;i<all.length;i++)all[i].setAttribute('aria-pressed',all[i].getAttribute('data-mode')===shown?'true':'false')},
near=function(el){try{el.scrollIntoView({block:'nearest',inline:'nearest'})}catch(x){}},
place=function(){var r=root.getBoundingClientRect(),vw=cw(),vh=w.innerHeight,rtl=html.getAttribute('dir')==='rtl',s=panel.style,
list=panel.querySelector('.avd-try__list'),was=[panel.scrollTop,list.scrollTop],how,hd=d.querySelector('[data-avd-header]'),hb=hd?hd.getBoundingClientRect():null,
above=Math.floor(r.top-8-(vh>=600?(hb?Math.max(88+Math.max(0,hb.top),hb.bottom+12):88):8)),side=Math.floor((rtl?vw-r.right:r.left)-24),tall=Math.floor(r.bottom-8),
set=function(h){panel.className='avd-try__panel'+(h?' '+h:'');s.width=h==='is-side'?Math.min(352,side)+'px':''},
rows=function(h,room){var li=list.children,n=0,i,a,b;set(h);s.maxHeight='none';
for(i=1;i<li.length;i++){a=li[i-1].getBoundingClientRect();b=li[i].getBoundingClientRect();n=Math.max(n,b.bottom-a.top)}
return room>=Math.ceil(panel.offsetHeight-list.offsetHeight+n+12)};how=above>=240&&rows('',above)?'':(side>=280&&rows('is-side',tall)?'is-side':'is-sheet');
set(how);s.maxHeight=Math.max(how==='is-sheet'?vh-16:how?tall:above,120)+'px';panel.scrollTop=was[0];list.scrollTop=was[1]},
show=function(){var f;if(!panel){panel=d.importNode(tpl.content,true).querySelector('.avd-try__panel');btn.parentNode.insertBefore(panel,btn.nextSibling);
btn.setAttribute('aria-controls',panel.id);panel.addEventListener('click',pick);panel.addEventListener('keydown',keys);panel.addEventListener('focusin',function(e){if(!press)near(e.target)})}
mark();open=true;panel.hidden=false;root.classList.add('is-open');place();btn.setAttribute('aria-expanded','true');
d.addEventListener('pointerdown',outside,true);d.addEventListener('keydown',escape);
f=panel.querySelector('[aria-pressed="true"]')||panel.querySelector('button[data-look]');f.focus({preventScroll:true});near(f)},
pick=function(e){var b=e.target.closest?e.target.closest('button'):null,look,value,ctx;if(!b||!panel.contains(b)||busy)return;
if(b.hasAttribute('data-avd-try-close')){hide(true);return}
if(!b.hasAttribute('data-look'))return;
if(b.getAttribute('aria-pressed')==='true'){hide(true);return}
if(!works()){tell(t('blocked'));return}
look=b.getAttribute('data-look');value=look?look+'.'+b.getAttribute('data-mode'):'';
if(look)put(LOOK,value,AGE);else put(LOOK,'',0);
if((get(LOOK)||'')!==value){tell(t('blocked'));return}
ctx=b.getAttribute('data-ctx');try{if(ctx&&w.localStorage.getItem('avd_manual_theme_context')===ctx){w.localStorage.removeItem('avd_manual_theme');w.localStorage.removeItem('avd_manual_theme_context')}}catch(x){}
busy=true;again('look')},
keys=function(e){var k=e.key,all,i,n,rtl;if(!/^(Arrow|Home$|End$)/.test(k))return;
all=panel.querySelectorAll('button[data-look]');i=Array.prototype.indexOf.call(all,d.activeElement);if(i<0)return;rtl=html.getAttribute('dir')==='rtl';
if(k==='Home')n=0;else if(k==='End')n=all.length-1;else if(k==='ArrowDown')n=i===0?1:Math.min(i+2,all.length-1);else if(k==='ArrowUp')n=i<=2?0:i-2;
else n=((k==='ArrowRight')!==rtl)?Math.min(i+1,all.length-1):Math.max(i-1,0);
e.preventDefault();e.stopPropagation();all[n].focus()};
btn.addEventListener('click',function(){if(open)hide(true);else show()});
root.addEventListener('keydown',function(e){if(open&&(e.key==='Escape'||e.key==='Esc')){e.preventDefault();e.stopPropagation();hide(true)}});
root.addEventListener('pointerdown',function(){press=true;tab=false},true);root.addEventListener('keydown',function(e){press=false;tab=e.key==='Tab';if(tab)w.setTimeout(function(){tab=false},100)},true);
root.addEventListener('focusout',function(e){var to=e.relatedTarget;if(!open)return;if(to){if(!root.contains(to))hide(false);return}
if(tab&&!press){hide(false);return}
w.setTimeout(function(){var a=d.activeElement;if(open&&!press&&(!a||!root.contains(a)||(tab&&!d.hasFocus())))hide(false)},0)})}
var away=function(){var fs=!!(d.fullscreenElement||d.webkitFullscreenElement);root.classList.toggle('is-away',fs);if(fs&&btn&&open)hide(false)};
d.addEventListener('fullscreenchange',away);d.addEventListener('webkitfullscreenchange',away);
w.addEventListener('pageshow',function(e){if(!e.persisted)return;var look=get(LOOK)||'',s=root.getAttribute('data-ads-now');
if((btn&&look!==(root.getAttribute('data-look-now')||''))||(ads&&s!=='premium'&&(get(ADS)==='1')!==(s==='on')))w.location.reload()});
root.hidden=false;fit();
d.addEventListener('focusin',function(e){var t=e.target;if(open&&t&&!root.contains(t))hide(false);fo=t&&t!==d.body&&!root.contains(t)?t:null;w.requestAnimationFrame(re2)});
var player=d.getElementById('avdMainPlayerShell');if(player&&w.requestAnimationFrame){watch=function(){po=see(po,player,4,'is-clear')};w.requestAnimationFrame(watch)}
if(w.IntersectionObserver&&w.requestAnimationFrame){var aim=[].slice.call(d.querySelectorAll('[data-avd-header] a[href],[data-avd-header] button,[data-avd-header] input,[data-avd-header] select,[data-avd-header] [role=button],#avd-maintenance-notice a')),ios=[],fr=0,
arm=function(){w.cancelAnimationFrame(fr);fr=w.requestAnimationFrame(function(){fr=w.requestAnimationFrame(shy)})};if(note)aim.push(note);var all=hd?aim.concat([hd]):aim;
calm=function(){ctl.forEach(function(c){c._a=null});decide()};
shy=function(){var vw=cw(),vh=w.innerHeight;calm();ios.forEach(function(o){o.disconnect()});ios=[];ctl.forEach(function(c){var r=c.getBoundingClientRect(),on=[],o;if(!r.width||!all.length)return;
o=new w.IntersectionObserver(function(es){es.forEach(function(e){on[all.indexOf(e.target)]=e.isIntersecting});c._a=(on.slice(0,aim.length).indexOf(true)>=0)*2+(on[aim.length]===true);decide()},
{rootMargin:[-(r.top+1),-(vw-r.right+1),-(vh-r.bottom+1),-(r.left+1)].map(Math.round).join('px ')+'px'});all.forEach(function(a){o.observe(a)});ios.push(o)});re2()};arm();
if(w.MutationObserver){var mo=new w.MutationObserver(arm);mo.observe(d.body,{attributes:true,attributeFilter:['style']});mo.observe(html,{attributes:true,attributeFilter:['data-mk-bar']});if(hd)new w.MutationObserver(decide).observe(hd,{attributes:true,subtree:true,attributeFilter:['aria-expanded']})}}
if(note)note.addEventListener('avd-vo-moved',re);
w.addEventListener('resize',function(){w.clearTimeout(later);later=w.setTimeout(re,200)});
try{var f=w.sessionStorage.getItem('avd_try_focus');if(f){w.sessionStorage.removeItem('avd_try_focus');f=f==='ads'?ads:btn;if(f)f.focus({preventScroll:true})}}catch(e){}
})(document,window);</script>
@endif
