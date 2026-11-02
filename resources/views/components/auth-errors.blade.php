{{-- The messages at the top of a sign-in card (member design 6.12, auth-24):
     - one alert for a message that belongs to no field: a wrong name or password (with a "Reset your password" link), a
       suspended account (with a "contact us" link), a wait after too many tries (in seconds), a closed provider, a
       reset link that does not work any more (with an "Ask for a new link" link: the same alert for an address without
       an account, so the form never tells who has one);
     - field errors stay under their fields; only when two or more fields need a change does a short summary come first
       (it never repeats the messages), and focus moves to it; a single field error takes the focus itself (the card's
       x-init focuses [data-auth-focus], else the first [aria-invalid="true"]);
     - an error of a field the page does not show (the robot check while it is switched off for this form, so also a
       filled honeypot then) is listed in the alert, so no error is ever lost. Pass the form's robot-check action
       (captcha="login" | "register" | "password_reset") and "captcha" counts as a field only while the check is on.
     Every message is printed as text (escaped); the links are built here.
     <x-auth-errors :fields="['username', 'password', 'captcha']" captcha="login" /> --}}
@props(['fields' => [], 'captcha' => null])
@php
    $authFields = array_values(array_filter((array) $fields, 'is_string'));
    if (is_string($captcha) && $captcha !== '' && ! app(\App\Services\CaptchaService::class)->enabledFor($captcha)) {
        $authFields = array_values(array_diff($authFields, ['captcha']));
    }
    $authFieldErrors = array_values(array_filter($authFields, fn ($field) => $errors->has($field)));
    $authOther = [];
    foreach ($errors->getMessages() as $authKey => $authMessages) {
        if ($authKey !== 'throttle' && ! in_array($authKey, $authFields, true)) {
            foreach ((array) $authMessages as $authMessage) {
                $authOther[] = (string) $authMessage;
            }
        }
    }
    $authOther = array_values(array_unique($authOther));
    $authError = session('error');
    $authError = is_string($authError) && trim($authError) !== '' ? trim(strip_tags($authError)) : null;
    $authThrottle = $errors->first('throttle') ?: (session('auth_throttled') ? $authError : null);
    $authBanned = (bool) session('auth_banned');
    $authFailed = (bool) session('auth_failed');
    $authResetInvalid = (bool) session('reset_link_invalid');
    $authFocusAlert = count($authFieldErrors) === 0;
@endphp

@if($authThrottle)
    <div class="mk-alert mk-alert--warning" role="alert" tabindex="-1" @if($authFocusAlert) data-auth-focus @endif>
        <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
        <div class="mk-alert__body"><span>{{ $authThrottle }}</span></div>
        <span></span>
    </div>
@elseif($authBanned)
    <div class="mk-alert mk-alert--danger" role="alert" tabindex="-1" @if($authFocusAlert) data-auth-focus @endif>
        <i class="fa-solid fa-ban" aria-hidden="true"></i>
        <div class="mk-alert__body"><span>{!! __('This account is suspended. If you think this is a mistake, :contact.', ['contact' => '<a href="'.e(locale_url(route('contact.show'))).'">'.e(__('contact us')).'</a>']) !!}</span></div>
        <span></span>
    </div>
@elseif($authFailed)
    <div class="mk-alert mk-alert--danger" role="alert" tabindex="-1" @if($authFocusAlert) data-auth-focus @endif>
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <div class="mk-alert__body"><span>{{ __('The username or password is wrong.') }} <a href="{{ locale_url(route('password.forgot')) }}">{{ __('Reset your password') }}</a></span></div>
        <span></span>
    </div>
@elseif($authResetInvalid)
    <div class="mk-alert mk-alert--danger" role="alert" tabindex="-1" @if($authFocusAlert) data-auth-focus @endif>
        <i class="fa-solid fa-link-slash" aria-hidden="true"></i>
        <div class="mk-alert__body">
            <span class="mk-alert__title">{{ __('This reset link does not work any more.') }}</span>
            <span>{{ __('It is older than :time, it was used already, or it is for another e-mail.', ['time' => \App\Notifications\Support\MemberMail::duration(\App\Http\Controllers\Auth\PasswordController::linkMinutes())]) }} <a href="{{ locale_url(route('password.forgot')) }}">{{ __('Ask for a new link') }}</a></span>
        </div>
        <span></span>
    </div>
@elseif(session('registration_closed'))
    <div class="mk-alert" role="status">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <div class="mk-alert__body">
            <span class="mk-alert__title">{{ __('Sign-ups are closed') }}</span>
            <span>{{ __(':site is not taking new members right now. If you already have an account, you can sign in.', ['site' => config('app.name')]) }}</span>
        </div>
        <span></span>
    </div>
@elseif($authError)
    <div class="mk-alert mk-alert--danger" role="alert" tabindex="-1" @if($authFocusAlert) data-auth-focus @endif>
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <div class="mk-alert__body"><span>{{ $authError }}</span></div>
        <span></span>
    </div>
@endif

@if(count($authFieldErrors) >= 2)
    <div class="mk-alert mk-alert--danger" role="alert" tabindex="-1" id="auth-errors" data-auth-focus>
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <div class="mk-alert__body"><span>{{ trans_choice('{1} One field needs a change: see below.|[2,*] :count fields need a change: see below.', count($authFieldErrors), ['count' => count($authFieldErrors)]) }}</span></div>
        <span></span>
    </div>
@endif

@if($authOther !== [])
    <div class="mk-alert mk-alert--danger" role="alert" tabindex="-1" @if($authFocusAlert && ! $authThrottle && ! $authBanned && ! $authFailed && ! $authResetInvalid && ! $authError) data-auth-focus @endif>
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <div class="mk-alert__body">
            @foreach($authOther as $authMessage)
                <span>{{ $authMessage }}</span>
            @endforeach
        </div>
        <span></span>
    </div>
@endif
