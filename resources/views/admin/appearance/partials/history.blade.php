{{--
    History panel of the Appearance pages: the page script fills it from GET /admin/appearance/history (the
    "History" button links to the same address as a page when scripts are off).
--}}
<dialog class="ad-dialog ad-history" id="ad-history" aria-labelledby="ad-history-title" data-ad-history>
    <div class="ad-dialog-b">
        <div class="ad-dialog-text">
            <h2 id="ad-history-title">{{ __('History') }}</h2>
            <p>{{ __('The last 20 changes of your design. Undo puts your site back to how it looked before that change.') }}</p>
            <ol class="ad-history-list" data-ad-history-list aria-live="polite"></ol>
        </div>
    </div>
    <div class="ad-dialog-f">
        <button type="button" class="ad-btn" data-ad-history-close autofocus>{{ __('Close') }}</button>
    </div>
</dialog>
