{{-- The sign-in pages' frame (member design 6.12): one card of at most 460px, centred, in the site's own header and
     menu; from 1200px a brand panel on the start side in the look's brand gradient (site name, one claim, three perks).
     No pictures (auth-6, auth-9): every colour, radius and font comes from the look's tokens, in both modes.
     A page sets its title and fills 'auth-card'; the card's heading carries id="authTitle". --}}
@extends('layouts.default')

@section('content')
    <div class="avd-mk">
        <div class="mk-auth">
            <aside class="mk-auth__brand" aria-hidden="true">
                <div class="mk-auth__brand-name"><span class="mk-auth__brand-mark"><i class="fa-solid fa-play"></i></span><span class="mk-auth__brand-text">{{ config('app.name') }}</span></div>
                <p class="mk-auth__claim">{{ __('Watch, save and share the videos you love.') }}</p>
                <ul class="mk-auth__perks">
                    <li><i class="fa-regular fa-clock"></i>{{ __('Save videos to watch later') }}</li>
                    <li><i class="fa-solid fa-user-plus"></i>{{ __('Follow your favourite creators') }}</li>
                    @if(config('site.allow_uploads', true))
                        <li><i class="fa-solid fa-arrow-up-from-bracket"></i>{{ __('Upload and share your own videos') }}</li>
                    @else
                        <li><i class="fa-solid fa-thumbs-up"></i>{{ __('Like and comment on videos') }}</li>
                    @endif
                </ul>
            </aside>
            <section class="mk-auth__card" aria-labelledby="authTitle">
                @yield('auth-card')
            </section>
        </div>
    </div>
@endsection
