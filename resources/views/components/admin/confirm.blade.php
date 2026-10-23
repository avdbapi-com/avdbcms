{{--
    The confirm dialog (window.adminConfirm and data-ad-confirm, resources/admin/modules/confirm.ts): the title
    is the question, the body the consequence, Cancel has the focus, the action button is named after the
    action; type-to-confirm for wipes. The shell renders it once.
--}}
@use('App\Support\AdminTheme')
<dialog id="ad-confirm" class="ad-dialog" aria-labelledby="ad-confirm-title" aria-describedby="ad-confirm-body">
    <div class="ad-dialog-b">
        <span class="ad-sym is-danger" aria-hidden="true">{{ AdminTheme::icon('alert') }}</span>
        <div class="ad-dialog-text">
            <h2 id="ad-confirm-title" data-ad-confirm-title>{{ __('Are you sure you want to continue?') }}</h2>
            <p id="ad-confirm-body" data-ad-confirm-body hidden></p>
            <div class="ad-dialog-type" data-ad-confirm-typebox hidden>
                <label for="ad-confirm-input">{{ __('To confirm, type this word:') }} <b data-ad-confirm-word></b></label>
                <input id="ad-confirm-input" class="ad-input" type="text" autocomplete="off" spellcheck="false" data-ad-confirm-input>
            </div>
        </div>
    </div>
    <div class="ad-dialog-f">
        <button type="button" class="ad-btn" data-ad-confirm-cancel autofocus>{{ __('Cancel') }}</button>
        <button type="button" class="ad-btn is-danger-solid" data-ad-confirm-accept>{{ __('Confirm') }}</button>
    </div>
</dialog>
