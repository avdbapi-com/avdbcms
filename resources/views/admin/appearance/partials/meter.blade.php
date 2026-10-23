{{-- Speed meter: 5 bars (more is faster) and the word, so it never relies on colour alone. --}}
<span class="ad-speed is-{{ $speed['verdict'] }}" data-ad-meter>
<span class="ad-speed-bars" aria-hidden="true">@for ($i = 1; $i <= 5; $i++)<i @class(['on' => $i <= $speed['bars']])></i>@endfor</span>
<span class="ad-speed-word">{{ $speed['label'] }}</span>
</span>
