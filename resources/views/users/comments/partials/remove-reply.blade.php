{{-- "Remove this reply?" (member design 6.6/6.8): the confirmation of a reply's trash button in the replies
     sheet and on the replies page. Its form posts to the button's data-action (users/partials/studio-scripts). --}}
<div class="modal fade" id="mk-remove-reply" tabindex="-1" aria-labelledby="mk-remove-reply-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
        <div class="modal-content avd-mk">
            <div class="mk-sheet__head">
                <h2 class="mk-sheet__title" id="mk-remove-reply-title">{{ __('Remove this reply?') }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mk-sheet__body"><p class="mk-sheet__lead mk-clamp-3" data-mk-title dir="auto"></p><p class="mk-muted">{{ __('The reply is removed from your video. You can\'t undo this.') }}</p></div>
            <div class="mk-sheet__foot">
                <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                <button type="submit" class="mk-btn mk-btn--danger" form="mk-remove-reply-form"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Remove reply') }}</span></button>
            </div>
            <form id="mk-remove-reply-form" method="POST" action="" data-mk-action-from hidden>@csrf @method('DELETE')</form>
        </div>
    </div>
</div>
