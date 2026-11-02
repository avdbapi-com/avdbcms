@extends('layouts.user')

@section('title', __('Analytics'))

@section('content')
@php
    use App\Support\Studio\StudioFormat;
    use App\Support\Studio\ViewsSeries;

    $title = (string) ($video->getRawOriginal('title') ?: __('Untitled video'));
    // 1.2.3 round 2 (R2A-009): "Published <date>" only for a video that has gone public (a scheduled one keeps its
    // scheduled time in published_at until then): a scheduled video says when it goes public, any other one that never
    // went public when it was uploaded; the views total counts from that day.
    $wentPublic = ViewsSeries::wentPublic($video);
    $published = $wentPublic ? $video->published_at : ($video->uploaded_at ?? $video->created_at);
    $storedStatus = $video->status instanceof \App\Enums\VideoStatus ? $video->status : \App\Enums\VideoStatus::tryFrom((int) $video->status);
    $scheduledLater = $storedStatus === \App\Enums\VideoStatus::PLANNED && $video->scheduled_at !== null && $video->scheduled_at->isFuture();
    $headDate = match (true) {
        $scheduledLater => \App\Support\Studio\ZoneLabel::goesPublic($video->scheduled_at),
        $wentPublic => __('Published :date', ['date' => StudioFormat::date($published)]),
        default => __('Uploaded :date', ['date' => StudioFormat::date($published)]),
    };
    $ratings = (int) $video->likes_count + (int) $video->dislikes_count;
    $pct = StudioFormat::percent((int) $video->likes_count, $ratings);
    $delta7 = StudioFormat::delta($last7, $previous7);
    $periodLabels = ['7' => __('7 days'), '28' => __('28 days'), '90' => __('90 days'), 'all' => __('Since upload')];
    // The chart needs daily figures: a video whose views live only in videos.views (imported, or older than the daily
    // totals) has none at all, and gets the note alone instead of an empty grid with an invented scale (studio-13).
    $hasDaily = $series !== null && $series['firstStatDay'] !== null;
    // a video that has not gone public yet has no publish day: its chart starts the day it was uploaded and says its dates
    $sincePublish = $hasDaily && $series['startsAtPublish'] && $series['wentPublic'];
    $chartLabel = ! $hasDaily ? '' : ($sincePublish
        ? __('Views per day since it was published on :date', ['date' => StudioFormat::shortDate($series['published'])])
        : __('Views per day, from :from to :to', ['from' => StudioFormat::shortDate($series['start']), 'to' => StudioFormat::shortDate($series['end'])]));
