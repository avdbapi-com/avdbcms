<article class="col-6 col-sm-6 col-md-4 col-lg-3 col-xl-2">
    <div class="avd-channel-card avd-channel-card--subscription h-100">
        <a href="{{$user->route}}" class="avd-channel-card-link text-decoration-none d-flex flex-column gap-2 align-items-center text-center w-100 min-w-0">
            <img class="avd-channel-card-avatar avd-avatar-media flex-shrink-0" src="{{$user->avatar_url}}" alt="{{$user->username}} avatar" width="104" height="104" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
            <strong class="text-body text-truncate w-100" title="{{$user->username}}">{{$user->username}}</strong>
            <span class="text-muted text-sm">{{ trans_choice('subscribers', $user->subscribers_count, ['count' => \App\Helpers\Number::humanize($user->subscribers_count)]) }}</span>
        </a>
        @auth
            <subscribe-button
                is-subscribe
                user="{{$user->id}}"
                size="sm"
                class="avd-subscribe-host mx-auto mt-3 position-relative"
            ></subscribe-button>
        @else
            <button
                type="button"
                class="avd-subscribe-button btn btn-dark px-3 btn-sm position-relative mx-auto mt-3"
                data-bs-toggle="popover"
                data-bs-placement="right"
                data-bs-title="{{ __('Want to subscribe to this channel ?') }}"
                data-bs-trigger="focus"
                data-bs-html="true"
                tabindex="0"
                data-bs-content="{{ __('Sign in to subscribe to this channel.') }}<hr><a href='{{ route('login') }}' class='btn btn-primary btn-sm'>{{ __('Sign In') }}</a>"
            >
                <i class="fa-solid fa-user-plus me-2" aria-hidden="true"></i>
                {{ __('Subscribe') }}
            </button>
        @endauth
    </div>
</article>
