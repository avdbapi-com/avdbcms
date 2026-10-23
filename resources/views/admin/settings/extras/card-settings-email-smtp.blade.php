{{-- Settings > Email > Email server: one click fills the server details of a common provider (then Save). --}}
@php
    $host = preg_replace('/^www\./', '', (string) request()->getHost());
    $providers = [
        'Gmail' => ['smtp.gmail.com', '587', 'tls'],
        'Outlook' => ['smtp.office365.com', '587', 'tls'],
        'Zoho' => ['smtp.zoho.com', '587', 'tls'],
        'Brevo' => ['smtp-relay.brevo.com', '587', 'tls'],
        'SendGrid' => ['smtp.sendgrid.net', '587', 'tls'],
        __('My host') => ['mail.'.$host, '465', 'ssl'],
        // Not listed: the details stay as they are and the server address takes the focus.
        __('Other') => [null, null, null],
    ];
@endphp
<div class="ad-field" data-smtp-presets>
<span class="ad-label" id="smtp-presets-label">{{ __('Fill in for') }}</span>
<div class="ad-help">{{ __('Pick your provider and we fill in the server details. You only add your username and password.') }}</div>
<div class="ad-control ad-row" role="group" aria-labelledby="smtp-presets-label">
@foreach ($providers as $name => [$server, $port, $security])
@if ($server === null)
<button type="button" class="ad-chip" data-provider-other aria-pressed="false">{{ $name }}</button>
@else
<button type="button" class="ad-chip" data-host="{{ $server }}" data-port="{{ $port }}" data-security="{{ $security }}" aria-pressed="false">{{ $name }}</button>
@endif
@endforeach
</div>
</div>
<script>
(function () {
    var box = document.currentScript.previousElementSibling;
    box.addEventListener('click', function (e) {
        var chip = e.target.closest('.ad-chip');
        if (!chip) return;
        Array.prototype.forEach.call(box.querySelectorAll('.ad-chip'), function (c) { c.classList.toggle('is-on', c === chip); c.setAttribute('aria-pressed', c === chip ? 'true' : 'false'); });
        var host = document.getElementById('smtp_host');
        if (chip.hasAttribute('data-provider-other')) {
            if (host) host.focus();
            return;
        }
        var set = function (el, value) { if (el) { el.value = value; el.dispatchEvent(new Event('input', { bubbles: true })); } };
        set(host, chip.dataset.host);
        set(document.getElementById('smtp_port'), chip.dataset.port);
        var security = document.querySelector('[data-key="smtp_encryption"] input[value="' + chip.dataset.security + '"]');
        if (security) { security.checked = true; security.dispatchEvent(new Event('change', { bubbles: true })); }
    });
})();
</script>