@endphp
<div class="avd-mk mk-page">
    <ol class="mk-crumbs">
        <li><a href="{{ route('user.videos.index') }}">{{ __('My videos') }}</a></li>
        <li>@can('update', $video)<a href="{{ route('user.videos.edit', $video) }}" dir="auto">{{ $title }}</a>@else<span dir="auto">{{ $title }}</span>@endcan</li>
        <li><span>{{ __('Analytics') }}</span></li>
    </ol>
    <header class="mk-head">
        <div class="mk-head__text">
            <span class="mk-head__kicker"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i>{{ __('Analytics') }}</span>
            <h1 class="mk-head__title mk-clamp-2" dir="auto">{{ $title }}</h1>
            <p class="mk-head__desc"><x-mk.status :video="$video" /> &nbsp;·&nbsp; {{ $headDate }}</p>
        </div>
        <div class="mk-head__actions">
            @can('update', $video)
                <a class="mk-btn mk-btn--secondary" href="{{ route('user.videos.edit', $video) }}"><i class="fa-solid fa-pen" aria-hidden="true"></i><span>{{ __('Edit details') }}</span></a>
            @endcan
            @unless($video->is_banned)
                <a class="mk-btn mk-btn--secondary mk-head__extra" href="{{ $video->route }}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span>{{ __('View on the site') }}</span></a>
            @endunless
        </div>
    </header>
    <x-mk.flash refusal />

    <div class="mk-stats">
        <div class="mk-stat">
            <span class="mk-stat__label"><i class="fa-solid fa-eye" aria-hidden="true"></i>{{ __('Views') }}</span>
            <span class="mk-stat__value">{{ StudioFormat::number((int) $video->views) }}</span>
            <span class="mk-stat__delta">{{ __('Since :date', ['date' => StudioFormat::date($published)]) }}</span>
        </div>
        <div class="mk-stat">
            <span class="mk-stat__label"><i class="fa-solid fa-calendar-week" aria-hidden="true"></i>{{ __('Last 7 days') }}</span>
            <span class="mk-stat__value">{{ $dailyStatsEnabled ? StudioFormat::number($last7) : '–' }}</span>
            @if($dailyStatsEnabled && $delta7 !== null)
                <span @class(['mk-stat__delta', 'mk-stat__delta--up' => $delta7 > 0, 'mk-stat__delta--down' => $delta7 < 0])>{!! strtr(e(__(':delta on the 7 days before')), [':delta' => StudioFormat::signedHtml($delta7, true)]) !!}</span>
            @elseif($dailyStatsEnabled)
                <span class="mk-stat__delta">{{ __('No views the 7 days before') }}</span>
            @else
                <span class="mk-stat__delta">{{ __('Daily figures are off') }}</span>
            @endif
        </div>
        <div class="mk-stat">
            <span class="mk-stat__label"><i class="fa-solid fa-thumbs-up" aria-hidden="true"></i>{{ __('Likes') }}</span>
            <span class="mk-stat__value">{{ $pct === null ? '–' : $pct.'%' }}</span>
            <span class="mk-stat__delta">{{ trans_choice(':count like|:count likes', (int) $video->likes_count, ['count' => StudioFormat::number((int) $video->likes_count)]) }}&nbsp;· {{ trans_choice(':count dislike|:count dislikes', (int) $video->dislikes_count, ['count' => StudioFormat::number((int) $video->dislikes_count)]) }}</span>
        </div>
        <div class="mk-stat">
            <span class="mk-stat__label"><i class="fa-solid fa-comment" aria-hidden="true"></i>{{ __('Comments') }}</span>
            <span class="mk-stat__value">{{ StudioFormat::number((int) $video->comments_count) }}</span>
            <span class="mk-stat__delta">@if($video->comments_count)<a class="mk-quiet-link" href="{{ route('user.comments.index', ['video' => $video->id]) }}">{{ __('Read the comments') }}</a>@else{{ __('No comments yet') }}@endif</span>
        </div>
    </div>

    <section class="mk-panel" aria-labelledby="mk-views-title">
        <div class="mk-panel__head">
            <h2 class="mk-panel__title" id="mk-views-title">{{ __('Views per day') }}</h2>
            @if($hasDaily)
                <div class="mk-seg" role="group" aria-label="{{ __('Period') }}">
                    @foreach($periodLabels as $key => $label)
                        <a class="mk-seg__item" href="{{ route('user.videos.show', ['video' => $video, 'period' => $key]) }}" @if($period === (string) $key) aria-current="true" @endif>{{ $label }}</a>
                    @endforeach
                </div>
                <p class="mk-panel__sub">
                    @if($sincePublish)
                        {{ trans_choice('Published on :date, so the chart starts that day. :count view in all.|Published on :date, so the chart starts that day. :count views in all.', (int) $video->views, ['date' => StudioFormat::shortDate($series['published']), 'count' => StudioFormat::number((int) $video->views)]) }}
                    @else
                        {{ trans_choice(':count view in these days.|:count views in these days.', $series['total'], ['count' => StudioFormat::number($series['total'])]) }}
                    @endif
                </p>
            @endif
        </div>
        @if($series === null)
            <p class="mk-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>{{ __('Daily view totals are switched off on this site, so there is no chart. The total above counts every view.') }}</span></p>
        @elseif(! $hasDaily)
            <p class="mk-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>{{ (int) $video->views > 0 ? __('There are no daily figures for this video yet. The total above counts every view.') : __('No views yet') }}</span></p>
        @else
            <div class="mk-chart-wrap">
                <x-mk.chart :series="$series" :label="$chartLabel" />
            </div>
            <div class="mk-chart-legend" style="padding-inline:var(--mk-pad)">
                <span><i class="fa-solid fa-square" aria-hidden="true" style="color:var(--avd-brand-ink)"></i> {{ $series['unit'] === 'day' ? __('Views that day') : ($series['unit'] === 'week' ? __('Views that week') : __('Views that month')) }}</span>
                <span><i class="fa-solid fa-square" aria-hidden="true" style="color:var(--avd-brand-ink);opacity:.45"></i> {{ __('Today so far') }}</span>
            </div>
            @if($series['note'])
                <p class="mk-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>{{ $series['note'] }}</span></p>
            @endif
            <details class="mk-note mk-chart-table">
                <summary>{{ __('Show as a table') }}</summary>
                <table>
                    <thead><tr><th scope="col">{{ $series['unit'] === 'day' ? __('Day') : ($series['unit'] === 'week' ? __('Week') : __('Month')) }}</th><th scope="col" class="mk-row__num">{{ __('Views') }}</th></tr></thead>
                    <tbody>
                        @foreach(array_reverse($series['buckets']) as $bucket)
                            <tr><td>{{ $bucket['partial'] && $series['unit'] === 'day' ? __('Today so far') : $bucket['label'] }}</td><td class="mk-row__num">{{ StudioFormat::number($bucket['views']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        @endif
    </section>

    <div class="mk-grid mk-grid--1-1">
        <section class="mk-panel" aria-labelledby="mk-likes-title">
            <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-likes-title">{{ __('Likes and dislikes') }}</h2></div>
            <div class="mk-panel__body" style="display:grid;gap:.75rem">
                @if($ratings > 0)
                    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:.5rem;flex-wrap:wrap"><span class="mk-bignum">{{ __(':percent% likes', ['percent' => $pct]) }}</span><span class="mk-muted">{{ trans_choice(':count rating|:count ratings', $ratings, ['count' => StudioFormat::number($ratings)]) }}</span></div>
                    <div class="mk-ratio mk-ratio--lg" role="img" aria-label="{{ __(':likes% likes, :dislikes% dislikes', ['likes' => $pct, 'dislikes' => 100 - $pct]) }}"><span style="inline-size:{{ $pct }}%"></span><span style="inline-size:{{ 100 - $pct }}%"></span></div>
                    <div class="mk-ratio__label" style="font-size:.875rem"><span><i class="fa-solid fa-thumbs-up" aria-hidden="true" style="color:var(--avd-success)"></i> {{ trans_choice(':count like|:count likes', (int) $video->likes_count, ['count' => StudioFormat::number((int) $video->likes_count)]) }}</span><span><i class="fa-solid fa-thumbs-down" aria-hidden="true" style="color:var(--mk-danger)"></i> {{ trans_choice(':count dislike|:count dislikes', (int) $video->dislikes_count, ['count' => StudioFormat::number((int) $video->dislikes_count)]) }}</span></div>
                    <div><button type="button" class="mk-btn mk-btn--secondary mk-btn--sm" data-bs-toggle="modal" data-bs-target="#mk-likes"><i class="fa-solid fa-users" aria-hidden="true"></i><span>{{ __('See who rated it') }}</span></button></div>
                @else
                    <p class="mk-muted">{{ __('No ratings yet.') }}</p>
                @endif
                @unless($video->show_likes)
                    <p class="mk-help"><i class="fa-solid fa-eye-slash" aria-hidden="true"></i> {{ __('Viewers do not see the number of likes of this video.') }}</p>
                @endunless
            </div>
        </section>
        <section class="mk-panel" aria-labelledby="mk-latest-comments-title">
            <div class="mk-panel__head">
                <h2 class="mk-panel__title" id="mk-latest-comments-title">{{ __('Latest comments') }}</h2>
                @if($video->comments_count)
                    <a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ route('user.comments.index', ['video' => $video->id]) }}">{{ trans_choice('See the :count comment|See all :count comments', (int) $video->comments_count, ['count' => StudioFormat::number((int) $video->comments_count)]) }}</a>
                @endif
            </div>
            @if($latestComments->isEmpty())
                <x-mk.empty icon="comments" :title="__('No comments yet')" :text="$video->allow_comments ? __('Comments people write under this video appear here.') : __('Comments are off for this video.')" compact />
            @else
                <ul class="mk-rows">
                    @foreach($latestComments as $comment)
                        <li class="mk-item">
                            @if($comment->user)
                                <img class="mk-avatar mk-avatar--32" src="{{ $comment->user->avatar_url }}" alt="" width="32" height="32" loading="lazy" decoding="async">
                            @else
                                <span class="mk-avatar mk-avatar--32" aria-hidden="true"></span>
                            @endif
                            <div class="mk-item__body">
                                <div><strong><bdi>{{ $comment->user?->username ?? __('Deleted member') }}</bdi></strong> <span class="mk-muted" style="font-size:.8125rem">&nbsp;· {{ StudioFormat::ago($comment->created_at) }}</span></div>
                                {{-- R1-025: a comment keeps its own direction in every language (an English comment on an
                                     Arabic page showed ".First! Loved the colours") --}}
                                <p class="mk-clamp-2" data-mk-clamp dir="auto">{{ $comment->content }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    @if($ratings > 0)
        <div class="modal fade" id="mk-likes" tabindex="-1" aria-labelledby="mk-likes-title-dialog" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="mk-likes-title-dialog">{{ __('Who rated it') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    {{-- perf-13: the list's first frame is the list itself, as Interactions/InteractionsList.tsx draws it
                         while it loads (the filter, the search, "Loading ...", five skeleton rows), inert until it mounts, in a box
                         of one fixed height (member/_studio.scss .mk-likes): the dialog never grows when the rows arrive, so its
                         head never moves, on phones (a bottom sheet) and in every pop-up window style. --}}
                    <div class="mk-sheet__body" id="mk-likes-body" data-target="{{ $video->id }}">
                        <div class="mk-likes" inert>
                            <div class="mk-likes__tools">
                                <div class="mk-seg" role="group" aria-label="{{ __('Show') }}">
                                    <button type="button" class="mk-seg__item" aria-pressed="true" aria-current="true">{{ __('All') }}</button>
                                    <button type="button" class="mk-seg__item" aria-pressed="false">{{ __('Likes') }}</button>
                                    <button type="button" class="mk-seg__item" aria-pressed="false">{{ __('Dislikes') }}</button>
                                </div>
                                <label class="mk-search"><span class="mk-sr">{{ __('Search names') }}</span><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input type="search" class="mk-input" placeholder="{{ __('Search names') }}"></label>
                            </div>
                            <p class="mk-muted mk-likes__count">{{ __('Loading ...') }}</p>
                            <div class="mk-likes__list">
                                <ul class="mk-rows">
                                    @for($i = 0; $i < 5; $i++)
                                        <li class="mk-item" aria-hidden="true"><span class="mk-skel" style="inline-size:40px;block-size:40px;border-radius:50%"></span><div class="mk-item__body"><span class="mk-skel mk-skel--title" style="inline-size:40%"></span><span class="mk-skel mk-skel--line" style="inline-size:25%"></span></div></li>
                                    @endfor
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
    @include('users.partials.studio-scripts', ['studioScriptsParts' => ['readmore']])
    {{-- sent without its comment lines and indentation (1.2.3 round 1, R1-069) --}}
    @php(ob_start())
    <script>
        (function () {
            var dialog = document.getElementById('mk-likes');
            if (!dialog) return;
            dialog.addEventListener('show.bs.modal', function () {
                var body = document.getElementById('mk-likes-body');
                if (!body || body.querySelector('interactions-area')) return;
                // the list mounts over the reserved skeleton (it keeps the same height until its rows arrive)
                var list = document.createElement('interactions-area');
                list.setAttribute('target', body.getAttribute('data-target'));
                while (body.firstChild) list.appendChild(body.firstChild);
                body.appendChild(list);
            });
        })();
    </script>
    {!! \App\Support\Studio\StudioHtml::js((string) ob_get_clean()) !!}
@endpush
