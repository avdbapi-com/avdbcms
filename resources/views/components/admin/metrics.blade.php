{{--
    Metrics strip: one bordered row with dividers (not one card per number), at most 5 numbers, each links to its
    page, a change in words and a tiny server-rendered sparkline (no chart library).
    <x-admin.metrics :items="[
        ['label' => 'Views', 'value' => '12,480', 'delta' => '+8% vs last week', 'url' => route('admin.views.index'), 'spark' => [3, 5, 4, 8, 7, 9, 12]],
        ['label' => 'New members', 'value' => 41, 'delta' => '-3', 'down' => true],
    ]" />
--}}
@props(['items' => []])
@php
    $spark = function (array $points, int $w = 200, int $h = 28): string {
        $points = array_values(array_map('floatval', $points));

        if (count($points) < 2) {
            return '';
        }

        $max = max($points);
        $min = min($points);
        $range = ($max - $min) ?: 1;
        $step = $w / (count($points) - 1);
        $coords = [];

        foreach ($points as $i => $p) {
            $coords[] = sprintf('%.1f,%.1f', $i * $step, $h - 3 - ($p - $min) / $range * ($h - 6));
        }

        $line = 'M'.implode(' L', $coords);

        return '<svg class="ad-spark" viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" aria-hidden="true"><path class="a" d="'.$line.' L'.$w.','.$h.' L0,'.$h.' Z"/><path class="l" d="'.$line.'"/></svg>';
    };
    $items = array_slice($items, 0, 5);
@endphp
<div {{ $attributes->class(['ad-metrics', 'is-n'.max(1, count($items))]) }}>
    @foreach ($items as $item)
        @php($tag = ! empty($item['url']) ? 'a' : 'div')
        <{{ $tag }} class="ad-metric" @if($tag === 'a') href="{{ $item['url'] }}" @endif>
            <span class="ad-metric-label">{{ $item['label'] }}</span>
            <span class="ad-metric-value">
                <strong>{{ $item['value'] }}</strong>
                @if (! empty($item['delta']))
                    <span @class(['ad-delta', 'is-down' => ! empty($item['down'])])>{{ $item['delta'] }}</span>
                @endif
            </span>
            @if (! empty($item['spark']) && is_array($item['spark']))
                {!! $spark($item['spark']) !!}
            @endif
        </{{ $tag }}>
    @endforeach
</div>
