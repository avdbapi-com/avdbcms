@extends('layouts.admin')

@section('title', 'Edit Video: ' . $video->title)

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0"><i class="fa-solid fa-video me-2"></i>{{__('Edit Video')}}</h3>
        <div class="d-flex gap-2">
            <a href="{{ $video->route ?? '#' }}" target="_blank" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-external-link me-1"></i>{{__('View on Site')}}</a>
            <a href="{{ route('admin.videos.index') }}" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left me-1"></i>{{__('Back to Videos')}}</a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow mb-3">
                <div class="card-header"><h5 class="mb-0"><i class="fa-solid fa-edit me-1"></i>{{__('Video Details')}}</h5></div>
                <div class="card-body">
                    <form action="{{ route('admin.videos.update', $video) }}" method="POST">
                        @csrf @method('PUT')
                        <div class="mb-3">
                            <label class="form-label fw-bold">{{__('Title')}}</label>
                            <input type="text" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title', $video->title) }}">
                            @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">{{__('Description')}}</label>
                            <textarea name="description" class="form-control" rows="5">{{ old('description', $video->description) }}</textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">{{__('Category')}}</label>
                                <select name="category_id" class="form-select">
                                    <option value="">{{__('No Category')}}</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}" {{ $video->category_id == $category->id ? 'selected' : '' }}>{{ $category->title }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">{{__('Status')}}</label>
                                <select name="status" class="form-select">
                                    @foreach(\App\Enums\VideoStatus::cases() as $status)
                                        <option value="{{ $status->value }}" {{ $video->status->value === $status->value ? 'selected' : '' }}>{{ ucfirst($status->name) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label fw-bold">{{__('Language')}}</label>
                                <select name="language" class="form-select">
                                    @foreach(['en','ar','fr','zh','nl','de','hi','it','ko','es','pt','ru'] as $lang)
                                        <option value="{{ $lang }}" {{ ($video->language ?? 'en') === $lang ? 'selected' : '' }}>{{ strtoupper($lang) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="show_ad" value="1" {{ $video->show_ad ? 'checked' : '' }}>
                                    <label class="form-check-label fw-bold">{{__('Show Ads')}}</label>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="allow_comments" value="1" {{ $video->allow_comments ? 'checked' : '' }}>
                                    <label class="form-check-label fw-bold">{{__('Allow Comments')}}</label>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="show_likes" value="1" {{ $video->show_likes ? 'checked' : '' }}>
                                    <label class="form-check-label fw-bold">{{__('Show Likes')}}</label>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_short" value="1" {{ $video->is_short ? 'checked' : '' }}>
                                    <label class="form-check-label fw-bold">{{__('Is Short')}}</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>{{__('Update Video')}}</button>
                    </form>
                </div>
            </div>

            {{-- Comments on this video --}}
            <div class="card shadow mb-3">
                <div class="card-header d-flex justify-content-between">
                    <h6 class="mb-0"><i class="fa-solid fa-comments me-1"></i>{{__('Comments')}} ({{ $video->comments_count ?? $video->comments()->count() }})</h6>
                    <a href="{{ route('admin.comments.index', ['video_id' => $video->id]) }}" class="btn btn-sm btn-outline-primary">{{__('View All')}}</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>{{__('User')}}</th><th>{{__('Comment')}}</th><th>{{__('Date')}}</th><th>{{__('Status')}}</th></tr></thead>
                        <tbody>
                        @forelse($video->comments()->with('user')->latest()->limit(10)->get() as $comment)
                        <tr>
                            <td><small>{{ $comment->user?->username ?? 'Deleted' }}</small></td>
                            <td><small>{{ Str::limit($comment->content, 60) }}</small></td>
                            <td><small>{{ $comment->created_at->diffForHumans() }}</small></td>
                            <td>
                                @if($comment->banned_at)
                                    <span class="badge bg-danger">{{__('Banned')}}</span>
                                @else
                                    <span class="badge bg-success">{{__('Active')}}</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">{{__('No comments')}}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            {{-- Thumbnail Preview --}}
            <div class="card shadow mb-3">
                <div class="card-body text-center">
                    @if($video->thumbnail_url)
                        <img src="{{ $video->thumbnail_url }}" class="img-fluid rounded mb-3" alt="{{ $video->title }}">
                    @else
                        <div class="bg-light p-4 rounded mb-3"><i class="fa-solid fa-image fa-3x text-muted"></i><p class="text-muted mt-2">{{__('No Thumbnail')}}</p></div>
                    @endif
                    <div class="d-flex justify-content-center gap-1 flex-wrap mb-2">
                        <span class="badge bg-{{ $video->status->value == 0 ? 'success' : ($video->status->value == 5 ? 'danger' : 'secondary') }}">{{ ucfirst($video->status->name) }}</span>
                        @if($video->is_short) <span class="badge bg-info">{{__('Short')}}</span> @endif
                    </div>
                    @if($video->user)
                        <p class="text-muted text-sm">{{__('By')}} <a href="{{ route('admin.users.edit', $video->user) }}">{{ $video->user->username }}</a></p>
                    @endif
                </div>
            </div>

            {{-- Video Info --}}
            <div class="card shadow mb-3">
                <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-circle-info me-1"></i>{{__('Video Info')}}</h6></div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">ID</span><span class="fw-bold">#{{ $video->id }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">UUID</span><span><code class="small">{{ $video->uuid }}</code></span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Views')}}</span><span class="fw-bold">{{ number_format($video->views) }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Duration')}}</span><span>{{ $video->duration ?? __('N/A') }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('File Size')}}</span><span>{{ $video->size ? round($video->size / 1048576, 1) . ' MB' : 'N/A' }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Slug')}}</span><span><small>{{ $video->slug }}</small></span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Language')}}</span><span>{{ strtoupper($video->language ?? 'en') }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Created')}}</span><span><small>{{ $video->created_at->translatedFormat('M d, Y H:i') }}</small></span></li>
                    @if($video->published_at)
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Published')}}</span><span><small>{{ $video->published_at->translatedFormat('M d, Y H:i') }}</small></span></li>
                    @endif
                    @if($video->scheduled_at)
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Scheduled')}}</span><span><small>{{ $video->scheduled_at->translatedFormat('M d, Y H:i') }}</small></span></li>
                    @endif
                    @if($video->banned_at)
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Banned')}}</span><span class="text-danger"><small>{{ $video->banned_at->translatedFormat('M d, Y H:i') }}</small></span></li>
                    @endif
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('Mime Type')}}</span><span><small>{{ $video->mimetype ?? __('N/A') }}</small></span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{__('File')}}</span><span><small class="text-truncate" style="max-width:150px">{{ $video->file ?? __('N/A') }}</small></span></li>
                </ul>
            </div>

            {{-- Quick Links --}}
            <div class="card shadow mb-3">
                <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-link me-1"></i>{{__('Quick Links')}}</h6></div>
                <div class="card-body d-flex flex-column gap-2">
                    <a href="{{ route('admin.views.index', ['video_id' => $video->id]) }}" class="btn btn-outline-info btn-sm w-100"><i class="fa-solid fa-eye me-1"></i>{{__('View Analytics')}}</a>
                    <a href="{{ route('admin.interactions.index', ['video_id' => $video->id]) }}" class="btn btn-outline-info btn-sm w-100"><i class="fa-solid fa-heart me-1"></i>{{__('Interactions')}}</a>
                    <a href="{{ route('admin.thumbnails.index', ['video_id' => $video->id]) }}" class="btn btn-outline-info btn-sm w-100"><i class="fa-solid fa-image me-1"></i>{{__('Thumbnails')}}</a>
                </div>
            </div>

            {{-- Actions --}}
            <div class="card shadow">
                <div class="card-header"><h6 class="mb-0"><i class="fa-solid fa-bolt me-1"></i>{{__('Actions')}}</h6></div>
                <div class="card-body d-flex flex-column gap-2">
                    @if($video->banned_at)
                        <form action="{{ route('admin.videos.unban', $video) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-success btn-sm w-100"><i class="fa-solid fa-unlock me-1"></i>{{__('Unban Video')}}</button>
                        </form>
                    @else
                        <form action="{{ route('admin.videos.ban', $video) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-warning btn-sm w-100"><i class="fa-solid fa-ban me-1"></i>{{__('Ban Video')}}</button>
                        </form>
                    @endif
                    <form action="{{ route('admin.videos.delete', $video) }}" method="POST" onsubmit='return confirm(@json(__('Delete this video permanently?')))'>
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100"><i class="fa-solid fa-trash me-1"></i>{{__('Delete Video')}}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
