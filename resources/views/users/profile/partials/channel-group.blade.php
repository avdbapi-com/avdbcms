{{--
    The body of one "Channel look" group of Account settings > Channel (member design 6.19): Colours, Header, Motion,
    Sections or Sizes ($group). Included by users/profile/sections/channel.blade.php, whose variables it uses ($on, $c,
    $opt, $bandChips, $accentChips, $accentOptions, $sectionSwitches): inline for an open group, in a template after a
    refused save, and alone as the answer the page fetches when a closed group is opened the first time.
--}}
{{-- (1.2.3 round 3, R3-086: the swatches' colour chips are empty boxes, which assistive technology skips without an
     aria-hidden) --}}
<div class="mk-form">
    @if($group === 'colours')
        @if($on('theme'))
            <div class="mk-field">
                <span class="mk-label" id="band-label">{{ __('Header band') }}</span>
                <div class="mk-swatches" role="radiogroup" aria-labelledby="band-label">
                    @foreach($opt['theme'] as $value => $text)
                        <label class="mk-swatch">
                            <input type="radio" name="channel_customization[theme]" value="{{ $value }}" @checked(old('channel_customization.theme', $c['theme']) === $value)>
                            <span class="mk-swatch__chip" style="background:{{ $bandChips[$value] ?? 'var(--avd-surface-2)' }}"></span>
                            <span class="mk-swatch__label">{{ __($text) }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
        @if($on('accent'))
            <div class="mk-field" x-data="{ accent: @js((string) old('channel_customization.accent', $c['accent'])) }">
                {{-- the field shows what the channel uses (an old default as the site's default); an accent changed here is
                     the member's pick and stays, one left as shown keeps following the site's default
                     (App\Support\ChannelCustomizationSettings::chosenDefaults()) --}}
                <span class="mk-label" id="accent-label">{{ __('Accent colour') }} <span class="mk-label__opt">{{ __('tab underline, statistics, button outline') }}</span></span>
                <div class="mk-swatches" role="radiogroup" aria-labelledby="accent-label">
                    @foreach($accentOptions as $value => $text)
                        <label class="mk-swatch">
                            <input type="radio" name="channel_customization[accent]" value="{{ $value }}" x-model="accent" @checked(old('channel_customization.accent', $c['accent']) === $value)>
                            <span class="mk-swatch__chip" style="background:{{ $value === 'custom' ? $c['custom_accent'] : ($accentChips[$value] ?? 'var(--avd-brand-fill)') }}"></span>
                            {{-- the own colour in short words ("Custom color" is one 80-110px word in French,
                                 German and Russian, wider than the 72px swatch) --}}
                            <span class="mk-swatch__label">{{ $value === 'custom' ? __('Your own colour') : __($text) }}</span>
                        </label>
                    @endforeach
                </div>
                @if(array_key_exists('custom', $accentOptions))
                    <div class="mk-field mk-field--inline" x-show="accent === 'custom'" @if(old('channel_customization.accent', $c['accent']) !== 'custom') x-cloak @endif>
                        <label class="mk-label" for="custom_accent">{{ __('Your own colour') }}</label>
                        <input class="mk-color" id="custom_accent" type="color" name="channel_customization[custom_accent]" value="{{ old('channel_customization.custom_accent', $c['custom_accent']) }}">
                    </div>
                @endif
                <span class="mk-help">{{ __('Colours are adjusted in light and dark so text on them stays easy to read.') }}</span>
            </div>
        @endif
        @if($on('surface'))
            <div class="mk-field">
                <span class="mk-label" id="cards-label">{{ __('Cards') }}</span>
                <div class="mk-seg" role="radiogroup" aria-labelledby="cards-label">
                    @foreach($opt['surface'] as $value => $text)
                        <label class="mk-seg__item">
                            <input type="radio" name="channel_customization[surface]" value="{{ $value }}" @checked(old('channel_customization.surface', $c['surface']) === $value)>
                            <span class="mk-seg__label">{{ __($text) }}</span>
                        </label>
                    @endforeach
                </div>
                <span class="mk-help">{{ __('Glass is drawn solid on phones, on slower devices and when reduced transparency or motion is set.') }}</span>
            </div>
        @endif
    @elseif($group === 'header')
        <div class="mk-form__row mk-form__row--2">
            @foreach([
                ['layout', 'layout', __('Page layout'), 'layout'],
                ['banner_treatment', 'banner_treatment', __('Banner'), 'banner'],
                ['header_shape', 'header_shape', __('Header shape'), 'shape'],
                ['avatar_frame', 'avatar_frame', __('Frame around your picture'), 'avatar'],
                ['pattern', 'pattern', __('Background pattern'), 'pattern'],
                ['stats_style', 'stats_style', __('Statistics style'), 'stats'],
            ] as [$name, $options, $title, $gate])
                @if($on($gate))
                    <div class="mk-field">
                        <label class="mk-label" for="cc-{{ $name }}">{{ $title }}</label>
                        <select class="form-select" id="cc-{{ $name }}" name="channel_customization[{{ $name }}]" data-native-select>
                            @foreach($opt[$options] as $value => $text)
                                <option value="{{ $value }}" @selected(old('channel_customization.'.$name, $c[$name]) === $value)>{{ __($text) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @endforeach
        </div>
    @elseif($group === 'motion')
        <div class="mk-field">
            <label class="mk-label" for="cc-motion">{{ __('Motion') }}</label>
            <select class="form-select" id="cc-motion" name="channel_customization[motion]" data-native-select>
                @foreach($opt['motion'] as $value => $text)
                    <option value="{{ $value }}" @selected(old('channel_customization.motion', $c['motion']) === $value)>{{ __($text) }}</option>
                @endforeach
            </select>
            <span class="mk-help">{{ __('Gentle fades the sections in once. Float, Pulse and Light sweep play once on fast devices. Nothing moves for visitors who ask for less motion.') }}</span>
        </div>
    @elseif($group === 'sections')
        @foreach($sectionSwitches as $key => $text)
            <label class="mk-option mk-option--switch">
                <input type="hidden" name="channel_customization[{{ $key }}]" value="0">
                <input class="mk-switch" type="checkbox" role="switch" name="channel_customization[{{ $key }}]" value="1" @checked(old('channel_customization.'.$key, $c[$key]) === '1')>
                <span class="mk-option__text"><span>{{ $text }}</span></span>
            </label>
        @endforeach
        @if($on('featured_video'))
            <div class="mk-field">
                <label class="mk-label" for="cc-featured_mode">{{ __('Which video is featured') }}</label>
                <select class="form-select" id="cc-featured_mode" name="channel_customization[featured_mode]" data-native-select>
                    @foreach($opt['featured_mode'] as $value => $text)
                        <option value="{{ $value }}" @selected(old('channel_customization.featured_mode', $c['featured_mode']) === $value)>{{ __($text) }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <label class="mk-option mk-option--switch">
            <input type="hidden" name="channel_customization[dedupe_home_sections]" value="0">
            <input class="mk-switch" type="checkbox" role="switch" name="channel_customization[dedupe_home_sections]" value="1" @checked(old('channel_customization.dedupe_home_sections', $c['dedupe_home_sections']) === '1')>
            <span class="mk-option__text"><span>{{ __('Show each video once on your channel home') }}</span></span>
        </label>
    @elseif($group === 'sizes')
        <div class="mk-form__row mk-form__row--2">
            @foreach([
                ['video_layout', 'video_layout', __('Video list'), 'video_layout'],
                ['featured_layout', 'featured_layout', __('Featured video layout'), 'featured_layout'],
                ['latest_uploads_layout', 'latest_uploads_layout', __('Latest uploads layout'), 'latest_layout'],
                ['playlists_layout', 'playlists_layout', __('Playlists layout'), 'playlists_layout'],
                ['density', 'density', __('Spacing'), 'layout'],
            ] as [$name, $options, $title, $gate])
                @if($on($gate))
                    <div class="mk-field">
                        <label class="mk-label" for="cc-{{ $name }}">{{ $title }}</label>
                        <select class="form-select" id="cc-{{ $name }}" name="channel_customization[{{ $name }}]" data-native-select>
                            @foreach($opt[$options] as $value => $text)
                                <option value="{{ $value }}" @selected(old('channel_customization.'.$name, $c[$name]) === $value)>{{ __($text) }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            @endforeach
        </div>
        {{-- no "Videos in a playlist" sizes: the channel shows each playlist as one card, so they changed nothing
             (the stored values stay as they are) --}}
        <div class="mk-form__row mk-form__row--2">
            @foreach([
                ['featured_columns', __('Featured: per row'), 1, 6, 'featured_sizing'],
                ['featured_rows', __('Featured: rows'), 1, 4, 'featured_sizing'],
                ['latest_uploads_columns', __('Latest uploads: per row'), 1, 6, 'latest_sizing'],
                ['latest_uploads_rows', __('Latest uploads: rows'), 1, 6, 'latest_sizing'],
                ['playlists_columns', __('Playlists: per row'), 1, 4, 'playlists_sizing'],
                ['playlists_rows', __('Playlists: rows'), 1, 6, 'playlists_sizing'],
            ] as [$name, $title, $min, $max, $gate])
                @if($on($gate))
                    <div class="mk-field">
                        <label class="mk-label" for="cc-{{ $name }}">{{ $title }}</label>
                        <input class="mk-input" id="cc-{{ $name }}" type="number" inputmode="numeric" name="channel_customization[{{ $name }}]"
                               min="{{ $min }}" max="{{ $max }}" step="1" value="{{ old('channel_customization.'.$name, $c[$name]) }}"
                               @if($errors->has('channel_customization.'.$name)) aria-invalid="true" aria-describedby="channel_customization-{{ $name }}-error" @endif>
                        @include('users.profile.partials.field-error', ['field' => 'channel_customization.'.$name])
                    </div>
                @endif
            @endforeach
        </div>
        <span class="mk-help">{{ __('Rows and cards per row are the most a section shows: on smaller screens it shows fewer per row.') }}</span>
    @endif
</div>
