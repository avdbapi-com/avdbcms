{{--
    Toggle row: title, one-line description, switch. The whole row is the click target; it never saves by
    itself (changes go to the save bar). Sends `value` (default "1") when on and the hidden `off` ("0") when off.
    <x-admin.toggle name="maintenance_mode_enabled" label="Maintenance mode" help="Visitors see a back-soon page." :checked="$on" />
--}}
@props(['name', 'label' => '', 'help' => null, 'checked' => false, 'value' => '1', 'off' => '0', 'disabled' => false, 'id' => null])
@php($toggleId = $id ?? $name)
<label {{ $attributes->class(['ad-toggle-row']) }} for="{{ $toggleId }}" data-ad-field="{{ $name }}">
    <span class="ad-toggle-text">
        <b>{{ $label }}</b>
        @if ($help)
            <span class="ad-help" id="{{ $toggleId }}-help">{{ $help }}</span>
        @endif
    </span>
    @if ($off !== null)
        <input type="hidden" name="{{ $name }}" value="{{ $off }}" @disabled($disabled)>
    @endif
    <span class="ad-switch">
        <input type="checkbox" role="switch" id="{{ $toggleId }}" name="{{ $name }}" value="{{ $value }}" @checked((string) old($name, $checked ? $value : $off) === (string) $value) @disabled($disabled) @if($help) aria-describedby="{{ $toggleId }}-help" @endif>
        <i aria-hidden="true"></i>
    </span>
</label>
