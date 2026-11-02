{{-- Account settings > Notifications (member design 6.14; mockup account-notifications). A partial inside the
     Account settings page: @include('users.profile.parts.notifications') in the Notifications section. It is
     self-contained: its own form (PUT user.notifications.settings), its own save bar (shown only with unsaved changes,
     mirrored on html[data-mk-bar] for the layers above it, 4.4), the view-only demo's refusal as an info alert at the top
     (6.18). Four switches; "Upload results" (only where members may upload) and "Account e-mails" are always on
     (locked). The per-channel bell of each
     Subscribe button wins over "New videos from channels I follow" (the help text says so). --}}
@php
    $notifyUser = auth()->user();
    $notifySettings = \App\Models\NotificationSetting::for($notifyUser);
    $notifySwitches = [
        'new_subscribers' => [__('New subscribers'), __('When someone subscribes to your channel.')],
        'comments' => [__('Comments on my videos'), __('Includes replies under your videos.')],
        'replies' => [__('Replies to my comments'), __('When someone answers you on another channel.')],
        'new_videos' => [__('New videos from channels I follow'), __('Each channel can also be set with the bell on its Subscribe button: All, Only highlights or None. That choice wins over this one.')],
    ];
    // The view-only demo's refusal: the Account settings page prints it above this partial (its partials/notices, with
    // the one-time messages); the partial prints it itself only where no such notices exist (one alert, never two).
    $notifyRefused = view()->exists('users.profile.partials.notices') ? null : $errors->first('view_only_demo');
@endphp
<form method="POST" action="{{ locale_url(route('user.notifications.settings')) }}" class="mk-notify-settings" id="notification-settings"
      x-data="{ dirty: false }"
      x-effect="document.documentElement.dataset.mkBar = dirty ? 'save' : ''"
      @change="dirty = true"
      @submit="dirty = false"
      @beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = ''; }">
    @csrf
    @method('PUT')
    <section class="mk-panel" aria-labelledby="notify-settings-title">
        <div class="mk-panel__head">
            <h2 class="mk-panel__title" id="notify-settings-title">{{ __('Notifications') }}</h2>
            <p class="mk-panel__sub">{{ __('What appears under the bell. Changes apply to new notifications.') }}</p>
        </div>
        <div class="mk-panel__body">
            @if($notifyRefused)
                <div class="mk-alert" role="alert" tabindex="-1" x-init="$el.focus()">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <div class="mk-alert__body"><span class="mk-alert__title">{{ __('Not saved') }}</span><span>{{ $notifyRefused }}</span></div>
                    <span></span>
                </div>
            @endif
            <div class="mk-form">
                @foreach($notifySwitches as $notifyKey => [$notifyLabel, $notifyHelp])
                    <input type="hidden" name="{{ $notifyKey }}" value="0">
                    <label class="mk-option mk-option--switch">
                        <input class="mk-switch" type="checkbox" role="switch" name="{{ $notifyKey }}" value="1" @checked($notifySettings[$notifyKey] ?? true)>
                        <span class="mk-option__text"><span>{{ $notifyLabel }}</span><span class="mk-option__help">{{ $notifyHelp }}</span></span>
                    </label>
                @endforeach
                <div class="mk-divider-text">{{ __('Always on') }}</div>
                @if(\App\Support\Studio\StudioSettings::uploadsAllowed())
                {{-- only where members may upload (judge R1-143): the same switch as the header's and the panel's upload --}}
                <label class="mk-option mk-option--switch is-locked">
                    <input class="mk-switch" type="checkbox" role="switch" checked disabled>
                    <span class="mk-option__text"><span>{{ __('Upload results') }}</span><span class="mk-option__help">{{ __('When a video is ready or failed. You need these to manage your videos.') }}</span></span>
                </label>
                @endif
                <label class="mk-option mk-option--switch is-locked">
                    <input class="mk-switch" type="checkbox" role="switch" checked disabled>
                    <span class="mk-option__text"><span>{{ __('Account e-mails') }}</span><span class="mk-option__help">{{ __('Password changes, a new e-mail address, Premium receipts.') }}</span></span>
                </label>
            </div>
        </div>
    </section>
    <div class="mk-savebar" x-show="dirty" style="display:none" role="region" aria-label="{{ __('Unsaved changes') }}">
        <span class="mk-savebar__note"><span class="mk-dot" aria-hidden="true"></span>{{ __('Unsaved changes') }}</span>
        <button type="reset" class="mk-btn mk-btn--quiet" @click="$nextTick(() => { dirty = false })"><span>{{ __('Cancel') }}</span></button>
        <button type="submit" class="mk-btn mk-btn--primary"><i class="fa-solid fa-check" aria-hidden="true"></i><span>{{ __('Save notification settings') }}</span></button>
    </div>
</form>
