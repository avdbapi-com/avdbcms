{{-- A channel card of Discover channels (member design 6.11, QA channel-33): avatar, name, subscribers, two lines of
     description and Subscribe (Discover lists only channels the member does not follow yet). --}}
@php
    $cardSubs = (int) ($user->subscribers_count ?? 0);
    $cardCountShown = $user->show_subscribers && config('site.show_subscriber_count', true);
@endphp
<div class="mk-chan-card">
    <img class="mk-avatar mk-avatar--64" src="{{ $user->avatar_url }}" alt="" width="64" height="64" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
    <a class="mk-item__title mk-chan-card__name" href="{{ $user->route }}"{!! \App\Support\TextDirection::attr($user->username) !!}>{{ $user->username }}</a>
    <span class="mk-muted mk-chan-card__subs" data-subscriber-count="{{ $user->getKey() }}"
          @if($cardCountShown) data-count-on="{{ trans_choice('subscribers', $cardSubs + 1, ['count' => \App\Support\LocalNumber::short($cardSubs + 1)]) }}" data-count-off="{{ trans_choice('subscribers', $cardSubs, ['count' => \App\Support\LocalNumber::short($cardSubs)]) }}" @endif>{{ $cardCountShown ? trans_choice('subscribers', $cardSubs, ['count' => \App\Support\LocalNumber::short($cardSubs)]) : __('Count hidden') }}</span>
    @if($user->description)
        <p class="mk-muted mk-clamp-2 mk-chan-card__desc"{!! \App\Support\TextDirection::attr($user->description) !!}>{{ $user->short_description ?? \Illuminate\Support\Str::limit($user->description, 140) }}</p>
    @endif
    @include('library.subscribe', ['channel' => $user, 'subscribed' => false, 'size' => 'sm'])
</div>
