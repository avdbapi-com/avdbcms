{{-- Member page head (member design 5.2). Kicker + title + one line of help; one primary action in the actions slot.
     <x-mk.head :title="__('My videos')" :kicker="__('Studio')" icon="clapperboard" :desc="...">
         <x-slot:actions>...</x-slot:actions>
     </x-mk.head> --}}
@props(['title', 'kicker' => null, 'icon' => null, 'desc' => null, 'clamp' => false])
<header {{ $attributes->class(['mk-head']) }}>
    <div class="mk-head__text">
        @if($kicker)
            <span class="mk-head__kicker">@if($icon)<i class="fa-solid fa-{{ $icon }}" aria-hidden="true"></i>@endif{{ $kicker }}</span>
        @endif
        <h1 @class(['mk-head__title', 'mk-clamp-2' => $clamp])>{{ $title }}</h1>
        @if($desc)
            <p class="mk-head__desc">{{ $desc }}</p>
        @endif
        {{ $below ?? '' }}
    </div>
    @isset($actions)
        <div class="mk-head__actions">{{ $actions }}</div>
    @endisset
</header>
