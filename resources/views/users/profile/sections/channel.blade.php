{{--
    Account settings > Channel (member design 6.9, 6.19; mockup account-channel): "Channel page" (banner 16:5, tagline,
    description, website, the creator's button, subscriber count) and "Channel look" in collapsible groups (Colours,
    Header, Motion, Sections, Sizes), each closed group showing its current choice. A creator's choices only paint the
    channel's top band; the admin's switches decide which choices exist (a group whose options are all switched off is
    not shown, and a hidden option keeps the admin's default). One form, one save bar ("Save channel").

    A closed group is fetched from this page when it is opened the first time (page-content budget, 4.3): the page asks
    for ?section=channel&group=<name> (ProfileController::edit() answers a script with that group's body only, $onlyGroup
    here) and adds the answer to the group. A field the member never opened is not sent, and the save keeps its stored
    value. After a refused save every group is in the page (a closed one in a template), so nothing typed is lost;
    ?group=<name> on a normal visit opens that group ($openGroup).
--}}
@php
    $onlyGroup = isset($onlyGroup) && is_string($onlyGroup) ? $onlyGroup : null;
    $openGroup = isset($openGroup) && is_string($openGroup) ? $openGroup : null;
    $lazyGroups = ! request()->hasSession() || ! request()->session()->hasOldInput();
    $customizationEnabled = (bool) config('site.channel_customization_enabled', true);
    $on = fn (string $key): bool => (bool) config('site.channel_customization_'.$key.'_enabled', true);
    $c = $channelCustomization;
    $opt = $channelOptions;
    $label = fn (string $group, string $value): string => __($opt[$group][$value] ?? \Illuminate\Support\Str::headline($value));
    $taglineMax = (int) config('site.channel_tagline_max_length', 120);
    $ctaMax = (int) config('site.channel_cta_label_max_length', 42);
    $descriptionMax = (int) config('validation.user.description.max', 5000);
    $websiteMax = (int) config('validation.user.website.max', 255);
    $siteShowsCounts = (bool) config('site.show_subscriber_count', true);
    $channelHost = preg_replace('#^https?://#', '', rtrim((string) $user->route, '/'));

    // The swatches of the header band (the creator's own colours) and of the accents. An option a later version adds
    // gets a neutral chip until it has its own colour here.
    $bandChips = [
        'follow' => 'var(--avd-surface-2)',
        'look' => 'var(--avd-surface-2)',
        'midnight' => 'linear-gradient(115deg,#121826,#0e242d)',
        'sunrise' => 'linear-gradient(115deg,#461c26,#4a3214)',
        'ocean' => 'linear-gradient(115deg,#0a2137,#064452)',
        'forest' => 'linear-gradient(115deg,#0b2c24,#27361c)',
        'candy' => 'linear-gradient(115deg,#371e3d,#1a3746)',
        'graphite' => 'linear-gradient(115deg,#13161d,#252a34)',
    ];
    $accentChips = [
        'look' => 'var(--avd-brand-fill)',
        'follow' => 'var(--avd-brand-fill)',
        'coral' => '#ff6177',
        'cyan' => '#38d7e8',
        'violet' => '#a78bfa',
        'amber' => '#fbbf24',
        'emerald' => '#34d399',
        'rose' => '#fb7185',
    ];
    $accentOptions = collect($opt['accent'] ?? [])->reject(fn ($text, $key) => $key === 'custom' && ! $on('custom_accent'))->all();

    $groups = [];

    if ($customizationEnabled) {
        if ($on('theme') || $on('accent') || $on('surface')) {
            $groups['colours'] = [__('Colours'), implode(', ', array_filter([
                $on('theme') ? __(':band band', ['band' => $label('theme', $c['theme'])]) : null,
                $on('accent') ? ($c['accent'] === 'custom' ? __('your own colour') : $label('accent', $c['accent'])) : null,
                $on('surface') ? $label('surface', $c['surface']) : null,
            ]))];
        }

        if ($on('layout') || $on('banner') || $on('shape') || $on('avatar') || $on('pattern') || $on('stats')) {
            $groups['header'] = [__('Header'), implode(', ', array_filter([
                $on('layout') ? $label('layout', $c['layout']) : null,
                $on('banner') ? $label('banner_treatment', $c['banner_treatment']) : null,
                $on('shape') ? $label('header_shape', $c['header_shape']) : null,
                $on('avatar') ? $label('avatar_frame', $c['avatar_frame']) : null,
                $on('pattern') ? $label('pattern', $c['pattern']) : null,
            ]))];
        }

        if ($on('motion')) {
            $groups['motion'] = [__('Motion'), $label('motion', $c['motion'])];
        }

        $sectionSwitches = array_filter([
            'show_featured_video' => $on('featured_video') ? __('Featured video') : null,
            'show_latest_uploads' => $on('latest_uploads') ? __('Latest uploads') : null,
            'show_playlists' => $on('playlists') ? __('Playlists') : null,
            'show_about_panel' => $on('sections') ? __('About') : null,
            'show_stats' => $on('stats') ? __('Statistics') : null,
            'show_social_links' => $on('socials') ? __('Website link') : null,
            'show_upload_rhythm' => $on('upload_rhythm') ? __('Last upload') : null,
            'show_subscribe_cta' => $on('cta') ? __('Your button') : null,
        ]);
        $shownSections = array_values(array_filter($sectionSwitches, fn ($text, $key) => ($c[$key] ?? '0') === '1', ARRAY_FILTER_USE_BOTH));
        $groups['sections'] = [__('Sections'), $shownSections !== [] ? implode(', ', $shownSections) : __('Only the videos')];
        $groupFields = [
            'colours' => ['theme', 'accent', 'custom_accent', 'surface'],
            'header' => ['layout', 'banner_treatment', 'header_shape', 'avatar_frame', 'pattern', 'stats_style'],
            'motion' => ['motion'],
            'sections' => [...array_keys($sectionSwitches), 'featured_mode', 'dedupe_home_sections'],
            'sizes' => ['video_layout', 'featured_layout', 'latest_uploads_layout', 'playlists_layout', 'density', 'featured_columns',
                'featured_rows', 'latest_uploads_columns', 'latest_uploads_rows', 'playlists_columns', 'playlists_rows',
                'playlist_video_columns', 'playlist_video_rows'],
        ];

        if ($on('video_layout') || $on('featured_layout') || $on('featured_sizing') || $on('latest_layout') || $on('latest_sizing') || $on('playlists_layout') || $on('playlists_sizing') || $on('layout')) {
            $groups['sizes'] = [__('Sizes'), $on('latest_sizing')
                ? trans_choice('{1} :columns per row, :count row|[2,*] :columns per row, :count rows', (int) $c['latest_uploads_rows'], ['columns' => $c['latest_uploads_columns'], 'count' => $c['latest_uploads_rows']])
                : $label('density', $c['density'])];
        }
    }
@endphp

@if($onlyGroup !== null)
    {{-- the answer to the page's request for one closed group: its body, nothing else (empty = not shown here: 404) --}}
    @if(array_key_exists($onlyGroup, $groups))
        @include('users.profile.partials.channel-group', ['group' => $onlyGroup])
    @endif
@else
@include('users.profile.partials.notices', ['fields' => ['banner', 'remove_banner', 'description', 'website', 'show_subscribers', 'channel_customization', 'channel_customization.*']])

<form id="mk-channel-form" method="POST" action="{{ route('user.update') }}" enctype="multipart/form-data" data-mk-savebar
      class="mk-settings__form">
    @csrf
    @method('PUT')
    <input type="hidden" name="section" value="channel">

    <section class="mk-panel" aria-labelledby="hChannelPage">
        <div class="mk-panel__head">
            <h2 class="mk-panel__title" id="hChannelPage">{{ __('Channel page') }}</h2>
            <a class="mk-btn mk-btn--secondary mk-btn--sm mk-nowrap mk-panel__action" href="{{ $user->route }}" target="_blank" rel="noopener">
                <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                <span>{{ __('Preview channel') }}</span>
            </a>
            <p class="mk-panel__sub">{!! __('What visitors see at :address.', ['address' => '<bdi dir="ltr">'.e($channelHost).'</bdi>']) !!}</p>
        </div>
        <div class="mk-panel__body">
            <div class="mk-form">
                <div class="mk-field"
                     x-data="{ original: @js(filled($user->banner) ? $user->banner_url : null), src: @js(filled($user->banner) ? $user->banner_url : null) }"
                     @cropped-banner.window="src = URL.createObjectURL($event.detail); dirty = true"
                     @mk-form-reset.window="src = original">
                    <span class="mk-label">{{ __('Banner') }} <span class="mk-label__opt">{{ __('2048 × 640, cropped to 16:5') }}</span></span>
                    <div class="mk-banner-edit">
                        <img x-show="src" :src="src" @if(filled($user->banner)) src="{{ $user->banner_url }}" @else x-cloak @endif alt="" width="2048" height="640" decoding="async">
                        <div class="mk-banner-edit__empty" x-show="!src" @if(filled($user->banner)) x-cloak @endif>
                            <i class="fa-regular fa-image" aria-hidden="true"></i>
                            <span>{{ __('No banner yet: the top of your channel uses the look\'s colours.') }}</span>
                        </div>
                        <div class="mk-banner-edit__actions">
                            <label class="mk-btn mk-btn--secondary mk-btn--sm mk-file">
                                <i class="fa-solid fa-image" aria-hidden="true"></i>
                                <span>{{ __('Change banner') }}</span>
                                <image-upload name="banner" config="banner" aria-label="{{ __('Change banner') }}"></image-upload>
                            </label>
                            @if(filled($user->banner))
                                <button type="submit" form="mk-remove-banner" class="mk-btn mk-btn--secondary mk-btn--sm"><span>{{ __('Remove') }}</span></button>
                            @endif
                        </div>
                    </div>
                    @include('users.profile.partials.field-error', ['field' => 'banner'])
                </div>

                @if($customizationEnabled)
                    {{-- a tagline, description or button label takes the direction of its own text once it has one (dir="auto",
                         R3-053's look-around; the studio script follows what is typed: data-mk-dir); the counters follow the
                         field (avdAccountSettings, the cached studio script) --}}
                    @php $taglineValue = (string) old('channel_customization.tagline', $c['tagline']); @endphp
                    <div class="mk-field">
                        <label class="mk-label" for="tagline">{{ __('Tagline') }} <span class="mk-label__opt">{{ __('optional') }}</span></label>
                        <input class="mk-input" id="tagline" type="text" data-mk-dir{!! trim($taglineValue) !== '' ? ' dir="auto"' : '' !!} name="channel_customization[tagline]" maxlength="{{ $taglineMax }}"
                               value="{{ $taglineValue }}"
                               aria-describedby="tagline-help{{ $errors->has('channel_customization.tagline') ? ' channel_customization-tagline-error' : '' }}"
                               @if($errors->has('channel_customization.tagline')) aria-invalid="true" @endif>
                        <div class="mk-counter" aria-hidden="true"><span>{{ mb_strlen($taglineValue) }}</span> / {{ $taglineMax }}</div>
                        <span class="mk-help" id="tagline-help">{{ __('One line under your channel name.') }}</span>
                        @include('users.profile.partials.field-error', ['field' => 'channel_customization.tagline'])
                    </div>
                @endif

                <div class="mk-field">
                    <label class="mk-label" for="description">{{ __('Description') }} <span class="mk-label__opt">{{ __('optional') }}</span></label>
                    <textarea class="mk-input" id="description" name="description" rows="4" data-mk-dir{!! trim((string) old('description', $user->description)) !== '' ? ' dir="auto"' : '' !!} maxlength="{{ $descriptionMax }}"
                              @if($errors->has('description')) aria-invalid="true" aria-describedby="description-error" @endif>{{ old('description', $user->description) }}</textarea>
                    <div class="mk-counter" aria-hidden="true"><span>{{ mb_strlen((string) old('description', $user->description)) }}</span> / {{ $descriptionMax }}</div>
                    @include('users.profile.partials.field-error', ['field' => 'description'])
                </div>

                <div class="mk-form__row mk-form__row--2">
                    <div class="mk-field">
                        <label class="mk-label" for="website">{{ __('Website') }} <span class="mk-label__opt">{{ __('optional') }}</span></label>
                        <input class="mk-input" id="website" type="text" inputmode="url" autocomplete="url" name="website" maxlength="{{ $websiteMax }}" dir="ltr"
                               value="{{ old('website', $user->website) }}" placeholder="https://"
                               @if($errors->has('website')) aria-invalid="true" aria-describedby="website-error" @endif>
                        @include('users.profile.partials.field-error', ['field' => 'website'])
                    </div>
                    @if($customizationEnabled && $on('cta'))
                        @php $ctaValue = (string) old('channel_customization.cta_label', $c['cta_label']); @endphp
                        <div class="mk-field">
                            <label class="mk-label" for="cta_label">{{ __('Button on your channel') }} <span class="mk-label__opt">{{ __('optional') }}</span></label>
                            <input class="mk-input" id="cta_label" type="text" data-mk-dir{!! trim($ctaValue) !== '' ? ' dir="auto"' : '' !!} name="channel_customization[cta_label]" maxlength="{{ $ctaMax }}"
                                   value="{{ $ctaValue }}"
                                   aria-describedby="cta-help{{ $errors->has('channel_customization.cta_label') ? ' channel_customization-cta_label-error' : '' }}"
                                   @if($errors->has('channel_customization.cta_label')) aria-invalid="true" @endif>
                            <div class="mk-counter" aria-hidden="true"><span>{{ mb_strlen($ctaValue) }}</span> / {{ $ctaMax }}</div>
                            <span class="mk-help" id="cta-help">{{ trans_choice('{1} Shown next to Subscribe. :count character at most.|[2,*] Shown next to Subscribe. :count characters at most.', $ctaMax, ['count' => $ctaMax]) }}</span>
                            @include('users.profile.partials.field-error', ['field' => 'channel_customization.cta_label'])
                        </div>
                    @endif
                </div>

                @if($customizationEnabled && $on('cta'))
                    <div class="mk-field">
                        <label class="mk-label" for="cta_url">{{ __('Button link') }} <span class="mk-label__opt">{{ __('optional') }}</span></label>
                        <input class="mk-input" id="cta_url" type="text" inputmode="url" name="channel_customization[cta_url]" maxlength="255" dir="ltr"
                               value="{{ old('channel_customization.cta_url', $c['cta_url']) }}" placeholder="https://"
                               @if($errors->has('channel_customization.cta_url')) aria-invalid="true" aria-describedby="channel_customization-cta_url-error" @endif>
                        @include('users.profile.partials.field-error', ['field' => 'channel_customization.cta_url'])
                    </div>
                @endif

                <label class="mk-option mk-option--switch">
                    @if($siteShowsCounts)
                        <input type="hidden" name="show_subscribers" value="0">
                    @endif
                    <input class="mk-switch" type="checkbox" role="switch" name="show_subscribers" value="1"
                           @checked(old('show_subscribers', $user->show_subscribers)) @disabled(! $siteShowsCounts)>
                    <span class="mk-option__text">
                        <span>{{ __('Show how many people subscribe') }}</span>
                        <span class="mk-option__help">
                            @if($siteShowsCounts)
                                {{ __('When it is off, your subscriber count is hidden and your channel is not listed in Discover channels.') }}
                            @else
                                {{ __('This site hides subscriber counts on every channel, so this choice waits until it shows them again.') }}
                            @endif
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </section>

    @if($groups !== [])
        <section class="mk-panel" aria-labelledby="hChannelLook">
            <div class="mk-panel__head">
                <h2 class="mk-panel__title" id="hChannelLook">{{ __('Channel look') }}</h2>
                <p class="mk-panel__sub">{{ __('Your choices change the top band of your channel. The rest of the page always follows the site\'s look, so it stays readable and fast.') }}</p>
            </div>
            <div class="mk-panel__body mk-groups"
                 @if($lazyGroups)
                     x-data
                     data-group-src="{{ \App\Http\Controllers\User\ProfileController::sectionUrl('channel') }}"
                     data-loading="{{ __('Loading...') }}"
                     data-failed="{{ __('These options did not load. Check your connection, then open the group again.') }}"
                     @toggle.capture="avdChannelGroup($event.target, $el)"
                 @endif>
                @foreach($groups as $group => [$groupTitle, $groupSummary])
                    @php
                        // open: the first group, the group ?group= names, and a group with an error (its fields print their message)
                        $groupOpen = $loop->first || $group === $openGroup
                            || collect($groupFields[$group] ?? [])->contains(fn ($field) => $errors->has('channel_customization.'.$field));
                    @endphp
                    @if($groupOpen)
                        <details class="mk-group-block" open>
                            <summary><span>{{ $groupTitle }}</span><span class="mk-muted">{{ $groupSummary }}</span></summary>
                            @include('users.profile.partials.channel-group')
                        </details>
                    @elseif($lazyGroups)
                        {{-- fetched when it is opened the first time (avdChannelGroup) --}}
                        <details class="mk-group-block" data-group="{{ $group }}">
                            <summary><span>{{ $groupTitle }}</span><span class="mk-muted">{{ $groupSummary }}</span></summary>
                        </details>
                    @else
                        {{-- after a refused save: the typed values wait in a template until the group is opened --}}
                        <details class="mk-group-block" x-data @toggle.once="if ($el.open) { const body = $el.querySelector(':scope > template'); if (body) { $el.append(body.content.cloneNode(true)); body.remove(); } }">
                            <summary><span>{{ $groupTitle }}</span><span class="mk-muted">{{ $groupSummary }}</span></summary>
                            <template>@include('users.profile.partials.channel-group')</template>
                        </details>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    @include('users.profile.partials.savebar', ['label' => __('Save channel')])
</form>

@if(filled($user->banner))
    <form id="mk-remove-banner" method="POST" action="{{ route('user.update') }}" hidden>
        @csrf
        @method('PUT')
        <input type="hidden" name="section" value="channel">
        <input type="hidden" name="remove_banner" value="1">
    </form>
@endif

@pushOnce('scripts')
<script>
    // Account settings > Channel: a closed "Channel look" group gets its fields from the page's own address the first
    // time it is opened (?section=channel&group=<name>, answered with X-Mk-Fragment: channel-group). Alpine starts the
    // added fields by itself. A failed load says so in the group; opening the group again tries once more.
    window.avdChannelGroup = window.avdChannelGroup || function (details, list) {
        if (!details || details.tagName !== 'DETAILS' || !details.open || !details.dataset.group || !list) {
            return;
        }

        var state = details.dataset.state;

        if (state === 'loaded' || state === 'loading') {
            return;
        }

        var status = details.querySelector(':scope > .mk-group-block__status');

        if (!status) {
            status = document.createElement('p');
            status.setAttribute('role', 'status');
            details.appendChild(status);
        }

        status.className = 'mk-help mk-group-block__status';
        status.textContent = list.dataset.loading || '';
        details.dataset.state = 'loading';

        var source = list.dataset.groupSrc || '';
        var url = source + (source.indexOf('?') === -1 ? '?' : '&') + 'group=' + encodeURIComponent(details.dataset.group);

        // a part of the page filled in after it appears: a background GET (App\Http\Middleware\BackgroundRequest), so it never
        // uses up a one-time message of the page the member opens next
        fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html', 'X-Background-Request': '1'}})
            .then(function (response) {
                if (!response.ok || response.headers.get('X-Mk-Fragment') !== 'channel-group') {
                    throw new Error('The group did not load.');
                }

                return response.text();
            })
            .then(function (html) {
                var body = document.createElement('template');
                body.innerHTML = html;
                status.remove();
                details.appendChild(body.content);
                details.dataset.state = 'loaded';
            })
            .catch(function () {
                status.className = 'mk-error mk-group-block__status';
                status.textContent = list.dataset.failed || '';
                details.dataset.state = 'failed';
            });
    };
</script>
@endPushOnce
@endif
