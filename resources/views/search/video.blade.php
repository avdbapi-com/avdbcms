<article class="avd-section-card avd-search-result-card mb-3 overflow-hidden">
    <a href="{{$video->route}}" class="avd-search-result-media avd-thumb">
        <image-loaded source="{{$video->thumbnail_url}}" title="{{$video->title}}"></image-loaded>
        <span class="avd-duration">{{$video->duration}}</span>
    </a>
    <div class="avd-search-result-body p-3 min-w-0">
        <a href="{{$video->route}}" class="avd-video-title text-decoration-none h5 mb-2" title="{{$video->title}}">
            {{$video->title}}
        </a>
        <div class="avd-video-meta">{{trans_choice('views', $video->views, ['count' => \App\Helpers\Number::humanize($video->views)])}} <span aria-hidden="true">&middot;</span> {{$video->published_at?->diffForHumans() ?? $video->created_at?->diffForHumans() ?? __('Recently')}}</div>
        @if($video->user)
            <a href="{{$video->user->route}}" class="d-flex align-items-center gap-2 text-muted text-sm text-decoration-none my-3">
                <img class="rounded-circle" src="{{$video->user->avatar_url}}" alt="{{$video->user->username}} avatar" width="35" height="35" style="object-fit:cover" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
                <span>{{$video->user->username}}</span>
            </a>
        @else
            <div class="d-flex align-items-center gap-2 text-muted text-sm my-3">
                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center" style="width:35px;height:35px;">
                    <i class="fa-solid fa-user-slash" style="font-size:11px;"></i>
                </div>
                <span>{{__('Deleted user')}}</span>
            </div>
        @endif
        <small class="text-muted">{{Str::limit($video->description, 150)}}</small>
    </div>
</article>
