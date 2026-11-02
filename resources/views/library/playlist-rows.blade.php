{{-- Numbered rows of a playlist page, Watch later and Liked videos (member design 6.11; QA channel-6, channel-26): part
     of the page (no inner scroll box), one row per video with its menu in the row's own column. A video the visitor may
     no longer watch (its channel made it private) keeps its place as a quiet row without its title or link.
     The Watch later item says what it does and sends that state (state=on|off: data-watch-later): "Remove from Watch
     later" in Watch later and for a video already saved there ($watchLaterIds), else "Save to Watch later" (QA: the item
     always said Save and took a saved video out).
     Each row is printed on one line with site-relative links (the pages' content budget, 4.3; R1-069). The Watch later
     item's two labels are on the list that holds the rows (<ol ... data-label-on data-label-off>), not in every row; the
     Share item (data-share-row) shares the row's video: the title link's address without the list it was opened from,
     made absolute in the page (resources/js/member/library-pages.js), and its title. Its items are .dropdown-item too, so
     Bootstrap's arrow keys reach them from the menu's button (1.2.3 round 3, R3-016's class: they did nothing).
     The list ad goes after row 'adAfter' of the whole list when a row follows it there: a part of the list ("Show more")
     passes the rows before it ('rowsBefore') and the list's length ('listRows'), so the ad keeps its place when the
     page prints only its first rows (Watch later, Liked videos: CustomPlaylistController::FIRST_PAGE).
     @include('library.playlist-rows', ['videos' => $videos, 'playlist' => $playlist, 'context' => 'playlist'|'watch-later'|'liked-videos', 'adAfter' => 6, 'watchLaterIds' => [...], 'rowsBefore' => 0, 'listRows' => null]) --}}
@php
    $rowsViewer = auth()->user();
    $rowsCount = $videos->count();
    // the list ad's place counts from the list's first row (a later part passes the rows before it)
    $rowsBefore = max(0, (int) ($rowsBefore ?? 0));
    $rowsInList = isset($listRows) ? (int) $listRows : $rowsBefore + $rowsCount;
    $rowsSaved = array_flip(array_map('intval', $watchLaterIds ?? []));
    $rowsMenuButton = '<button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-toggle="dropdown" aria-expanded="false" aria-label="' . e(__('More actions')) . '"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>';
    // the first rows of the page load their pictures at once ('eagerRows', the first one with a high priority unless
    // 'highFirst' => false; R1-067); rows added later ("Show more") pass none. From 576px the other first rows get a
    // high priority too (a preload): their pictures are one size, so the browser's largest picture can be any of them
    $rowsEager = (int) ($eagerRows ?? 0);
    $rowsShown = 0;
@endphp
@foreach($videos as $video)
@php
    $rowVisible = ($video->user && $rowsViewer && $video->user->is($rowsViewer)) || $video->is_public || $video->is_unlisted;
    $rowViews = (int) ($video->views ?? 0);
    $rowHref = \App\Support\PageUrl::local($video->routeWithParams(['list' => $playlist->uuid]));
    $rowDate = ($video->published_at ?? $video->created_at)?->diffForHumans();
    $rowSaved = $context === 'watch-later' || isset($rowsSaved[(int) $video->id]);
    $rowLater = \App\Support\PageUrl::local(route('custom-playlist.watch-later.toggle', $video->id)) . ($context === 'watch-later' ? '?summary=1' : '');
    $rowLoad = $rowsShown >= $rowsEager ? ' loading="lazy"' : ($rowsShown === 0 && ($highFirst ?? true) ? ' fetchpriority="high"' : '');
@endphp
@if($rowVisible)
<li class="mk-lrow" data-playlist-row="{{ $video->id }}"><a class="mk-lrow__media" href="{{ $rowHref }}" tabindex="-1" aria-hidden="true"><span class="mk-thumb"><img src="{{ \App\Support\PageUrl::local($video->thumbnail_url) }}" alt="" width="168" height="94"{!! $rowLoad !!} decoding="async">@if($video->duration)<span class="mk-thumb__dur">{{ $video->duration }}</span>@endif</span></a><div class="mk-lrow__body"><a class="mk-lrow__title mk-clamp-2" href="{{ $rowHref }}"{!! \App\Support\TextDirection::attr($video->title) !!}>{{ $video->title }}</a>@if($video->user)<span class="mk-card__channel">{{ $video->user->username }}</span>@endif<span class="mk-card__meta"><span>{{ trans_choice('views', $rowViews, ['count' => \App\Support\LocalNumber::short($rowViews)]) }}@if($rowDate)&nbsp;·@endif</span>@if($rowDate) <span>{{ $rowDate }}</span>@endif</span></div><div class="dropdown mk-lrow__menu">{!! $rowsMenuButton !!}<div class="dropdown-menu dropdown-menu-end mk-menu">@if($rowsViewer)<button type="button" class="dropdown-item mk-menu__item" data-watch-later="{{ $rowLater }}" data-watch-later-state="{{ $rowSaved ? 'on' : 'off' }}" @if($context === 'watch-later') data-watch-later-row @endif><i class="fa-{{ $rowSaved ? 'solid fa-xmark' : 'regular fa-clock' }}" aria-hidden="true"></i><span>{{ $rowSaved ? __('Remove from Watch later') : __('Save to Watch later') }}</span></button>@endif<button type="button" class="dropdown-item mk-menu__item" data-share-row><i class="fa-solid fa-share-nodes" aria-hidden="true"></i>{{ __('Share') }}</button></div></div></li>
@if($rowsShown > 0 && $rowsShown < $rowsEager && ($highFirst ?? true))@push('preload')<link rel="preload" as="image" href="{{ \App\Support\PageUrl::local($video->thumbnail_url) }}" fetchpriority="high" media="(min-width: 576px)">@endpush @endif
@php $rowsShown++; @endphp
@else
<li class="mk-lrow mk-lrow--gone" data-playlist-row="{{ $video->id }}"><span class="mk-lrow__media"><span class="mk-thumb mk-thumb--icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></span></span><div class="mk-lrow__body"><span class="mk-lrow__title">{{ __('Private video') }}</span><span class="mk-card__meta">{{ __('Its channel made it private, so it cannot be played here.') }}</span></div>@if($context === 'watch-later')<div class="dropdown mk-lrow__menu">{!! $rowsMenuButton !!}<div class="dropdown-menu dropdown-menu-end mk-menu"><button type="button" class="dropdown-item mk-menu__item" data-watch-later="{{ $rowLater }}" data-watch-later-state="on" data-watch-later-row><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>{{ __('Remove from Watch later') }}</span></button></div></div>@else<span></span>@endif</li>
@endif
@if(!empty($adAfter) && \App\Support\FeedAdSlots::listAdAfter($rowsBefore + $loop->iteration, $rowsInList, (int) $adAfter))
<x-feed-ad-slot as="li" context="playlist_videos" class="mk-lrows__ad" />
@endif
@endforeach
