@use('App\Filters\Forms\User\CommentFiltersForm')

@extends('layouts.user')

@section('title', __('Your videos comments'))

@section('content')
    @if($comments->total() || request()->all())
        <div x-data="{selected:[], comment:{}}">
            <div class="d-flex flex-column flex-sm-row gap-3 justify-content-between align-items-start align-items-md-center mt-3">
                <h2 class="mb-0">{{ __('Your videos comments') }}</h2>
                <button
                    x-show.important="selected.length > 0"
                    x-cloak
                    class="btn btn-danger d-flex align-items-center gap-1"
                    type="button"
                    onclick="if(confirm('{{ __('Delete selected comments?') }}')) document.getElementById('mass-delete-form').submit()"
                >
                    <i class="fa-solid fa-trash me-1"></i>
                    <span x-text="'Delete ' + selected.length + (selected.length > 1 ? ' comments' : ' comment')"></span>
                </button>
            </div>
            <hr>
            {!! form(FormBuilder::create(CommentFiltersForm::class)) !!}

            <form action="{{route('user.comments.mass-delete')}}" method="POST" id="mass-delete-form">
                @csrf
                @method('DELETE')
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="ids[]" :value="id">
                </template>
            </form>

            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead>
                    <tr style="border-top: 3px solid #0D6EFD;">
                        <th scope="col">
                            <input type="checkbox" class="form-check-input"
                                @change="if($event.target.checked) { selected = [...document.querySelectorAll('.bulk-check')].map(e => e.value) } else { selected = [] }"
                            >
                        </th>
                        <th class="w-40" style="min-width: 400px">{{ __('Video') }}</th>
                        <th class="w-50" style="min-width: 400px">{{ __('Comment') }}</th>
                        <th style="min-width: 140px">{{ __('Replies') }}</th>
                        <th style="min-width: 170px;">{{ __('Interactions') }}</th>
                        <th>{{ __('Action') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($comments as $comment)
                        <tr class="bg-light">
                            <td class="align-middle">
                                <input type="checkbox" class="form-check-input bulk-check" value="{{ $comment->id }}"
                                    x-model="selected"
                                >
                            </td>
                            <td class="">
                                @if($comment->video)
                                    <div class="d-flex gap-3 align-items-center">
                                        <a href="{{$comment->video->route}}">
                                            @include('users.videos.partials.thumbnail', ['video' => $comment->video])
                                        </a>
                                        <small>{{Str::limit($comment->video->title, 100), '...'}}</small>
                                    </div>
                                @else
                                    <span class="text-muted"><i class="fa-solid fa-video-slash me-1"></i>{{__('Video deleted')}}</span>
                                @endif
                            </td>
                            <td class="align-middle">
                                @if($comment->user)
                                    <div class="d-flex gap-2">
                                        <a href="{{$comment->user->route}}">
                                            <img class="rounded" src="{{$comment->user->avatar_url}}" alt="{{$comment->user->username}} avatar" style="width: 50px;">
                                        </a>
                                        <div>
                                            <div class="d-flex align-items-center">
                                                <a class="text-sm text-decoration-none" href="{{$comment->user->route}}">{{$comment->user->username}}</a>
                                                <small class="text-muted" data-bs-toggle="tooltip" data-bs-title="{{$comment->created_at->translatedFormat('d F Y - H:i')}}">&nbsp;• {{$comment->created_at->diffForHumans()}}</small>
                                                @if($comment->is_updated)
                                                    <small class="text-muted text-muted fw-semibold">&nbsp;• {{ __('Modified')}}</small>
                                                @endif
                                            </div>
                                            <x-expand-item>
                                                {{$comment->content}}
                                            </x-expand-item>
                                        </div>
                                    </div>
                                @else
                                    <div class="d-flex gap-2 align-items-start">
                                        <span class="text-muted"><i class="fa-solid fa-user-slash me-1"></i></span>
                                        <div>
                                            <div class="d-flex align-items-center">
                                                <span class="text-muted text-sm">{{__('Deleted user')}}</span>
                                                <small class="text-muted">&nbsp;• {{$comment->created_at->diffForHumans()}}</small>
                                            </div>
                                            <x-expand-item>
                                                {{$comment->content}}
                                            </x-expand-item>
                                        </div>
                                    </div>
                                @endif
                            </td>
                            <td class="align-middle">
                                @if($comment->replies_count)
                                    <div class="badge bg-primary">
                                        {{trans_choice('replies', $comment->replies_count)}}
                                    </div>
                                @else
                                    <div class="badge bg-secondary">
                                        {{ __('No replies') }}
                                    </div>
                                @endif
                            </td>
                            <td class="align-middle">
                                @include('users.partials.interactions', ['item' => $comment])
                            </td>
                            <td class="align-middle">
                                <button
                                    class="btn btn-sm btn-danger d-flex align-items-center gap-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#comment_delete"
                                    data-route="{{route('user.comments.destroy', $comment)}}"
                                    data-author="{{$comment->user?->username ?? __('Deleted user')}}"
                                    data-replies-count="{{$comment->replies_count}}"
                                    @click="comment = $el.dataset"
                                >
                                    <i class="fa-solid fa-trash"></i>
                                    {{ __('Remove') }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center">
                                <i class="fa-solid fa-database fa-2x my-3"></i>
                                <p class="fw-bold">{{ __('No matching comments') }}</p>
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            {{ $comments->links() }}
            @include('users.comments.modals.replies')
            @include('users.comments.modals.delete')
        </div>
    @else
        <div class="card shadow">
            <div class="card-body d-flex justify-content-center align-items-center">
                <div class="my-3 text-center">
                    <i class="fa-solid fa-comment-slash fa-2x"></i>
                    <h5 class="my-3">{{ __('No comments yet') }}</h5>
                    <div class="text-muted my-3">{{ __('Upload more videos to receive comments') }}</div>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#video_create">
                        <i class="fa-solid fa-plus"></i>
                        {{ __('Upload new video') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
@endsection
