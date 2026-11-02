@extends('layouts.user')

@section('title', __('New playlist'))

@section('content')
    @include('users.playlists.partials.editor', ['playlist' => null])
@endsection

@push('scripts')
    @include('users.partials.studio-scripts', ['studioScriptsParts' => []])
@endpush
