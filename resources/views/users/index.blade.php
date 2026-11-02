@extends('layouts.user')

@section('title', __('Dashboard'))

@section('content')
@php
    use App\Support\Studio\Dashboard;
    use App\Support\Studio\StudioFormat;
    use App\Support\Studio\ZoneLabel;

    $f = $figures;
    $days = $period['days'];
    $spark = function (array $points) {
        $paths = Dashboard::sparkPaths($points);
        if ($paths === null) {
            return '';
        }
        return '<svg class="mk-spark" viewBox="0 0 200 34" preserveAspectRatio="none" aria-hidden="true" focusable="false"><polygon class="mk-spark__area" points="'.e($paths['area']).'"/><polyline class="mk-spark__line" points="'.e($paths['line']).'"/></svg>';
    };
    // signed figures keep their sign in front in every direction (Arabic read "12+" and "30%-")
    $signed = fn (int $value, bool $percent = false) => StudioFormat::signedHtml($value, $percent);
    $desc = $period['key'] === 'custom'
        ? __('How your channel did from :from to :to.', ['from' => StudioFormat::date($period['start']), 'to' => StudioFormat::date($period['end'])])
        : trans_choice('How your channel did in the last :count day.|How your channel did in the last :count days.', $days, ['count' => $days]);
    $before = trans_choice('on the :count day before|on the :count days before', $days, ['count' => $days]);
    $periodLinks = ['7' => __('7 days'), '28' => __('28 days'), '90' => __('90 days')];
    // 1.2.3 round 1 (R1-113): a member who has no video yet is welcomed, not welcomed back. The name is isolated (<bdi>,
    // R1-025): on an Arabic page a Latin name ending in "_", "." or "!" keeps that character at its own end.
    $greeting = new \Illuminate\Support\HtmlString(str_replace(':name', '<bdi>'.e($user->username).'</bdi>', e($hasVideos ? __('Welcome back, :name') : __('Welcome, :name'))));
