{{-- A video's thumbnail as a picture in the page's HTML (watch-6, perf-3): the browser fetches it while it parses the
     page instead of after the <image-loaded> component's script chunk (which waited behind ~38 other chunks: the first
     thumbnail came 15-38 s after the text on a weak phone). Same box and classes as resources/js/components/Images/
     ImageLoaded.tsx renders (video.scss .avd-image-loaded), so the cards keep their look and their hover zoom.
     $src: the picture's URL; $alt: the video's title; $eager: one of the first pictures of the first screen (loaded at
     once, with high priority); $hoverBinding: the card's Alpine "hover" state (the scrubbing cards). A missing or broken
     picture shows the site's "no image" picture. --}}
@php
    $avdThumbSrc = trim((string) ($src ?? ''));
    $avdThumbAlt = (string) ($alt ?? '');
    $avdThumbEager = (bool) ($eager ?? false);
    $avdThumbFallback = asset('images/placeholders/no-image.svg');
@endphp
<div class="avd-image-loaded" @if($hoverBinding ?? false) :class="{ 'hover': hover }" @endif>
    <img class="avd-image-loaded-img" src="{{ $avdThumbSrc !== '' ? $avdThumbSrc : $avdThumbFallback }}" alt="{{ $avdThumbAlt }}" width="640" height="360" decoding="async" @if($avdThumbEager) fetchpriority="high" @else loading="lazy" @endif onerror="this.onerror=null;this.src='{{ $avdThumbFallback }}'">
</div>
