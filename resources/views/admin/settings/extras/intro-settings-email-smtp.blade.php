{{-- Settings > Email > Email server, beside the card title: the Gmail set-up in five steps (Gmail needs an app password). --}}
<details class="ad-intro-steps">
    <summary class="ad-link">{{ __('Setting up Gmail, step by step') }}</summary>
    <ol>
        <li>{{ __('Turn on 2-Step Verification in your Google account.') }}</li>
        <li>{{ __('Create an app password for your site:') }} <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">{{ __('App passwords') }}</a></li>
        <li>{{ __('Click "Gmail" under "Fill in for".') }}</li>
        <li>{{ __('Username: your full Gmail address. Password: the 16-letter app password.') }}</li>
        <li>{{ __('Save, then send a test email.') }}</li>
    </ol>
</details>
