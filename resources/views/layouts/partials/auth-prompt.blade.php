{{-- The guest prompt #avdAuthPromptModal (Watch later and the other personal actions of a guest; member design 6.13,
     auth-25): a small dialog in the kit's dialog skin (.mk-dialog.mk-prompt, 420px), which follows the look's "Pop-up
     window style" like every dialog of the site (a side sheet in the sliding-sheet looks, centred in the others) and is
     a bottom sheet on phones. Its actions sit right under its text in every style, and its close button is visible in
     every look (it is a kit icon button, not Bootstrap's white "X"). It opens mostly on PUBLIC pages, where a guest has
     only the main CSS: its rules are in member/_shared.scss.
     resources/js/layout/core.ts (window.avdShowAuthPrompt) fills #avdAuthPromptTitle, #avdAuthPromptMessage and the icon
     class of #avdAuthPromptIcon for the action that was refused; the ids stay as they are.
     "Sign in" brings the visitor back to this page (?redirect=, auth-8); "Create account" only while sign-ups are open. --}}
<div class="modal fade" id="avdAuthPromptModal" tabindex="-1" aria-labelledby="avdAuthPromptTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mk-dialog mk-prompt avd-mk">
        <div class="modal-content">
            <div class="mk-sheet__head">
                <span class="mk-sheet__grip mk-prompt__grip" aria-hidden="true"></span>
                <h2 class="mk-sheet__title" id="avdAuthPromptTitle">{{ __('Sign in to save videos') }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mk-sheet__body">
                <div class="mk-empty mk-empty--compact" style="padding:0">
                    <span class="mk-empty__icon" aria-hidden="true"><i class="fa-regular fa-clock" id="avdAuthPromptIcon"></i></span>
                    <p class="mk-empty__text" id="avdAuthPromptMessage">{{ __('Watch later keeps videos for you on every device. It takes a minute to create an account.') }}</p>
                </div>
            </div>
            <div class="mk-sheet__foot">
                @if(config('site.registration_enabled', true))
                    <a class="mk-btn mk-btn--secondary" href="{{ locale_url(route('registration')) }}"><span>{{ __('Create account') }}</span></a>
                @endif
                <a class="mk-btn mk-btn--primary" href="{{ \App\Http\Controllers\Auth\ReturnPath::loginUrl() }}" id="avdAuthPromptLoginBtn"><span>{{ __('Sign in') }}</span></a>
            </div>
        </div>
    </div>
</div>
