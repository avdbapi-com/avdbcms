{{-- Video cards with the list ad of $adContext after the cards numbered in $adAfter (1-based, this list's own
     numbers): the ad takes one card's box (grid_card) or a full row (wide_row), so nothing moves when it fills (6.20).
     Each list's ad tag names its context literally (tests/Unit/ListAdContextsTest scans the views for them).
     'eagerFirst' => N: the first N cards load at once, the first with a high priority unless 'highFirst' => false (the
     page's first screen, R1-067); a list loaded later passes none.
     @include('library.cards', ['videos' => $videos, 'showChannel' => false, 'adContext' => 'channel_videos', 'adAfter' => [6]]) --}}
@foreach($videos as $video)
@include('library.card', ['video' => $video, 'showChannel' => $showChannel ?? true, 'eager' => $loop->index < (int) ($eagerFirst ?? 0), 'high' => $loop->first && (int) ($eagerFirst ?? 0) > 0 && ($highFirst ?? true)])
@if(!empty($adContext) && in_array($loop->iteration, $adAfter ?? [], true))
@if($adContext === 'channel_home_featured')
<x-feed-ad-slot context="channel_home_featured" class="mk-cards__ad" />
@elseif($adContext === 'channel_home_latest')
<x-feed-ad-slot context="channel_home_latest" class="mk-cards__ad" />
@elseif($adContext === 'channel_videos')
<x-feed-ad-slot context="channel_videos" class="mk-cards__ad" />
@endif
@endif
@endforeach
