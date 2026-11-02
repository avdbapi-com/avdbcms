{{-- One-time messages after a redirect (member design 5.10), always escaped (account-22: status was printed with
     {!! !!}). Put it once at the top of each member page root, under the page head.
     - Keys: success / status (success), info, warning, error (danger): an inline alert each.
     - refusal (opt in, <x-mk.flash refusal />): the view-only demo guard's refusal (App\Http\Middleware\ViewOnlyDemo
       sends a page back with the error view_only_demo): "Not saved" + the guard's sentence, in a focused alert (6.18,
       section 8). Opt in, because a page that prints the refusal itself (Account settings) must not show it twice.
     - toast: a flashed `toast` (a string or ['message' => ..., 'type' => success|error|info|warning]) belongs to the
       layout's one toast system (5.10, 4.4). The layout prints it; the inert <template data-mk-flash-toast> here hands
       it over to the studio script on a layout that does not (users/partials/studio-scripts shows it only when no
       [data-avd-toast-flash] came with the page), so a toast is never shown twice.
     - messages=false (<x-mk.flash refusal :messages="false" />): the refusal only, for a page that prints its own
       one-time messages (the Premium page: PayPal's "Almost done" is its waiting message, R2A-015). --}}
@props(['refusal' => false, 'messages' => true])
@php
    $mkFlashKeys = $messages ? ['success' => 'success', 'status' => 'success', 'info' => 'info', 'warning' => 'warning', 'error' => 'danger'] : [];
    $mkRefusal = $refusal && isset($errors) && $errors->has('view_only_demo') ? (string) $errors->first('view_only_demo') : '';
    $mkToast = $messages ? session('toast') : null;
    $mkToast = is_array($mkToast) ? $mkToast : (is_string($mkToast) ? ['message' => $mkToast] : null);
    $mkToastText = is_array($mkToast) && is_scalar($mkToast['message'] ?? null) ? trim((string) $mkToast['message']) : '';
    $mkToastType = is_array($mkToast) && in_array($mkToast['type'] ?? 'success', ['success', 'error', 'info', 'warning'], true) ? ($mkToast['type'] ?? 'success') : 'success';
@endphp
@if($mkRefusal !== '')
    <x-mk.alert type="info" :title="__('Not saved')" tabindex="-1" data-mk-focus data-view-only-refusal>{{ $mkRefusal }}</x-mk.alert>
    <script>(function (a) { if (a && a.focus) a.focus({preventScroll: true}); })(document.currentScript && document.currentScript.previousElementSibling);</script>
@endif
@foreach($mkFlashKeys as $mkKey => $mkType)
    @if(session()->has($mkKey) && is_string(session($mkKey)) && session($mkKey) !== '')
        <x-mk.alert :type="$mkType" dismissible>{{ session($mkKey) }}</x-mk.alert>
    @endif
@endforeach
@if($mkToastText !== '')
    <template data-mk-flash-toast data-type="{{ $mkToastType }}">{{ $mkToastText }}</template>
@endif
