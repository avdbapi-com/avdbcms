@use('App\Filters\Forms\Admin\VideoFiltersForm')

@extends('layouts.admin')

@section('title', __('Videos'))

@section('content')
    <div x-data="{video: {}}">
        <div class="d-flex justify-content-between align-items-center my-3">
            <h2>{{__('Videos')}}</h2>
        </div>
        <hr>
        {!! form(FormBuilder::create(VideoFiltersForm::class)) !!}

        {{-- Bulk Actions Bar --}}
        <div class="card shadow-sm mb-3" id="bulkBar" style="display:none">
            <div class="card-body py-2 d-flex align-items-center gap-3">
                <span class="fw-bold text-primary"><span id="selectedCount">0</span> {{__('selected')}}</span>
                <form method="POST" action="{{ route('admin.bulk.videos') }}" id="bulkForm" class="d-flex gap-2 align-items-center">
                    @csrf
                    <div id="bulkIds"></div>
                    <select name="action" id="bulkActionSelect" class="form-select form-select-sm" style="width:auto" required>
                        <option value="">{{__('-- Bulk Action --')}}</option>
                        <option value="change_category">{{__('Change Category')}}</option>
                        <option value="resync_duration">{{__('Re-sync Duration')}}</option>
                        <option value="make_public">{{__('Make Public')}}</option>
                        <option value="make_private">{{__('Make Private')}}</option>
                        <option value="make_unlisted">{{__('Make Unlisted')}}</option>
                        <option value="ban">{{__('Ban')}}</option>
                        <option value="unban">{{__('Unban')}}</option>
                        <option value="delete">{{__('Delete')}}</option>
                    </select>
                    <select name="category_id" id="bulkCategorySelect" class="form-select form-select-sm d-none" style="width:auto">
                        <option value="">-- {{__('Select Category')}} --</option>
                        @foreach(\App\Models\Category::orderBy('title')->get(['id', 'title']) as $cat)
                            <option value="{{$cat->id}}">{{$cat->title}}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary" onclick='return confirm(@json(__('Apply bulk action to selected videos?')))'>{{__('Apply')}}</button>
                </form>
            </div>
        </div>

        <div class="table-responsive" tabindex="0">
            <table class="table table-bordered table-striped">
                <thead>
                <tr style="border-top: 3px solid #0D6EFD;">
                    <th><input type="checkbox" id="selectAll" class="form-check-input" aria-label="{{ __('Select all') }}"></th>
                    <th style="min-width: 300px" scope="col">{{__('User')}}</th>
                    <th scope="col" style="min-width: 400px">{{__('Video')}}</th>
                    <th scope="col">{{__('Status')}}</th>
                    <th scope="col" style="min-width: 210px">{{__('Date')}}</th>
                    <th scope="col">{{__('Views')}}</th>
                    <th scope="col">{{__('Comments')}}</th>
                    <th scope="col" style="min-width: 140px">{{__('Interactions')}}</th>
                    <th scope="col">{{__('Actions')}}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($videos as $video)
                    <tr class="bg-light">
                        <td class="align-middle"><input type="checkbox" class="form-check-input bulk-check" aria-label="{{ __('Select') }}" value="{{ $video->id }}"></td>
                        <td class="align-middle">
                            @if($video->user)
                                <a href="{{$video->user->route}}" class="d-flex align-items-start gap-3 text-decoration-none">
                                    <img class="rounded" src="{{$video->user->avatar_url}}" alt="{{$video->user->username}} avatar" style="width: 50px;">
                                    <div class="d-flex flex-column">
                                        <span>{{$video->user->username}}</span>
                                        <span class="text-muted text-sm"> {{trans_choice('subscribers', $video->user->subscribers_count)}} &bull; {{trans_choice('videos', $video->user->videos_count)}}</span>
                                    </div>
                                </a>
                            @else
                                <span class="text-muted"><i class="fa-solid fa-user-slash me-1"></i>{{__('Deleted user')}}</span>
                            @endif
                        </td>
                        <td class="align-middle">
                            @if(!$video->is_draft)
                                @if($video->category)
                                    <div class="badge bg-primary my-3">
                                        {{$video->category->title}}
                                    </div>
                                @else
                                    <div class="badge bg-secondary my-3">{{__('No category')}}</div>
                                @endif
                            @endif
                            <div class="d-flex gap-3">
                                <a class="d-block " href="{{$video->route}}">
                                    @if($video->is_draft)
                                        <div class="bg-secondary text-white d-flex justify-content-center align-items-center" style="width: 120px;height: 68px">
                                            <i class="fa-solid fa-image fa-2x"></i>
                                        </div>
                                    @else
                                        <img src="{{$video->thumbnail_url}}" alt="" style="width: 120px;height: 68px">
                                    @endif
                                </a>
                                <div class="text-sm">{{Str::limit($video->title, 100), '...'}}</div>
                            </div>
                        </td>
                        <td class="align-middle">
                            @include('videos.status')
                        </td>
                        <td class="align-middle">
                            @if($video->is_private || $video->is_unlisted || $video->is_draft || $video->is_failed)
                                {{ ($video->created_at ?? now())->translatedFormat('d F Y H:i') }}
                                <div class="text-sm text-muted">{{__('Uploaded')}}</div>
                            @elseif($video->is_planned)
                                {{ ($video->scheduled_at ?? $video->created_at ?? now())->translatedFormat('d F Y H:i') }}
                                <div class="text-sm text-muted">{{__('Scheduled')}}</div>
                            @else
                                {{ ($video->published_at ?? $video->created_at ?? now())->translatedFormat('d F Y H:i') }}
                                <div class="text-sm text-muted">{{__('Published')}}</div>
                            @endif
                        </td>
                        <td class="align-middle">
                            @if(!$video->is_draft)
                                @if($video->views)
                                    <span class="badge bg-secondary">{{trans_choice('views', $video->views)}}</span>
                                @else
                                    <span class="badge bg-secondary">{{__('No views')}}</span>
                                @endif
                            @endif
                        </td>
                        <td class="align-middle">
                            @if(!$video->is_draft)
                                @if($video->comments_count)
                                    <a href="{{route('admin.comments.index') .'?video='.$video->id}}" class="badge bg-info text-decoration-none">
                                        {{trans_choice('comments', $video->comments_count)}}
                                    </a>
                                @else
                                    <div class="badge bg-secondary">{{__('No comments')}}</div>
                                @endif
                                @if(!$video->allow_comments)
                                    <div class="badge bg-danger">{{__('Disabled')}}</div>
                                @endif
                            @endif
                        </td>
                        <td class="align-middle">
                            @if(!$video->is_draft)
                                @include('users.partials.interactions', ['item' => $video])
                                <div class="d-flex align-items-center gap-1">
                                    @if($video->interactions_count)
                                        <div class="badge bg-primary mt-2" data-bs-toggle="modal" data-bs-target="#video_likes" style="cursor: pointer;" data-id="{{$video->id}}">{{__('Details')}}</div>
                                    @endif
                                    @if(!$video->show_likes)
                                        <div class="badge bg-danger mt-2">{{__('Disabled')}}</div>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="align-middle">
                            <div class="d-flex gap-1 flex-wrap">
                                <a href="{{route('admin.videos.edit', $video)}}" class="btn btn-primary btn-sm" title="{{__('Edit video')}}">
                                    <i class="fa-solid fa-edit"></i>
                                </a>
                                <a href="{{route('user.videos.show', $video)}}" class="btn btn-success btn-sm" title="{{__('Video statistics')}}">
                                    <i class="fa-solid fa-chart-simple"></i>
                                </a>
                                @if($video->is_banned)
                                    <form method="POST" action="{{route('admin.videos.unban', $video)}}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-success" title="{{__('Unban video')}}">
                                            <i class="fa-solid fa-unlock"></i>
                                        </button>
                                    </form>
                                @else
                                    <button
                                        type="button"
                                        title="{{__('Ban video')}}"
                                        class="btn btn-warning btn-sm"
                                        data-bs-toggle="modal"
                                        data-bs-target="#ban_video"
                                        @click="video = $el.dataset"
                                        data-title="{{$video->title}}"
                                        data-infos="{{trans_choice('views', $video->views)}} • {{$video->created_at->translatedFormat('d F Y')}}"
                                        data-poster="{{$video->thumbnail_url}}"
                                        data-route="{{route('admin.videos.ban', $video)}}"
                                    >
                                        <i class="fa-solid fa-ban"></i>
                                    </button>
                                @endif
                                <form method="POST" action="{{route('admin.videos.delete', $video)}}" onsubmit='return confirm(@json(__('Delete this video permanently?')))'>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger" title="{{__('Delete video')}}">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center">
                            <i class="fa-solid fa-database fa-2x my-3"></i>
                            <p class="fw-bold">{{__('No matching videos')}}</p>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$videos" :per-page-options="\App\Support\AdminPagination::perPageOptions()" />
        @include('videos.modals.interactions')
        @include('admin.videos.modals.ban')
    </div>

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

        const bulkActionSelect = document.getElementById('bulkActionSelect');
        const bulkCategorySelect = document.getElementById('bulkCategorySelect');
        if (bulkActionSelect && bulkCategorySelect) {
            bulkActionSelect.addEventListener('change', function() {
                if (this.value === 'change_category') {
                    bulkCategorySelect.classList.remove('d-none');
                    bulkCategorySelect.required = true;
                } else {
                    bulkCategorySelect.classList.add('d-none');
                    bulkCategorySelect.required = false;
                }
            });
        }
    });
    </script>
@endsection
