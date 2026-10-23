@extends('errors::minimal')

@section('title', __('Gone'))
@section('code', '410')
@section('message', __($exception->getMessage() ?: 'This content has been removed'))
@section('text')
    <p class="mb-1">{{__("This content has been permanently removed and is no longer available")}}</p>
    <p>{{__("Please go back to the homepage")}}</p>
@endsection
