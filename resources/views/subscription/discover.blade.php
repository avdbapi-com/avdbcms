@extends('layouts.default')

@section('title', __('Discover channels'))

{{-- Discover channels /discover (member design 6.11, QA channel-33): channel cards, two per row on phones, whose
     Subscribe never wraps (a card narrower than 180px drops the button's icon). No animated title. --}}
@section('content')
    <div class="avd-mk mk-page mk-discover">
        <x-mk.head :title="__('Discover channels')" :kicker="__('Discover')" icon="compass" :desc="__('Creators on this site, the most followed first.')" />

        @if($users->count())
            <div class="mk-chan-cards">
                @each('subscription.partials.user', $users, 'user')
            </div>
        @else
            <section class="mk-panel">
                <x-mk.empty icon="compass" :title="__('No channels to discover yet')" :text="__('When creators share videos here, their channels appear on this page.')" />
            </section>
        @endif
        @include('library.actions-script')
    </div>
@endsection
