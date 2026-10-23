{{--
    Effects & speed: one Effects level (Off / Calm / Standard / Maximum) that sets the effects below, the device rule,
    then every effect grouped by what it costs on a weak phone (impact badge + where it runs + switch).
--}}
@php
    $cards = collect(array_merge($form['cards'], $form['advancedCards']))->keyBy('id');
    $levelCard = $cards->get('level');
@endphp
<section class="ad-sec" id="card-level" aria-labelledby="card-level-title">
<div class="ad-sec-intro"><h2 id="card-level-title">{{ __('Effects level') }}</h2><p>{{ __('One choice sets everything below. You can still fine-tune each effect; the level then shows "Custom".') }}</p></div>
<div class="ad-sec-body">
<div class="ad-field">
<div class="ad-level" role="radiogroup" aria-labelledby="card-level-title" data-ad-levels>
@foreach ($levels as $id => $option)
<label class="ad-level-opt"><input type="radio" class="ad-sr" name="ad_effects_level" value="{{ $id }}" data-ad-level="{{ json_encode($option['writes']) }}" @checked($level === $id)>
<b>{{ __($option['label']) }}</b><span>{{ __($option['help']) }}</span>
@include('admin.appearance.partials.meter', ['speed' => $option['speed']])
</label>
@endforeach
</div>
<div class="ad-field-meta"><span data-ad-level-custom @if ($level !== 'custom') hidden @endif>{{ __('Custom: you changed single effects below.') }}</span> <span>{{ __('Bars show speed on a weak phone: more bars is faster.') }}</span></div>
</div>
@if ($levelCard !== null)
@foreach ($levelCard['fields'] as $f)
@include('admin.appearance.partials.field', ['f' => $f])
@endforeach
@if ($levelCard['more'] !== [])
<details class="ad-more" data-remember="{{ $pageId }}.level">
<summary class="ad-disclose">{{ __('More options (:count)', ['count' => count($levelCard['more'])]) }}: {{ implode(', ', array_map(fn (array $f): string => __($f['label']), array_slice($levelCard['more'], 0, 3))) }}</summary>
@foreach ($levelCard['more'] as $f)
@include('admin.appearance.partials.field', ['f' => $f])
@endforeach
</details>
@endif
@endif
</div>
</section>

@foreach (['background', 'fx_free', 'fx_low', 'fx_mid', 'fx_high', 'player'] as $cardId)
    @if ($cards->has($cardId))
        @include('admin.appearance.partials.card', ['card' => $cards->get($cardId), 'fx' => true])
    @endif
@endforeach
