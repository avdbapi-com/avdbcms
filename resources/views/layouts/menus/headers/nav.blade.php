{{-- Header links: the menu the bar carries when the side menu is hidden (island, crest, transparent bars;
     .avd-hnav) and the section row of the two-row bars (.avd-hrow2). $links: [['label', 'url', 'active', 'new_tab']]. --}}
@if($links !== [])
    <nav @class([$navClass ?? 'avd-hnav']) aria-label="{{ __($navLabel ?? 'Main menu') }}">
        @foreach($links as $link)
            <a @class(['active' => $link['active']]) href="{{ $link['url'] }}"
               @if($link['active']) aria-current="page" @endif
               @if($link['new_tab']) target="_blank" rel="noopener" @endif>{{ $link['label'] }}</a>
        @endforeach
    </nav>
@endif
