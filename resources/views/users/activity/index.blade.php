{{-- "Your activity" (member design 6.8): a timeline grouped by day (Today, Yesterday, 23 Oct), each entry an
     icon + a sentence + the time. Filter links All, Likes, Dislikes, Comments, Replies (studio-9) and a Date select;
     per_page_activity (12). Only the member sees this page. --}}
@extends('layouts.user')

@section('title', __('Your activity'))

@section('content')
@php
    $links = [];
    foreach (['' => __('All'), 'likes' => __('Likes'), 'dislikes' => __('Dislikes'), 'comments' => __('Comments'), 'replies' => __('Replies')] as $key => $label) {
        $links[] = [
            'label' => $label,
            'url' => route('user.activity.index', array_filter(['type' => $key, 'date' => $date], fn ($value) => $value !== '')),
            'current' => $type === $key,
        ];
    }
@endphp
<div class="avd-mk mk-page">
    <x-mk.head icon="clock-rotate-left" :kicker="__('Activity')" :title="__('Your activity')" :desc="__('What you liked, disliked and wrote on the site. Only you see this.')" />
    <x-mk.flash refusal />

    @if($activities->total() === 0 && ! $filtersActive)
        <section class="mk-panel">
            <x-mk.empty icon="clock-rotate-left" :title="__('No activity yet')" :text="__('Videos and comments you like or dislike, and the comments you write, appear here.')">
                <a class="mk-btn mk-btn--secondary" href="{{ route('pages.home') }}"><i class="fa-solid fa-play" aria-hidden="true"></i><span>{{ __('Watch videos') }}</span></a>
            </x-mk.empty>
        </section>
    @else
        <section class="mk-panel" aria-label="{{ __('Activity') }}">
            @include('users.partials.filters', [
                'id' => 'activity',
                'action' => route('user.activity.index'),
                'hidden' => ['type' => $type],
                'links' => ['label' => __('Show'), 'items' => $links],
                'search' => null,
                'selects' => [
                    ['name' => 'date', 'label' => __('Date'), 'options' => ['' => __('Any date'), '7' => __('Last 7 days'), '28' => __('Last 28 days'), '90' => __('Last 90 days')], 'value' => $date, 'fold' => false],
                ],
                'custom' => null,
                'clearUrl' => route('user.activity.index'),
                'active' => $filtersActive,
            ])
            @if($days === [])
                <x-mk.empty icon="filter" :title="__('No activity matches these filters')" :text="__('Try another filter or another date.')" compact>
                    <a class="mk-btn mk-btn--secondary" href="{{ route('user.activity.index') }}">{{ __('Clear filters') }}</a>
                </x-mk.empty>
            @else
                <ul class="mk-days">
                    @foreach($days as $day)
                        <li class="mk-day">
                            <h2 class="mk-day__title">{{ $day['label'] }}</h2>
                            <ul class="mk-rows">
                                @foreach($day['items'] as $activity)
                                    @include('users.activity.partials.entry', ['activity' => $activity])
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>
            @endif
            {{ $activities->onEachSide(1)->links('vendor.pagination.mk') }}
        </section>
    @endif
    @stack('mk-overlays')
</div>
@endsection
