{{-- The About tab of a channel (member design 6.10; QA channel-8, 16, 18, 22): the description panel sized to its
     content and the details as a list: the real channel address with a copy button (its "Address copied" status right
     under the address), the website, joined, views written with the language's separators, videos and country. The tab
     loads it when it is first opened (UserController@show ?part=about), so the channel's first screen stays within its
     content budget (4.3).
     @include('library.channel-about', ['user' => $user, 'details' => [...]]) --}}
<div class="mk-grid mk-grid--2-1 mk-channel__about"><section class="mk-panel" aria-labelledby="mkAboutTitle"><div class="mk-panel__head"><h2 class="mk-panel__title" id="mkAboutTitle">{{ __('About') }}</h2></div><div class="mk-panel__body">@if(trim((string) $user->description) !== '')<p class="mk-prose"{!! \App\Support\TextDirection::attr($user->description) !!}>{!! nl2br(e($user->description)) !!}</p>@else<p class="mk-muted">{{ __('This channel has no description yet.') }}</p>@endif</div></section>
<section class="mk-panel" aria-labelledby="mkDetailsTitle"><div class="mk-panel__head"><h2 class="mk-panel__title" id="mkDetailsTitle">{{ __('Details') }}</h2></div><div class="mk-panel__body"><dl class="mk-dl mk-channel__details">
<div><dt>{{ __('Channel address') }}</dt><dd class="mk-channel__address"><span class="mk-copy__value" dir="ltr">{{ $user->route }}</span><button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--sm" data-copy-text="{{ $user->route }}" aria-label="{{ __('Copy channel address') }}" title="{{ __('Copy channel address') }}"><i class="fa-regular fa-copy" aria-hidden="true"></i></button><p class="mk-help mk-copy__done" role="status" data-copy-done hidden>{{ __('Address copied') }}</p></dd></div>
@if($details['website'])<div><dt>{{ __('Website') }}</dt><dd><a href="{{ $details['website'] }}" target="_blank" rel="external nofollow noopener" dir="ltr">{{ $user->website }}</a></dd></div>@endif
<div><dt>{{ __('Joined') }}</dt><dd>{{ \App\Support\SiteDate::monthYear($user->created_at) }}</dd></div>
@if($details['stats'])<div><dt>{{ __('Views') }}</dt><dd>{{ $details['views'] }}</dd></div><div><dt>{{ __('Videos') }}</dt><dd>{{ $details['videos'] }}</dd></div>@endif
@if($user->country)<div><dt>{{ __('Country') }}</dt><dd>{{ $user->country_name }}</dd></div>@endif
</dl></div></section></div>
