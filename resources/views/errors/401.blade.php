@extends('errors.minimal')

{{-- 401 (member design 6.15): a request a page answers with "sign in first" (an expired signed link of a guest). --}}
@section('title', __('Sign in to continue'))
@section('code', '401')
@section('icon', 'right-to-bracket')
@section('message', __('Sign in to continue'))
@section('text', __('This page is for members. Sign in, or create an account, and you come straight back here.'))
@section('actions')
    <a class="mk-btn mk-btn--primary" href="{{ \App\Http\Controllers\Auth\ReturnPath::loginUrl() }}"><i class="fa-solid fa-right-to-bracket mk-flip-rtl" aria-hidden="true"></i><span>{{ __('Sign in') }}</span></a>
    @if(config('site.registration_enabled', true))
        <a class="mk-btn mk-btn--secondary" href="{{ locale_url(route('registration')) }}"><i class="fa-solid fa-user-plus" aria-hidden="true"></i><span>{{ __('Create account') }}</span></a>
    @endif
@endsection
