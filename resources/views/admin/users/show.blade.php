@extends('layouts.admin')

@section('title', 'User: ' . $user->username)

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="fa-solid fa-user me-2"></i>{{ $user->username }}</h3>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-primary btn-sm"><i class="fa-solid fa-edit me-1"></i>{{__('Edit')}}</a>
            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>{{__('Back')}}</a>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card shadow text-center">
                <div class="card-body">
                    <img src="{{ $user->avatar_url }}" alt="{{ $user->username }}" class="rounded-circle mb-3" style="width: 120px; height: 120px; object-fit: cover;">
                    <h4>{{ $user->username }}</h4>
                    <p class="text-muted">{{ $user->email }}</p>
                    <div class="d-flex justify-content-center gap-1 flex-wrap">
                        @if($user->is_admin) <span class="badge bg-danger">{{__('Admin')}}</span> @endif
                        @if($user->is_premium) <span class="badge bg-warning">{{__('Premium')}}</span> @endif
                        @if($user->banned_at) <span class="badge bg-dark">{{__('Banned')}}</span> @else <span class="badge bg-success">{{__('Active')}}</span> @endif
                        @if($user->email_verified_at) <span class="badge bg-info">{{__('Verified')}}</span> @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-9">
            <div class="row">
                <div class="col-md-3 col-6 mb-3">
                    <div class="card shadow-sm border-primary">
                        <div class="card-body text-center">
                            <i class="fa-solid fa-video fa-2x text-primary mb-2"></i>
                            <h3>{{ $user->videos_count ?? 0 }}</h3>
                            <small class="text-muted">{{__('Videos')}}</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="card shadow-sm border-success">
                        <div class="card-body text-center">
                            <i class="fa-solid fa-users fa-2x text-success mb-2"></i>
                            <h3>{{ $user->subscribers_count ?? 0 }}</h3>
                            <small class="text-muted">{{__('Subscribers')}}</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="card shadow-sm border-info">
                        <div class="card-body text-center">
                            <i class="fa-solid fa-comment fa-2x text-info mb-2"></i>
                            <h3>{{ $user->comments_count ?? 0 }}</h3>
                            <small class="text-muted">{{__('Comments')}}</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-6 mb-3">
                    <div class="card shadow-sm border-warning">
                        <div class="card-body text-center">
                            <i class="fa-solid fa-thumbs-up fa-2x text-warning mb-2"></i>
                            <h3>{{ $user->interactions_count ?? 0 }}</h3>
                            <small class="text-muted">{{__('Interactions')}}</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow">
                <div class="card-header"><h5 class="mb-0">{{__('Account Info')}}</h5></div>
                <div class="card-body">
                    <table class="table table-bordered mb-0">
                        <tr><td class="fw-bold" style="width:200px">{{__('Joined')}}</td><td>{{ $user->created_at->translatedFormat('d F Y H:i') }}</td></tr>
                        <tr><td class="fw-bold">{{__('Last Login')}}</td><td>{{ $user->last_login_at?->translatedFormat('d F Y H:i') ?? __('Never') }}</td></tr>
                        <tr><td class="fw-bold">{{__('Last IP')}}</td><td>{{ $user->last_login_ip ?? __('N/A') }}</td></tr>
                        <tr><td class="fw-bold">{{__('Country')}}</td><td>{{ $user->country ?? __('N/A') }}</td></tr>
                        <tr><td class="fw-bold">{{__('Website')}}</td><td>{{ $user->website ?? __('N/A') }}</td></tr>
                        <tr><td class="fw-bold">{{__('Description')}}</td><td>{{ Str::limit($user->description, 300) ?: __('N/A') }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @if($user->videos && $user->videos->count() > 0)
    <div class="card shadow">
        <div class="card-header d-flex justify-content-between">
            <h5 class="mb-0">{{__('Recent Videos')}}</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive" tabindex="0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{__('Thumbnail')}}</th>
                            <th>{{__('Title')}}</th>
                            <th>{{__('Status')}}</th>
                            <th>{{__('Views')}}</th>
                            <th>{{__('Created')}}</th>
                            <th>{{__('Actions')}}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($user->videos as $video)
                        <tr>
                            <td><img src="{{ $video->thumbnail_url }}" style="width:120px;height:68px" alt=""></td>
                            <td>{{ Str::limit($video->title, 50) }}</td>
                            <td><span class="badge bg-{{ $video->status->value === 'public' ? 'success' : ($video->status->value === 'banned' ? 'danger' : 'secondary') }}">{{ $video->status->value }}</span></td>
                            <td>{{ $video->views }}</td>
                            <td>{{ $video->created_at->translatedFormat('M d, Y') }}</td>
                            <td><a href="{{ route('admin.videos.edit', $video) }}" class="btn btn-sm btn-primary"><i class="fa-solid fa-edit"></i></a></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
