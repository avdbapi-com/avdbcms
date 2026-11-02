@extends('layouts.user')

@section('title', __('Finish your video'))

@section('content')
    {{-- The draft page (member design 6.4): the same layout as Video details, with the Upload18 processing state and hooks
         (data-video-draft-form, data-video-processing-box, data-upload18-pending: users/videos/partials/form.blade.php). --}}
    @include('users.videos.partials.form', ['draft' => true])
@endsection

@push('scripts')
    {{-- the core part only: no row menu, bulk bar or clamped text on this page (1.2.3 round 1, R1-069) --}}
    @include('users.partials.studio-scripts', ['studioScriptsParts' => []])
@endpush

{{-- The Upload18 completion poll, the failed notice and the thumbnail capture (moved out verbatim; hands-off, included
     unchanged). --}}
@include('users.videos.partials.create-status-poll')
