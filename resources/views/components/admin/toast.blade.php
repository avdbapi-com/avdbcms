{{--
    Toast area (bottom-right; bottom on phones). JS: window.adminToast(text, {type: 'ok'|'error'|'info', undo}).
    The shell renders it once with the session's success message, if any.
    Props: success (text of a toast to show on load), error.
--}}
@use('App\Support\AdminTheme')
@props(['success' => null, 'error' => null])
<div id="ad-toasts" class="ad-toasts" aria-live="polite">
    @if (is_string($success) && $success !== '')
        <div class="ad-toast" role="status">
            {{ AdminTheme::icon('check-circle') }}<span>{{ $success }}</span>
            <button type="button" class="ad-toast-x" aria-label="{{ __('Close') }}">{{ AdminTheme::icon('x', 'sm') }}</button>
        </div>
    @endif
    @if (is_string($error) && $error !== '')
        <div class="ad-toast is-error" role="alert">
            {{ AdminTheme::icon('alert-circle') }}<span>{{ $error }}</span>
            <button type="button" class="ad-toast-x" aria-label="{{ __('Close') }}">{{ AdminTheme::icon('x', 'sm') }}</button>
        </div>
    @endif
</div>
