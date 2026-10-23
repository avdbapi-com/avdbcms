<article class="avd-section-card avd-search-result-card mb-3 overflow-hidden">
    <a href="{{$playlist->route}}" class="avd-search-result-media avd-thumb">
        @if($playlist->first_video)
            <img src="{{$playlist->first_video->thumbnail_url}}" alt="{{$playlist->title}}" width="640" height="360" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/placeholders/no-image.svg') }}';">
        @else
            <div class="w-100 h-100 bg-secondary text-white d-flex justify-content-center align-items-center">
                <i class="fa-solid fa-image fa-2x"></i>
            </div>
        @endif
        <span class="avd-duration d-flex gap-2 align-items-center">
            <i class="fa-solid fa-list"></i>
            {{__('Playlist')}}
        </span>
    </a>
    <div class="avd-search-result-body p-3 min-w-0">
        <a href="{{$playlist->route}}" class="avd-video-title text-decoration-none h5 mb-2" title="{{$playlist->title}}">
            {{$playlist->title}}
        </a>
        <div class="avd-video-meta">{{trans_choice('videos', $playlist->videos_count, ['count' => \App\Helpers\Number::humanize($playlist->videos_count)])}} <span aria-hidden="true">&middot;</span> {{$playlist->created_at->diffForHumans()}}</div>
        @if($playlist->user)
            <a href="{{$playlist->user->route}}" class="d-flex align-items-center gap-2 text-muted text-sm text-decoration-none my-3">
                <img class="rounded-circle" src="{{$playlist->user->avatar_url}}" alt="{{$playlist->user->username}} avatar" width="35" height="35" style="object-fit:cover" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
                <span>{{$playlist->user->username}}</span>
            </a>
        @else
            <div class="d-flex align-items-center gap-2 text-muted text-sm my-3">
                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center" style="width:35px;height:35px;">
                    <i class="fa-solid fa-user-slash" style="font-size:11px;"></i>
                </div>
                <span>{{__('Deleted user')}}</span>
            </div>
        @endif
    </div>
</article>
