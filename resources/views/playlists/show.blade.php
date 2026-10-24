@extends('layouts.default')

@php
    $seoService = app(\App\Services\SeoService::class);
    $playlistDescription = trim(strip_tags((string) $playlist->description));
    $playlistSeoValues = [
        'title' => $playlist->title,
        'description' => $playlistDescription,
        'username' => $playlist->user?->username,
        'user' => $playlist->user?->username,
        'videos_count' => (string) $videos->count(),
    ];
@endphp

@section('title', $seoService->renderTemplate('playlist_meta_title_template', $playlistSeoValues, $playlist->title, 180))
@section('description', $seoService->renderTemplate('playlist_meta_description_template', $playlistSeoValues, \Illuminate\Support\Str::limit($playlistDescription, 155, ''), 500))
@section('image', $playlist->first_video?->thumbnail_url ?? '')

@section('content')
    <div class="row justify-content-center gy-3">
        <div class="col-md-12 col-lg-5 col-xl-4 col-xxl-4">
            <div x-data="{favorite: {{$favorite_by_auth_user ? 'true' : 'false'}}}" class="h-100 card">
                @if($playlist->first_video)
                    <img class="img-fluid card-img-top" src="{{$playlist->first_video->thumbnail_url}}" alt="{{$playlist->title}}" width="640" height="360" loading="eager" fetchpriority="high" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/placeholders/no-image.svg') }}';">
                @endif
                <div class="card-body">
                    <h2 class="h4 my-3">{{$playlist->title}}</h2>
                    <div class="mt-3">{{ __('by') }}
                        @if($playlist->user)
                            <a class="text-decoration-none fw-bold" href="{{$playlist->user->route}}">{{$playlist->user->username}}</a>
                        @else
                            <span class="text-muted">{{__('Deleted user')}}</span>
                        @endif
                    </div>
                    <span class="badge bg-{{$playlist->status->color()}} my-3">
                        <i class="fa-solid fa-{{$playlist->status->icon()}}"></i>&nbsp;
                        {{$playlist->status->name()}}
                    </span>
                    <div class="d-flex align-items-center gap-1 mb-2">
                        <div class="text-sm">{{trans_choice('videos', $videos->count())}}</div>
                        <div class="text-sm">•</div>
                        <div class="text-sm">{{ __('Created') }} {{$playlist->created_at->diffForHumans()}}</div>
                    </div>
                    <div class="text-sm mb-3">{{ __('Updated') }} {{$playlist->updated_at->diffForHumans()}}</div>
                    <div class="d-flex flex-wrap gap-2">
                        @if($playlist->first_video)
                            <a href="{{$playlist->first_video->routeWithParams(['list' => $playlist->uuid])}}" class="btn btn-danger btn-sm d-flex gap-2 align-items-center">
                                <i class="fa-solid fa-play"></i>
                                <span>{{ __('Play All') }}</span>
                            </a>
                        @endif
                        @auth
                            @can('favorite', $playlist)
                                <button
                                    x-show.important="!favorite"
                                    x-cloak
                                    @click="favorite = true;"
                                    class="btn btn-primary d-flex align-items-center gap-2 btn-sm ajax-button"
                                    data-url="{{route('user.playlists.favorite', $playlist)}}"
                                    data-method="POST"
                                >
                                    <i class="fa-regular fa-heart"></i>
                                    <span>{{ __('Add to favorites') }}</span>
                                </button>
                                <button
                                    x-show.important="favorite"
                                    x-cloak
                                    @click="favorite = false;"
                                    class="btn btn-primary d-flex align-items-center gap-2 btn-sm ajax-button"
                                    data-url="{{route('user.playlists.favorite', $playlist)}}"
                                    data-method="POST"
                                >
                                    <i class="fa-solid fa-heart"></i>
                                    <span>{{ __('Remove from favorites') }}</span>
                                </button>
                            @endcan
                        @else
                            <button
                                type="button"
                                tabindex="0"
                                class="btn btn-primary btn-sm"
                                data-bs-toggle="popover"
                                data-bs-placement="right"
                                data-bs-title="{{ __('Want to save this playlist ?') }}"
                                data-bs-trigger="focus"
                                data-bs-html="true"
                                data-bs-content="{{ __('Sign in to save this playlist') }}.<hr><a href='{{ route('login') }}' class='btn btn-primary btn-sm'>{{ __('Sign In') }}</a>"
                            >
                                <i class="fa-regular fa-heart"></i>
                                <span>{{ __('Add to favorites') }}</span>
                            </button>
                        @endauth
                    </div>
                    @if($playlist->user && $playlist->user->is(Auth::user()))
                        <div class="d-flex align-items-start mt-2">
                            <a href="{{route('user.playlists.edit', $playlist)}}" class="btn btn-info btn-sm d-flex gap-2">
                                <i class="fa-solid fa-pen"></i>
                                <span>{{ __('Edit playlist') }}</span>
                            </a>
                        </div>
                    @endif
                    <div class="mt-3">
                        <x-expand-item max="150" color="dark">
                            {{$playlist->description}}
                        </x-expand-item>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-12 col-lg-7 col-xl-8 col-xxl-8">
            <div class="card card-body p-0">
                @if($videos->count())
                    <div class="avd-playlist-video-list">
                        <ul class="list-group list-group-flush">
                            @foreach($videos as $key => $video)
                                    <li class="d-flex gap-3 align-items-center list-group-item list-group-item-action px-2 px-sm-3 py-3 py-sm-3">
                                    @if(($video->user && $video->user->is(Auth::user())) || $video->is_public)
                                        @include('videos.card-secondary', ['video' => $video, 'playlist_video' => true])
                                    @else
                                        <div class="w-100 align-items-center">
                                            <div class="col-12">
                                                <div class="bg-secondary text-white d-flex flex-column justify-content-center align-items-center w-100 gap-2" style="height: 100px">
                                                    <i class="fa-solid fa-lock fa-1x"></i>
                                                    <div class="text-center text-sm">
                                                        <div class='text-sm fw-bold'>{{ __('This video is private') }}</div>
                                                        <div class='text-sm'>{{ __('The author update video status to private') }}</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                    </li>
                                @if(\App\Support\FeedAdSlots::listAdAfter($loop->iteration, $loop->count, \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6))))
                                    <x-feed-ad-slot as="li" context="playlist_videos" class="list-group-item px-2 px-sm-3 py-3" />
                                @endif
                            @endforeach
                        </ul>
                    </div>
                @else
                    <div class="alert alert-info mb-0">
                        {{ __('This playlist does not contain any videos at the moment') }}
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
