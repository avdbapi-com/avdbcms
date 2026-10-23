@use('App\Filters\Forms\User\DashboardFiltersForm')

@extends('layouts.user')

@section('title', __('Dashboard'))

@section('content')
    {!! form(FormBuilder::create(DashboardFiltersForm::class)) !!}
    <div class="row mb-4">
        <div class="col-12 col-sm-4 col-md-4 col-xl-3 col-xxl mb-4 mb-xl-0">
            <div class="card shadow border-primary">
                <div class="card-body">
                    <h5 class="card-title text-center text-primary">{{ __('Videos') }}</h5>
                    <hr>
                    <div class="d-flex align-items-center justify-content-center gap-4">
                        <i class="fa-solid fa-video fa-2x"></i>
                        <p class="card-text text-center fs-1">{{$user->videos_count}}</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-4 col-md-4 col-xl-3 col-xxl mb-4 mb-xl-0">
            <div class="card shadow border-primary">
                <div class="card-body">
                    <h5 class="card-title text-center text-primary">{{ __('Subscribers') }}</h5>
                    <hr>
                    <div class="d-flex align-items-center justify-content-center gap-4">
                        <i class="fa-solid fa-user fa-2x"></i>
                        <p class="card-text text-center fs-1">{{$user->subscribers_count}}</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-4 col-md-4 col-xl-3 col-xxl mb-4 mb-xl-0">
            <div class="card shadow border-primary">
                <div class="card-body">
                    <h5 class="card-title text-center text-primary">{{ __('Views') }}</h5>
                    <hr>
                    <div class="d-flex align-items-center justify-content-center gap-4">
                        <i class="fa-solid fa-eye fa-2x"></i>
                        <p class="card-text text-center fs-1">{{$user->videos_views_count}}</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-6 col-xl-3 col-xxl mb-4 mb-sm-0">
            <div class="card shadow border-primary">
                <div class="card-body">
                    <h5 class="card-title text-center text-primary">{{ __('Comments') }}</h5>
                    <hr>
                    <div class="d-flex align-items-center justify-content-center gap-4">
                        <i class="fa-solid fa-comment fa-2x"></i>
                        <p class="card-text text-center fs-1">{{$user->videos_comments_count}}</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-6 col-xl mt-0 mt-xl-4 mt-xxl-0">
            <div class="card shadow border-primary">
                <div class="card-body text-center">
                    <h5 class="card-title text-center text-primary">{{ __('Interactions') }}</h5>
                    <hr>
                    <div class="d-flex align-items-center justify-content-center gap-5">
                        <div class="d-flex align-items-center justify-content-center gap-2">
                            <i class="fa-solid fa-thumbs-up fa-2x"></i>
                            <p class="card-text text-center fs-1">{{$user->videos_likes_count}}</p>
                        </div>
                        <div class="d-flex align-items-center justify-content-center gap-2">
                            <i class="fa-solid fa-thumbs-down fa-2x"></i>
                            <p class="card-text text-center fs-1">{{$user->videos_dislikes_count}}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @php
        $studioHealthAlertsEnabled = $studioHealthAlertsEnabled ?? config('site.studio_health_alerts_enabled', \App\Services\StudioHealthAlertsService::DEFAULT_ENABLED);
        $studioHealthAlerts = $studioHealthAlerts ?? collect();
    @endphp
    @if($studioHealthAlertsEnabled)
        <div class="row mb-4">
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-3 p-3">
                        <i class="fa-solid fa-shield-heart"></i>
                        <h5 class="card-title mb-0">
                            {{ __('Studio Health Alerts') }}
                        </h5>
                    </div>
                    <div class="card-body">
                        @if($studioHealthAlerts->isNotEmpty())
                            <div class="d-flex flex-column flex-xl-row gap-3">
                                @foreach($studioHealthAlerts as $alert)
                                    @php
                                        $alertVideo = $alert['video'] ?? null;
                                    @endphp
                                    <div class="border rounded p-3 flex-fill" style="min-width: 0;">
                                        <div class="d-flex align-items-start gap-3">
                                            <span class="badge bg-{{ $alert['severity'] ?? 'secondary' }} mt-1">
                                                <i class="fa-solid fa-{{ $alert['icon'] ?? 'circle-info' }}"></i>
                                            </span>
                                            <div class="avd-studio-alert-copy">
                                                <div class="fw-bold">{{ $alert['title'] ?? __('Studio alert') }}</div>
                                                @if($alertVideo)
                                                    <a href="{{ $alertVideo->route }}" class="text-decoration-none d-block text-wrap avd-studio-alert-link" title="{{ $alertVideo->title }}">
                                                        {{ Str::limit($alertVideo->title, 56) }}
                                                    </a>
                                                @endif
                                                <small class="text-muted">{{ $alert['message'] ?? '' }}</small>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-4 px-3">
                                <div class="rounded-circle bg-success-subtle text-success d-inline-flex align-items-center justify-content-center mb-3" style="width: 48px; height: 48px;">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                                <h5 class="mb-2 mx-auto text-wrap" style="max-width: 22rem; overflow-wrap: anywhere;">
                                    {{ __('No urgent studio alerts') }}
                                </h5>
                                <p class="text-muted mb-0">
                                    {{ __('Recent uploads, reports, thumbnails, and views are within the current alert rules.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
    @php
        $creatorRecoveryChecklistEnabled = $creatorRecoveryChecklistEnabled ?? config('site.creator_recovery_checklist_enabled', \App\Services\CreatorRecoveryChecklistService::DEFAULT_ENABLED);
        $creatorRecoveryChecklistItems = $creatorRecoveryChecklistItems ?? collect();
    @endphp
    @if($creatorRecoveryChecklistEnabled)
        <div class="row mb-4">
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-3 p-3">
                        <i class="fa-solid fa-list-check"></i>
                        <h5 class="card-title mb-0">
                            {{ __('Creator Recovery Checklist') }}
                        </h5>
                    </div>
                    <div class="card-body">
                        @if($creatorRecoveryChecklistItems->isNotEmpty())
                            <div class="d-flex flex-column flex-lg-row gap-3">
                                @foreach($creatorRecoveryChecklistItems as $item)
                                    @php
                                        $checklistVideo = $item['video'] ?? null;
                                    @endphp
                                    <div class="border rounded p-3 flex-fill" style="min-width: 0;">
                                        <div class="d-flex align-items-start gap-3">
                                            <span class="badge bg-{{ $item['severity'] ?? 'secondary' }} mt-1">
                                                <i class="fa-solid fa-{{ $item['icon'] ?? 'circle-check' }}"></i>
                                            </span>
                                            <div style="min-width: 0; flex: 1 1 auto; max-width: 100%;">
                                                <div class="fw-bold">{{ $item['title'] ?? __('Recovery item') }}</div>
                                                @if($checklistVideo)
                                                    <div class="text-muted text-wrap" style="max-width: 100%; overflow-wrap: anywhere; word-break: break-word;">
                                                        {{ Str::limit($checklistVideo->title, 56) }}
                                                    </div>
                                                @endif
                                                <small class="text-muted d-block mb-2">{{ $item['message'] ?? '' }}</small>
                                                @if(!empty($item['action_url']))
                                                    <a class="btn btn-outline-primary btn-sm" href="{{ $item['action_url'] }}">
                                                        {{ $item['action_label'] ?? __('Review') }}
                                                    </a>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-4 px-3">
                                <div class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center mb-3" style="width: 48px; height: 48px;">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                                <h5 class="mb-2 mx-auto text-wrap" style="max-width: 22rem; overflow-wrap: anywhere;">
                                    {{ __('No stalled creator tasks') }}
                                </h5>
                                <p class="text-muted mb-0">
                                    {{ __('Drafts, failed uploads, and unpublished videos are current under the configured rules.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
    @php
        $creatorQualityPulseEnabled = $creatorQualityPulseEnabled ?? config('site.creator_quality_pulse_enabled', \App\Services\CreatorQualityPulseService::DEFAULT_ENABLED);
        $creatorQualityPulseVideos = $creatorQualityPulseVideos ?? collect();
    @endphp
    @if($creatorQualityPulseEnabled)
        <div class="row mb-4">
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center gap-3 p-3">
                        <i class="fa-solid fa-gauge-high"></i>
                        <h5 class="card-title mb-0">
                            {{ __('Creator Quality Pulse') }}
                        </h5>
                    </div>
                    <div class="card-body">
                        @if($creatorQualityPulseVideos->isNotEmpty())
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead>
                                    <tr>
                                        <th scope="col" class="w-40" style="min-width: 260px">{{ __('Video') }}</th>
                                        <th scope="col">{{ __('Recent views') }}</th>
                                        <th scope="col">{{ __('Recent comments') }}</th>
                                        <th scope="col">{{ __('Thumbnail') }}</th>
                                        <th scope="col" style="min-width: 160px">{{ __('Pulse') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach($creatorQualityPulseVideos as $video)
                                        @php
                                            $pulseScore = (int) $video->creator_quality_score;
                                        @endphp
                                        <tr>
                                            <td class="align-middle">
                                                <div class="d-flex gap-3 align-items-center">
                                                    <a href="{{$video->route}}">
                                                        @include('users.videos.partials.thumbnail', ['video' => $video])
                                                    </a>
                                                    <small>{{Str::limit($video->title, 42)}}</small>
                                                </div>
                                            </td>
                                            <td class="align-middle">{{ trans_choice('views', (int) $video->creator_quality_views_count) }}</td>
                                            <td class="align-middle">{{ trans_choice('comments', (int) $video->creator_quality_comments_count) }}</td>
                                            <td class="align-middle">
                                                <span class="badge bg-{{ $video->creator_quality_thumbnail_ready ? 'success' : 'secondary' }}">
                                                    {{ $video->creator_quality_thumbnail_ready ? __('Ready') : __('Missing') }}
                                                </span>
                                            </td>
                                            <td class="align-middle">
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="progress flex-grow-1" style="height: .6rem;">
                                                        <div
                                                            class="progress-bar bg-primary"
                                                            role="progressbar"
                                                            style="width: {{ $pulseScore }}%;"
                                                            aria-valuenow="{{ $pulseScore }}"
                                                            aria-valuemin="0"
                                                            aria-valuemax="100"
                                                        ></div>
                                                    </div>
                                                    <span class="small fw-bold">{{ $pulseScore }}</span>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-center my-3 px-3">
                                <i class="fa-solid fa-gauge-high fa-2x"></i>
                                <h5 class="my-3 mx-auto text-wrap" style="max-width: 22rem; overflow-wrap: anywhere;">{{ __('Upload videos to build your quality pulse') }}</h5>
                                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#video_create">
                                    <i class="fa-solid fa-upload"></i>
                                    {{ __('Import') }}
                                </button>
                            </div>
                        @endif
                    </div>
                    <div class="card-footer">
                        <a href="{{route('user.videos.index')}}" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-eye"></i>&nbsp;
                            {{ __('Review videos') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @endif
    <div class="row mb-4">
        <div class="col-xl-7 mb-4 mb-xl-0">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-3 p-3">
                    <i class="fa-solid fa-video"></i>
                    <h5 class="card-title mb-0">
                        {{ __('Last uploaded videos') }}
                    </h5>
                </div>
                <div class="card-body">
                    @if($user->videos_count || request()->all())
                        <div class="table-responsive">
                            <table class="table">
                            <thead>
                            <tr>
                                <th scope="col" class="w-35" style="min-width: 250px">{{ __('Video') }}</th>
                                <th scope="col" class="w-15" >{{ __('Status') }}</th>
                                <th scope="col" class="w-10">{{ __('Views') }}</th>
                                <th scope="col" class="w-10">{{ __('Comments') }}</th>
                                <th scope="col" class="w-20">{{ __('Interactions') }}</th>
                                <th scope="col" class="w-10">{{ __('Actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                                @forelse($user->videos as $video)
                                    <tr>
                                        <td class="align-middle d-flex gap-3 align-items-center">
                                            <a href="{{$video->route}}">
                                                @include('users.videos.partials.thumbnail', ['video' => $video])
                                            </a>
                                            <small>{{Str::limit($video->title, 30)}}</small>
                                        </td>
                                        <td class="align-middle">
                                            @include('videos.status')
                                        </td>
                                        <td class="align-middle">{{$video->views}}</td>
                                        <td class="align-middle">
                                            @if($video->comments_count)
                                                <a href="{{route('user.comments.index') .'?video='.$video->id}}" class="badge bg-info text-decoration-none">
                                                    {{trans_choice('comments', $video->comments_count)}}
                                                </a>
                                            @else
                                                <div class="badge bg-secondary">
                                                    {{ __('No comments') }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="align-middle">
                                            @include('users.partials.interactions', ['item' => $video])
                                        </td>
                                        <td class="align-middle text-center">
                                            @can('update', $video)
                                                @if($video->is_draft)
                                                    <a href="{{route('user.videos.create', $video)}}" title="{{__('Edit draft')}}">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </a>
                                                @else
                                                    <a href="{{route('user.videos.edit', $video)}}" title="{{__('Edit video')}}">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </a>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr class="bg-light">
                                        <td colspan="7" class="text-center">
                                            <i class="fa-solid fa-video-slash fa-2x my-3"></i>
                                            <p class="fw-bold">{{ __('No matching videos for this period') }}</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    @else
                        <div class="text-center my-3">
                            <i class="fa-solid fa-upload fa-2x"></i>
                            <h5 class="my-3">{{ __('Import your first video') }}</h5>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#video_create">
                                <i class="fa-solid fa-plus"></i>
                                {{ __('Import') }}
                            </button>
                        </div>
                    @endif
                </div>
                <div class="card-footer">
                    <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-2">
                        <a href="{{route('user.videos.index')}}" class="btn btn-primary btn-sm w-100 w-sm-auto">
                            <i class="fa-solid fa-eye"></i>&nbsp;
                            {{ __('See all videos') }}
                        </a>
                        <button class="btn btn-success btn-sm w-100 w-sm-auto" data-bs-toggle="modal" data-bs-target="#video_create">
                            <i class="fa-solid fa-upload"></i>
                            {{ __('Import new video') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-3 p-3">
                    <i class="fa-solid fa-user"></i>
                    <h5 class="card-title mb-0">
                        {{ __('Last subscribers')}}
                    </h5>
                </div>
                <div class="card-body">
                    @if($user->subscribers_count || request()->all())
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th scope="col">{{ __('Channel') }}</th>
                                <th scope="col" style="min-width: 100px;">{{ __('Date') }}</th>
                                <th scope="col">{{ __('Subscribers') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($user->subscribers as $subscriber)
                                <tr>
                                    <td class="align-middle">
                                        <a href="{{$subscriber->route}}" class="d-flex align-items-center gap-2 text-decoration-none">
                                            <img class="rounded-circle" src="{{$subscriber->avatar_url}}" alt="{{$subscriber->username}} avatar" style="width: 45px;">
                                            <span>{{$subscriber->username}}</span>
                                        </a>
                                    </td>
                                    <td class="align-middle">
                                        <div class="text-sm" data-bs-toggle="tooltip" data-bs-title="{{$subscriber->pivot->subscribe_at->translatedFormat('d F Y - H:i')}}">
                                            {{$subscriber->pivot->subscribe_at->diffForHumans()}}
                                        </div>
                                    </td>
                                    <td class="align-middle">
                                        <div class="text-sm">
                                            {{trans_choice('subscribers', $subscriber->subscribers_count)}}
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr class="bg-light">
                                    <td colspan="7" class="text-center">
                                        <i class="fa-solid fa-user-slash fa-2x my-3"></i>
                                        <p class="fw-bold">{{ __('No matching subscribers for this period') }}</p>
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @else
                        <div class="text-center my-3">
                            <i class="fa-solid fa-user-slash fa-2x"></i>
                            <h5 class="my-3">{{ __('No subscribers yet') }}</h5>
                        </div>
                    @endif
                </div>
                <div class="card-footer">
                    <div class="d-flex gap-2">
                        <a href="{{route('user.subscribers')}}" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-eye"></i>&nbsp;
                            {{ __('See all subscribers') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row mb-4">
        <div class="col-xl-7 mb-4 mb-xl-0">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-3 p-3">
                    <i class="fa-solid fa-comment"></i>
                    <h5 class="card-title mb-0">
                        {{ __('Last comments on my videos') }}
                    </h5>
                </div>
                <div class="card-body">
                    @if($user->videos_comments_count || request()->all())
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th scope="col" class="w-20" >{{ __('Video') }}</th>
                                    <th scope="col" class="w-55" style="min-width: 260px">{{ __('Comment') }}</th>
                                    <th scope="col" class="w-10">{{ __('Replies') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($user->videos_comments as $comment)
                                    <tr>
                                        <td class="align-start">
                                            <div class="d-flex gap-3 align-items-center">
                                                @if($comment->video)
                                                    <a href="{{$comment->video->route}}">
                                                        @include('users.videos.partials.thumbnail', ['video' => $comment->video])
                                                    </a>
                                                @else
                                                    <span class="text-muted text-sm"><i class="fa-solid fa-video-slash me-1"></i>{{__('Deleted')}}</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="align-middle">
                                            <div class="d-flex gap-3 align-items-center">
                                                @if($comment->user)
                                                    <a href="{{$comment->user->route}}">
                                                        <img class="rounded-circle" src="{{$comment->user->avatar_url}}" alt="{{$comment->user->username}} avatar" style="width: 45px;">
                                                    </a>
                                                    <div>
                                                        <div class="d-flex align-items-center">
                                                            <a href="{{$comment->user->route}}" class="text-decoration-none text-sm">{{$comment->user->username}}</a>
                                                            <small class="text-muted" data-bs-toggle="tooltip" data-bs-title="{{$comment->created_at->translatedFormat('d F Y - H:i')}}">&nbsp;• {{$comment->created_at->diffForHumans()}}</small>
                                                        </div>
                                                        <x-expand-item max="180" class="d-block">
                                                            {{$comment->content}}
                                                        </x-expand-item>
                                                    </div>
                                                @else
                                                    <span class="text-muted"><i class="fa-solid fa-user-slash me-1"></i></span>
                                                    <div>
                                                        <div class="d-flex align-items-center">
                                                            <span class="text-muted text-sm">{{__('Deleted user')}}</span>
                                                            <small class="text-muted">&nbsp;• {{$comment->created_at->diffForHumans()}}</small>
                                                        </div>
                                                        <x-expand-item max="180" class="d-block">
                                                            {{$comment->content}}
                                                        </x-expand-item>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="align-middle">
                                            @if($comment->replies_count)
                                                <div class="badge bg-info">
                                                    {{trans_choice('replies', $comment->replies_count)}}
                                                </div>
                                            @else
                                                <div class="badge bg-secondary">
                                                    {{ __('No replies') }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr class="bg-light">
                                        <td colspan="7" class="text-center">
                                            <i class="fa-solid fa-comment-slash fa-2x my-3"></i>
                                            <p class="fw-bold">{{ __('No matching comments for this period') }}</p>
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="text-center my-3">
                            <i class="fa-solid fa-comment-slash fa-2x"></i>
                            <h5 class="my-3">{{ __('No comments yet') }}</h5>
                        </div>
                    @endif
                </div>
                <div class="card-footer">
                    <div class="d-flex gap-2">
                        <a href="{{route('user.comments.index')}}" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-eye"></i>&nbsp;
                            {{ __('See all comments') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center gap-3 p-3">
                    <i class="fa-solid fa-thumbs-up"></i>
                    <h5 class="card-title mb-0">
                        {{ __('Last interactions on my videos') }}
                    </h5>
                </div>
                <div class="card-body">
                    @if($user->videos_interactions_count || request()->all())
                        <div class="table-responsive">
                            <table class="table">
                            <thead>
                            <tr>
                                <th scope="col">{{ __('Video') }}</th>
                                <th scope="col" style="min-width: 150px;">{{ __('User') }}</th>
                                <th scope="col">{{ __('Interaction') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                                @forelse($user->videos_interactions as $interaction)
                                <tr>
                                    <td class="align-middle">
                                        @if($interaction->likeable)
                                            <a href="{{$interaction->likeable->route}}">
                                                @include('users.videos.partials.thumbnail', ['video' => $interaction->likeable])
                                            </a>
                                        @else
                                            <span class="text-muted text-sm"><i class="fa-solid fa-video-slash me-1"></i>{{__('Deleted')}}</span>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        @if($interaction->user)
                                            <a href="{{$interaction->user->route}}" class="d-flex align-items-center gap-2 text-decoration-none">
                                                <img class="rounded-circle" src="{{$interaction->user->avatar_url}}" alt="{{$interaction->user->username}} avatar" style="width: 45px;">
                                                <div class="d-flex flex-column text-sm">
                                                    <span>{{$interaction->user->username}}</span>
                                                    <span class="text-muted" data-bs-toggle="tooltip" data-bs-title="{{$interaction->perform_at->translatedFormat('d F Y - H:i')}}">{{$interaction->perform_at->diffForHumans()}}</span>
                                                </div>
                                            </a>
                                        @else
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="text-muted"><i class="fa-solid fa-user-slash"></i></span>
                                                <span class="text-muted text-sm">{{__('Deleted user')}}</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="align-middle">
                                        @if($interaction->status)
                                            <div class="badge bg-success">
                                                <i class="fa-solid fa-thumbs-up"></i>
                                            </div>
                                        @else
                                            <div class="badge bg-danger">
                                                <i class="fa-solid fa-thumbs-down"></i>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                    <tr class="bg-light">
                                        <td colspan="7" class="text-center">
                                            <i class="fa-solid fa-bell-slash fa-2x my-3"></i>
                                            <p class="fw-bold">{{ __('No matching interactions for this period') }}</p>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                        </div>
                    @else
                        <div class="text-center my-3">
                            <i class="fa-solid fa-eye-slash fa-2x"></i>
                            <h5 class="my-3">{{ __('No interactions yet') }}</h5>
                        </div>
                    @endif
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="{{route('user.videos.index')}}" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-eye"></i>&nbsp;
                            {{ __('See all interactions') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
