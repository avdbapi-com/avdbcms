{{-- Home hero. hero_layout_mode picks the kind (ticker, bento, banner, lead, or one of the slider kinds), the
     theme's hero deco picks its variant (App\Support\ThemeStructure::hero()). Every kind shows real videos
     and real labels only; colours come from the theme tokens (no inline colours). Markup classes follow the
     mockup classes prefixed avd- (the theme CSS contract); .avd-hero-zone caps the hero on phones. A rendered hero zone
     is declared to the layout (ThemeStructure::HERO_ZONE_SECTION), which marks the page body.has-hero-zone for the
     transparent header bar (layout-theme-a.css). --}}
@php
    $heroSpec = ($heroLayoutMode ?? 'off') === 'off' ? null : \App\Support\ThemeStructure::hero($heroLayoutMode, $homeLayoutMode ?? '');
    // The controller's list (PageController::home(): kept 3 minutes per language, an empty one too) is the hero's list.
    // Read again here whenever it was empty, it gave the same empty list at every view of the page, uncached (a visitor
    // whose language no video can be shown in: thousands of hidden rows read at each view on a big site).
    $heroVideos = $heroFeaturedVideos ?? null;
    if ($heroSpec && $heroVideos === null && $heroSpec['kind'] !== 'banner') {
        // The page was rendered without the controller's list (an include of its own): the same videos the main list
        // shows, i.e. published and live (active(): public or due scheduled, uploaded, published_at reached), made for
        // this language (forLocale()), from active creators (active() checks the creator: verified, not banned).
        // The fallback used to take any public row: unpublished uploads, other languages, banned creators.
        $heroVideos = app(\App\Services\PublicVideoFeedService::class)
            ->trending()
            ->take(10)
            ->with(['user', 'category'])
            ->get();
    }
    $heroVideos ??= collect();
    $heroMeta = function ($video, bool $withChannel = false): string {
        $parts = [];
        if ($withChannel && $video->user) {
            $parts[] = $video->user->username;
        }
        $parts[] = trans_choice('views', (int) $video->views, ['count' => \App\Helpers\Number::humanize((int) $video->views)]);
        if ($date = ($video->published_at ?? $video->created_at)) {
            $parts[] = $date->diffForHumans();
        }

        return implode(' · ', $parts);
    };
@endphp
@if($heroSpec && ($heroVideos->isNotEmpty() || $heroSpec['kind'] === 'banner'))
    @section(\App\Support\ThemeStructure::HERO_ZONE_SECTION, '1')
    <div class="avd-hero-zone" data-avd-hero>
        @include('pages.partials.hero.'.$heroSpec['kind'], ['heroDeco' => $heroSpec['deco'], 'heroVideos' => $heroVideos, 'heroMeta' => $heroMeta])
    </div>
@endif
