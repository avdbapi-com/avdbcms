{{-- Home feed of the spotlight, cinema, bento, shorts and HUD layouts: the hero is above (pages.partials.hero),
     then the category chips and the main feed under a heading in the theme's voice. $feedAdContext keeps
     each layout's grid-ad context; $feedIcon is the heading icon the "icon" section style shows. --}}
@if($homeCategoryTabsEnabled)
    @include('pages.partials.category-chips-scroller', ['chipsSpacing' => 'mb-2'])
@endif

{{-- R1-151: the page gutter of the phone mockups (16px; the cards sat 8px from the edges) --}}
<section class="avd-home-feed px-3 px-sm-0" aria-labelledby="avdHomeFeedTitle">
    <x-theme.section-head
        :title="__(\App\Support\ThemeStructure::voice('feed'))"
        :kicker="filled(\App\Support\ThemeStructure::voice('feed_kicker')) ? __(\App\Support\ThemeStructure::voice('feed_kicker')) : null"
        title-id="avdHomeFeedTitle"
        :icon="$feedIcon"
    />
    {{-- Server-rendered skeleton grid and, as the page's first feed, the early
         first-page fetch and eager first row: see resources/views/components/videos-area.blade.php. --}}
    <x-videos-area
        :url="$homeMainFeed['url']"
        :columns="$homeMainFeed['columns']"
        :load-mode="$homeMainFeed['loadMode']"
        :ad-context="$feedAdContext"
        :phone-strip-screen="true"
    />
</section>
