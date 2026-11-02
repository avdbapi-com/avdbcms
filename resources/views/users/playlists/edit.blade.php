@extends('layouts.user')

@section('title', __('Edit playlist'))

@section('content')
    @include('users.playlists.partials.editor', ['playlist' => $playlist])
@endsection

@push('scripts')
    @include('users.partials.studio-scripts', ['studioScriptsParts' => []])
@endpush
