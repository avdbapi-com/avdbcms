{{-- One card of an Appearance page: title and purpose, its fields, advanced fields folded under "More options". --}}
@php($fx = $fx ?? false)
<section @class(['ad-sec', 'is-fx' => $fx]) id="card-{{ $card['id'] }}" aria-labelledby="card-{{ $card['id'] }}-title">
<div class="ad-sec-intro"><h2 id="card-{{ $card['id'] }}-title">{{ __($card['title']) }}</h2>@if (($card['description'] ?? '') !== '')<p>{{ __($card['description']) }}</p>@endif</div>
<div class="ad-sec-body">
@foreach ($card['fields'] as $f)
@include('admin.appearance.partials.field', ['f' => $f, 'fx' => $fx, 'soleField' => count($card['fields']) === 1 && $card['more'] === [] && $f['label'] === $card['title']])
@endforeach
@if ($card['id'] === 'colors' && ($pageId ?? null) === 'appearance.brand')
@include('admin.appearance.partials.colour-note', ['note' => $colourNote ?? null, 'onThemes' => false])
@endif
@if (! empty($card['link']))
<p class="ad-small ad-card-link ad-sec-pad">{{ $card['link']['text'] }} <a href="{{ $card['link']['url'] }}">{{ $card['link']['label'] }}</a></p>
@endif
@if ($card['more'] !== [])
<details class="ad-more" data-remember="{{ $pageId }}.{{ $card['id'] }}">
<summary class="ad-disclose">{{ __('More options (:count)', ['count' => count($card['more'])]) }}: {{ implode(', ', array_map(fn (array $f): string => __($f['label']), array_slice($card['more'], 0, 3))) }}{{ count($card['more']) > 3 ? ', ...' : '' }}</summary>
@foreach ($card['more'] as $f)
@include('admin.appearance.partials.field', ['f' => $f, 'fx' => $fx])
@endforeach
</details>
@endif
</div>
</section>
