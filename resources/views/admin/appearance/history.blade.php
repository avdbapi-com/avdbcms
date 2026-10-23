{{--
    History of the design as a page (GET /admin/appearance/history without JavaScript; the Appearance pages show the
    same list in a panel). Undo puts the settings back to how they were before that change.
--}}
@extends('layouts.admin')
@section('title', __('History'))
@section('admin_page', 'appearance.themes')
@section('admin_width', 'form')

@push('head')
    @vite('resources/admin/appearance/appearance.ts')
@endpush

@section('content')
    <x-admin.page-header page="appearance.themes" :title="__('History')" :description="__('The last 20 changes of your design. Undo puts your site back to how it looked before that change.')" />

    <ol class="ad-history-list is-page">
        @forelse ($items as $item)
            <li>
                <span class="ad-history-text">
                    <b>{{ $item['title'] }}</b>
                    <span class="ad-muted">{{ $item['when'] }}@if ($item['by']) · {{ $item['by'] }}@endif · {{ $item['detail'] }}</span>
                </span>
                @if ($item['restorable'])
                    <form method="POST" action="{{ route('admin.appearance.restore', ['revision' => $item['id']]) }}" data-ad-confirm="{{ $item['snapshot'] ? __('Restore your previous look?') : __('Undo this change?') }}">
                        @csrf
                        <button type="submit" class="ad-btn sm">{{ $item['snapshot'] ? __('Restore') : __('Undo') }}</button>
                    </form>
                @endif
            </li>
        @empty
            <li class="ad-muted">{{ __('No changes yet.') }}</li>
        @endforelse
    </ol>
@endsection
