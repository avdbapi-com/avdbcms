{{-- A video card of the member pages (channel Home and Videos tab, Library shelves; member design 6.10, 6.11): the
     kit's light card (thumbnail, title, the channel when asked, views and age), one link. The channel's Home and its
     Videos tab print it from the same partial, so both show the same numbers and dates (QA channel-19).
     Printed on one line with site-relative links: these cards fill the pages' content budget (4.3); the duration is the
     thumbnail's data-dur, drawn by member/_channel.scss as _kit's badge, and the title's two lines and the picture's
     size are the stylesheet's too (R1-069: one element and no clamp class or picture size per card). A thumbnail that
     cannot load leaves the thumbnail's own quiet box (alt=""), as the site's video cards do.
     A page's first cards load at once ('eager'; the very first with a high priority: 'high'), the others when they come
     near the screen (R1-067: every thumbnail was lazy, so the first screen's largest picture came 3 to 5 seconds after the
     page on a weak phone); lists added later ("Show more", a tab's next page) stay lazy.
     @include('library.card', ['video' => $video, 'showChannel' => true, 'eager' => false, 'high' => false]) --}}
@php
    $cardViews = (int) ($video->views ?? 0);
    $cardDate = ($video->published_at ?? $video->created_at)?->diffForHumans();
    $cardChannel = ($showChannel ?? true) && $video->user ? $video->user->username : null;
    $cardLoad = empty($eager) ? ' loading="lazy"' : (empty($high) ? '' : ' fetchpriority="high"');
    // a no-break space as the character itself (2 bytes, the entity took 6: R1-069)
    $cardSep = "\u{00A0}· ";
@endphp
<a class="mk-card" href="{{ \App\Support\PageUrl::local($video->route) }}"><span class="mk-thumb"@if($video->duration) data-dur="{{ $video->duration }}"@endif><img src="{{ \App\Support\PageUrl::local($video->thumbnail_url) }}" alt=""{!! $cardLoad !!} decoding="async"></span><span class="mk-card__title"{!! \App\Support\TextDirection::attr($video->title) !!}>{{ $video->title }}</span>@if($cardChannel)<span class="mk-card__channel">{{ $cardChannel }}</span>@endif<span class="mk-card__meta">{{ trans_choice('views', $cardViews, ['count' => \App\Support\LocalNumber::short($cardViews)]) }}@if($cardDate){{ $cardSep.$cardDate }}@endif</span></a>
