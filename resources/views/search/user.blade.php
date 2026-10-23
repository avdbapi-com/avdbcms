<article class="avd-section-card avd-search-user-card mb-3">
    <a href="{{$user->route}}" class="avd-search-user-card-link text-decoration-none d-flex flex-column flex-sm-row align-items-center gap-3 gap-sm-4 p-3 p-md-4">
        <img class="avd-channel-card-avatar avd-avatar-media flex-shrink-0" src="{{$user->avatar_url}}" alt="{{$user->username}} avatar" width="104" height="104" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
        <div class="text-center text-sm-start min-w-0">
            <h6 class="mb-1 fw-bold text-body text-truncate" title="{{$user->username}}">{{$user->username}}</h6>
            <small class="text-muted d-block mb-1">{{trans_choice('subscribers', $user->subscribers_count, ['count' => \App\Helpers\Number::humanize($user->subscribers_count)])}} <span aria-hidden="true">&middot;</span> {{trans_choice('videos', $user->videos_count, ['count' => \App\Helpers\Number::humanize($user->videos_count)])}}</small>
            <small class="text-muted d-block">{{Str::limit($user->short_description, 160)}}</small>
        </div>
    </a>
</article>
