{{--
    Account settings (member design 6.9, mockups account-*): one server-rendered section per page (?section=), a
    section column from 992px and a sticky scroll strip on phones (account-14, account-36), every section one form with
    its own save bar. /profile/edit opens Profile.

    The page root holds the state of the save bar: `dirty` turns on when a field of a form marked data-mk-savebar
    changes (a field marked data-mk-instant or outside every form never counts), `saving` while the form is sent (no
    "leave page" warning then), and one x-effect mirrors the bar on html[data-mk-bar] (4.4). A section partial of
    its own (Notifications) uses the same: data-mk-savebar on its form, the savebar partial inside it.
--}}
@extends('layouts.user')

@section('title', __('Account settings'))
{{-- #main-container is never a scroll container here (perf-1, 6.1: layouts/user no longer makes it one):
     inside one, the sticky section column and the phone strip could never stick (6.9). --}}
@section('class', '')

@php
    // The page came back from a refused save of this section's own form (its hidden "section" field is in the old
    // input): its fields hold what was typed, so the save bar shows. The one-click forms beside the fields (a refused
    // phone code, R1-115), the password and delete sheets and the view-only demo's refusal change nothing in the form.
    $startDirty = $errors->any() && session()->hasOldInput('section') && ! $errors->has('view_only_demo') && ! $errors->has('current_password') && ! $errors->has('new_password') && ! $errors->has('understand');
    $needsCrop = in_array($section, ['profile', 'channel'], true);
@endphp

@section('content')
@php
    // the page root is printed without the indentation of the template lines (ProfileController::compactHtml())
    ob_start();
