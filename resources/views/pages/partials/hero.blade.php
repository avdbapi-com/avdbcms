{{-- Home hero. hero_layout_mode picks the kind (ticker, bento, banner, lead, or one of the slider kinds), the
     theme's hero deco picks its variant (App\Support\ThemeStructure::hero()). Every kind shows real videos
     and real labels only; colours come from the theme tokens (no inline colours). Markup classes follow the
     mockup classes prefixed avd- (WP4's CSS contract); .avd-hero-zone caps the hero on phones. A rendered hero zone
     is declared to the layout (ThemeStructure::HERO_ZONE_SECTION), which marks the page body.has-hero-zone for the
     transparent header bar (layout-theme-a.css). --}}
@php
    $heroSpec = ($heroLayoutMode ?? 'off') === 'off' ? null : \App\Support\ThemeStructure::hero($heroLayoutMode, $homeLayoutMode ?? '');
    $heroVideos = $heroFeaturedVideos ?? collect();
    if ($heroSpec && $heroVideos->isEmpty() && $heroSpec['kind'] !== 'banner') {
        $heroVideos = \App\Models\Video::query()
            ->where('status', \App\Enums\VideoStatus::PUBLIC->value)
            ->latest('views')
            ->take(10)
            ->with(['user', 'category'])
            ->get();
    }
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
