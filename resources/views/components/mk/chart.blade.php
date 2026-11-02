{{-- Column chart, server-rendered (member design 5.8, 6.5). No chart library and no
     script: the SVG holds the gridlines, the columns and their hit areas (each with a <title>, "12 Oct: 640 views"), and
     stretches to the plot box; the axis labels are HTML around it, so they never scale with the drawing (>= 11px at every
     width, the plot >= 160px tall on phones). The series comes from App\Support\Studio\ViewsSeries::build().
     <x-mk.chart :series="$series" :label="__('Views per day, last 28 days')" /> --}}
@props(['series', 'label'])
@php
    $mkBuckets = $series['buckets'] ?? [];
    $mkCount = max(1, count($mkBuckets));
    $mkTop = max(1, (int) ($series['top'] ?? 1));
    $mkTicks = $series['ticks'] ?? [0, $mkTop];
    $mkW = $mkCount * 10;
    $mkWidest = '';
    foreach ($mkTicks as $mkTick) {
        $mkText = \App\Support\Studio\StudioFormat::number($mkTick);
        if (mb_strlen($mkText) > mb_strlen($mkWidest)) {
            $mkWidest = $mkText;
        }
    }
@endphp
<div {{ $attributes->class(['mk-chart']) }} dir="ltr">
    <div class="mk-chart__y" aria-hidden="true">
        <span class="mk-chart__label mk-chart__label--sizer">{{ $mkWidest }}</span>
        @foreach($mkTicks as $mkTick)
            <span class="mk-chart__label" style="--p: {{ round($mkTick * 100 / $mkTop, 3) }}%">{{ \App\Support\Studio\StudioFormat::number($mkTick) }}</span>
        @endforeach
    </div>
    <div class="mk-chart__plot">
        <svg class="mk-chart__svg" viewBox="0 0 {{ $mkW }} 100" preserveAspectRatio="none" role="img" aria-label="{{ $label }}" focusable="false">
            @foreach($mkTicks as $mkTick)
                @php $mkY = round(100 - ($mkTick * 100 / $mkTop), 3); @endphp
                <line @class(['mk-chart__grid', 'mk-chart__grid--base' => $mkTick === 0]) x1="0" x2="{{ $mkW }}" y1="{{ $mkY }}" y2="{{ $mkY }}"/>
            @endforeach
            @foreach($mkBuckets as $mkIndex => $mkBucket)
                @php
                    $mkH = round(($mkBucket['views'] / $mkTop) * 100, 3);
                    $mkX = $mkIndex * 10;
                @endphp
                <rect class="mk-chart__hit" x="{{ $mkX }}" y="0" width="10" height="100"><title>{{ $mkBucket['title'] }}</title></rect>
                <rect @class(['mk-chart__bar', 'mk-chart__bar--partial' => $mkBucket['partial']]) x="{{ $mkX + 1.8 }}" y="{{ round(100 - $mkH, 3) }}" width="6.4" height="{{ $mkH }}" rx="1"/>
            @endforeach
        </svg>
    </div>
    <div class="mk-chart__x" aria-hidden="true">
        @foreach($series['labels'] ?? [] as $mkLabel)
            <span @class(['mk-chart__label', $mkLabel['class'] => $mkLabel['class'] !== '']) style="--p: {{ $mkLabel['position'] }}%">{{ $mkLabel['text'] }}</span>
        @endforeach
    </div>
</div>
