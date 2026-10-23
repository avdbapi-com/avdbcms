{{--
    Colour field: swatch (native picker) + hex input + "Reset to theme colour" + a contrast badge computed in
    the browser ("Text on this colour: 4.9:1"). The hex input carries the name; the swatch follows it.
    <x-admin.color name="primary_color" :value="$hex" default="#ff0055" label="Main colour" help="Buttons and links." />
--}}
@props(['name', 'value' => '#000000', 'default' => null, 'label' => '', 'help' => null, 'id' => null])
@php
    $colorId = $id ?? $name;
    $raw = (string) old($name, $value);
    $hex = preg_match('/^#[0-9a-fA-F]{6}$/', $raw) ? strtolower($raw) : '#000000';
@endphp
<x-admin.field :name="$name" :label="$label" :help="$help" :id="$colorId" {{ $attributes }}>
    <div class="ad-color" data-ad-color>
        <input type="color" class="ad-color-swatch" value="{{ $hex }}" aria-label="{{ __('Pick a colour') }}" data-ad-color-swatch data-ad-ignore>
        <input type="text" class="ad-input" id="{{ $colorId }}" name="{{ $name }}" value="{{ $hex }}" pattern="#[0-9a-fA-F]{6}" maxlength="7" spellcheck="false" dir="ltr" data-ad-color-hex>
        <span class="ad-badge" data-ad-contrast aria-live="polite"></span>
        @if ($default)
            <button type="button" class="ad-btn is-ghost sm" data-ad-color-reset="{{ $default }}">{{ __('Reset to theme colour') }}</button>
        @endif
    </div>
</x-admin.field>
