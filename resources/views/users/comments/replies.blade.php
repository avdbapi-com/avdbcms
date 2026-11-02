{{-- The replies of a comment as a page of its own (member design 6.8): where "N replies" leads without
     JavaScript (with JavaScript it opens the replies sheet of Comments). --}}
@extends('layouts.user')

@section('title', __('Replies'))

@section('content')
<div class="avd-mk mk-page">
    <ol class="mk-crumbs">
        <li><a href="{{ route('user.comments.index') }}">{{ __('Comments') }}</a></li>
        <li><span>{{ __('Replies') }}</span></li>
    </ol>
    <x-mk.head icon="comments" :kicker="__('Studio')" :title="__('Replies')" :desc="__('Remove anything that breaks your rules.')" />
    <x-mk.flash refusal />
    <section class="mk-panel mk-replies-panel" aria-label="{{ __('Replies') }}">
        @include('users.comments.partials.replies')
    </section>
    @include('users.comments.partials.remove-reply')
</div>
@endsection

@push('scripts')
    @include('users.partials.studio-scripts', ['studioScriptsParts' => ['confirm']])
@endpush
