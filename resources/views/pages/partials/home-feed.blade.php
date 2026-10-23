{{-- Home feed of the spotlight, cinema, bento, shorts and HUD layouts: the hero is above (pages.partials.hero),
     then the category chips and the main feed under a heading in the theme's voice. $feedAdContext keeps
     each layout's grid-ad context; $feedIcon is the heading icon the "icon" section style shows. --}}
@if($homeCategoryTabsEnabled)
    @include('pages.partials.category-chips-scroller', ['chipsSpacing' => 'mb-2'])
@endif

<section class="avd-home-feed px-2 px-sm-0" aria-labelledby="avdHomeFeedTitle">
    <x-theme.section-head
        :title="__(\App\Support\ThemeStructure::voice('feed'))"
        :kicker="filled(\App\Support\ThemeStructure::voice('feed_kicker')) ? __(\App\Support\ThemeStructure::voice('feed_kicker')) : null"
        title-id="avdHomeFeedTitle"
        :icon="$feedIcon"
    />
    {{-- Server-rendered skeleton grid (perf plan step 20) and, as the page's first feed, the early
         first-page fetch and eager first row (step 21): see resources/views/components/videos-area.blade.php. --}}
    <x-videos-area
        :url="$homeMainFeed['url']"
        :columns="$homeMainFeed['columns']"
        :load-mode="$homeMainFeed['loadMode']"
        :ad-context="$feedAdContext"
        :phone-strip-screen="true"
    />
</section>
