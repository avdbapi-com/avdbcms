@extends('layouts.user')

@section('title', __('Video details'))

@section('content')
    @include('users.videos.partials.form', ['draft' => false])
@endsection

{{-- studio-11: the page's script is pushed into the layout's scripts stack (it was printed before <!DOCTYPE html> and
     put the page in quirks mode). --}}
@push('scripts')
    {{-- the core part only: no row menu, bulk bar or clamped text on this page (1.2.3 round 1, R1-069) --}}
    @include('users.partials.studio-scripts', ['studioScriptsParts' => []])
@endpush
