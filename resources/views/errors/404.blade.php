@extends('errors.minimal')

{{-- 404 (member design 6.15): "We cannot find this page", with the search and the home page. --}}
@section('title', __('We cannot find this page'))
@section('code', '404')
@section('icon', 'compass')
@section('message', __('We cannot find this page'))
@section('text', __('The link may be broken or the page was moved. Try the search, or start from the home page.'))
@section('actions')
    <a class="mk-btn mk-btn--primary" href="{{ locale_url('/') }}"><i class="fa-solid fa-house" aria-hidden="true"></i><span>{{ __('Go home') }}</span></a>
    <a class="mk-btn mk-btn--secondary" href="{{ locale_url(route('search.index')) }}"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span>{{ __('Search') }}</span></a>
@endsection
