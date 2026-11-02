{{-- Inline alert (member design 5.10). Text stays --avd-text on the tint; the tone is the bar and the icon.
     <x-mk.alert type="danger" :title="__('Could not save')">{{ __('...') }}</x-mk.alert> --}}
@props(['type' => 'info', 'title' => null, 'dismissible' => false])
@php
    $type = in_array($type, ['info', 'success', 'warning', 'danger'], true) ? $type : 'info';
    $icon = ['info' => 'circle-info', 'success' => 'circle-check', 'warning' => 'triangle-exclamation', 'danger' => 'circle-exclamation'][$type];
@endphp
<div {{ $attributes->class(['mk-alert', 'mk-alert--' . $type => $type !== 'info']) }} role="{{ in_array($type, ['danger', 'warning'], true) ? 'alert' : 'status' }}">
    <i class="fa-solid fa-{{ $icon }}" aria-hidden="true"></i>
    <div class="mk-alert__body">
        @if($title)
            <span class="mk-alert__title">{{ $title }}</span>
        @endif
        <span>{{ $slot }}</span>
    </div>
    @if($dismissible)
        <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--sm" aria-label="{{ __('Close') }}" onclick="this.closest('.mk-alert').remove()"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    @else
        <span></span>
    @endif
</div>
