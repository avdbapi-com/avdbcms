{{-- The Subscribe control of the member pages (member design 5.4, 6.10, 6.11; QA channel-17, channel-33):
     - a member: <subscribe-button> (resources/js/components/Actions/Subscribe.tsx) holding the same button the component
       draws, so nothing moves when it starts; with the channel bell (its route and its menu component) the
       "Subscribed" placeholder already shows the bell and the chevron of the menu it becomes;
     - a guest: a primary Subscribe that opens the site's sign-in prompt (the sign-in page without JavaScript); the
       prompt's words are printed once per page (#mkAuthSubscribe);
     - the channel's owner: nothing.
     @include('library.subscribe', ['channel' => $user, 'subscribed' => $isSubscribed, 'size' => 'sm']) --}}
@php
    $subscribeSmall = ($size ?? null) === 'sm';
    $subscribeOn = (bool) ($subscribed ?? false);
    $subscribeBell = \Illuminate\Support\Facades\Route::has('notifications.channel');
@endphp
@auth
    @if(auth()->user()->isNot($channel))
        {{-- the face of the state, drawn over both faces kept invisible (R1-125: the button is as wide in both states;
             R2A-027: the invisible faces are drawn in the look's local fallback font, so the width stays when the web
             font arrives; member/_shared.scss .mk-subscribe--faces). The same markup as Subscribe.tsx draws. --}}
        @php
            $subscribeOffFace = '<i class="fa-solid fa-bell fa-fw" aria-hidden="true"></i><span>'.e(__('Subscribe')).'</span>';
            $subscribeOnFace = '<i class="fa-solid fa-'.($subscribeBell ? 'bell' : 'check').' fa-fw" aria-hidden="true"></i><span>'.e(__('Subscribed')).'</span>'
                .($subscribeBell ? '<i class="fa-solid fa-chevron-down mk-bell-menu__chevron" aria-hidden="true"></i>' : '');
        @endphp
        <subscribe-button user="{{ $channel->getKey() }}" subscribed="{{ $subscribeOn ? '1' : '0' }}" name="{{ $channel->username }}" @if($subscribeSmall) size="sm" @endif>
            <button type="button" @class(['avd-mk', 'mk-btn', 'mk-btn--fixed', 'mk-subscribe', 'mk-subscribe--faces', 'mk-btn--secondary' => $subscribeOn, 'mk-btn--primary' => !$subscribeOn, 'mk-btn--sm' => $subscribeSmall]) aria-pressed="{{ $subscribeOn ? 'true' : 'false' }}" disabled>
                <span class="mk-subscribe__face mk-subscribe__face--{{ $subscribeOn ? 'on' : 'off' }}">{!! $subscribeOn ? $subscribeOnFace : $subscribeOffFace !!}</span>
                <span class="mk-subscribe__face mk-subscribe__face--off" aria-hidden="true">{!! $subscribeOffFace !!}</span>
                <span class="mk-subscribe__face mk-subscribe__face--on" aria-hidden="true">{!! $subscribeOnFace !!}</span>
            </button>
        </subscribe-button>
        @pushOnce('head-styles', 'mk-face-sizer')
            {{-- R2A-027: the look's body font list without its web font: the metric-matched local fallback face
                 (R1-068 / R1R-03) and the local fonts after it, for the invisible faces above; set before the first
                 paint, so the button's width never follows the web font's arrival (not even where a browser cannot
                 open the local bold face). Nothing is set where the look's font has no fallback face (the web font
                 sizes the button, as before). Subscribe.tsx ensureFaceSizer() does the same for the buttons it draws
                 alone. --}}
            <script>(function(){try{var r=document.documentElement,s=getComputedStyle(r).getPropertyValue('--avd-font-body').split(','),f=function(x){return x.trim().replace(/^["']|["']$/g,'')};if(s.length>1&&f(s[1])===f(s[0])+' Fallback'){r.style.setProperty('--mk-sizer',s.slice(1).join(','))}}catch(e){}})();</script>
        @endPushOnce
    @endif
@else
    <a @class(['avd-mk', 'mk-btn', 'mk-btn--primary', 'mk-btn--fixed', 'mk-subscribe', 'mk-btn--sm' => $subscribeSmall]) href="{{ \App\Http\Controllers\Auth\ReturnPath::loginUrl() }}" data-auth-prompt="mkAuthSubscribe">
        <i class="fa-solid fa-bell" aria-hidden="true"></i><span>{{ __('Subscribe') }}</span>
    </a>
    @once
        @push('scripts')
            {{-- the sign-in prompt's words for every guest Subscribe of the page, printed once (library/actions-script reads
                 them from here: the page roots stay within their content budget, 4.3) --}}
            <template id="mkAuthSubscribe" data-auth-icon="fa-solid fa-bell" data-auth-title="{{ __('Sign in to subscribe') }}" data-auth-message="{{ __('Subscribe to see the new videos of this channel in your feed.') }}"></template>
        @endpush
    @endonce
@endauth
