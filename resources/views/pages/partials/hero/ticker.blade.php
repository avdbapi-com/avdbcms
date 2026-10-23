{{-- Two ranked lists (Midnight Spotlight; scoreboard / code variants via html[data-avd-hero-deco]). Phones keep the
     first list only: its three rows fill the first screen. --}}
@php
    $tickerVoice = \App\Support\ThemeStructure::voice('ticker');
    $tickerLists = [
        ['label' => $tickerVoice[2], 'pill' => __('Top Reach'), 'icon' => 'fire', 'class' => 'avd-lbl', 'videos' => $heroVideos->take(3)->values()],
        ['label' => $tickerVoice[3], 'pill' => __('Breakout'), 'icon' => 'arrow-trend-up', 'class' => 'avd-lbl2', 'videos' => $heroVideos->slice(3, 3)->values()],
    ];
    if ($tickerLists[1]['videos']->isEmpty()) {
        unset($tickerLists[1]);
    }
    // ticker_code: "<views> views · <channel>" in syntax colours (the translated text stays escaped).
    $tickerMeta = $heroDeco !== 'ticker_code' ? $heroMeta : function ($video): \Illuminate\Support\HtmlString {
        $count = '<span class="avd-n">'.e(\App\Helpers\Number::humanize((int) $video->views)).'</span>';
        $views = str_replace('%avd-count%', $count, e(trans_choice('views', (int) $video->views, ['count' => '%avd-count%'])));

        return new \Illuminate\Support\HtmlString($views.($video->user ? ' · <span class="avd-f">'.e($video->user->username).'</span>' : ''));
    };
@endphp
<section class="avd-hero avd-hero--ticker" aria-labelledby="avdTickerTitle">
    <div class="avd-tk-head">
        <span class="avd-badge live">{{ __($tickerVoice[0]) }}</span>
        <h2 class="avd-ttl" id="avdTickerTitle">{{ __($tickerVoice[1]) }}</h2>
        <span class="avd-aux d-none d-sm-flex"><i class="fa-solid fa-bolt" aria-hidden="true"></i>{{ __('Updated every few minutes') }}</span>
    </div>
    <div class="avd-tk">
        @foreach($tickerLists as $list)
            <div class="avd-tkp min-w-0">
                <div class="avd-tkp-h">
                    <span class="{{ $list['class'] }}"><i class="fa-solid fa-{{ $list['icon'] }}" aria-hidden="true"></i> {{ __($list['label']) }}</span>
                    <span class="avd-pill">{{ $list['pill'] }}</span>
                </div>
                @foreach($list['videos'] as $rank => $video)
                    @include('pages.partials.hero.rank-row', ['video' => $video, 'rank' => $rank + 1, 'meta' => $tickerMeta($video)])
                @endforeach
            </div>
        @endforeach
    </div>
</section>
