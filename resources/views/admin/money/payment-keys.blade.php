{{--
    Money > Payment setup: the Stripe and PayPal keys, written to .env by PUT /admin/money/setup/keys.
    A saved key is never printed back: its row says "Saved - ends in 4242" (or "Not set") with Replace and Remove.
    Saving or removing a key needs the admin's own password, asked once for the whole form (like a payout change at
    a bank). The page script counts typed keys in the save bar and saves them with the switches above (Save changes,
    Ctrl+S, warning before leaving); without the script every field shows and this form has its own button.
--}}
<form class="ad-settings-form" method="POST" action="{{ route('admin.money.setup.keys') }}" autocomplete="off" data-payment-keys>
    @csrf
    @method('PUT')
    @foreach ([
        'stripe' => [__('Card payments (Stripe)'), __('From your Stripe dashboard: Developers > API keys, and Developers > Webhooks for the signing secret.'), ['STRIPE_KEY', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET', 'CASHIER_CURRENCY']],
        'paypal' => [__('PayPal'), __('From developer.paypal.com: Apps & Credentials. Use Sandbox to test, Live to take real payments.'), ['PAYPAL_MODE', 'PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET', 'PAYPAL_WEBHOOK_ID']],
    ] as $provider => [$title, $intro, $keys])
        <section class="ad-sec" id="card-payment-keys{{ $provider === 'paypal' ? '-paypal' : '' }}" aria-labelledby="keys-{{ $provider }}-title">
            <div class="ad-sec-intro">
                <h2 id="keys-{{ $provider }}-title">{{ $title }}</h2>
                <p>{{ $intro }}</p>
            </div>
            <div class="ad-sec-body">
                @foreach ($keys as $key)
                    @php($definition = $paymentKeys[$key])
                    @php($error = $errors->first($key))
                    <div class="ad-field{{ $error ? ' is-error' : '' }}" data-payment-key="{{ $key }}">
                        <label class="ad-label" for="pay-{{ $key }}">{{ __($definition['label']) }}</label>
                        <div class="ad-control ad-row">
                            @if ($key === 'PAYPAL_MODE')
                                <select class="ad-select" id="pay-{{ $key }}" name="{{ $key }}" style="max-width:220px">
                                    @foreach (['sandbox' => __('Sandbox (testing)'), 'live' => __('Live (real payments)')] as $mode => $modeLabel)
                                        <option value="{{ $mode }}" @selected($paymentPublic['PAYPAL_MODE'] === $mode)>{{ $modeLabel }}</option>
                                    @endforeach
                                </select>
                            @elseif ($key === 'CASHIER_CURRENCY')
                                <input type="text" class="ad-input" id="pay-{{ $key }}" name="{{ $key }}" value="{{ $paymentPublic['CASHIER_CURRENCY'] }}"
                                       maxlength="3" spellcheck="false" dir="ltr" style="max-width:120px" aria-describedby="pay-{{ $key }}-help">
                                <span class="ad-help" id="pay-{{ $key }}-help">{{ __('Three letters, for example usd or eur.') }}</span>
                            @else
                                @php($saved = $paymentKeyStatus[$key])
                                {{-- The ending is isolated so a right-to-left page keeps its characters in order. --}}
                                @php($savedText = ! $saved ? __('Not set') : (($paymentKeyEnds[$key] ?? null) !== null ? __('Saved - ends in :end', ['end' => "\u{2066}".$paymentKeyEnds[$key]."\u{2069}"]) : __('Saved')))
                                <span class="ad-badge{{ $saved ? ' is-ok' : '' }}" id="pay-{{ $key }}-status" data-key-status data-saved="{{ $saved ? '1' : '0' }}" data-saved-text="{{ $savedText }}">{{ $savedText }}</span>
                                <span class="ad-pw" data-key-input><input type="password" class="ad-input" id="pay-{{ $key }}" name="{{ $key }}" value="" autocomplete="new-password" spellcheck="false" dir="ltr"
                                       placeholder="{{ $saved ? __('Type a new value to replace it') : '' }}" style="max-width:360px"></span>
                                {{-- Up to seven rows have these two controls: their names say which key they act on. --}}
                                <button type="button" class="ad-btn sm" data-key-replace hidden aria-label="{{ __('Replace :key', ['key' => __($definition['label'])]) }}" aria-describedby="pay-{{ $key }}-status">{{ __('Replace') }}</button>
                                <label class="ad-btn sm is-ghost ad-key-remove" data-key-clear @unless ($saved) hidden @endunless><input type="checkbox" name="clear[]" value="{{ $key }}" aria-label="{{ __('Remove :key', ['key' => __($definition['label'])]) }}" aria-describedby="pay-{{ $key }}-status"> {{ __('Remove') }}</label>
                            @endif
                        </div>
                        @if ($error)<div class="ad-error" role="alert" data-error>{{ $error }}</div>@endif
                    </div>
                @endforeach
                <div class="ad-field-meta ad-sec-pad">
                    <span>{{ __('Stored in your site\'s .env file. Keys are never shown again after saving.') }}</span>
                </div>
            </div>
        </section>
    @endforeach
    <section class="ad-sec" id="card-payment-keys-password" aria-labelledby="keys-password-title" data-key-password>
        <div class="ad-sec-intro">
            <h2 id="keys-password-title">{{ __('Confirm with your password') }}</h2>
            <p>{{ __('Changing payment keys needs your admin password, like a payout change at a bank. It is checked once, never stored.') }}</p>
        </div>
        <div class="ad-sec-body">
            @php($error = $errors->first('password'))
            <div class="ad-field{{ $error ? ' is-error' : '' }}">
                <label class="ad-label" for="pay-password">{{ __('Your admin password') }}</label>
                <div class="ad-control"><input type="password" class="ad-input" id="pay-password" name="password" value="" autocomplete="current-password" spellcheck="false" style="max-width:260px"></div>
                @if ($error)<div class="ad-error" role="alert" data-error>{{ $error }}</div>@endif
            </div>
        </div>
    </section>
    <div class="ad-row" style="justify-content:flex-end;margin-top:16px" data-keys-submit>
        <button type="submit" class="ad-btn is-primary">{{ __('Save payment keys') }}</button>
    </div>
</form>
