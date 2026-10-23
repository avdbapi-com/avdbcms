{{--
    One setting row of an Appearance page. Visual choices are pickers (radio tiles with a wireframe or a picture),
    colours get the colour field, switches of the Effects page show what they cost on a weak phone; every other
    control is the settings pages' row (admin/settings/partials/field.blade.php). Every control posts
    groups[group][key]; the page script turns changes into the admin's draft.
--}}
@use('App\Support\AppearancePages')
@php
    $id = $f['key'];
    $picker = in_array($f['control'], ['picker', 'segmented'], true) || $f['key'] === 'sidebar_style' ? AppearancePages::picker($f['key']) : [];
    $impact = ($fx ?? false) ? AppearancePages::impact($f['key']) : null;
    $data = 'data-key="'.e($id).'" data-group="'.e($f['group']).'"'.($f['type'] === 'bool' ? ' data-type="bool"' : '');
    $error = $errors->first($f['group'].'.'.$f['key']);
@endphp
@if ($picker !== [])
<div class="ad-field ad-pick-field{{ $error ? ' is-error' : '' }}" id="{{ $id }}-row" {!! $data !!} data-picker>
<span class="ad-label{{ ($soleField ?? false) ? ' ad-sr' : '' }}" id="{{ $id }}-label">{{ __($f['label']) }}</span>
<div class="ad-help" id="{{ $id }}-help">{{ __($f['help']) }}</div>
<div class="ad-control">
@php
    // Many choices (the 34 font pairings): the first ones and the current one show, the rest fold away (their
    // pictures load only when opened).
    $shownCount = $f['key'] === 'typography_pairing' ? 8 : count($picker);
    $first = array_slice($picker, 0, $shownCount, true);
    if (! array_key_exists((string) $f['value'], $first) && array_key_exists((string) $f['value'], $picker)) {
        $first[(string) $f['value']] = $picker[(string) $f['value']];
    }
    $rest = array_diff_key($picker, $first);
@endphp
@foreach (array_filter([$first, $rest]) as $part => $options)
@if ($part === 1)<details class="ad-more ad-pick-more"><summary class="ad-disclose">{{ __('Show all :count choices', ['count' => count($picker)]) }}</summary>@endif
<div @class(['ad-pickers', 'is-fonts' => $f['key'] === 'typography_pairing', 'is-small' => count($picker) > 6 && $f['key'] !== 'typography_pairing']) role="radiogroup" aria-labelledby="{{ $id }}-label" aria-describedby="{{ $id }}-help">
@foreach ($options as $value => $option)
<label class="ad-pick"><input type="radio" class="ad-sr" name="{{ $f['name'] }}" value="{{ $value }}" @if ($part === 0 && $loop->first) id="{{ $id }}" @endif @checked((string) $value === (string) $f['value'])>@if (! empty($option['svg'])){!! $option['svg'] !!}@elseif (! empty($option['img']))<img data-ad-src="{{ $option['img'] }}" alt="" width="320" height="80" decoding="async">@else<span class="ad-pick-ph" aria-hidden="true">Aa</span>@endif<span><b>{{ $option['label'] }}</b>@if ($option['help'] !== '')<small>{{ $option['help'] }}</small>@endif</span></label>
@endforeach
@if ($part === 0)
@unless (array_key_exists((string) $f['value'], $picker) || (string) $f['value'] === '')
{{-- A value this list no longer offers (e.g. the retired "App rail" header) stays selected until another is picked. --}}
<label class="ad-pick is-retired"><input type="radio" class="ad-sr" name="{{ $f['name'] }}" value="{{ $f['value'] }}" checked><span><b>{{ __('Current: :value', ['value' => \Illuminate\Support\Str::headline((string) $f['value'])]) }}</b><small>{{ __('An older choice. Pick one above to change it.') }}</small></span></label>
@endunless
@endif
</div>
@if ($part === 1)</details>@endif
@endforeach
</div>
@if ($error)<div class="ad-error" role="alert" data-error>{{ $error }}</div>@endif
</div>
@elseif ($f['control'] === 'color')
@php($hex = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $f['value']) ? strtolower((string) $f['value']) : '#000000')
<div class="ad-field{{ $error ? ' is-error' : '' }}" id="{{ $id }}-row" {!! $data !!}>
<label class="ad-label" for="{{ $id }}">{{ __($f['label']) }}</label>
<div class="ad-help" id="{{ $id }}-help">{{ __($f['help']) }}</div>
<div class="ad-control">
<div class="ad-color" data-ad-color>
<input type="color" class="ad-color-swatch" value="{{ $hex }}" aria-label="{{ __('Pick a colour') }}" data-ad-color-swatch data-ad-ignore>
<input type="text" class="ad-input" id="{{ $id }}" name="{{ $f['name'] }}" value="{{ $hex }}" pattern="#[0-9a-fA-F]{6}" maxlength="7" spellcheck="false" dir="ltr" aria-describedby="{{ $id }}-help" data-ad-color-hex>
<span class="ad-badge" data-ad-contrast aria-live="polite"></span>
<button type="button" class="ad-btn is-ghost sm" data-ad-appearance-reset="{{ $id }}">{{ __('Use the colour style') }}</button>
</div>
</div>
@if ($error)<div class="ad-error" role="alert" data-error>{{ $error }}</div>@endif
</div>
@elseif ($impact !== null && $f['control'] === 'toggle')
<label class="ad-fx-row ad-field{{ $error ? ' is-error' : '' }}" id="{{ $id }}-row" for="{{ $id }}" {!! $data !!}>
<span class="ad-toggle-text"><b>{{ __($f['label']) }}</b><span class="ad-help" id="{{ $id }}-help">{{ __($f['help']) }}</span></span>
<span class="ad-fx-impact"><x-admin.impact :level="$impact['cost']" :where="$impact['where']" /></span>
<input type="checkbox" class="ad-sr" id="{{ $id }}" name="{{ $f['name'] }}" value="1" role="switch" aria-describedby="{{ $id }}-help" @checked($f['value'] === '1')>
<span class="ad-switch" aria-hidden="true"></span>
</label>
@else
@include('admin.settings.partials.field', ['f' => $f])
@endif
