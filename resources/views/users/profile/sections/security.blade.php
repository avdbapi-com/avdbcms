{{--
    Account settings > Sign-in and security (member design 6.9; mockup account-security): the password (with a
    strength meter and "Sign out of all other devices", ticked: account-18), the sign-in services this site has set up
    (account-13, account-46: neutral rows with the services' own marks) and the devices signed in, with "Sign out all
    other devices" behind the password (account-23, account-39).
--}}
@php
    $sheet = old('_sheet');
    $passwordError = $errors->first('current_password');
    $signoutHasError = $sheet === 'signout' && $passwordError !== '';
    $passwordFormError = $sheet !== 'signout' && $passwordError !== '';
    $strengthWords = [
        'short' => __('Too short: use at least :count characters.', ['count' => $passwordMin]),
        'weak' => __('Weak: add numbers or symbols.'),
        'fair' => __('Fair: a longer password is safer.'),
        'strong' => __('Strong.'),
        'very' => __('Very strong.'),
    ];
@endphp

@include('users.profile.partials.notices', ['fields' => ['current_password', 'new_password', 'new_password_confirmation']])

<section class="mk-panel" aria-labelledby="hPassword">
    <div class="mk-panel__head">
        <h2 class="mk-panel__title" id="hPassword">{{ __('Password') }}</h2>
        <p class="mk-panel__sub">{{ __('Use a long password you do not use anywhere else.') }}</p>
    </div>
    <div class="mk-panel__body">
        <form method="POST" action="{{ route('user.update.password') }}" class="mk-form" autocomplete="on">
            @csrf
            <input type="hidden" name="_sheet" value="password">
            <input type="text" name="username" value="{{ $user->email }}" autocomplete="username" hidden aria-hidden="true" tabindex="-1">

            <div class="mk-field" x-data="{ shown: false }">
                <label class="mk-label" for="current_password">{{ __('Current password') }}</label>
                <div class="mk-group">
                    <input class="mk-input" id="current_password" name="current_password" :type="shown ? 'text' : 'password'" type="password" required autocomplete="current-password"
                           @if($passwordFormError) aria-invalid="true" aria-describedby="current_password-error" @endif>
                    <button type="button" class="mk-group__addon mk-group__addon--end mk-group__eye" @click="shown = !shown"
                            :aria-pressed="shown ? 'true' : 'false'" aria-pressed="false"
                            :aria-label="shown ? @js(__('Hide password')) : @js(__('Show password'))" aria-label="{{ __('Show password') }}">
                        <i class="fa-regular" :class="shown ? 'fa-eye-slash' : 'fa-eye'" aria-hidden="true"></i>
                    </button>
                </div>
                @if($passwordFormError)
                    <span class="mk-error" id="current_password-error">{{ $passwordError }}</span>
                @endif
            </div>

            <div class="mk-form__row mk-form__row--2">
                <div class="mk-field"
                     x-data="{
                        shown: false,
                        value: '',
                        min: {{ $passwordMin }},
                        words: @js($strengthWords),
                        get level() {
                            const v = this.value;
                            if (!v.length) return 0;
                            if (v.length < this.min) return 1;
                            let score = 1;
                            if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
                            if (/[0-9]/.test(v)) score++;
                            if (/[^A-Za-z0-9]/.test(v)) score++;
                            if (v.length >= 12) score++;
                            return Math.min(4, score);
                        },
                        get word() {
                            const v = this.value;
                            if (!v.length) return '';
                            if (v.length < this.min) return this.words.short;
                            return [this.words.weak, this.words.weak, this.words.fair, this.words.strong, this.words.very][this.level];
                        }
                     }">
                    <label class="mk-label" for="new_password">{{ __('New password') }}</label>
                    <div class="mk-group">
                        <input class="mk-input" id="new_password" name="new_password" :type="shown ? 'text' : 'password'" type="password" required
                               minlength="{{ $passwordMin }}" autocomplete="new-password" x-model="value"
                               aria-describedby="new_password-strength{{ $errors->has('new_password') ? ' new_password-error' : '' }}"
                               @if($errors->has('new_password')) aria-invalid="true" @endif>
                        <button type="button" class="mk-group__addon mk-group__addon--end mk-group__eye" @click="shown = !shown"
                                :aria-pressed="shown ? 'true' : 'false'" aria-pressed="false"
                                :aria-label="shown ? @js(__('Hide password')) : @js(__('Show password'))" aria-label="{{ __('Show password') }}">
                            <i class="fa-regular" :class="shown ? 'fa-eye-slash' : 'fa-eye'" aria-hidden="true"></i>
                        </button>
                    </div>
                    <span class="mk-help mk-strength-line" id="new_password-strength" aria-live="polite">
                        <span class="mk-strength" :data-level="level" data-level="0" aria-hidden="true"><span></span><span></span><span></span><span></span></span>
                        <span x-text="word"></span>
                    </span>
                    @if($strongPassword)
                        <span class="mk-help">{{ __('Use upper and lower case letters, a number and a symbol.') }}</span>
                    @endif
                    @include('users.profile.partials.field-error', ['field' => 'new_password'])
                </div>
                <div class="mk-field">
                    <label class="mk-label" for="new_password_confirmation">{{ __('Confirm new password') }}</label>
                    <input class="mk-input" id="new_password_confirmation" name="new_password_confirmation" type="password" required autocomplete="new-password">
                </div>
            </div>

            <label class="mk-option">
                <input class="mk-check" type="checkbox" name="sign_out_others" value="1" checked>
                <span class="mk-option__text">
                    <span>{{ __('Sign out of all other devices') }}</span>
                    <span class="mk-option__help">{{ __('Recommended. Your other phones, computers and apps will ask for the new password.') }}</span>
                </span>
            </label>

            <div>
                <button type="submit" class="mk-btn mk-btn--primary"><i class="fa-solid fa-key" aria-hidden="true"></i><span>{{ __('Change password') }}</span></button>
            </div>
        </form>
    </div>
