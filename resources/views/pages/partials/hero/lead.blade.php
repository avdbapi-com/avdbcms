{{-- Editorial lead (hero_layout_mode editorial_lead; lead_news, lead_brutal): the lead story with its stand-first
     and byline, beside a numbered "Most watched" column. --}}
@php
    $leadVideo = $heroVideos->first();
    $leadSide = $heroVideos->slice(1)->sortByDesc('views')->take(5)->values();
@endphp
@push('preload')
    <link rel="preload" as="image" href="{{ $leadVideo->thumbnail_url }}" fetchpriority="high">
@endpush
<section class="avd-hero avd-hero--lead" aria-labelledby="avdLeadTitle">
    <div class="avd-lead">
        <article class="avd-l">
            <div class="avd-ph">
                <a class="d-block h-100" href="{{ $leadVideo->route }}" tabindex="-1" aria-hidden="true">
                    <img src="{{ $leadVideo->thumbnail_url }}" alt="" width="1280" height="720" loading="eager" fetchpriority="high" decoding="async">
                </a>
                @if($leadVideo->duration)
                    <span class="avd-duration">{{ $leadVideo->duration }}</span>
                @endif
            </div>
            <span class="avd-page-kicker mb-0">{{ $leadVideo->category ? __($leadVideo->category->title) : __(\App\Support\ThemeStructure::voice('kicker')) }}</span>
            <h2 @class(['avd-htitle', 'line-clamp-2', \App\Support\TitleScript::cssClass($leadVideo->title)]) id="avdLeadTitle">
                <a class="text-reset text-decoration-none" href="{{ $leadVideo->route }}">{{ $leadVideo->title }}</a>
            </h2>
            @if($leadVideo->description)
                <p>{{ Str::limit(strip_tags($leadVideo->description), 180) }}</p>
            @endif
            <div class="avd-byline">
                @if($leadVideo->user)
                    {{ __('By :channel', ['channel' => $leadVideo->user->username]) }} <span aria-hidden="true">·</span>
                @endif
                @if($leadVideo->duration)
                    {{ $leadVideo->duration }} <span aria-hidden="true">·</span>
                @endif
                {{ trans_choice('views', (int) $leadVideo->views, ['count' => \App\Helpers\Number::humanize((int) $leadVideo->views)]) }}
            </div>
        </article>
        @if($leadSide->isNotEmpty())
            <aside class="avd-r" aria-labelledby="avdLeadSideTitle">
                <h3 id="avdLeadSideTitle">{{ __(\App\Support\ThemeStructure::voice('side')) }}</h3>
                @foreach($leadSide as $index => $video)
                    <a class="avd-mw text-reset text-decoration-none" href="{{ $video->route }}" data-avd-rank-row>
                        <span class="avd-n" aria-hidden="true">{{ $index + 1 }}</span>
                        <div class="min-w-0">
                            <b @class(['line-clamp-2', \App\Support\TitleScript::cssClass($video->title)])>{{ $video->title }}</b>
                            <span>{{ $video->user ? $video->user->username.' · ' : '' }}{{ trans_choice('views', (int) $video->views, ['count' => \App\Helpers\Number::humanize((int) $video->views)]) }}</span>
                        </div>
                        <div class="avd-tt"><img src="{{ $video->thumbnail_url }}" alt="" width="96" height="54" loading="lazy" decoding="async"></div>
                    </a>
                @endforeach
            </aside>
        @endif
    </div>
</section>
