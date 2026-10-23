{{--
    Tabs are links to real URLs (merged pages keep every old address). Counts are neutral badges.
    <x-admin.tabs :tabs="[['label' => 'All', 'url' => ..., 'active' => true, 'count' => 433], ...]" />
--}}
@props(['tabs' => [], 'label' => null])
<nav {{ $attributes->class(['ad-tabs']) }} aria-label="{{ $label ?? __('Sections of this page') }}">
    @foreach ($tabs as $tab)
        <a href="{{ $tab['url'] }}"@if($tab['active'] ?? false) class="is-active" aria-current="page"@endif @if($tab['new_tab'] ?? false) target="_blank" rel="noopener"@endif>{{ __($tab['label']) }}@isset($tab['count'])<span class="ad-count">{{ $tab['count'] }}</span>@endisset</a>
    @endforeach
</nav>
