{{--
    Account settings > Profile (member design 6.9, 6.17, 6.18; mockups account-profile, account-phone,
    account-viewonly, account-crop): picture, username, channel address, e-mail, country, the phone number (only while
    the site can send text messages), language and theme. One form, one save bar ("Save changes").
--}}
@php
    $hostBase = preg_replace('#^https?://#', '', rtrim(url('/'), '/'));
    $uidSuffix = config('site.profile_uid_urls_enabled', true) && filled($user->profile_uid) ? '-'.strtolower((string) $user->profile_uid) : '';
    $usernameMin = (int) config('validation.user.username.min', 3);
    $usernameMax = (int) config('validation.user.username.max', 40);
    $slugMin = (int) config('validation.user.slug.min', 3);
    $slugMax = (int) config('validation.user.slug.max', 30);
    $emailRequired = (bool) config('site.email_verification_required', false);
    $pendingEmail = $user->getTemporaryEmailForVerification();
    $secondsUntil = function (mixed $next): int {
        return $next instanceof \DateTimeInterface && \Illuminate\Support\Carbon::instance($next)->isFuture()
            ? (int) ceil(now()->diffInSeconds($next, true))
            : 0;
    };
    $verifyWait = $secondsUntil(session("verification.send|{$user->id}:next"));
    $verifyUpdateWait = $secondsUntil(session("verification.send.update|{$user->id}:next"));
    $phoneWaiting = $user->waitForPhoneCode();
    $phoneVerified = $user->phone !== null && $user->hasVerifiedPhone();
    $phoneWait = max($secondsUntil(session("phone.send|{$user->id}:next")), $user->phoneCodeResendSeconds());
    // The country code the profile form sent when the page came back from it (its "section" field is in the old input).
    // The code form (phone.verify) sends a field named "code" too, the 5 digits, which is never a country: after a
    // refused code the list kept no country at all (R1-115).
    $phoneCountry = session()->hasOldInput('section') ? old('code', $user->phoneCountry()) : $user->phoneCountry();
    $phoneCountryRow = collect($phoneCodes)->firstWhere(0, $phoneCountry);
    $showLanguage = config('site.allow_language_switch', true) && count($languages) > 1;
    $showTheme = (bool) config('site.sidebar_theme_switcher_enabled', true) || (bool) config('site.footer_theme_switcher_enabled', true);
    $enhancedSelects = (bool) config('site.enhanced_selects_enabled', true);
    // The language the page is in (its address decides it): a save of other fields never switches the language the
    // member is reading, and what the list shows is what a save stores (the site's sign-in and language switch store
    // it the same way).
    $pageLanguage = app()->getLocale();
    $currentLanguage = old('language', array_key_exists($pageLanguage, $languages) ? $pageLanguage : (array_key_exists((string) $user->language, $languages) ? $user->language : $pageLanguage));
    $phoneUnchecked = $user->phone !== null && ! $phoneVerified && ! $phoneWaiting;
    $isUploadedAvatar = filled($user->avatar) && ! \App\Support\AvdbCreatorAvatarFactory::isGenerated($user->avatar);
    // the month and year in the language's own order ("2026年10月", "octubre de 2026"; BillingFormat::month, R2A-038)
    $joined = $user->created_at ? \App\Support\BillingFormat::month($user->created_at) : null;
    $selectedCountry = old('country', $user->country);
    // the codes of the country list as one string of two-letter codes ("ADAEAF..."), for the script below
    $countryCodes = implode('', array_filter(array_keys($countries), fn ($code): bool => is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1));
@endphp

@include('users.profile.partials.notices', ['fields' => ['username', 'slug', 'email', 'avatar', 'remove_avatar', 'country', 'language', 'phone', 'code', 'prefix', 'remove_phone']])

