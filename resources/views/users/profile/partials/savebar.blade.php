{{-- The save bar of a section form (member design 5.9, 4.4): shown only while the form has unsaved changes,
     fixed at the bottom of the main column (above the studio tab bar on phones); the page root mirrors it on
     html[data-mk-bar="save"] so "Back to top" and the toasts float above it. Cancel puts the stored values back. --}}
<div class="mk-savebar" x-show="dirty" x-cloak>
    <span class="mk-savebar__note"><span class="mk-dot" aria-hidden="true"></span>{{ __('Unsaved changes') }}</span>
    <button type="button" class="mk-btn mk-btn--quiet" @click="cancel($el.closest('form'))"><span>{{ __('Cancel') }}</span></button>
    <button type="submit" class="mk-btn mk-btn--primary" :aria-busy="saving ? 'true' : 'false'">
        <span class="mk-spinner" aria-hidden="true" x-show="saving" x-cloak></span>
        <i class="fa-solid fa-check" aria-hidden="true" x-show="!saving"></i>
        <span>{{ $label }}</span>
    </button>
</div>
