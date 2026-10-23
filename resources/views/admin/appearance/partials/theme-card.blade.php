{{--
    One theme of the gallery: a real screenshot (scripts/theme_thumbs.js) or the colour style as a placeholder, the
    name, family, colour styles and old names. The page script draws the same markup for gallery pages it loads.
    $deferred: the picture loads when it comes near the screen (data-ad-src, appearance.ts), not with the page.
--}}
<li @class(['ad-theme', 'is-on' => $theme['id'] === ($selected ?? null)]) data-theme-id="{{ $theme['id'] }}">
<button type="button" class="ad-theme-pick" data-ad-theme="{{ $theme['id'] }}" aria-pressed="{{ $theme['id'] === ($selected ?? null) ? 'true' : 'false' }}" data-flavors="{{ json_encode($theme['flavors'], JSON_UNESCAPED_UNICODE) }}">
@if ($theme['thumb'])
<img @if ($deferred ?? false) data-ad-src="{{ $theme['thumb'] }}" @else src="{{ $theme['thumb'] }}" loading="lazy" @endif alt="" width="320" height="200" decoding="async">
@else
<span class="ad-theme-ph" aria-hidden="true">@include('admin.appearance.partials.swatch', ['colours' => $theme['flavors'][0]['colours'] ?? []])</span>
@endif
<span class="ad-theme-b">
<b>{{ $theme['name'] }}@if ($theme['current']) <span class="ad-badge is-ok">{{ __('On your site now') }}</span>@endif</b>
<small>{{ $theme['family_name'] }} · {{ trans_choice('1 colour style|:count colour styles', count($theme['flavors']), ['count' => count($theme['flavors'])]) }}@if (! empty($theme['match'])) · {{ $theme['match'] }}@elseif ($theme['formerly']) · {{ $theme['formerly'] }}@endif</small>
<span class="ad-dots" aria-hidden="true">@foreach (array_slice($theme['flavors'], 0, 8) as $flavor)<svg viewBox="0 0 14 14"><circle cx="7" cy="7" r="6.5" fill="{{ $flavor['colours'][0] ?? '#888888' }}"/></svg>@endforeach</span>
</span>
</button>
</li>
