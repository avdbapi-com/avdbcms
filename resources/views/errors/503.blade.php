@extends('errors::minimal')

@section('title', __('Service Unavailable'))
@section('code', '503')
@section('message', __( config('app.name').' is under maintenance'))
@section('text')
    <p class="mb-1">{{config('app.name')}} {{__('is undergoing maintenance. Sorry for the inconvenience')}}</p>
    <p>{{__('Please go back later')}}</p>
@endsection
