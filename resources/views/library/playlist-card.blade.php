{{-- A playlist card (channel Home and Playlists tab, Library, Saved playlists): the first video's picture with the
     video count, the title and one meta line. The whole card opens the playlist page (QA channel-15: "See all" opened
     the first video's watch page). One line with site-relative links (the pages' content budget, 4.3); the count's
     list icon, the title's two lines and the picture's size come from member/_channel.scss (R1-069).
     'eager' / 'high': a first-screen card loads its picture at once (the first one with a high priority; R1-067).
     @include('library.playlist-card', ['playlist' => $playlist, 'meta' => '...']) --}}
@php
    $playlistCover = $playlist->relationLoaded('videos') ? $playlist->videos->first() : $playlist->first_video;
    $playlistCount = (int) ($playlist->videos_count ?? ($playlist->relationLoaded('videos') ? $playlist->videos->count() : 0));
    $playlistMeta = $meta ?? match ($playlist->status) {
        \App\Enums\PlaylistStatus::PRIVATE => __('Private'),
        \App\Enums\PlaylistStatus::UNLISTED => __('Unlisted'),
        default => __('Public'),
    } . "\u{00A0}· " . __('Updated :date', ['date' => \App\Support\SiteDate::dayDate($playlist->updated_at)]);
    $playlistLoad = empty($eager) ? ' loading="lazy"' : (empty($high) ? '' : ' fetchpriority="high"');
@endphp
<a class="mk-card mk-card--playlist" href="{{ \App\Support\PageUrl::local($playlist->route) }}"><span @class(['mk-thumb', 'mk-thumb--icon' => !$playlistCover])>@if($playlistCover)<img src="{{ \App\Support\PageUrl::local($playlistCover->thumbnail_url) }}" alt=""{!! $playlistLoad !!} decoding="async">@else<i class="fa-solid fa-list" aria-hidden="true"></i>@endif<span class="mk-thumb__count">{{ trans_choice('videos', $playlistCount, ['count' => \App\Support\LocalNumber::short($playlistCount)]) }}</span></span><span class="mk-card__title"{!! \App\Support\TextDirection::attr($playlist->title) !!}>{{ $playlist->title }}</span>@if(!empty($channelName))<span class="mk-card__channel">{{ $channelName }}</span>@endif<span class="mk-card__meta">{{ $playlistMeta }}</span></a>
