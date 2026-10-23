@php
    $channelCustomizationEnabled = config('site.channel_customization_enabled', true);
    $channelCustomization = \App\Support\ChannelCustomizationSettings::merge(old('channel_customization', $user->channel_customization ?? []));
    $channelOptions = \App\Support\ChannelCustomizationSettings::options();
@endphp

<div class="row align-items-start my-3">
    <div class="col-12 col-xl-4">
        <h2>{{ __('Channel Information') }}</h2>
        <p class="text-muted">{{ __('Update your channel information') }}</p>
    </div>
    <div class="col-12 col-xl-8">
        <div class="card shadow-soft my-3">
            <form action="{{ route('user.update') }}" method="POST" enctype="multipart/form-data">
                <div class="card-body">
                    @method('PUT')
                    @csrf
                    <div
                        class="position-relative overflow-hidden"
                        x-data="{hover:false}"
                        @mouseover="hover=true"
                        @mouseleave="hover=false"
                        @focusin="hover=true"
                        @focusout="hover=false"
                        @cropped-banner.window="$refs.image.setAttribute('src', URL.createObjectURL($event.detail))"
                    >
                        <img x-ref="image" class="img-fluid w-100" src="{{$user->banner_url}}" alt="{{__('Banner')}}" width="1600" height="450" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_banner.jpg') }}';">
                        <div
                            class="position-absolute w-100 text-center text-white start-50 z-2"
                            :class="hover ? 'opacity-100 top-50' : 'opacity-0 top-75'"
                            style="transition: opacity 0.3s ease-in-out, top 0.3s ease-in-out; transform: translate(-50%, -50%);"
                        >
                            <div class="fw-bold">{{ __('Click to update Banner') }}</div>
                        </div>
                        <image-upload
                            name="banner"
                            config="banner"
                            class="position-absolute bottom-0 left-0 right-0 top-0 opacity-0 cursor-pointer w-100 z-3"
                        >
                        </image-upload>
                        <div
                            style="transition: opacity 0.4s ease-in-out;"
                            class="position-absolute bg-dark bg-opacity-75 bottom-0 left-0 right-0 top-0 w-100"
                            :class="hover ? 'opacity-100' : 'opacity-0'"
                        >
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12 col-sm-6 mb-3">
                            <label for="website" class="form-label">{{ __('Website') }}</label>
                            <input
                                class="form-control"
                                type="text"
                                id="website"
                                name="website"
                                value="{{old('website', $user->website)}}"
                                maxlength="{{config('validation.user.website.max')}}"
                            >
                        </div>
                    </div>
                    <div class="row" x-data="{ count: 0 }" x-init="count = $refs.message.value.length">
                        <div class="col-12 mb-3">
                            <label for="description" class="form-label">{{ __('Channel Description') }}</label>
                            <textarea
                                class="form-control"
                                id="description"
                                rows="6"
                                name="description"
                                maxlength="{{config('validation.user.description.max')}}"
                                x-ref="message"
                                @keyup="count = $refs.message.value.length"
                            >{{old('description', $user->description)}}</textarea>
                            <div class="form-text">
                                <span x-text="count"></span> / <span>{{config('validation.user.description.max')}}</span>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 mb-3">
                            <div class="form-check form-switch">
                                <input type="hidden" value="0" name="show_subscribers">
                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    id="show_subscribers"
                                    value="1"
                                    name="show_subscribers"
                                    @checked(old('show_subscribers', $user->show_subscribers))
                                >
                                <label class="form-check-label" for="show_subscribers">
                                    {{ __('Show my subscribers count') }}
                                </label>
                            </div>
                            <div class="form-text">
                                {!! __("By disabling this option, you don't appear on <a class=\"text-decoration-none\" href=\":discover_url\">discover</a> page", ['discover_url' => route('subscription.discover')]) !!}
                            </div>
                        </div>
                    </div>
                    @if($channelCustomizationEnabled)
                        <hr class="my-4">
                        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-3">
                            <div>
                                <h3 class="h5 mb-1">{{ __('Channel design studio') }}</h3>
                                <p class="text-muted mb-0">{{ __('Shape the public channel page with layouts, themes, motion, sections, and calls to action controlled by admin settings.') }}</p>
                            </div>
                            <a class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-2" href="{{$user->route}}">
                                <i class="fa-solid fa-eye"></i>
                                <span>{{ __('Preview') }}</span>
                            </a>
                        </div>
                        <div class="row g-3">
                            @if(config('site.channel_customization_layout_enabled', true))
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Page Layout') }}</label>
                                    <select name="channel_customization[layout]" class="form-select">
                                        @foreach($channelOptions['layout'] as $key => $label)
                                            <option value="{{ $key }}" @selected($channelCustomization['layout'] === $key)>{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            @if(config('site.channel_customization_theme_enabled', true))
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Theme Mood') }}</label>
                                    <select name="channel_customization[theme]" class="form-select">
                                        @foreach($channelOptions['theme'] as $key => $label)
                                            <option value="{{ $key }}" @selected($channelCustomization['theme'] === $key)>{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            @if(config('site.channel_customization_accent_enabled', true))
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Accent Color') }}</label>
                                    <select name="channel_customization[accent]" class="form-select">
                                        @foreach($channelOptions['accent'] as $key => $label)
                                            <option value="{{ $key }}" @selected($channelCustomization['accent'] === $key)>{{ __($label) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            @if(config('site.channel_customization_custom_accent_enabled', true))
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Custom Accent') }}</label>
                                    <input type="color" name="channel_customization[custom_accent]" class="form-control form-control-color w-100" value="{{ $channelCustomization['custom_accent'] }}">
                                </div>
                            @endif
                            @foreach([
                                ['surface', __('Surface Style'), 'site.channel_customization_surface_enabled'],
                                ['banner_treatment', __('Banner Treatment'), 'site.channel_customization_banner_enabled'],
                                ['avatar_frame', __('Avatar Frame'), 'site.channel_customization_avatar_enabled'],
                                ['motion', __('Motion Signature'), 'site.channel_customization_motion_enabled'],
                                ['pattern', __('Background Pattern'), 'site.channel_customization_pattern_enabled'],
                                ['header_shape', __('Header Shape'), 'site.channel_customization_shape_enabled'],
                                ['stats_style', __('Stats Style'), 'site.channel_customization_stats_enabled'],
                                ['video_layout', __('Video Layout'), 'site.channel_customization_video_layout_enabled'],
                                ['featured_mode', __('Featured Video Source'), 'site.channel_customization_featured_video_enabled'],
                                ['featured_layout', __('Featured Layout'), 'site.channel_customization_featured_layout_enabled'],
                                ['latest_uploads_layout', __('Latest Uploads Layout'), 'site.channel_customization_latest_layout_enabled'],
                                ['playlists_layout', __('Playlists Layout'), 'site.channel_customization_playlists_layout_enabled'],
                                ['density', __('Page Density'), 'site.channel_customization_layout_enabled'],
                            ] as $select)
                                @if(config($select[2], true))
                                    <div class="col-12 col-md-6">
                                        <label class="form-label fw-bold">{{ $select[1] }}</label>
                                        <select name="channel_customization[{{ $select[0] }}]" class="form-select">
                                            @foreach($channelOptions[$select[0]] as $key => $label)
                                                <option value="{{ $key }}" @selected($channelCustomization[$select[0]] === $key)>{{ __($label) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            @endforeach
                            @if(config('site.channel_customization_featured_sizing_enabled', true))
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Featured Items Per Row') }}</label>
                                    <input type="number" name="channel_customization[featured_columns]" class="form-control" value="{{ $channelCustomization['featured_columns'] }}" min="1" max="6">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Featured Rows') }}</label>
                                    <input type="number" name="channel_customization[featured_rows]" class="form-control" value="{{ $channelCustomization['featured_rows'] }}" min="1" max="4">
                                </div>
                            @endif
                            @if(config('site.channel_customization_latest_sizing_enabled', true))
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Latest Uploads Items Per Row') }}</label>
                                    <input type="number" name="channel_customization[latest_uploads_columns]" class="form-control" value="{{ $channelCustomization['latest_uploads_columns'] }}" min="1" max="6">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Latest Uploads Rows') }}</label>
                                    <input type="number" name="channel_customization[latest_uploads_rows]" class="form-control" value="{{ $channelCustomization['latest_uploads_rows'] }}" min="1" max="6">
                                </div>
                            @endif
                            @if(config('site.channel_customization_playlists_sizing_enabled', true))
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Playlists Per Row') }}</label>
                                    <input type="number" name="channel_customization[playlists_columns]" class="form-control" value="{{ $channelCustomization['playlists_columns'] }}" min="1" max="4">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Playlist Rows') }}</label>
                                    <input type="number" name="channel_customization[playlists_rows]" class="form-control" value="{{ $channelCustomization['playlists_rows'] }}" min="1" max="6">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Playlist Videos Per Row') }}</label>
                                    <input type="number" name="channel_customization[playlist_video_columns]" class="form-control" value="{{ $channelCustomization['playlist_video_columns'] }}" min="1" max="6">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label fw-bold">{{ __('Playlist Video Rows') }}</label>
                                    <input type="number" name="channel_customization[playlist_video_rows]" class="form-control" value="{{ $channelCustomization['playlist_video_rows'] }}" min="1" max="4">
                                </div>
                            @endif
                            <div class="col-12">
                                <label class="form-label fw-bold">{{ __('Channel Tagline') }}</label>
                                <input
                                    type="text"
                                    name="channel_customization[tagline]"
                                    class="form-control"
                                    value="{{ $channelCustomization['tagline'] }}"
                                    maxlength="{{ config('site.channel_tagline_max_length', 120) }}"
                                    placeholder="{{ __('Short line shown under your channel name') }}"
                                >
                            </div>
                            @if(config('site.channel_customization_cta_enabled', true))
                                <div class="col-12 col-md-5">
                                    <label class="form-label fw-bold">{{ __('Custom CTA Label') }}</label>
                                    <input type="text" name="channel_customization[cta_label]" class="form-control" value="{{ $channelCustomization['cta_label'] }}" maxlength="{{ config('site.channel_cta_label_max_length', 42) }}" placeholder="{{ __('Visit my site') }}">
                                </div>
                                <div class="col-12 col-md-7">
                                    <label class="form-label fw-bold">{{ __('Custom CTA URL') }}</label>
                                    <input type="url" name="channel_customization[cta_url]" class="form-control" value="{{ $channelCustomization['cta_url'] }}" maxlength="255" placeholder="https://example.com">
                                </div>
                            @endif
                        </div>
                        <div class="avd-switch-grid mt-4">
                            @foreach([
                                ['show_stats', __('Show Stats'), 'site.channel_customization_stats_enabled'],
                                ['show_featured_video', __('Show Featured Video'), 'site.channel_customization_featured_video_enabled'],
                                ['show_latest_uploads', __('Show Latest Uploads'), 'site.channel_customization_latest_uploads_enabled'],
                                ['show_playlists', __('Show Playlists'), 'site.channel_customization_playlists_enabled'],
                                ['show_about_panel', __('Show About Panel'), 'site.channel_customization_sections_enabled'],
                                ['show_social_links', __('Show Website Link'), 'site.channel_customization_socials_enabled'],
                                ['show_upload_rhythm', __('Show Upload Rhythm'), 'site.channel_customization_upload_rhythm_enabled'],
                                ['show_subscribe_cta', __('Show Subscribe CTA'), 'site.channel_customization_cta_enabled'],
                                ['dedupe_home_sections', __('Avoid duplicate videos on Home'), null],
                            ] as $toggle)
                                @if($toggle[2] === null || config($toggle[2], true))
                                    <label class="avd-switch-card">
                                        <input type="hidden" name="channel_customization[{{ $toggle[0] }}]" value="0">
                                        <input class="form-check-input" type="checkbox" name="channel_customization[{{ $toggle[0] }}]" value="1" @checked($channelCustomization[$toggle[0]] === '1')>
                                        <span class="fw-bold">{{ $toggle[1] }}</span>
                                    </label>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="card-footer d-flex flex-column flex-sm-row gap-2 justify-content-between">
                    <a class="btn btn-success d-flex align-items-center gap-2" href="{{$user->route}}">
                        <i class="fa-solid fa-eye"></i>
                        <span>{{ __('Show my channel') }}</span>
                    </a>
                    <button type="submit" class="btn btn-primary d-flex align-items-center gap-2">
                        <i class="fa-solid fa-user-edit"></i>
                        <span>{{ __('Update My Channel') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
