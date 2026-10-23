{{--
    Visual picker for layout and style choices: tiles with a wireframe (inline SVG drawn with the tokens:
    classes wf, bg, blk, soft, acc, ln) or a screenshot, a name and one line. The chosen tile gets the accent
    border and a check.
    <x-admin.picker name="home_layout" :value="$layout" label="Homepage layout" :options="[
        'grid_modern' => ['label' => 'Grid', 'help' => 'A banner, then rows of videos', 'svg' => $wireframe],
        'youtube_spotlight' => ['label' => 'Ranked lists', 'img' => $thumbUrl, 'badge' => 'Default'],
    ]" />
    Props: name, options (value => [label, help, svg (trusted markup) | img, badge]), value, label (group name).
--}}
@props(['name', 'options' => [], 'value' => null, 'label' => null])
<div {{ $attributes->class(['ad-pickers']) }} role="radiogroup" @if($label) aria-label="{{ $label }}" @endif data-ad-field="{{ $name }}">
    @foreach ($options as $optionValue => $option)
        <label class="ad-pick">
            <input type="radio" name="{{ $name }}" value="{{ $optionValue }}" @checked((string) old($name, $value) === (string) $optionValue)>
            @if (! empty($option['svg']))
                {!! $option['svg'] !!}
            @elseif (! empty($option['img']))
                <img src="{{ $option['img'] }}" alt="" width="160" height="100" loading="lazy" decoding="async">
            @endif
            <span>
                <b>{{ $option['label'] ?? $optionValue }}@if(! empty($option['badge'])) <x-admin.badge>{{ $option['badge'] }}</x-admin.badge>@endif</b>
                @if (! empty($option['help']))
                    <small>{{ $option['help'] }}</small>
                @endif
            </span>
        </label>
    @endforeach
</div>
