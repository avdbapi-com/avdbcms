@use('App\Models\Video')
@use('App\Enums\VideoStatus')
@use('App\Enums\ThumbnailStatus')
@use('Diglactic\Breadcrumbs\Breadcrumbs')

@extends('layouts.user')

@section('title', __('Create new video'))

@section('content')
    {{-- Thumbnail generation overlay is scoped to the right-side video setup card. --}}
    @php
        $readyThumbnailCount = $video->thumbnails
            ->whereIn('status', [ThumbnailStatus::GENERATED, ThumbnailStatus::UPLOADED])
            ->count();
        $shouldAutoGenerateThumbnails = $video->is_draft && $video->embed_code && $readyThumbnailCount === 0;
        $initialThumbnails = $video->thumbnails
            ->sortBy('id')
            ->map(fn ($thumbnail) => [
                'id' => $thumbnail->id,
                'url' => route('video.thumbnails', ['video' => $video->uuid, 'thumbnail' => $thumbnail->id]),
                'status' => $thumbnail->status instanceof \BackedEnum ? $thumbnail->status->value : (int) $thumbnail->status,
                'is_active' => (bool) $thumbnail->is_active,
            ])
            ->values()
            ->all();
        $defaultVideoStatus = (string) old('status', config('site.default_video_status', VideoStatus::PUBLIC->value));
        $defaultVideoLanguage = (string) old('language', config('site.default_video_language', 'en'));
        $customThumbnailsEnabled = config('site.allow_custom_thumbnails', true);
    @endphp
    @if($shouldAutoGenerateThumbnails)
    <style>
        .thumbnail-preview-wrap {
            position: relative;
            overflow: hidden;
        }
        .sidebar-card-wrap .card-body {
            position: relative;
            overflow: hidden;
        }
        #thumb-processing {
            position: absolute;
            inset: 0;
            z-index: 40;
            background: rgba(10, 12, 22, 0.98);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: clamp(0.9rem, 1.5vw, 1.35rem);
            padding: clamp(1.5rem, 3vw, 2.5rem);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            text-align: center;
        }
        #thumb-processing.is-visible {
            opacity: 1;
            pointer-events: auto;
        }
        #thumb-processing.fade-out {
            opacity: 0;
            pointer-events: none;
        }
        .tp-ring {
            width: clamp(132px, 13vw, 180px);
            height: clamp(132px, 13vw, 180px);
            position: relative;
        }
        .tp-ring svg {
            transform: rotate(-90deg);
            width: 100%;
            height: 100%;
            display: block;
        }
        .tp-ring .tp-bg {
            fill: none;
            stroke: rgba(255,255,255,0.08);
            stroke-width: 5;
            stroke-linecap: round;
        }
        .tp-ring .tp-progress {
            fill: none;
            stroke: #0d6efd;
            stroke-width: 5;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s linear;
        }
        .tp-number {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(2.35rem, 3.4vw, 3.45rem);
            font-weight: 700;
            color: #fff;
            font-family: 'Inter', sans-serif;
        }
        .tp-status {
            color: rgba(255,255,255,0.85);
            font-size: clamp(0.98rem, 1.15vw, 1.15rem);
            line-height: 1.3;
            text-align: center;
            max-width: 100%;
        }
        .tp-status .spinner-border {
            width: 1rem;
            height: 1rem;
            border-width: 2px;
        }
        .tp-step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.22rem 0;
            font-size: clamp(0.9rem, 1vw, 1rem);
            line-height: 1.25;
            color: rgba(255,255,255,0.6);
        }
        #tp-steps {
            width: min(340px, 100%);
        }
        .tp-step span:last-child {
            min-width: 0;
            overflow-wrap: anywhere;
        }
        .tp-step .spinner-border {
            width: 1rem;
            height: 1rem;
            border-width: 2px;
        }
        .tp-step .check { color: #2ecc71; font-size: 1.1rem; }
        .tp-step .pending { color: rgba(255,255,255,0.2); font-size: 1rem; }
        .tp-step.done { color: rgba(255,255,255,0.95); }
        .tp-step.active .tp-spin { display: inline-block; animation: tp-spin 1.5s linear infinite; color: #17a2b8; }
        #tp-skip-always {
            max-width: 100%;
            white-space: normal;
            line-height: 1.2;
            font-size: 0.95rem;
            padding: 0.5rem 0.9rem;
        }
        @keyframes tp-spin { 100% { transform: rotate(360deg); } }
        @media (max-width: 480px) {
            #thumb-processing {
                gap: 0.45rem;
                padding: 0.75rem;
            }
            .tp-ring,
            .tp-ring svg {
                width: 88px;
                height: 88px;
            }
            .tp-number {
                font-size: 1.55rem;
            }
            .tp-status,
            .tp-step {
                font-size: 0.72rem;
            }
            #tp-skip-always {
                font-size: 0.72rem;
                padding: 0.25rem 0.55rem;
            }
        }
    </style>
    @endif
    {{ Breadcrumbs::render('create_video', $video) }}
    @if ($errors->any())
        <div class="alert alert-danger mt-3">
            <p class="fw-bold">{{ __('Oops some fields are incorrect') }}</p>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    <div class="d-flex justify-content-between align-items-center my-3">
        <h2>{{ __('Create new video') }}</h2>
    </div>
    <form action="{{ route('user.videos.store', $video) }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="row">
            <div class="col-xl-7 col-xxl-8 order-last order-xl-first">
                <div class="card shadow-soft h-100">
                    <div class="card-body" style="min-height: 912px">
                        <h5 class="text-primary">{{ __('Details') }}</h5>
                        <hr>
                        <div class="mb-3" x-data="{ count: 0 }" x-init="count = $refs.title.value.length">
                            <label for="title" class="form-label">{{ __('Title') }}</label>
                            <input
                                type="text"
                                class="form-control"
                                id="title"
                                name="title"
                                required
                                value="{{old('title', $video->title)}}"
                                maxlength="{{config('validation.video.title.max')}}"
                                x-ref="title"
                                @keyup="count = $refs.title.value.length"
                            >
                            <div class="d-flex flex-wrap align-items-center justify-content-between">
                                <div class="form-text">{{ __('A catchy title can help you hook viewers') }}</div>
                                <div class="form-text">
                                    <span x-text="count"></span> / <span>{{config('validation.video.title.max')}}</span>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3" x-data="{ count: 0 }" x-init="count = $refs.description.value.length">
                            <label for="description" class="form-label">{{ __('Description') }}</label>
                            <textarea
                                class="form-control"
                                id="description"
                                rows="15"
                                name="description"
                                maxlength="{{config('validation.video.description.max')}}"
                                x-ref="description"
                                @keyup="count = $refs.description.value.length"
                            >{{old('description')}}</textarea>
                            <div class="d-flex flex-wrap align-items-center justify-content-between">
                                <div class="form-text">
                                    {{ __('Writing description with keywords can help viewers find your video more easily through search')}}
                                </div>
                                <div class="form-text">
                                    <span x-text="count"></span> / <span>{{config('validation.video.description.max')}}</span>
                                </div>
                            </div>
                        </div>
                        <h5 class="text-primary mt-3">{{ __('Secondary') }}</h5>
                        <hr>
                        <div class="row">
                            <div class="col-12 col-lg-4 mb-3">
                                <label for="category" class="form-label">{{ __('Category') }}</label>
                                <select class="form-control" name="category_id" id="category">
                                    <option value="" selected>--- {{ __('Select Category') }} ---</option>
                                    @foreach($categories as $category)
                                        <option
                                            @selected(old('category_id') == $category->id) value="{{$category->id}}">{{__($category->title)}}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-lg-4 mb-3">
                                <label for="language" class="form-label">{{ __('Video language') }}</label>
                                <select class="form-control" name="language" id="language">
                                    <option value="" @selected($defaultVideoLanguage === '')>--- {{ __('Select Language') }} ---</option>
                                    @foreach($languages as $code => $language)
                                        <option @selected($defaultVideoLanguage === (string) $code) value="{{$code}}">{{ucfirst($language)}}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-lg-4">
                                <label for="default_comments_sort" class="form-label">{{ __('Default comments sort') }}</label>
                                <select class="form-control" name="default_comments_sort" id="default_comments_sort">
                                    @foreach(['top' => "Top comments", 'newest' => 'Newest first'] as $value => $label)
                                        <option
                                            @selected(old('default_comments_sort') == $value) value="{{$value}}">{{__($label)}}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <h5 class="text-primary mt-3">{{ __('Playlists') }}</h5>
                        <hr>
                        <div class="row">
                            <div class="col-12">
                                <label for="playlists" class="form-label d-none">{{ __('Playlists') }}</label>
                                <select
                                    id="playlists"
                                    name="playlists[]"
                                    multiple
                                    placeholder="{{ __('Search Playlist') }}"
                                    autocomplete="off"
                                    class="select-multiple form-control"
                                >
                                    @foreach($playlists as $playlist)
                                        <option @selected(in_array($playlist->id, old('playlists', []))) value="{{$playlist->id}}">{{Str::limit($playlist->title, 40)}}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5 col-xxl-4 order-first order-xl-last mb-4 mb-xl-0">
                <div class="card shadow-soft h-100 sidebar-card-wrap">
                    <div class="card-body">
                        @if($shouldAutoGenerateThumbnails)
                        <div id="thumb-processing">
                            <div class="tp-ring">
                                <svg viewBox="0 0 140 140">
                                    <circle class="tp-bg" cx="70" cy="70" r="65"/>
                                    <circle class="tp-progress" id="tp-progress" cx="70" cy="70" r="65"/>
                                </svg>
                                <div class="tp-number" id="tp-number">0%</div>
                            </div>
                            <div class="tp-status" id="tp-status">
                                <i class="fa-solid fa-cloud-arrow-up me-2"></i>
                                {{ __('Processing video...') }}
                            </div>
                            <div id="tp-steps">
                                <div class="tp-step active" id="step-process">
                                    <span class="tp-spin"><i class="fa-solid fa-gear"></i></span>
                                    <span>{{ __('Video processing') }}</span>
                                </div>
                                <div class="tp-step" id="step-stream">
                                    <span class="pending"><i class="fa-regular fa-circle"></i></span>
                                    <span>{{ __('Loading stream') }}</span>
                                </div>
                                <div class="tp-step" id="step-capture">
                                    <span class="pending"><i class="fa-regular fa-circle"></i></span>
                                    <span>{{ __('Capturing thumbnails') }}</span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="tp-skip-always" style="opacity:0.7">
                                <i class="fa-solid fa-forward me-1"></i>{{ __('Skip & upload manually') }}
                            </button>
                        </div>
                        @endif
                        <div
                            class="ratio ratio-16x9 thumbnail-preview-wrap"
                            x-data="{loading: false, video: {{$video->is_uploading ? 'false' : json_encode(['url' => $video->file_url, 'embed_url' => $video->embed_url, 'is_upload18' => $video->is_upload18])}} }"
                            @uploaded.window="loading=true"
                            @terminate.window="loading= false; video = $event.detail"
                        >
                            <template x-if="!video">
                                <div class="bg-light-dark border border-light d-flex justify-content-center align-items-center">
                                    <div class="text-center">
                                        <div class="mb-2">{{ __('Your video is processing ...') }}</div>
                                        <div class="text-muted text-sm">{{ __('You will receive a notification once the processing is complete') }}</div>
                                    </div>
                                </div>
                            </template>
                            <template x-if="loading">
                                <div class="bg-light-dark border border-light d-flex justify-content-center align-items-center">
                                    <div class="text-center">
                                        <div class="mb-4 fw-bold text-danger">{{ __('Your video is almost ready ...') }}</div>
                                        <div class="spinner-border text-danger" role="status">
                                            <span class="visually-hidden">{{ __('Loading...') }}</span>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <template x-if="video">
                                <div class="bg-light-dark border border-light d-flex justify-content-center align-items-center" style="min-height: 100%">
                                    <div class="text-center">
                                        <div class="mb-3">
                                            <i class="fa-solid fa-circle-play" style="font-size: 3rem; opacity: 0.5"></i>
                                        </div>
                                        <a :href="'{{ $video->route }}'" target="_blank" class="btn btn-outline-danger btn-sm">
                                            <i class="fa-solid fa-eye me-1"></i>{{ __('Preview Video') }}
                                        </a>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="bg-light border mt-2 px-3 py-2 d-flex flex-column gap-3">
                            <div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted fw-bold">{{ __('Video Link') }}</small>
                                    <button
                                        x-data
                                        @click="navigator.clipboard.writeText($event.currentTarget.dataset.link)"
                                        type="button"
                                        class="btn btn-sm btn-light"
                                        title="{{__('Copy video link')}}"
                                        data-link="{{$video->route}}"
                                        data-bs-toggle="tooltip"
                                        data-bs-trigger="click"
                                        data-bs-title="{{ __('Link copied !') }}"
                                    >
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                </div>
                                <a class="text-decoration-none text-sm" href="{{$video->route}}">{{$video->route}}</a>
                            </div>
                            <div>
                                <small class="text-muted fw-bold mb-2">{{ __('Original file name') }}</small>
                                <div class="text-sm">{{$video->original_file_name}}</div>
                            </div>
                            <div>
                                <small class="text-muted fw-bold mb-2">{{ __('File size') }}</small>
                                <div class="text-sm">@size($video->size)</div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted fw-bold mb-2">{{ __('Video ID') }}</small>
                                    <div class="text-sm">{{$video->uuid}}</div>
                                </div>
                                <button
                                    x-data
                                    @click="navigator.clipboard.writeText($event.currentTarget.dataset.link)"
                                    type="button"
                                    class="btn btn-sm btn-light"
                                    title="{{__('Copy video ID')}}"
                                    data-link="{{$video->uuid}}"
                                    data-bs-toggle="tooltip"
                                    data-bs-trigger="click"
                                    data-bs-title="{{ __('ID copied !') }}"
                                >
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <h6 class="text-primary mt-4">{{ __('Visibility') }}</h6>
                        <hr class="mt-2">
                        <div>
                            <div id="planned_value" class="d-none">{{VideoStatus::PLANNED->value}}</div>
                            <div class="row" x-data="planned({{ json_encode($defaultVideoStatus == VideoStatus::PLANNED->value)}})">
                                <div class="col-6">
                                    <label for="status" class="form-label d-none">{{ __('Visibility') }}</label>
                                    <select class="form-control" name="status" id="status" required @change="update">
                                        @foreach($status as $id => $name)
                                            <option @selected($defaultVideoStatus == $id) value="{{$id}}">{{__($name)}}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-6" x-show="value" x-cloak>
                                    <label for="scheduled_at" class="form-label d-none">{{ __('Scheduled At') }}</label>
                                    <input
                                        class="form-control"
                                        type="datetime-local"
                                        id="scheduled_at"
                                        name="scheduled_at"
                                        min="{{now()->toDateTimeLocalString('minute')}}"
                                        :required="value"
                                        x-model="date"
                                    >
                                </div>
                            </div>
                        </div>
                        <h6 class="text-primary mt-4">{{ __('Thumbnail') }}</h6>
                        <hr class="mt-2">
                        <div class="text-sm text-muted mb-3">{{ __("Select or upload a picture that shows what's in your video. A good thumbnail stands out and draws viewers attention") }}</div>
                        <thumbnails-select video="{{$video->id}}" initial='@json($initialThumbnails)' allowupload="{{ $customThumbnailsEnabled ? '1' : '0' }}"></thumbnails-select>
                        <h6 class="text-primary mt-4">{{ __('Comments and ratings') }}</h6>
                        <hr class="mt-2">
                        <div class="d-flex flex-column gap-1">
                            <div class="form-check form-switch">
                                <input type="hidden" value="0" name="allow_comments">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="allow_comments"
                                    value="1"
                                    name="allow_comments"
                                    @checked(!old() || old('allow_comments'))
                                >
                                <label class="form-check-label" for="allow_comments">
                                    {{ __('Allow comments on this video') }}
                                </label>
                            </div>
                            <div class="form-check form-switch">
                                <input type="hidden" value="0" name="show_likes">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="show_likes"
                                    value="1"
                                    name="show_likes"
                                    @checked(!old() || old('show_likes'))
                                >
                                <label class="form-check-label" for="show_likes">
                                    {{ __('Show how many viewers like this video') }}
                                </label>
                            </div>
                        </div>
                        <h6 class="text-primary mt-4">{{ __('Ads management') }}</h6>
                        <hr class="mt-2">
                        <div class="d-flex flex-column gap-1">
                            <div class="form-check form-switch">
                                <input type="hidden" value="0" name="show_ad">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="show_ad"
                                    value="1"
                                    name="show_ad"
                                    @checked(!old() || old('show_ad'))
                                >
                                <label class="form-check-label" for="show_ad">
                                    {{ __('Allow ad on this video') }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-between mt-3">
            <a href="{{route('user.videos.index')}}" class="btn btn-primary">
                {{ __('Complete Later') }}
            </a>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-plus"></i>
                    {{ __('Create Video') }}
                </button>
            </div>
        </div>
    </form>
    @include('users.partials.crop')
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            const planned_value = document.getElementById('planned_value').textContent;
            Alpine.data('planned', (initial) => ({
                value: initial,
                date: initial ? '{{old('scheduled_at')}}' : '',
                update(e) {
                    this.value = e.target.options[e.target.selectedIndex].index == planned_value;
                }
            }));
        })

        document.addEventListener("DOMContentLoaded", function(event) {
            if (window.PRIVATE_CHANNEL) {
                window.PRIVATE_CHANNEL.listen('.video.uploaded', (data) => {
                    window.dispatchEvent(new CustomEvent('uploaded'));
                    setTimeout(() => {
                        window.dispatchEvent(new CustomEvent('terminate', {detail: data}));
                    }, 4000)
                });
            }

            const VIDEO_ID = {{ $video->id }};
            const CREATE_STATUS_ENDPOINT = @json(route('videos.create-status', ['video' => $video->id], false));
            const GENERATE_SERVER_THUMBNAILS_ENDPOINT = @json(route('videos.generate-server-thumbnails', ['video' => $video->id], false));
            const CSRF_TOKEN = '{{ csrf_token() }}';
            const THUMBNAIL_COUNT = {{ max(1, min(5, (int) config('site.upload18_thumbnail_count', 3))) }};
            const IS_UPLOAD18 = @json((bool) $video->is_upload18);
            const SHOULD_POLL_UPLOAD_COMPLETION = @json((bool) ($video->is_draft && !$video->embed_code));
            const SKIP_KEY = 'tp_skip_' + VIDEO_ID;
            const SKIP_VALUE = 'manual:server-fallback-v1';
            const DONE_KEY = 'tp_done_' + VIDEO_ID;
            const DONE_TTL_MS = 10 * 60 * 1000;
            const STREAM_READY_UNTIL_KEY = 'tp_stream_ready_until_' + VIDEO_ID;
            const STREAM_READY_DEADLINE_TTL_MS = 30 * 60 * 1000;
            const STREAM_READY_DELAY_SECONDS = 30;
            const form = document.querySelector('form');
            let formDirty = false;

            if (form) {
                form.addEventListener('input', function () {
                    formDirty = true;
                }, {capture: true});
                form.addEventListener('change', function () {
                    formDirty = true;
                }, {capture: true});
            }

            function showReadyRefreshNotice() {
                if (document.getElementById('video-ready-refresh-notice')) return;

                const notice = document.createElement('div');
                notice.id = 'video-ready-refresh-notice';
                notice.className = 'alert alert-info shadow-lg position-fixed d-flex align-items-center gap-3';
                notice.style.cssText = 'right:1rem;bottom:1rem;z-index:1080;max-width:min(420px,calc(100vw - 2rem));';
                notice.innerHTML =
                    '<div class="flex-grow-1">{{ __("Video processing is complete.") }}</div>' +
                    '<button type="button" class="btn btn-sm btn-primary">{{ __("Refresh") }}</button>';
                notice.querySelector('button')?.addEventListener('click', function () {
                    window.location.reload();
                });
                document.body.appendChild(notice);
            }

            function pollUploadCompletion() {
                if (!SHOULD_POLL_UPLOAD_COMPLETION) return;

                const poll = async () => {
                    try {
                        const response = await fetch(CREATE_STATUS_ENDPOINT, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        });

                        if (!response.ok) return;

                        const data = await response.json();
                        if (data.has_embed_code || data.ready_thumbnail_count > 0) {
                            clearInterval(timer);
                            if (formDirty) {
                                showReadyRefreshNotice();
                            } else {
                                window.location.reload();
                            }
                        }
                    } catch (e) {
                    }
                };

                const timer = setInterval(poll, 10000);
                setTimeout(poll, 3000);
            }

            pollUploadCompletion();

            const widget = document.getElementById('thumb-processing');
            if (!widget) return;

            const doneAt = parseInt(localStorage.getItem(DONE_KEY) || '0', 10);
            const recentlyDone = Number.isFinite(doneAt) && doneAt > 0 && Date.now() - doneAt < DONE_TTL_MS;
            if (doneAt && !recentlyDone) {
                localStorage.removeItem(DONE_KEY);
            }

            const skipValue = localStorage.getItem(SKIP_KEY);
            if (skipValue && skipValue !== SKIP_VALUE) {
                localStorage.removeItem(SKIP_KEY);
            }

            if (!IS_UPLOAD18 || skipValue === SKIP_VALUE || recentlyDone) {
                widget.remove();
                return;
            }

            const countdownEl = document.getElementById('tp-number');
            const progressEl = document.getElementById('tp-progress');
            const statusEl = document.getElementById('tp-status');
            const circumference = 2 * Math.PI * 65;
            progressEl.style.strokeDasharray = circumference;
            progressEl.style.strokeDashoffset = circumference;

            function setStep(stepId, state) {
                const el = document.getElementById(stepId);
                if (!el) return;
                const icon = el.querySelector('span:first-child');
                el.classList.remove('done', 'active', 'error');
                el.classList.add(state);
                if (state === 'active') {
                    icon.innerHTML = '<span class="tp-spin"><i class="fa-solid fa-gear"></i></span>';
                } else if (state === 'done') {
                    icon.innerHTML = '<span class="check"><i class="fa-solid fa-check"></i></span>';
                } else if (state === 'error') {
                    icon.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-xmark"></i></span>';
                }
            }

            function setProgress(pct) {
                const offset = circumference * (1 - pct / 100);
                progressEl.style.strokeDashoffset = offset;
                countdownEl.textContent = Math.round(pct) + '%';
            }

            async function waitForUpload18StorageReady() {
                const startProgress = 35;
                const endProgress = 45;
                const now = Date.now();
                let readyAt = parseInt(localStorage.getItem(STREAM_READY_UNTIL_KEY) || '0', 10);

                if (!Number.isFinite(readyAt) || readyAt <= 0 || now - readyAt > STREAM_READY_DEADLINE_TTL_MS) {
                    readyAt = now + STREAM_READY_DELAY_SECONDS * 1000;
                    localStorage.setItem(STREAM_READY_UNTIL_KEY, String(readyAt));
                }

                while (true) {
                    const remainingMs = readyAt - Date.now();
                    if (remainingMs <= 0) break;

                    const remaining = Math.ceil(remainingMs / 1000);
                    const elapsed = Math.max(0, STREAM_READY_DELAY_SECONDS - remainingMs / 1000);
                    const progress = startProgress + (Math.min(elapsed, STREAM_READY_DELAY_SECONDS) / STREAM_READY_DELAY_SECONDS) * (endProgress - startProgress);
                    setProgress(progress);
                    countdownEl.textContent = remaining + 's';
                    statusEl.innerHTML = '<i class="fa-solid fa-clock me-2"></i>{{ __("Finalizing stream files...") }} ' + remaining + 's';
                    await new Promise(r => setTimeout(r, Math.min(1000, remainingMs)));
                }

                setProgress(endProgress);
            }

            async function generateServerThumbnails() {
                const response = await fetch(GENERATE_SERVER_THUMBNAILS_ENDPOINT, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({count: THUMBNAIL_COUNT}),
                });

                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.message || data.error || 'Server thumbnail generation failed (HTTP ' + response.status + ')');
                }

                return data;
            }

            function skipOverlay() {
                localStorage.setItem(SKIP_KEY, SKIP_VALUE);
                localStorage.removeItem(STREAM_READY_UNTIL_KEY);
                widget.classList.add('fade-out');
                setTimeout(() => { try { widget.remove(); } catch(e){} }, 800);
            }

            function finishOverlay() {
                localStorage.removeItem(SKIP_KEY);
                localStorage.removeItem(STREAM_READY_UNTIL_KEY);
                localStorage.setItem(DONE_KEY, String(Date.now()));
                widget.classList.add('fade-out');
                setTimeout(() => { try { widget.remove(); } catch(e){} }, 800);
            }

            const skipAlwaysBtn = document.getElementById('tp-skip-always');
            if (skipAlwaysBtn) {
                skipAlwaysBtn.addEventListener('click', function() {
                    skipOverlay();
                });
            }

            widget.classList.add('is-visible');
            runThumbnailGeneration();

            async function runThumbnailGeneration() {
                try {
                    setStep('step-process', 'active');
                    statusEl.innerHTML = '<i class="fa-solid fa-cloud-arrow-up me-2"></i>{{ __("Waiting for video to be ready...") }}';
                    setProgress(5);

                    await waitForUpload18StorageReady();

                    setStep('step-process', 'done');
                    setStep('step-stream', 'done');
                    setStep('step-capture', 'active');
                    setProgress(72);
                    countdownEl.textContent = '72%';
                    statusEl.innerHTML = '<i class="fa-solid fa-camera me-2"></i>{{ __("Generating thumbnails on server...") }}';

                    const data = await generateServerThumbnails();

                    setStep('step-capture', 'done');
                    setProgress(100);
                    statusEl.innerHTML = '<i class="fa-solid fa-check-circle text-success me-2"></i>' + data.count + ' {{ __("thumbnails generated!") }}';
                    window.dispatchEvent(new CustomEvent('thumbnails-generated', {
                        detail: data.data,
                    }));
                    setTimeout(() => finishOverlay(), 1800);
                } catch (err) {
                    console.error('Server thumbnail generation error:', err);
                    setStep('step-process', 'error');
                    setStep('step-stream', 'error');
                    setStep('step-capture', 'error');
                    setProgress(0);
                    countdownEl.textContent = '!';
                    statusEl.innerHTML = '<div class="text-warning" style="font-size:0.85rem"><i class="fa-solid fa-triangle-exclamation me-2"></i>' + err.message + '</div>' +
                        '<div class="mt-3 d-flex gap-2 justify-content-center">' +
                            '<button type="button" class="btn btn-sm btn-outline-info" id="tp-retry-btn"><i class="fa-solid fa-rotate-right me-1"></i>{{ __("Retry") }}</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary" id="tp-skip-btn"><i class="fa-solid fa-forward me-1"></i>{{ __("Skip") }}</button>' +
                        '</div>' +
                        '<div class="mt-2"><small class="text-muted">{{ __("You can upload a thumbnail manually below.") }}</small></div>';

                    const autoDismiss = setTimeout(() => skipOverlay(), 3000);

                    const retryBtn = document.getElementById('tp-retry-btn');
                    if (retryBtn) {
                        retryBtn.addEventListener('click', function() {
                            clearTimeout(autoDismiss);
                            localStorage.removeItem(SKIP_KEY);
                            localStorage.removeItem(DONE_KEY);
                            runThumbnailGeneration();
                        }, { once: true });
                    }

                    const skipBtn = document.getElementById('tp-skip-btn');
                    if (skipBtn) {
                        skipBtn.addEventListener('click', function() {
                            clearTimeout(autoDismiss);
                            skipOverlay();
                        }, { once: true });
                    }
                }
            }
        })
    </script>
@endpush
