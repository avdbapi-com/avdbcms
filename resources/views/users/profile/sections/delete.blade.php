{{--
    Account settings > Delete account (member design 6.9; mockup account-delete, its dialog in every pop-up window
    style): a neutral danger panel with what goes (account-43: Watch later and Liked videos named, not counted),
    "Download my data" (OD-M6) and "Delete account…", which asks in a dialog for the password and an "I understand"
    tick (account-17); a wrong password or a missing tick brings the dialog back with the message under its field.
--}}
@php
    $sheet = old('_sheet');
    $dialogError = $sheet === 'delete' && ($errors->has('current_password') || $errors->has('understand'));
@endphp

@include('users.profile.partials.notices', ['fields' => ['current_password', 'understand']])

<section class="mk-panel mk-panel--danger" aria-labelledby="hDelete">
    <div class="mk-panel__head">
        <h2 class="mk-panel__title" id="hDelete"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>{{ __('Delete your account') }}</h2>
        <p class="mk-panel__sub">{{ __('Your account is closed at once and hidden from everyone. The rest is removed in the next minutes.') }}</p>
    </div>
    <div class="mk-panel__body mk-delete-body">
        <ul class="mk-facts">
            <li><i class="fa-solid fa-clapperboard" aria-hidden="true"></i>{{ trans_choice('{0} No videos|{1} :count video, with its comments and likes|[2,*] :count videos, with their comments and likes', $counts['videos'], ['count' => \App\Support\LocalNumber::format($counts['videos'])]) }}</li>
            <li><i class="fa-solid fa-list" aria-hidden="true"></i>{{ trans_choice('{0} Your Watch later and Liked videos lists|{1} :count playlist, and your Watch later and Liked videos lists|[2,*] :count playlists, and your Watch later and Liked videos lists', $counts['playlists'], ['count' => \App\Support\LocalNumber::format($counts['playlists'])]) }}</li>
            <li><i class="fa-regular fa-comment" aria-hidden="true"></i>{{ trans_choice('{0} No comments|{1} :count comment you wrote|[2,*] :count comments you wrote', $counts['comments'], ['count' => \App\Support\LocalNumber::format($counts['comments'])]) }}</li>
            <li><i class="fa-solid fa-users" aria-hidden="true"></i>{{ trans_choice('{0} Your subscriptions|{1} Your :count subscriber and your subscriptions|[2,*] Your :count subscribers and your subscriptions', $counts['subscribers'], ['count' => \App\Support\LocalNumber::format($counts['subscribers'])]) }}</li>
        </ul>
        <div class="mk-inline-actions">
            <a class="mk-btn mk-btn--secondary" href="{{ route('user.export') }}"><i class="fa-solid fa-download" aria-hidden="true"></i><span>{{ __('Download my data') }}</span></a>
            <button type="button" class="mk-btn mk-btn--danger-quiet" data-bs-toggle="modal" data-bs-target="#mk-delete-dialog">
                <i class="fa-solid fa-trash-can" aria-hidden="true"></i><span>{{ __('Delete account…') }}</span>
            </button>
        </div>
        <p class="mk-help">{{ __('The download is a file with your profile, channel settings, videos list, playlists, comments, subscriptions and invoices.') }}</p>
    </div>
</section>

@push('mk-account-dialogs')
    <div class="modal fade" id="mk-delete-dialog" tabindex="-1" aria-labelledby="mkDeleteTitle" aria-hidden="true"
         x-data x-init="if (@js($dialogError)) { $nextTick(() => window.bootstrap && window.bootstrap.Modal.getOrCreateInstance($el).show()); }">
        <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
            <form class="modal-content avd-mk" method="POST" action="{{ route('user.delete') }}">
                @csrf
                @method('DELETE')
                <input type="hidden" name="_sheet" value="delete">
                <div class="mk-sheet__grip" aria-hidden="true"></div>
                <div class="mk-sheet__head">
                    <h2 class="mk-sheet__title" id="mkDeleteTitle">{{ __('Delete your account?') }}</h2>
                    <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" title="{{ __('Close') }}" aria-label="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
                <div class="mk-sheet__body">
                    <div class="mk-alert mk-alert--danger" role="alert">
                        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                        <div class="mk-alert__body"><span>{{ __('You cannot undo this. Download your data first if you want to keep it.') }}</span></div>
                        <span></span>
                    </div>
                    <div class="mk-form">
                        <div class="mk-field">
                            <label class="mk-label" for="delete_password">{{ __('Your password') }}</label>
                            <input class="mk-input" id="delete_password" type="password" name="current_password" required autocomplete="current-password"
                                   @if($sheet === 'delete' && $errors->has('current_password')) aria-invalid="true" aria-describedby="delete_password-error" @endif>
                            @if($sheet === 'delete' && $errors->has('current_password'))
                                <span class="mk-error" id="delete_password-error">{{ $errors->first('current_password') }}</span>
                            @endif
                        </div>
                        <div class="mk-field">
                            <label class="mk-option">
                                <input class="mk-check" type="checkbox" name="understand" value="1" required @checked(old('understand'))
                                       @if($errors->has('understand')) aria-invalid="true" aria-describedby="understand-error" @endif>
                                <span class="mk-option__text">
                                    <span>{{ $counts['videos'] > 0
                                        ? trans_choice('{1} I understand that my video is deleted too.|[2,*] I understand that my :count videos are deleted too.', $counts['videos'], ['count' => \App\Support\LocalNumber::format($counts['videos'])])
                                        : __('I understand that this cannot be undone.') }}</span>
                                </span>
                            </label>
                            @include('users.profile.partials.field-error', ['field' => 'understand'])
                        </div>
                    </div>
                </div>
                <div class="mk-sheet__foot">
                    <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal"><span>{{ __('Cancel') }}</span></button>
                    <button type="submit" class="mk-btn mk-btn--danger"><i class="fa-solid fa-trash-can" aria-hidden="true"></i><span>{{ __('Delete my account') }}</span></button>
                </div>
            </form>
        </div>
    </div>
@endpush
