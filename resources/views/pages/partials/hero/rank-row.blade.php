<a class="avd-tkr text-reset text-decoration-none" href="{{ $video->route }}" data-avd-rank-row>
    <span @class(['avd-rk', 'r1' => $rank === 1])>#{{ $rank }}</span>
    <div class="avd-tt">
        <img src="{{ $video->thumbnail_url }}" alt="" width="112" height="63" loading="lazy" decoding="async">
        @if($video->duration)
            <span class="avd-duration">{{ $video->duration }}</span>
        @endif
    </div>
    <div class="avd-tx">
        <b @class([\App\Support\TitleScript::cssClass($video->title)])>{{ $video->title }}</b>
        <span>{{ $meta }}</span>
    </div>
    <span class="avd-pl" aria-hidden="true"><i class="fa-solid fa-play"></i></span>
</a>
