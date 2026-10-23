{{-- Settings > Email: say plainly when emails are not delivered at all. --}}
@if (in_array($mailer, ['log', 'array'], true))
    <div class="ad-callout is-warn" role="status" style="margin-bottom:8px">
        <div>
            <h3>{{ __('Emails are not being delivered') }}</h3>
            <p>{{ __('Your site is set to "Log only", so password resets and sign-up emails are written to a file instead of being sent. Choose an email server below, save, then send a test.') }}</p>
            <div class="ad-callout-actions"><a class="ad-link ad-small" href="#card-mailer">{{ __('Which email service should I use?') }}</a></div>
        </div>
    </div>
@endif
