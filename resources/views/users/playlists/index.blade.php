@use('App\Filters\Forms\User\PlaylistFiltersForm')

@extends('layouts.user')

@section('title', __('My Playlists'))

@section('content')
    @if($playlists->total() || request()->all())
    <div x-data="{selected:[], playlist:{}}">
        {{ Breadcrumbs::render('playlists') }}
        <div class="d-flex flex-column flex-sm-row gap-3 justify-content-between align-items-start align-items-md-center my-3">
            <h2>{{ __('My Playlists') }}</h2>
            <div class="d-flex flex-wrap gap-2 w-100 w-sm-auto">
                <button
                    x-show.important="selected.length > 0"
                    x-cloak
                    class="btn btn-danger d-flex align-items-center gap-1"
                    type="button"
                    onclick="if(confirm('{{ __('Delete selected playlists?') }}')) document.getElementById('mass-delete-form').submit()"
                >
                    <i class="fa-solid fa-trash me-1"></i>
                    <span x-text="'Delete ' + selected.length + (selected.length > 1 ? ' playlists' : ' playlist')"></span>
                </button>
                <a href="{{route('user.playlists.create')}}" class="btn btn-success d-flex align-items-center gap-1">
                    <i class="fa-solid fa-plus"></i>
                    <span>{{ __('Create new playlist') }}</span>
                </a>
            </div>
        </div>
        <hr>
        {!! form(FormBuilder::create(PlaylistFiltersForm::class)) !!}

        <form action="{{route('user.playlists.mass-delete')}}" method="POST" id="mass-delete-form">
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
                        <input type="checkbox" class="form-check-input" aria-label="{{ __('Select all playlists') }}"
                            @change="if($event.target.checked) { selected = [...document.querySelectorAll('.bulk-check')].map(e => e.value) } else { selected = [] }"
                        >
                    </th>
                    <th scope="col" class="w-40" style="min-width: 370px;">{{ __('Playlist') }}</th>
                    <th scope="col">{{ __('Visibility') }}</th>
                    <th scope="col" style="min-width: 160px;">{{ __('Created') }}</th>
                    <th scope="col" style="min-width: 160px;">{{ __('Updated') }}</th>
                    <th scope="col" style="min-width: 160px;">{{ __('Videos count') }}</th>
                    <th scope="col">{{ __('Actions') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse($playlists as $playlist)
                    <tr class="bg-light">
                        <td class="align-middle">
                            <input type="checkbox" class="form-check-input bulk-check" value="{{ $playlist->id }}"
                                x-model="selected"
                            >
                        </td>
                        <td class="d-flex flex-wrap flex-sm-nowrap gap-3">
                            <a href="{{$playlist->route}}">
                                @if($playlist->first_video)
                                    <img src="{{$playlist->first_video->thumbnail_url}}" alt="{{$playlist->title}} thumbnail" width="120" height="68" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/placeholders/no-image.svg') }}';">
                                @else
                                    <div class="bg-secondary text-white d-flex justify-content-center align-items-center" style="width: 120px;height: 68px">
                                        <i class="fa-solid fa-image fa-2x"></i>
                                    </div>
                                @endif
                            </a>
                            <div class="d-flex flex-column align-items-start gap-2">
                                <a class="text-body text-decoration-none text-break" href="{{$playlist->route}}">{{Str::limit($playlist->title, 100, '...')}}</a>
                                <small class="text-muted text-break">{{Str::limit($playlist->description, 190, '...')}}</small>
                            </div>
                        </td>
                        <td class="align-middle">
                            <span class="badge bg-{{$playlist->status->color()}}">
                                <i class="fa-solid fa-{{$playlist->status->icon()}}"></i>&nbsp;
                                {{ $playlist->status->name() }}
                            </span>
                        </td>
                        <td class="align-middle">
                            <div class="text-muted" data-bs-toggle="tooltip" data-bs-title="{{$playlist->created_at->translatedFormat('d F Y - H:i')}}">
                                {{$playlist->created_at->diffForHumans()}}
                            </div>
                        </td>
                        <td class="align-middle">
                            <div class="text-muted" data-bs-toggle="tooltip" data-bs-title="{{$playlist->updated_at->translatedFormat('d F Y - H:i')}}">
                                {{$playlist->updated_at->diffForHumans()}}
                            </div>
                        </td>
                        <td class="align-middle">
                            <div class="badge bg-secondary">
                                {{trans_choice('videos', $playlist->videos_count, ['count' => \App\Helpers\Number::humanize($playlist->videos_count)])}}
                            </div>
                        </td>
                        <td class="align-middle">
                            <div class="d-flex gap-1">
                                <a href="{{route('user.playlists.edit', $playlist)}}" class="btn btn-primary btn-sm" title="{{__('Edit Playlist')}}" aria-label="{{__('Edit Playlist')}}">
                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                </a>
                                <button
                                    type="button"
                                    title="{{__('Delete playlist')}}"
                                    aria-label="{{__('Delete playlist')}}"
                                    class="btn btn-danger btn-sm"
                                    data-bs-toggle="modal"
                                    data-bs-target="#delete_playlist"
                                    data-title="{{$playlist->title}}"
                                    data-thumbnail="{{$playlist->first_video?->thumbnail_url}}"
                                    data-infos="{{trans_choice('videos', $playlist->videos_count, ['count' => \App\Helpers\Number::humanize($playlist->videos_count)]) . ' • ' . $playlist->created_at->diffForHumans()}}"
                                    data-route="{{route('user.playlists.destroy', $playlist)}}"
                                    @click="playlist = $el.dataset;"
                                >
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="avd-empty-state text-center p-4">
                                <i class="fa-solid fa-filter-circle-xmark fa-2x text-muted mb-3"></i>
                                <p class="fw-bold mb-1">{{ __('No matching playlists') }}</p>
                                <p class="text-muted mb-0">{{ __('Adjust filters or create a new playlist.') }}</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $playlists->links() }}
        @include('users.playlists.modals.delete')
    </div>
    @else
        <div class="avd-empty-state text-center p-4 p-md-5">
            <i class="fa-solid fa-list-check fa-2x text-muted mb-3"></i>
            <h5 class="mb-2">{{ __('Create your first Playlist') }}</h5>
            <div class="text-muted mb-3">{{ __('Create your first playlist to start organizing your videos.') }}</div>
            <a href="{{route('user.playlists.create')}}" class="btn btn-success d-inline-flex align-items-center gap-2">
                <i class="fa-solid fa-plus"></i>
                <span>{{ __('Create new playlist') }}</span>
            </a>
        </div>
    @endif
@endsection