</section>

@if($providers !== [])
    <section class="mk-panel" aria-labelledby="hConnected">
        <div class="mk-panel__head">
            <h2 class="mk-panel__title" id="hConnected">{{ __('Connected accounts') }}</h2>
            <p class="mk-panel__sub">{{ __('Sign in with these as well as your password. Only the services this site has set up are listed.') }}</p>
        </div>
        <ul class="mk-rows">
            @foreach($providers as $provider)
                @php($providerName = $provider['service'] === 'google' ? 'Google' : 'Facebook')
                <li class="mk-item">
                    <span class="mk-provider" aria-hidden="true">
                        @if($provider['service'] === 'google')
                            <svg viewBox="0 0 48 48" focusable="false"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                        @else
                            <i class="fa-brands fa-facebook mk-provider__facebook"></i>
                        @endif
                    </span>
                    <div class="mk-item__body">
                        <span class="mk-item__title">{{ $providerName }}</span>
                        <span class="mk-muted mk-item__meta">{{ $provider['linked'] ? __('Connected') : __('Not connected') }}</span>
                    </div>
                    <div class="mk-item__aside">
                        @if($provider['linked'])
                            <form method="POST" action="{{ route('oauth.unlink', ['service' => $provider['service']]) }}">
                                @csrf
                                <button type="submit" class="mk-btn mk-btn--quiet mk-btn--sm" aria-label="{{ __('Disconnect :service', ['service' => $providerName]) }}"><span>{{ __('Disconnect') }}</span></button>
                            </form>
                        @elseif($provider['available'])
                            <a class="mk-btn mk-btn--secondary mk-btn--sm" href="{{ route('oauth.connect', ['service' => $provider['service']]) }}" aria-label="{{ __('Connect :service', ['service' => $providerName]) }}"><span>{{ __('Connect') }}</span></a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@endif

