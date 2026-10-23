{{--
    Segmented control for 2-4 short options (a radio group; arrow keys move).
    <x-admin.segmented name="theme" :options="['dark' => 'Dark', 'light' => 'Light', 'auto' => 'Follow device']" :value="$theme" label="Colour mode" />
--}}
@props(['name', 'options' => [], 'value' => null, 'label' => null])
<div {{ $attributes->class(['ad-seg']) }} role="radiogroup" @if($label) aria-label="{{ $label }}" @endif data-ad-field="{{ $name }}">
    @foreach ($options as $optionValue => $optionLabel)
        <label>
            <input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @checked((string) old($name, $value) === (string) $optionValue)>
            <span>{{ $optionLabel }}</span>
        </label>
    @endforeach
</div>
