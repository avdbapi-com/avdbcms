{{-- One History row (member design 6.11): thumbnail, title, channel, views and the time it was watched, and the card
     menu in the row's own column (QA channel-7: the menu floated out of the card). On touch screens the row is one
     target (the kit stretches the title link over it); the menu sits above that link. The meta line wraps like the
     mockup's (each fact stays whole; the dot keeps to the fact before it). One line, site-relative links, the row's id
     once (its Remove item takes it from the row): the rows fill the page's content budget (4.3, R1-069). Round 3
     (R1-069): the duration is the thumbnail's data-dur, drawn by member/_channel.scss as the kit's badge (as the Library's
     cards draw it: the same look, one element less per row); the menu's one item ("Remove from history") is printed
     once, as the page's template (pages/history.blade.php), and put into a row's menu the first time its button opens
     it (resources/js/member/library-pages.js): the same menu, not the same words in every row; the no-break space before
     the dot is the character itself (2 bytes, the entity took 6). --}}
@php
    $historyVideo = $view->video;
    $historyViews = (int) ($historyVideo->views ?? 0);
    $historyAt = \Illuminate\Support\Carbon::parse($view->view_at)->timezone(config('app.timezone'));
    $historyHref = \App\Support\PageUrl::local($historyVideo->route);
    $historyLoad = empty($eager) ? ' loading="lazy"' : (empty($high) ? '' : ' fetchpriority="high"');
@endphp
<li class="mk-lrow" data-history-row="{{ $view->id }}"><a class="mk-lrow__media" href="{{ $historyHref }}" tabindex="-1" aria-hidden="true"><span class="mk-thumb"@if($historyVideo->duration) data-dur="{{ $historyVideo->duration }}"@endif><img src="{{ \App\Support\PageUrl::local($historyVideo->thumbnail_url) }}" alt="" width="168" height="94"{!! $historyLoad !!} decoding="async"></span></a><div class="mk-lrow__body"><a class="mk-lrow__title mk-clamp-2" href="{{ $historyHref }}"{!! \App\Support\TextDirection::attr($historyVideo->title) !!}>{{ $historyVideo->title }}</a>@if($historyVideo->user)<span class="mk-card__channel">{{ $historyVideo->user->username }}</span>@endif<span class="mk-card__meta"><span>{{ trans_choice('views', $historyViews, ['count' => \App\Support\LocalNumber::short($historyViews)]) }} ·</span> <span>{{ __('Watched at :time', ['time' => $historyAt->format('H:i')]) }}</span></span></div><div class="dropdown mk-lrow__menu"><button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('More actions') }}"><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button></div></li>