<section class="mk-panel" aria-labelledby="hDevices"
         x-data="{
            message: '',
            async signOut(button, url) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                let text = @js(__('That did not work. Please try again.'));
                try {
                    const token = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
                    const answer = await fetch(url, { method: 'DELETE', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    if (answer.ok) {
                        button.closest('li')?.remove();
                        this.message = @js(__('That device is signed out.'));
                        return;
                    }
                    try { const body = await answer.json(); if (body && body.message) text = body.message; } catch (e) {}
                } catch (e) {}
                this.message = text;
                button.disabled = false;
                button.setAttribute('aria-busy', 'false');
            }
         }">
    <div class="mk-panel__head">
        <h2 class="mk-panel__title" id="hDevices">{{ __('Devices') }}</h2>
        <button type="button" class="mk-btn mk-btn--secondary mk-btn--sm mk-nowrap mk-panel__action" data-bs-toggle="modal" data-bs-target="#mk-signout-dialog">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            <span>{{ __('Sign out all other devices') }}</span>
        </button>
        <p class="mk-panel__sub">{{ __('Where your account is signed in.') }}</p>
    </div>
    <ul class="mk-rows">
        <li class="mk-item">
            <span class="mk-attn__icon mk-attn__icon--muted" aria-hidden="true"><i class="fa-solid {{ $thisDevice['icon'] }}"></i></span>
            <div class="mk-item__body">
                <span class="mk-item__title">{{ $thisDevice['name'] }} <span class="mk-badge mk-badge--neutral">{{ __('This device') }}</span></span>
                <span class="mk-muted mk-item__meta">{{ __('Active now') }}</span>
            </div>
            <div class="mk-item__aside"></div>
        </li>
        @foreach($signIns as $signIn)
            <li class="mk-item">
                <span class="mk-attn__icon mk-attn__icon--muted" aria-hidden="true"><i class="fa-solid {{ $signIn['icon'] }}"></i></span>
                @if($signIn['kind'] === 'app')
                    <div class="mk-item__body">
                        <span class="mk-item__title">{{ $signIn['name'] }} <span class="mk-badge mk-badge--neutral">{{ __('App') }}</span></span>
                        <span class="mk-muted mk-item__meta">
                            {{ $signIn['used'] ? __('Last used :time', ['time' => $signIn['time']->diffForHumans()]) : __('Signed in :time', ['time' => $signIn['time']?->diffForHumans()]) }}
                        </span>
                    </div>
                    <div class="mk-item__aside">
                        <button type="button" class="mk-btn mk-btn--quiet mk-btn--sm"
                                aria-label="{{ __('Sign out :device', ['device' => $signIn['name']]) }}"
                                @click="signOut($el, @js(route('devices.delete', $signIn['device'])))"><span>{{ __('Sign out') }}</span></button>
                    </div>
                @else
                    <div class="mk-item__body">
                        <span class="mk-item__title">{{ $signIn['name'] }}</span>
                        <span class="mk-muted mk-item__meta">{{ $signIn['time'] ? __('Active :time', ['time' => $signIn['time']->diffForHumans()]) : __('A browser') }}</span>
                    </div>
                    <div class="mk-item__aside"></div>
                @endif
            </li>
        @endforeach
    </ul>
    @if($moreSignIns > 0)
        {{-- the rest of a long list (page-content budget, 4.3): how many, where to see them, and what ends them all --}}
        <p class="mk-help mk-panel__note" data-more-sign-ins="{{ $moreSignIns }}">
            {{ trans_choice('{1} :count more sign-in is not shown here.|[2,*] :count more sign-ins are not shown here.', $moreSignIns, ['count' => $moreSignIns]) }}
            {{ __('“Sign out all other devices” ends every one of them.') }}
            @unless($allSignInsShown)
                <a href="{{ locale_url(route('user.edit', ['section' => 'security', 'devices' => 'all'])) }}#hDevices">{{ __('Show all') }}</a>
            @endunless
        </p>
    @endif
    <p class="mk-help mk-panel__note" role="status" aria-live="polite" x-text="message" x-show="message !== ''" x-cloak></p>
</section>

@unless(config('site.allow_account_deletion', true))
    <section class="mk-panel" aria-labelledby="hYourData">
        <div class="mk-panel__head">
            <h2 class="mk-panel__title" id="hYourData">{{ __('Your data') }}</h2>
            <p class="mk-panel__sub">{{ __('A file with your profile, channel settings, videos list, playlists, comments, subscriptions and invoices.') }}</p>
        </div>
        <div class="mk-panel__body">
            <a class="mk-btn mk-btn--secondary" href="{{ route('user.export') }}"><i class="fa-solid fa-download" aria-hidden="true"></i><span>{{ __('Download my data') }}</span></a>
        </div>
    </section>
@endunless

@push('mk-account-dialogs')
    <div class="modal fade" id="mk-signout-dialog" tabindex="-1" aria-labelledby="mkSignoutTitle" aria-hidden="true"
         x-data x-init="if (@js($signoutHasError)) { $nextTick(() => window.bootstrap && window.bootstrap.Modal.getOrCreateInstance($el).show()); }">
        <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
            <form class="modal-content avd-mk" method="POST" action="{{ route('user.devices.sign-out-others') }}">
                @csrf
                <input type="hidden" name="_sheet" value="signout">
                <div class="mk-sheet__grip" aria-hidden="true"></div>
                <div class="mk-sheet__head">
                    <h2 class="mk-sheet__title" id="mkSignoutTitle">{{ __('Sign out all other devices?') }}</h2>
                    <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" title="{{ __('Close') }}" aria-label="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
                <div class="mk-sheet__body">
                    <p class="mk-sheet__lead">{{ __('Every other phone, computer and app is signed out. This device stays signed in.') }}</p>
                    <div class="mk-form">
                        <div class="mk-field">
                            <label class="mk-label" for="signout_password">{{ __('Your password') }}</label>
                            <input class="mk-input" id="signout_password" type="password" name="current_password" required autocomplete="current-password"
                                   @if($signoutHasError) aria-invalid="true" aria-describedby="signout_password-error" @endif>
                            @if($signoutHasError)
                                <span class="mk-error" id="signout_password-error">{{ $passwordError }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="mk-sheet__foot">
                    <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal"><span>{{ __('Cancel') }}</span></button>
                    <button type="submit" class="mk-btn mk-btn--primary"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i><span>{{ __('Sign out other devices') }}</span></button>
                </div>
            </form>
        </div>
    </div>
@endpush
