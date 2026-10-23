{{-- One card of a settings page: title and purpose on the left, its fields on the right, advanced ones folded. --}}
<section class="ad-sec" id="card-{{ $card['id'] }}" aria-labelledby="card-{{ $card['id'] }}-title">
<div class="ad-sec-intro"><h2 id="card-{{ $card['id'] }}-title">{{ __($card['title']) }}</h2>@if ($card['description'] !== '')<p>{{ __($card['description']) }}</p>@endif
@includeIf('admin.settings.extras.intro-'.str_replace('.', '-', $pageId).'-'.$card['id'])</div>
<div class="ad-sec-body">
@includeIf('admin.settings.extras.card-'.str_replace('.', '-', $pageId).'-'.$card['id'])
@foreach ($card['fields'] as $f)
{{-- A card holding one field under the same name shows the name once (the label stays for screen readers). --}}
@include('admin.settings.partials.field', ['f' => $f, 'soleField' => count($card['fields']) === 1 && $card['more'] === [] && $f['label'] === $card['title']])
@endforeach
@if ($card['more'] !== [])
<details class="ad-more" data-remember="{{ $pageId }}.{{ $card['id'] }}">
<summary class="ad-disclose">{{ __('More options (:count)', ['count' => count($card['more'])]) }}: {{ implode(', ', array_map(fn (array $f): string => __($f['label']), array_slice($card['more'], 0, 3))) }}{{ count($card['more']) > 3 ? ', ...' : '' }}</summary>
@foreach ($card['more'] as $f)
@include('admin.settings.partials.field', ['f' => $f])
@endforeach
</details>
@endif
</div>
</section>