@endphp
<div class="avd-mk mk-page">
    <x-mk.head icon="chart-line" :kicker="__('Studio')" :title="$greeting" :desc="$desc">
        @if($uploadsAllowed)
            <x-slot:actions>
                <button type="button" class="mk-btn mk-btn--primary mk-head__upload" data-bs-toggle="modal" data-bs-target="#video_create"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i><span>{{ __('Upload video') }}</span></button>
            </x-slot:actions>
        @endif
    </x-mk.head>
    <x-mk.flash refusal />

    <div class="mk-period">
        <div class="mk-seg" role="group" aria-label="{{ __('Period') }}">
            @foreach($periodLinks as $key => $label)
                <a class="mk-seg__item" href="{{ route('user.index', ['period' => $key]) }}" @if($period['key'] === (string) $key) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
            <button type="button" class="mk-seg__item" data-bs-toggle="modal" data-bs-target="#mk-dash-dates" @if($period['key'] === 'custom') aria-current="true" @endif>{{ __('Custom') }}</button>
        </div>
        <span class="mk-muted" style="font-size:.8125rem">{{ $period['label'] }}</span>
    </div>

    <div class="mk-stats">
        <div class="mk-stat">
            <span class="mk-stat__label"><i class="fa-solid fa-eye" aria-hidden="true"></i>{{ __('Views') }}</span>
            <span class="mk-stat__value" title="{{ __(':count views from :from to :to', ['count' => StudioFormat::number($f['views']), 'from' => StudioFormat::shortDate($period['start']), 'to' => StudioFormat::shortDate($period['end'])]) }}">{{ StudioFormat::number($f['views']) }}</span>
            @if(! $f['daily'])
                <span class="mk-stat__delta">{{ __('Every view your videos ever had') }}</span>
            @elseif($f['viewsDelta'] !== null)
                <span @class(['mk-stat__delta', 'mk-stat__delta--up' => $f['viewsDelta'] > 0, 'mk-stat__delta--down' => $f['viewsDelta'] < 0])>{!! $signed($f['viewsDelta'], true) !!} {{ $before }}</span>
            @else
                <span class="mk-stat__delta">{{ __('No views before these days') }}</span>
            @endif
            {!! $spark($f['viewsSpark']) !!}
        </div>
        <div class="mk-stat">
            <span class="mk-stat__label"><i class="fa-solid fa-user-plus" aria-hidden="true"></i>{{ __('New subscribers') }}</span>
            <span class="mk-stat__value">{!! $signed($f['subs']) !!}</span>
            <span class="mk-stat__delta">{{ __(':count in total', ['count' => StudioFormat::number($f['subsTotal'])]) }}</span>
            {!! $spark($f['subsSpark']) !!}
        </div>
        <div class="mk-stat">
            <span class="mk-stat__label"><i class="fa-solid fa-comment" aria-hidden="true"></i>{{ __('Comments') }}</span>
            <span class="mk-stat__value">{{ StudioFormat::number($f['comments']) }}</span>
            @php $commentsChange = $f['comments'] - $f['commentsPrev']; @endphp
            <span @class(['mk-stat__delta', 'mk-stat__delta--up' => $commentsChange > 0, 'mk-stat__delta--down' => $commentsChange < 0])>{!! $signed($commentsChange) !!} {{ $before }}</span>
            {!! $spark($f['commentsSpark']) !!}
        </div>
        <div class="mk-stat">
            <span class="mk-stat__label"><i class="fa-solid fa-thumbs-up" aria-hidden="true"></i>{{ __('Likes') }}</span>
            <span class="mk-stat__value">{{ StudioFormat::number($f['likes']) }}</span>
            <span class="mk-stat__delta">{{ $f['likesPct'] === null ? __('No ratings in these days') : __(':percent% of ratings are likes', ['percent' => $f['likesPct']]) }}</span>
            {!! $spark($f['likesSpark']) !!}
        </div>
    </div>
    @unless($f['daily'])
        <p class="mk-help"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> {{ __('Daily view totals are switched off on this site: Views counts every view, without a curve.') }}</p>
    @endunless

    @if(! $hasVideos)
        <section class="mk-panel">
            <x-mk.empty icon="clapperboard" :title="__('Upload your first video')" :text="$uploadsAllowed ? __('Your views, comments and subscribers appear here as soon as people watch.') : __('Uploads are switched off on this site.')">
                @if($uploadsAllowed)
                    <button type="button" class="mk-btn mk-btn--primary" data-bs-toggle="modal" data-bs-target="#video_create"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i><span>{{ __('Upload video') }}</span></button>
                @endif
            </x-mk.empty>
        </section>
    @else
        <div @class(['mk-grid', 'mk-grid--2-1' => $attentionEnabled])>
            <section class="mk-panel" aria-labelledby="mk-latest-title">
                <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-latest-title">{{ __('Latest videos') }}</h2><a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ route('user.videos.index') }}">{{ __('See all') }}</a></div>
                @if($latestVideos === [])
                    <x-mk.empty icon="clapperboard" :title="__('No published videos yet')" :text="__('Drafts and failed uploads are listed in My videos.')" compact />
                @else
                    <ul class="mk-rows">
                        @foreach($latestVideos as $video)
                            <li class="mk-item">
                                <a href="{{ $video['edit'] }}" class="mk-item__media mk-item__media--sm" tabindex="-1" aria-hidden="true">
                                    <span class="mk-thumb">@if($video['poster'])<img src="{{ $video['poster'] }}" alt="" width="160" height="90" loading="lazy" decoding="async">@else<span class="mk-thumb__empty"><i class="fa-regular fa-image"></i></span>@endif @if($video['duration'] !== '')<span class="mk-thumb__dur">{{ $video['duration'] }}</span>@endif</span>
                                </a>
                                <div class="mk-item__body">
                                    <a class="mk-item__title mk-clamp-2" href="{{ $video['edit'] }}" dir="auto">{{ $video['title'] }}</a>
                                    <div class="mk-row__meta"><span><x-mk.status :kind="$video['kind']" :label="$video['label']" /></span>@if($video['scheduled'] !== null)<span>{{ $video['scheduled'] }}</span>@else<span>{{ trans_choice(':count view|:count views', $video['views'], ['count' => StudioFormat::number($video['views'])]) }}</span><span>{{ trans_choice(':count comment|:count comments', $video['comments'], ['count' => StudioFormat::number($video['comments'])]) }}</span>@endif</div>
                                </div>
                                <div class="mk-item__aside"><a class="mk-icon-btn mk-icon-btn--muted" href="{{ $video['edit'] }}" title="{{ __('Edit details') }}" aria-label="{{ __('Edit details') }}"><i class="fa-solid fa-pen" aria-hidden="true"></i></a></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if($attentionEnabled)
                <section class="mk-panel" aria-labelledby="mk-attn-title">
                    <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-attn-title">{{ __('Needs your attention') }}</h2>@if($attention !== [])<span class="mk-count">{{ StudioFormat::number(count($attention)) }}</span>@endif</div>
                    @if($attention === [])
                        <p class="mk-note"><i class="fa-solid fa-circle-check" aria-hidden="true" style="color:var(--avd-success)"></i><span>{{ __('All good: nothing needs your attention.') }}</span></p>
                    @else
                        <div>
                            @foreach($attention as $item)
                                <div class="mk-attn {{ $item['tone'] }}">
                                    <span class="mk-attn__icon" aria-hidden="true"><i class="fa-solid fa-{{ $item['icon'] }}"></i></span>
                                    <div class="mk-attn__body"><strong>{{ $item['sentence'] }}</strong>@if($item['line'] !== '')<span class="mk-muted" style="font-size:.8125rem">{{ $item['line'] }}</span>@endif</div>
                                    <div class="mk-attn__actions">
                                        @foreach($item['actions'] as $action)
                                            @if($action['type'] === 'upload')
                                                @if($uploadsAllowed)
                                                    <button type="button" class="mk-btn mk-btn--secondary mk-btn--sm" data-bs-toggle="modal" data-bs-target="#video_create"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i><span>{{ $action['label'] }}</span></button>
                                                @endif
                                            @elseif($action['type'] === 'delete')
                                                <button type="button" class="mk-btn mk-btn--danger-quiet mk-btn--sm" data-bs-toggle="modal" data-bs-target="#mk-dash-delete" data-action="{{ $action['url'] }}" data-title="{{ $action['title'] }}"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ $action['label'] }}</span></button>
                                            @else
                                                <a class="mk-btn mk-btn--secondary mk-btn--sm" href="{{ $action['url'] }}">{{ $action['label'] }}</a>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif
        </div>

        <div class="mk-grid mk-grid--2-1">
            <section class="mk-panel" aria-labelledby="mk-dash-comments-title">
                <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-dash-comments-title">{{ __('Latest comments') }}</h2><a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ route('user.comments.index') }}">{{ __('See all') }}</a></div>
                @if($latestComments === [])
                    <x-mk.empty icon="comments" :title="__('No comments yet')" :text="__('Comments on your videos appear here.')" compact />
                @else
                    <ul class="mk-rows">
                        @foreach($latestComments as $comment)
                            <li class="mk-item">
                                @if($comment['avatar'] !== null)
                                    <img class="mk-avatar" style="--mk-av:36px" src="{{ $comment['avatar'] }}" alt="" width="36" height="36" loading="lazy" decoding="async">
                                @else
                                    <span class="mk-avatar" style="--mk-av:36px" aria-hidden="true"></span>
                                @endif
                                <div class="mk-item__body">
                                    <div><strong><bdi>{{ $comment['author'] ?? __('Deleted member') }}</bdi></strong> <span class="mk-muted" style="font-size:.8125rem">&nbsp;· {{ $comment['at'] ? StudioFormat::ago(\Carbon\CarbonImmutable::parse($comment['at'])) : '' }}</span></div>
                                    {{-- R1-025: a comment keeps its own direction in every language; names and titles are isolated --}}
                                    <p class="mk-clamp-2" data-mk-clamp dir="auto">{{ $comment['content'] }}</p>
                                    @if($comment['video'] !== null)
                                        <span class="mk-muted mk-ref-line" style="font-size:.8125rem">{!! strtr(e(__('on :title')), [':title' => '<a class="mk-quiet-link mk-ref-line__fit" href="'.e($comment['video']['url']).'" dir="auto">'.e($comment['video']['title']).'</a>']) !!}</span>
                                    @endif
                                </div>
                                <div class="mk-item__aside">
                                    <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--danger" title="{{ __('Remove comment') }}" aria-label="{{ __('Remove comment') }}" data-bs-toggle="modal" data-bs-target="#mk-remove-comment" data-action="{{ $comment['destroy'] }}"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
            <section class="mk-panel" aria-labelledby="mk-dash-subs-title">
                <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-dash-subs-title">{{ __('New subscribers') }}</h2><a class="mk-btn mk-btn--quiet mk-btn--sm" href="{{ route('user.subscribers') }}">{{ __('See all') }}</a></div>
                @if($subscribers === [])
                    <x-mk.empty icon="users" :title="__('No subscribers yet')" :text="__('People who follow your channel appear here.')" compact />
                @else
                    <ul class="mk-rows">
                        @foreach($subscribers as $fan)
                            {{-- R1-021: the time stays at the row end, on the avatar's line (mk-item--timed) --}}
                            <li class="mk-item mk-item--timed">
                                @if($fan['name'] !== null)
                                    <img class="mk-avatar" style="--mk-av:36px" src="{{ $fan['avatar'] }}" alt="" width="36" height="36" loading="lazy" decoding="async">
                                    <div class="mk-item__body">
                                        <a class="mk-item__title" href="{{ $fan['url'] }}"><bdi>{{ $fan['name'] }}</bdi></a>
                                        @if($showSubscriberCounts && ! $fan['hidesCount'])
                                            <span class="mk-muted" style="font-size:.8125rem">{{ $fan['count'] === 0 ? __('No subscribers yet') : trans_choice(':count subscriber|:count subscribers', $fan['count'], ['count' => StudioFormat::number($fan['count'])]) }}</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="mk-avatar" style="--mk-av:36px" aria-hidden="true"></span>
                                    <div class="mk-item__body"><span class="mk-muted">{{ __('Deleted member') }}</span></div>
                                @endif
                                <span class="mk-item__time">{{ $fan['at'] ? StudioFormat::ago(\Carbon\CarbonImmutable::parse($fan['at'])) : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        @if($tipsEnabled && $tips !== [])
            <section class="mk-panel" aria-labelledby="mk-tips-title">
                <div class="mk-panel__head"><h2 class="mk-panel__title" id="mk-tips-title">{{ __('Tips for your videos') }}</h2></div>
                <ul class="mk-rows">
                    @foreach($tips as $tip)
                        <li class="mk-item">
                            <span class="mk-attn__icon" aria-hidden="true"><i class="fa-solid fa-{{ $tip['icon'] }}"></i></span>
                            <div class="mk-item__body"><strong>{{ $tip['title'] }}</strong><span class="mk-muted" style="font-size:.8125rem">{{ $tip['text'] }}</span></div>
                            <div class="mk-item__aside"><a class="mk-btn mk-btn--secondary mk-btn--sm" href="{{ $tip['url'] }}">{{ $tip['action'] }}</a></div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- Remove a comment from the dashboard: the same confirmation as the Comments page. --}}
        <div class="modal fade" id="mk-remove-comment" tabindex="-1" aria-labelledby="mk-remove-comment-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="mk-remove-comment-title">{{ __('Remove this comment?') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <div class="mk-sheet__body"><p class="mk-sheet__lead">{{ __('The comment and its replies are removed from your video. You can\'t undo this.') }}</p></div>
                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                        <button type="submit" class="mk-btn mk-btn--danger" form="mk-remove-comment-form"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Remove comment') }}</span></button>
                    </div>
                    <form id="mk-remove-comment-form" method="POST" action="" data-mk-action-from hidden>@csrf @method('DELETE')</form>
                </div>
            </div>
        </div>

        {{-- Delete a failed upload from "Needs your attention". --}}
        <div class="modal fade" id="mk-dash-delete" tabindex="-1" aria-labelledby="mk-dash-delete-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm">
                <div class="modal-content avd-mk">
                    <div class="mk-sheet__head">
                        <h2 class="mk-sheet__title" id="mk-dash-delete-title">{{ __('Delete this video?') }}</h2>
                        <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                    </div>
                    <div class="mk-sheet__body"><p class="mk-sheet__lead"><strong data-mk-title dir="auto"></strong></p><x-mk.alert type="danger">{{ __('You can\'t undo this. The failed upload and its pictures are deleted.') }}</x-mk.alert></div>
                    <div class="mk-sheet__foot">
                        <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal" data-mk-autofocus>{{ __('Cancel') }}</button>
                        <button type="submit" class="mk-btn mk-btn--danger" form="mk-dash-delete-form"><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span>{{ __('Delete video') }}</span></button>
                    </div>
                    <form id="mk-dash-delete-form" method="POST" action="" data-mk-action-from hidden>@csrf @method('DELETE')</form>
                </div>
            </div>
        </div>
    @endif

    {{-- Custom dates of the dashboard's period (a dialog in the look's pop-up window style). --}}
    <div class="modal fade" id="mk-dash-dates" tabindex="-1" aria-labelledby="mk-dash-dates-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered mk-dialog">
            <div class="modal-content avd-mk">
                <div class="mk-sheet__head">
                    <h2 class="mk-sheet__title" id="mk-dash-dates-title">{{ __('Custom dates') }}</h2>
                    <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
                <form class="mk-sheet__body" method="GET" action="{{ route('user.index') }}" id="mk-dash-dates-form">
                    <input type="hidden" name="period" value="custom">
                    <div class="mk-form">
                        <div class="mk-form__row mk-form__row--2">
                            <div class="mk-field"><label class="mk-label" for="mk-dash-from">{{ __('Start date') }}</label><input class="mk-input" type="date" id="mk-dash-from" name="from" value="{{ $period['from'] }}" max="{{ $period['to'] }}" required></div>
                            <div class="mk-field"><label class="mk-label" for="mk-dash-to">{{ __('End date') }}</label><input class="mk-input" type="date" id="mk-dash-to" name="to" value="{{ $period['to'] }}" max="{{ \Carbon\CarbonImmutable::now(ZoneLabel::zone())->toDateString() }}" required></div>
                        </div>
                        <p class="mk-help">{{ __('Dates use the site\'s time zone (:city). Both days are included.', ['city' => ZoneLabel::city()]) }}</p>
                    </div>
                </form>
                <div class="mk-sheet__foot">
                    <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="mk-btn mk-btn--primary" form="mk-dash-dates-form">{{ __('Apply dates') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @include('users.partials.studio-scripts', ['studioScriptsParts' => ['readmore', 'confirm']])
@endpush
