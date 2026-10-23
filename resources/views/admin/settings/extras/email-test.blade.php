{{-- Settings > Email: send a test email with the saved settings (POST /admin/settings/test-email). --}}
<section class="ad-sec" id="test-email" aria-labelledby="test-email-title">
    <div class="ad-sec-intro">
        <h2 id="test-email-title">{{ __('Send a test email') }}</h2>
        <p>{{ __('The quickest way to know emails work. This used to be the "Email Test" page.') }}</p>
    </div>
    <div class="ad-sec-body">
        <form class="ad-sec-pad" method="POST" action="{{ route('admin.settings.test-email') }}" data-test-email>
            @csrf
            <label class="ad-label" for="test-email-to">{{ __('Send a test email to') }}</label>
            <div class="ad-help">{{ __('Save first. Then check the inbox (and the spam folder) of this address.') }}</div>
            <div class="ad-row" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
                <input type="email" class="ad-input" id="test-email-to" name="to" required value="{{ auth()->user()->email }}" style="max-width:340px">
                <button type="submit" class="ad-btn">{{ __('Send test email') }}</button>
            </div>
            <div class="ad-field-meta" style="margin-top:10px" role="status" data-test-email-result>{{ __('Last test: not sent yet.') }}</div>
        </form>
    </div>
</section>
<script>
(function () {
    var form = document.querySelector('[data-test-email]');
    if (!form || !window.fetch) return;
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var out = form.querySelector('[data-test-email-result]');
        var button = form.querySelector('button[type=submit]');
        button.disabled = true;
        out.textContent = @json(__('Sending...'));
        fetch(form.action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        }).then(function (res) { return res.json().catch(function () { return {}; }); }).then(function (data) {
            out.textContent = data.message || @json(__('The test email could not be sent.'));
        }).catch(function () {
            out.textContent = @json(__('Could not reach your site.'));
        }).then(function () { button.disabled = false; });
    });
})();
</script>
