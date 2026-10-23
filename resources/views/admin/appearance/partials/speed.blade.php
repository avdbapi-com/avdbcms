{{--
    "Speed on a weak phone" (Effects & speed): an estimate from the effects that are on and the device rule
    (AppearancePages::speed); the page script refreshes it from every draft answer.
--}}
@php
    $heavyLabels = array_map(fn (string $key): string => \App\Support\AppearancePages::label($key), $speed['heavy']);
    $auto = $speed['tier'] !== 'rich';
@endphp
<section class="ad-panel ad-speedcard" data-ad-speed aria-labelledby="ad-speed-title">
    <div class="ad-panel-h"><h2 id="ad-speed-title">{{ __('Speed on a weak phone') }}</h2></div>
    <div class="ad-panel-b" aria-live="polite">
        <div class="ad-speed-head"><strong data-ad-speed-label>{{ $speed['label'] }}</strong> <span class="ad-muted">{{ __('with your choices') }}</span></div>
        <div data-ad-speed-meter>@include('admin.appearance.partials.meter', ['speed' => $speed])</div>
        <ul class="ad-speed-list" data-ad-speed-lines>
            <li>{{ \App\Support\AdminTheme::icon('zap') }}<span><b>{{ trans_choice('{0} No heavy effect on|{1} 1 heavy effect on|[2,*] :count heavy effects on', count($heavyLabels), ['count' => count($heavyLabels)]) }}</b>@if ($heavyLabels !== [])<span>{{ implode(', ', $heavyLabels) }}.@if ($auto) {{ __('Turned off automatically on weak phones.') }}@endif</span>@endif</span></li>
            <li>{{ \App\Support\AdminTheme::icon($speed['background'] ? 'sparkle' : 'check-circle') }}<span><b>{{ $speed['background'] ? __('Moving background on') : __('Moving background off') }}</b><span>{{ $speed['background'] ? __('Runs only on strong computers.') : __('Plain or still background, no cost.') }}</span></span></li>
            <li>{{ \App\Support\AdminTheme::icon('monitor') }}<span><b>{{ $auto ? __('Computers get every effect you chose') : __('Every device gets every effect') }}</b><span>{{ $auto ? __('Weak phones get still versions (Auto).') : __('Old phones may stutter.') }}</span></span></li>
        </ul>
        <button type="button" class="ad-btn" data-ad-preview-phone>{{ \App\Support\AdminTheme::icon('phone') }}{{ __('Preview on a phone') }}</button>
        <p class="ad-small ad-faint">{{ __('An estimate from the effects you chose: each effect adds its speed cost for weak phones. The real site still adapts to every device (Auto).') }}</p>
    </div>
</section>