@endphp
{{-- avdAccountSettings (resources/js/member/studio-pages.js, the cached studio script of every back-office page) also
     keeps html[data-mk-bar], the leave warning and the page's input / change / submit / pageshow listeners (1.2.3 round
     3, R3-086: they were attributes of every section's page). --}}
<div class="avd-mk mk-page mk-page--form mk-page--has-bar avd-profile-settings" data-account-section="{{ $section }}" x-data="avdAccountSettings({{ $startDirty ? 'true' : 'false' }})">

    <x-mk.head :title="__('Account settings')" :kicker="__('Account')" icon="user-gear" :desc="__('Your profile, your channel and how you sign in.')" />

    <div class="mk-settings">
        <nav class="mk-settings__nav" aria-label="{{ __('Account settings') }}">
            <ul class="mk-subnav">
                @foreach($sections as $key => [$label, $icon])
                    <li><a href="{{ \App\Http\Controllers\User\ProfileController::sectionUrl($key) }}"{!! $key === $section ? ' aria-current="page"' : '' !!}{!! $key === 'delete' ? ' class="is-danger"' : '' !!}><i class="{{ $icon }}" aria-hidden="true"></i>{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>

        <div class="mk-settings__content">
            @if($section === 'notifications')
                @include('users.profile.partials.notices', ['fields' => ['*']])
                @include('users.profile.parts.notifications', ['user' => $user])
            @else
                @include('users.profile.sections.'.$section)
            @endif
        </div>
    </div>

    {{-- Dialogs at the end of the page root, outside every query container (a container holds position: fixed). --}}
    @stack('mk-account-dialogs')
</div>
{{-- The picture dialog follows the page root: a dialog is not page content (member design 4.3 counts dialogs with the
     layout), and a modal belongs near the top of the document. It talks to the page through window events only. --}}
@if($needsCrop)
    @include('users.partials.crop', ['cropKinds' => [$section === 'channel' ? 'banner' : 'avatar']])
@endif
@php
    echo \App\Http\Controllers\User\ProfileController::compactHtml((string) ob_get_clean());
@endphp

{{-- The page root of Account settings (Alpine, avdAccountSettings in resources/js/member/studio-pages.js since 1.2.3 round
     3, R3-086: one cached file instead of an inline script on every section's page): `dirty` turns on when a field of a
     form marked data-mk-savebar changes (a field marked data-mk-instant, a file input or a field outside every form never
     counts), `saving` while the form is sent; Cancel puts the stored values back. After a refused save (`refused`) the
     fields hold what was typed, which the form's own reset keeps (it goes back to the values the page was printed with):
     Cancel then loads the section again, with the stored values and without the errors (R1-032). On load, on phones the
     strip first shows the open section (it is still in its place, so only the strip scrolls), then the first field with
     an error gets the focus (not after a view-only refusal: its alert has it) and is put on screen with its error by
     window.avdRevealRefused, which only a page that came back with a field error carries (the script below). --}}
{{-- A refused save (R2A-007, R2A-044, R1-115): the first field with an error is put on screen with its error, printed only
     on a page that came back with one (every other page of Account settings stays as small as before, 4.3):
     - a field of the page: the focus without a scroll, then its whole field block (label, field, help, error) in the
       middle of what the bars leave free. The bars that stay on screen at the top (the header, the phone strip) and at the
       bottom (the studio tab bar, the save bar above it) are found from the edges of the screen inward (edge(): the
       fixed or sticky box under a point, then the next one under it). A plain focus() scrolled a field only as far as
       the screen's edge in Safari (iPhone, iPad): the field stayed under the tab bar or the save bar and its message
       below the screen. A block taller than the free space starts under the top bars. Once the page's pictures and fonts
       are in (window load) it is put back once more, unless the member has touched, scrolled with the wheel or typed;
     - a field of a sheet (the password of "Delete your account?" and "Sign out all other devices?", the "I understand"
       tick): the sheet's own script opens the sheet again and Bootstrap then focuses the sheet itself, so the field gets
       the focus once the sheet is shown (shown.bs.modal) and the sheet's body scrolls to show its block. --}}
@if($errors->any() && ! $errors->has('view_only_demo'))
@pushOnce('scripts')
@php ob_start(); @endphp
<script>
    window.avdRevealRefused = window.avdRevealRefused || (function () {
        var edge = function (fromTop, x) {
            var at = fromTop ? 0 : window.innerHeight;
            for (var i = 0; i < 4; i++) {
                var y = fromTop ? at + 1 : at - 1;
                var bar = null;
                for (var el = y > 0 && y < window.innerHeight ? document.elementFromPoint(x, y) : null; el && el !== document.body; el = el.parentElement) {
                    var position = getComputedStyle(el).position;
                    if (position === 'fixed' || position === 'sticky') { bar = el; break; }
                }
                var next = bar ? bar.getBoundingClientRect()[fromTop ? 'bottom' : 'top'] : at;
                if (fromTop ? next <= at : next >= at) { break; }
                at = next;
            }
            return at;
        };
        var place = function (field) {
            var box = (field.closest('.mk-field') || field).getBoundingClientRect();
            var x = Math.min(Math.max(box.left + box.width / 2, 1), window.innerWidth - 1);
            var top = edge(true, x);
            var room = edge(false, x) - top;
            var delta = box.height + 16 <= room ? box.top + box.height / 2 - top - room / 2 : box.top - top - 8;
            if (Math.abs(delta) >= 1) { window.scrollBy(0, delta); }
        };
        return function (field) {
            var sheet = field.closest('.modal');
            if (sheet) {
                var show = function () {
                    field.focus({preventScroll: true});
                    var body = field.closest('.mk-sheet__body');
                    var block = (field.closest('.mk-field') || field).getBoundingClientRect();
                    var frame = body ? body.getBoundingClientRect() : null;
                    if (frame && block.bottom > frame.bottom) { body.scrollTop += block.bottom - frame.bottom; }
                    if (frame && block.top < frame.top) { body.scrollTop -= frame.top - block.top; }
                };
                sheet.addEventListener('shown.bs.modal', show, {once: true});
                setTimeout(function () { if (document.activeElement === sheet) { show(); } }, 1500);
                return;
            }
            field.focus({preventScroll: true});
            (field.closest('.mk-field') || field).scrollIntoView({block: 'center', inline: 'nearest'});
            place(field);
            if (document.readyState !== 'complete') {
                var touched = false;
                ['pointerdown', 'touchstart', 'wheel', 'keydown'].forEach(function (type) { window.addEventListener(type, function () { touched = true; }, {once: true, passive: true}); });
                window.addEventListener('load', function () { if (!touched && document.activeElement === field) { place(field); } }, {once: true});
            }
        };
    })();
</script>
@php echo \App\Http\Controllers\User\ProfileController::compactHtml((string) ob_get_clean()); @endphp
@endPushOnce
@endif
@endsection
