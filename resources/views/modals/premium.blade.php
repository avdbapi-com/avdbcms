{{--
    Welcome to Premium (member design 6.16; mockups premium-welcome, premium-welcome__modal-*): the kit's small dialog
    (.mk-dialog.mk-prompt, 420px) in the look's "Pop-up window style", a bottom sheet on phones; an icon tile instead of
    the old stock picture. Its host is /premium?checkout=success (Stripe's success_url and the PayPal "active" return), a
    page without ads and without the popunder (OD-M10). The member's address is printed as text (security plan S16).
    Closing it takes ?checkout=success out of the address, so a reload does not open it again.
--}}
@use('Illuminate\Support\Facades\Auth')
@php
    $welcomeEmail = (string) (Auth::user()?->email ?? '');
@endphp
<div class="modal fade" tabindex="-1" id="premium" aria-labelledby="premiumWelcomeTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mk-dialog mk-prompt">
        <div class="modal-content avd-mk">
            <div class="mk-sheet__grip" aria-hidden="true"></div>
            <div class="mk-sheet__head">
                <h2 class="mk-sheet__title" id="premiumWelcomeTitle">{{ __('Welcome to Premium') }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" title="{{ __('Close') }}" aria-label="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mk-sheet__body">
                <div class="mk-empty mk-empty--compact mk-welcome">
                    <span class="mk-empty__icon" aria-hidden="true"><i class="fa-solid fa-star"></i></span>
                    <p class="mk-empty__text">{!! __('Premium is on. Ads are gone and you can upload without limits. A receipt is on its way to :email.', ['email' => '<span class="fw-bold">'.e($welcomeEmail).'</span>']) !!}</p>
                    <p class="mk-help">{!! __('Your plan and your invoices are in <a href=":url">Account settings</a>.', ['url' => e(route('user.edit'))]) !!}</p>
                </div>
            </div>
            <div class="mk-sheet__foot">
                <a class="mk-btn mk-btn--secondary" href="{{ \App\Http\Controllers\User\ProfileController::sectionUrl('premium') }}"><span>{{ __('See my plan') }}</span></a>
                <button type="button" class="mk-btn mk-btn--primary" data-bs-dismiss="modal"><span>{{ __('Done') }}</span></button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var sheet = document.getElementById('premium');

        if (!sheet || !window.bootstrap || !window.bootstrap.Modal) {
            return;
        }

        sheet.addEventListener('hidden.bs.modal', function () {
            try {
                var url = new URL(window.location.href);

                if (url.searchParams.get('checkout') === 'success' || url.searchParams.has('premium')) {
                    url.searchParams.delete('checkout');
                    url.searchParams.delete('premium');
                    url.searchParams.delete('wait');
                    window.history.replaceState(window.history.state, '', url.pathname + (url.search ? url.search : '') + url.hash);
                }
            } catch (e) {}

            // No control opened the sheet (the page did): the keyboard focus goes to the page's heading, never to <body>
            // (R3-014: the next Tab went to the footer). The heading takes it for that moment only.
            var heading = document.querySelector('.avd-premium-page h1');
            var active = document.activeElement;
            if (heading && (!active || active === document.body || sheet.contains(active))) {
                if (!heading.hasAttribute('tabindex')) {
                    heading.setAttribute('tabindex', '-1');
                    heading.addEventListener('blur', function () { heading.removeAttribute('tabindex'); }, { once: true });
                }
                heading.focus();
            }
        }, { once: true });

        window.bootstrap.Modal.getOrCreateInstance(sheet).show();
    });
</script>
