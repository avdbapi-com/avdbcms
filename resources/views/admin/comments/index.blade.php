@use('App\Filters\Forms\Admin\CommentFiltersForm')

@extends('layouts.admin')

@section('title', __('Comments'))

@section('content')
    @if($comments->total() || request()->all())
        <div class="d-flex justify-content-between align-items-center my-3">
            <h2>{{__('Comments')}}</h2>
        </div>
        <hr>
        {!! form(FormBuilder::create(CommentFiltersForm::class)) !!}

        {{-- Bulk Actions Bar --}}
        <div class="card shadow-sm mb-3" id="bulkBar" style="display:none">
            <div class="card-body py-2 d-flex align-items-center gap-3">
                <span class="fw-bold text-primary"><span id="selectedCount">0</span> {{__('selected')}}</span>
                <form method="POST" action="{{ route('admin.bulk.comments') }}" id="bulkForm" class="d-flex gap-2 align-items-center">
                    @csrf
                    <div id="bulkIds"></div>
                    <select name="action" class="form-select form-select-sm" style="width:auto" required>
                        <option value="">{{__('-- Bulk Action --')}}</option>
                        <option value="ban">{{__('Ban')}}</option>
                        <option value="unban">{{__('Unban')}}</option>
                        <option value="delete">{{__('Delete')}}</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary" onclick='return confirm(@json(__('Apply bulk action to selected comments?')))'>{{__('Apply')}}</button>
                </form>
            </div>
        </div>

        <div class="table-responsive" tabindex="0">
            <table class="table table-bordered table-striped">
                <thead>
                <tr style="border-top: 3px solid #0D6EFD;">
                    <th><input type="checkbox" id="selectAll" class="form-check-input" aria-label="{{ __('Select all') }}"></th>
                    <th style="min-width: 400px">{{__('Video')}}</th>
                    <th style="min-width: 420px">{{__('Comment')}}</th>
                    <th>{{__('Replies')}}</th>
                    <th style="min-width: 150px">{{__('Interactions')}}</th>
                    <th style="min-width: 200px">{{__('Actions')}}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($comments as $comment)
                    <tr class="bg-light">
                        <td class="align-middle"><input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $comment->id }}"></td>
                        <td class="">
                            @if($comment->video)
                                <div class="d-flex gap-3 align-items-center">
                                    <a href="{{$comment->video->route}}">
                                        <img src="{{$comment->video->thumbnail_url}}" alt="" style="width: 120px;height: 68px">
                                    </a>
                                    <small>{{Str::limit($comment->video->title, 100), '...'}}</small>
                                </div>
                            @else
                                <span class="text-muted"><i class="fa-solid fa-video-slash me-1"></i>{{__('Video deleted')}}</span>
                            @endif
                        </td>
                        <td class="align-middle">
                            <div class="d-flex gap-3">
                                @if($comment->user)
                                    <a href="{{$comment->user->route}}">
                                        <img class="rounded" src="{{$comment->user->avatar_url}}" alt="{{$comment->user->username}} avatar" style="width: 50px;">
                                    </a>
                                    <div class="d-flex flex-column gap-1">
                                        <div class="d-flex align-items-center">
                                            <a @class(['text-sm text-decoration-none', 'badge rounded-pill text-bg-secondary' => $comment->video && $comment->video->user && $comment->user->is($comment->video->user)]) href="{{$comment->user->route}}">{{$comment->user->username}}</a>
                                            <small class="text-muted" data-bs-toggle="tooltip" data-bs-title="{{$comment->created_at->translatedFormat('d F Y - H:i')}}">&nbsp;• {{$comment->created_at->diffForHumans()}}</small>
                                            @if($comment->is_updated)
                                                <small class="text-muted fw-semibold">&nbsp;&bull; {{__('Modified')}}</small>
                                            @endif
                                            @if($comment->trashed())
                                                <small class="text-danger fw-semibold">&nbsp;&bull; {{__('Deleted')}}</small>
                                            @endif
                                        </div>
                                        <x-expand-item>
                                            {{$comment->content}}
                                        </x-expand-item>
                                    </div>
                                @else
                                    <span class="text-muted"><i class="fa-solid fa-user-slash me-1"></i></span>
                                    <div class="d-flex flex-column gap-1">
                                        <div class="d-flex align-items-center">
                                            <span class="text-muted text-sm">{{__('Deleted user')}}</span>
                                            <small class="text-muted">&nbsp;• {{$comment->created_at->diffForHumans()}}</small>
                                        </div>
                                        <x-expand-item>
                                            {{$comment->content}}
                                        </x-expand-item>
                                    </div>
                                @endif
                            </div>
                        </td>
                        <td class="align-middle">
                            @if($comment->replies_count)
                                <button class="btn btn-sm btn-info text-white" data-bs-toggle="modal" data-bs-target="#comment_replies" data-id="{{$comment->id}}" data-video="{{$comment->video->uuid}}">
                                    {{__('See replies')}} ({{$comment->replies_count}})
                                </button>
                            @else
                                <div class="badge bg-secondary">{{__('No replies')}}</div>
                            @endif
                        </td>
                        <td class="align-middle">
                            @include('users.partials.interactions', ['item' => $comment])
                        </td>
                        <td class="align-middle">
                            <div class="d-flex gap-1 flex-wrap">
                                @if($comment->is_banned)
                                    <div class="badge bg-dark me-1">
                                        {{__('Banned')}} {{$comment->banned_at->diffForHumans()}}
                                    </div>
                                    <form method="POST" action="{{route('admin.comments.unban', $comment)}}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success" title="{{__('Unban')}}">
                                            <i class="fa-solid fa-unlock"></i>
                                        </button>
                                    </form>
                                @else
                                    <button
                                        class="btn btn-warning btn-sm"
                                        title="{{__('Ban comment')}}"
                                        data-bs-toggle="modal"
                                        data-bs-target="#ban_comment"
                                        data-route="{{route('admin.comments.ban', $comment)}}"
                                        data-username="{{$comment->user?->username ?? __('Deleted user')}}"
                                        data-avatar="{{$comment->user?->avatar_url ?? ''}}"
                                        data-date="{{$comment->created_at->diffForHumans()}}"
                                        data-content="{{$comment->content}}"
                                    >
                                        <i class="fa-solid fa-ban"></i>
                                    </button>
                                @endif
                                <form method="POST" action="{{route('admin.comments.delete', $comment)}}" onsubmit='return confirm(@json(__('Delete this comment permanently?')))'>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger" title="{{__('Delete')}}">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center">
                            <i class="fa-solid fa-database fa-2x my-3"></i>
                            <p class="fw-bold">{{__('No matching comments')}}</p>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$comments" />
        @include('admin.comments.modals.ban')
        @include('users.comments.modals.replies')
    @else
        <div class="card shadow">
            <div class="card-body d-flex justify-content-center align-items-center">
                <div class="my-3 text-center">
                    <i class="fa-solid fa-comment-slash fa-2x"></i>
                    <h5 class="my-3">{{__('No comments yet')}}</h5>
                    <div class="text-muted my-3">{{__('Upload more videos to get comments')}}</div>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#video_create">
                        <i class="fa-solid fa-plus"></i>{{__('Upload new video')}}</button>
                </div>
            </div>
        </div>
    @endif

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const selectAll = document.getElementById('selectAll');
        const bulkBar = document.getElementById('bulkBar');
        const bulkIds = document.getElementById('bulkIds');
        const selectedCount = document.getElementById('selectedCount');

        function updateBulk() {
            const checked = document.querySelectorAll('.bulk-check:checked');
            bulkIds.innerHTML = '';
            checked.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = 'ids[]'; input.value = cb.value;
                bulkIds.appendChild(input);
            });
            selectedCount.textContent = checked.length;
            bulkBar.style.display = checked.length > 0 ? 'block' : 'none';
        }

        if (selectAll) {
            selectAll.addEventListener('change', function() {
                document.querySelectorAll('.bulk-check').forEach(cb => cb.checked = this.checked);
                updateBulk();
            });
        }
        document.querySelectorAll('.bulk-check').forEach(cb => cb.addEventListener('change', updateBulk));
    });
    </script>
@endsection