<form id="mk-profile-form" method="POST" action="{{ route('user.update') }}" enctype="multipart/form-data" data-mk-savebar
      class="mk-settings__form">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="profile">

    <section class="mk-panel" aria-labelledby="hProfile">
        <div class="mk-panel__head">
            <h2 class="mk-panel__title" id="hProfile">{{ __('Profile') }}</h2>
            <p class="mk-panel__sub">{{ __('How you appear on your channel, in comments and in the menu.') }}</p>
        </div>
        <div class="mk-panel__body">
            <div class="mk-form">
                <div class="mk-avatar-edit"
                     x-data="{ original: @js($user->avatar_url), src: @js($user->avatar_url) }"
                     @cropped-avatar.window="src = URL.createObjectURL($event.detail); dirty = true"
                     @mk-form-reset.window="src = original">
                    <img class="mk-avatar mk-avatar--96" :src="src" src="{{ $user->avatar_url }}" alt="" width="96" height="96" decoding="async">
                    <div class="mk-avatar-edit__text">
                        <div class="mk-avatar-edit__name">
                            <strong>{{ $user->username }}</strong>
                            <x-account-badge :user="$user" class="mk-account-badge" />
                        </div>
                        @if($joined)
                            <span class="mk-muted mk-avatar-edit__meta">{{ __('Joined :date', ['date' => $joined]) }}</span>
                        @endif
                        <div class="mk-avatar-edit__actions">
                            <label class="mk-btn mk-btn--secondary mk-btn--sm mk-file">
                                <i class="fa-solid fa-camera" aria-hidden="true"></i>
                                <span>{{ __('Change picture') }}</span>
                                <image-upload name="avatar" config="avatar" aria-label="{{ __('Change picture') }}"></image-upload>
                            </label>
                            @if($isUploadedAvatar)
                                <button type="submit" form="mk-remove-avatar" class="mk-btn mk-btn--quiet mk-btn--sm"><span>{{ __('Remove') }}</span></button>
                            @endif
                            @if($canSell && ! $user->has_current_subscription)
                                <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ locale_url(route('pages.premium')) }}"><i class="fa-regular fa-star" aria-hidden="true"></i><span>{{ __('Become premium') }}</span></a>
                            @endif
                        </div>
                        @include('users.profile.partials.field-error', ['field' => 'avatar'])
                    </div>
                </div>

                <div class="mk-form__row mk-form__row--2">
                    <div class="mk-field">
                        <label class="mk-label" for="username">{{ __('Username') }}</label>
                        @if($viewOnly)
                            {{-- The view-only demo (6.18): the stored name as read-only text that is never sent. The demo's
                                 page filter empties the value of every field NAMED like a user name (a credential in the
                                 admin), and an empty required field would stop the form before the friendly refusal. --}}
                            <input class="mk-input" id="username" type="text" value="{{ $user->username }}" readonly aria-describedby="username-help">
                        @else
                            <input class="mk-input" id="username" type="text" name="username" required
                                   value="{{ old('username', $user->username) }}"
                                   minlength="{{ $usernameMin }}" maxlength="{{ $usernameMax }}"
                                   autocomplete="username" spellcheck="false"
                                   aria-describedby="username-help{{ $errors->has('username') ? ' username-error' : '' }}"
                                   @if($errors->has('username')) aria-invalid="true" @endif>
                        @endif
                        <span class="mk-help" id="username-help">{{ __('Letters, numbers, dots, dashes and underscores, :min to :max characters.', ['min' => $usernameMin, 'max' => $usernameMax]) }}</span>
                        @include('users.profile.partials.field-error', ['field' => 'username'])
                    </div>
                    <div class="mk-field" x-data="{ slug: @js((string) old('slug', $user->slug)) }">
                        <label class="mk-label" for="slug">{{ __('Channel address') }}</label>
                        <div class="mk-group">
                            <span class="mk-group__addon" aria-hidden="true">@</span>
                            <input class="mk-input" id="slug" type="text" name="slug" required
                                   x-model="slug"
                                   value="{{ old('slug', $user->slug) }}"
                                   minlength="{{ $slugMin }}" maxlength="{{ $slugMax }}"
                                   pattern="[A-Za-z0-9._\-]+" spellcheck="false" autocapitalize="off"
                                   title="{{ __('Use letters, numbers, dots, dashes and underscores only.') }}"
                                   aria-describedby="slug-preview slug-help{{ $errors->has('slug') ? ' slug-error' : '' }}"
                                   @if($errors->has('slug')) aria-invalid="true" @endif>
                        </div>
                        <span class="mk-help mk-help--url" id="slug-preview"><bdi dir="ltr">{{ $hostBase }}/@<span x-text="slug">{{ old('slug', $user->slug) }}</span>{{ $uidSuffix }}</bdi></span>
                        <span class="mk-help" id="slug-help">{{ __('Letters, numbers, dots, dashes and underscores, :min to :max characters.', ['min' => $slugMin, 'max' => $slugMax]) }}</span>
                        @include('users.profile.partials.field-error', ['field' => 'slug'])
                    </div>
                </div>

                <div class="mk-form__row mk-form__row--2">
                    <div class="mk-field">
                        <label class="mk-label" for="email">
                            {{ __('E-mail') }}
                            {{-- "Verified" whenever the address is confirmed (mockup account-profile); "Not confirmed" only
                                 where the site asks members to confirm it --}}
                            @if(! $pendingEmail && $user->email_verified_at)
                                <span class="mk-verified"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>{{ __('Verified') }}</span>
                            @elseif(! $pendingEmail && $emailRequired)
                                <span class="mk-label__opt">{{ __('Not confirmed') }}</span>
                            @endif
                        </label>
                        @if($viewOnly)
                            {{-- The view-only demo sees its address masked: a read-only text field, so no browser bubble about the
                                 masked characters stands before the friendly refusal (account-31). It is never sent. --}}
                            <div class="mk-group">
                                <span class="mk-group__addon"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
                                <input class="mk-input" id="email" type="text" value="{{ $user->email }}" readonly dir="ltr" aria-label="{{ __('E-mail (hidden in the demo)') }}">
                            </div>
                        @else
                            <input class="mk-input" id="email" type="email" name="email" required maxlength="255" dir="ltr"
                                   value="{{ old('email', $user->email) }}" autocomplete="email"
                                   @if($pendingEmail) disabled aria-describedby="email-pending" @elseif($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>
                        @endif
                        @if($pendingEmail)
                            <span class="mk-help" id="email-pending">{{ __('Waiting for you to confirm :email. Open the link we sent to it.', ['email' => $pendingEmail]) }}</span>
                            <div class="mk-inline-actions">
                                <button type="submit" form="mk-email-resend" class="mk-btn mk-btn--quiet mk-btn--sm"
                                        x-data="{ wait: {{ $verifyUpdateWait }} }"
                                        x-init="if (wait > 0) { const timer = setInterval(() => { if (--wait <= 0) { wait = 0; clearInterval(timer); } }, 1000); }"
                                        :disabled="wait > 0">
                                    <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                                    <span x-show="wait <= 0">{{ __('Send the link again') }}</span>
                                    <span x-show="wait > 0" x-cloak x-text="@js(__('Send the link again in :time')).replace(':time', Math.floor(wait / 60) + ':' + String(wait % 60).padStart(2, '0'))"></span>
                                </button>
                                <button type="submit" form="mk-email-cancel" class="mk-btn mk-btn--quiet mk-btn--sm"><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>{{ __('Keep my current e-mail') }}</span></button>
                            </div>
                        @elseif($emailRequired && ! $user->email_verified_at)
                            <span class="mk-help">{{ __('Confirm your e-mail to use every feature of your account.') }}</span>
                            <div class="mk-inline-actions">
                                <button type="submit" form="mk-email-verify" class="mk-btn mk-btn--quiet mk-btn--sm"
                                        x-data="{ wait: {{ $verifyWait }} }"
                                        x-init="if (wait > 0) { const timer = setInterval(() => { if (--wait <= 0) { wait = 0; clearInterval(timer); } }, 1000); }"
                                        :disabled="wait > 0">
                                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                    <span x-show="wait <= 0">{{ __('Send the link again') }}</span>
                                    <span x-show="wait > 0" x-cloak x-text="@js(__('Send the link again in :time')).replace(':time', Math.floor(wait / 60) + ':' + String(wait % 60).padStart(2, '0'))"></span>
                                </button>
                            </div>
                        @endif
                        @include('users.profile.partials.field-error', ['field' => 'email'])
                    </div>
                    <div class="mk-field">
                        <label class="mk-label" for="country">{{ __('Country') }} <span class="mk-label__opt">{{ __('optional') }}</span></label>
                        {{-- 250 countries: a searchable list while "Searchable drop-down lists" is on, native when it is off (5.9).
                             The page sends their codes and the chosen one; the browser writes the names in the page's
                             language and sorts them before the list is used (page-content budget, 4.3). --}}
                        <select class="form-select" id="country" name="country" autocomplete="country"
                                x-data x-init="avdCountryOptions($el)"
                                @if($errors->has('country')) aria-invalid="true" aria-describedby="country-error" @endif>
                            <option value="">{{ __('Choose a country') }}</option>
                            @if(is_string($selectedCountry) && isset($countries[$selectedCountry]))
                                <option value="{{ $selectedCountry }}" selected>{{ $countries[$selectedCountry] }}</option>
                            @endif
                        </select>
                        @if($enhancedSelects)
                            <span class="mk-help">{{ __('Type to search the list.') }}</span>
                        @endif
                        @include('users.profile.partials.field-error', ['field' => 'country'])
                    </div>
                </div>
            </div>
        </div>
    </section>

    @if($smsReady || $user->phone !== null)
        <section class="mk-panel" aria-labelledby="hPhone">
            <div class="mk-panel__head">
                <h2 class="mk-panel__title" id="hPhone">{{ __('Phone number') }}</h2>
                <p class="mk-panel__sub">
                    @if($smsReady)
                        {{ __('Optional. We check it with a code by text message, and you can remove it any time.') }}
                    @else
                        {{ __('This site cannot send text messages at the moment, so the number cannot be checked. You can remove it.') }}
                    @endif
                </p>
            </div>
            <div class="mk-panel__body">
                <div class="mk-form">
                    @if($smsReady)
                        <div class="mk-form__row mk-form__row--2">
                            <div class="mk-field">
                                <label class="mk-label" for="phone">
                                    {{ __('Phone') }}
                                    @if($phoneVerified)
                                        <span class="mk-verified"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>{{ __('Verified') }}</span>
                                    @elseif($phoneWaiting)
                                        <span class="mk-label__opt">{{ __('waiting for the code') }}</span>
                                    @elseif($phoneUnchecked)
                                        <span class="mk-label__opt">{{ __('not verified') }}</span>
                                    @else
                                        <span class="mk-label__opt">{{ __('optional') }}</span>
                                    @endif
                                </label>
                                <div class="mk-group">
                                    {{-- The country code: a native list whose 250 entries are added the first time it is used
                                         (account-36, perf-4: the old prefix menu put 1,226 elements in the page). --}}
                                    <select class="mk-group__select" id="phone-code" name="code" data-native-select
                                            aria-label="{{ __('Country code') }}"
                                            data-options="{{ json_encode($phoneCodes, JSON_UNESCAPED_UNICODE) }}"
                                            x-data="{
                                                filled: false,
                                                fill() {
                                                    if (this.filled) return;
                                                    this.filled = true;
                                                    const select = this.$el;
                                                    const current = select.value;
                                                    let list = [];
                                                    try { list = JSON.parse(select.dataset.options || '[]'); } catch (e) {}
                                                    const fragment = document.createDocumentFragment();
                                                    list.forEach(([code, prefix, name]) => {
                                                        if (code === current) return;
                                                        const option = document.createElement('option');
                                                        option.value = code;
                                                        option.textContent = code + ' ' + prefix;
                                                        option.title = name;
                                                        fragment.appendChild(option);
                                                    });
                                                    select.appendChild(fragment);
                                                    select.value = current;
                                                }
                                            }"
                                            @focus="fill()" @pointerdown="fill()" @keydown="fill()" @touchstart.passive="fill()">
                                        <option value="">{{ __('Country code') }}</option>
                                        @if($phoneCountryRow)
                                            <option value="{{ $phoneCountryRow[0] }}" title="{{ $phoneCountryRow[2] }}" selected>{{ $phoneCountryRow[0] }} {{ $phoneCountryRow[1] }}</option>
                                        @endif
                                    </select>
                                    <input class="mk-input" id="phone" type="tel" name="phone" inputmode="tel" autocomplete="tel-national" dir="ltr"
                                           value="{{ old('phone', $user->phoneNational()) }}"
                                           aria-describedby="{{ $phoneWaiting ? 'phone-sent' : '' }}{{ $errors->has('phone') ? ' phone-error' : '' }}"
                                           @if($errors->has('phone')) aria-invalid="true" @endif>
                                </div>
                                @if($phoneWaiting && $user->phoneInternational())
                                    {{-- the number keeps its own order in a right-to-left sentence (Unicode isolate marks
                                         around it: in Arabic it read "00 00 00 99 1 33+") --}}
                                    <span class="mk-help" id="phone-sent">{{ __('We sent a 5-digit code to :number.', ['number' => "\u{2066}".$user->phoneInternational()."\u{2069}"]) }}</span>
                                @endif
                                @include('users.profile.partials.field-error', ['field' => 'phone'])
                                @if(! $phoneWaiting)
                                    @include('users.profile.partials.field-error', ['field' => 'code'])
                                @endif
                            </div>
                            @if($phoneWaiting)
                                <div class="mk-field">
                                    <label class="mk-label" for="phone-otp">{{ __('Code from the text message') }}</label>
                                    <div class="mk-group">
                                        <input class="mk-input" id="phone-otp" form="mk-phone-verify" name="code" type="text" dir="ltr"
                                               inputmode="numeric" autocomplete="one-time-code" maxlength="5" pattern="[0-9]{5}" required
                                               @if($errors->has('code')) aria-invalid="true" aria-describedby="code-error" @endif>
                                        <button class="mk-group__addon mk-group__addon--end mk-group__btn" type="submit" form="mk-phone-verify">{{ __('Verify') }}</button>
                                    </div>
                                    @include('users.profile.partials.field-error', ['field' => 'code'])
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="mk-field">
                            <span class="mk-label">{{ __('Phone') }}</span>
                            <p class="mk-phone-readonly"><bdi dir="ltr">{{ $user->phoneInternational() ?? '' }}</bdi></p>
                        </div>
                    @endif
                    <div class="mk-inline-actions">
                        @if($smsReady && ($phoneWaiting || $phoneUnchecked))
                            <button type="submit" form="mk-phone-send" class="mk-btn mk-btn--quiet mk-btn--sm"
                                    x-data="{ wait: {{ $phoneWait }} }"
                                    x-init="if (wait > 0) { const timer = setInterval(() => { if (--wait <= 0) { wait = 0; clearInterval(timer); } }, 1000); }"
                                    :disabled="wait > 0">
                                <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                                <span x-show="wait <= 0">{{ $phoneWaiting ? __('Send a new code') : __('Send a code') }}</span>
                                <span x-show="wait > 0" x-cloak x-text="@js(__('Send a new code in :time')).replace(':time', Math.floor(wait / 60) + ':' + String(wait % 60).padStart(2, '0'))"></span>
                            </button>
                        @endif
                        @if($user->phone !== null)
                            <button type="submit" form="mk-remove-phone" class="mk-btn mk-btn--quiet mk-btn--sm"><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>{{ __('Remove this number') }}</span></button>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if($showLanguage || $showTheme)
        <section class="mk-panel" aria-labelledby="hPrefs">
            <div class="mk-panel__head"><h2 class="mk-panel__title" id="hPrefs">{{ __('Language and theme') }}</h2></div>
            <div class="mk-panel__body">
                <div class="mk-form">
                    <div class="mk-form__row mk-form__row--2">
                        @if($showLanguage)
                            <div class="mk-field">
                                <label class="mk-label" for="language">{{ __('Language') }}</label>
                                <select class="form-select" id="language" name="language" data-native-select>
                                    @foreach($languages as $code => $info)
                                        <option value="{{ $code }}" lang="{{ $code }}" @selected($currentLanguage === $code)>{{ $info['label'] ?? strtoupper($code) }}</option>
                                    @endforeach
                                </select>
                                <span class="mk-help">{{ __('The site and your e-mails use this language.') }}</span>
                                @include('users.profile.partials.field-error', ['field' => 'language'])
                            </div>
                        @endif
                        @if($showTheme)
                            <div class="mk-field" data-mk-instant x-data="avdAccountTheme()" @theme-changed.window="sync($event.detail)">
                                <span class="mk-label" id="theme-label">{{ __('Theme') }}</span>
                                <div class="mk-seg mk-seg--block" role="radiogroup" aria-labelledby="theme-label">
                                    @foreach(['light' => ['fa-regular fa-sun', __('Light')], 'dark' => ['fa-regular fa-moon', __('Dark')], 'device' => ['fa-solid fa-display', __('Device')]] as $mode => [$icon, $text])
                                        <label class="mk-seg__item">
                                            <input type="radio" name="avd_theme_mode" value="{{ $mode }}" form="mk-theme-only" x-model="mode" @change="choose('{{ $mode }}')">
                                            <span class="mk-seg__label"><i class="{{ $icon }}" aria-hidden="true"></i> {{ $text }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <span class="mk-help">{{ __('Changes at once, on this device.') }}</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>
    @endif

    @include('users.profile.partials.savebar', ['label' => __('Save changes')])
</form>

{{-- The one-click actions next to the fields: forms of their own, so they never send the profile form's changes. --}}
@if($isUploadedAvatar)
    <form id="mk-remove-avatar" method="POST" action="{{ route('user.update') }}" hidden>
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="profile">
        <input type="hidden" name="remove_avatar" value="1">
    </form>
@endif
@if($user->phone !== null)
    <form id="mk-remove-phone" method="POST" action="{{ route('user.update') }}" hidden>
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="profile">
        <input type="hidden" name="remove_phone" value="1">
    </form>
@endif
@if($smsReady && ($phoneWaiting || $phoneUnchecked))
    <form id="mk-phone-send" method="POST" action="{{ route('phone.send') }}" hidden>@csrf</form>
@endif
@if($smsReady && $phoneWaiting)
    <form id="mk-phone-verify" method="POST" action="{{ route('phone.verify') }}" hidden>@csrf</form>
@endif
@if($pendingEmail)
    <form id="mk-email-resend" method="POST" action="{{ route('verification.send.update') }}" hidden>@csrf</form>
    <form id="mk-email-cancel" method="POST" action="{{ route('verification.verify.update.cancel') }}" hidden>@csrf</form>
@elseif($emailRequired && ! $user->email_verified_at)
    <form id="mk-email-verify" method="POST" action="{{ route('verification.send') }}" hidden>@csrf</form>
@endif

{{-- The country list (see the select): every code with its name in the page's language, sorted by that name, the chosen
     one kept (also as the value a reset goes back to): window.avdCountryOptions, run when Alpine starts, before TomSelect
     reads the list. The codes go out as one string of two-letter codes ("ADAEAF..."), the smallest form of the list.
     The theme of this device (Light, Dark, Device): avdAccountTheme, the same memory as the switch in the account panel
     (localStorage avd_manual_theme for the site's current look), applied at once; nothing is sent to the server; both
     switches tell each other their choice with the "theme-changed" event (R1-029). Both scripts are in the cached studio
     script (resources/js/member/studio-pages.js) since 1.2.3 round 3 (R3-086: they were inline on every Profile page). --}}
@pushOnce('scripts')
<script>window.avdCountryCodes = @json($countryCodes);</script>
@endPushOnce
