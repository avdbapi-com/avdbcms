@use('App\Models\Video')

@extends('layouts.default')

@php
    $videoDescription = trim(strip_tags((string) $video->description));
    $videoCategoryRawTitle = $video->category ? decode_html_entities($video->category->title) : null;
    $videoCategoryTitle = $video->localized_type_name ?: ($videoCategoryRawTitle ? __($videoCategoryRawTitle) : null);
    // 1.2.3: a category named with a word that is always blocked is never shown (App\Support\ContentSafety)
    $videoCategoryShown = $video->category && ! \App\Support\ContentSafety::hides($video->category->title);
    $videoTags = $video->relationLoaded('tags') ? $video->tags : collect();
    $videoTagNames = $videoTags
        ->map(fn ($tag) => $tag->localized_name ?: $tag->name)
        ->filter()
        ->unique()
        ->values();
    // Providers send placeholders such as "Updating" for unknown fields: never show or link those.
    $metaValue = fn ($value) => in_array(mb_strtolower(trim((string) $value)), ['', 'updating', 'unknown', 'n/a', 'na', 'none', 'null', '-', '--', '---', '----'], true) ? null : trim((string) $value);
    $videoAvdbCode = $metaValue($video->avdb_code) ?: ($video->avdb_id ? $video->original_file_name : null);
    $videoCode = $metaValue($video->xvid_code) ?: ($videoAvdbCode ?: ($video->isImported ? $video->original_file_name : null));
    $videoActor = $metaValue($video->xvid_actor) ?: $metaValue($video->avdb_actor);
    $videoDirector = $metaValue($video->avdb_director);
    $videoAuthor = $metaValue($video->xvid_author) ?: $metaValue($video->avdb_writer);
    $videoQuality = trim((string) $video->xvid_quality);
    $videoDuration = trim((string) $video->duration);
    // The video's internal id (UUID) is not a fact for viewers (watch-42): it is in the page's address already.
    $videoSourceDetails = collect([
        ['label' => __('Source').' '.__('Quality'), 'value' => $videoQuality],
        ['label' => __('Duration'), 'value' => $videoDuration],
    ])->filter(fn ($row) => filled($row['value'] ?? null))->values();
    $videoMetaDescription = collect([
        $videoDescription,
        $videoCode ? __('Code: :code.', ['code' => $videoCode]) : null,
        $videoQuality ? __('Quality: :quality.', ['quality' => $videoQuality]) : null,
        $videoActor ? __('Actor: :actor.', ['actor' => $videoActor]) : null,
        $videoAuthor ? __('Studio: :studio.', ['studio' => $videoAuthor]) : null,
        $videoDirector ? __('Director: :director.', ['director' => $videoDirector]) : null,
        $videoTagNames->isNotEmpty() ? __('Tags: :tags.', ['tags' => $videoTagNames->take(6)->implode(', ')]) : null,
    ])->filter()->implode(' ');
    $videoDetailRows = collect([
        ['label' => __('Code'), 'icon' => 'barcode', 'value' => $videoCode, 'search' => $videoCode],
        ['label' => __('Quality'), 'icon' => 'film', 'value' => $videoQuality, 'search' => null],
        ['label' => __('Actor'), 'icon' => 'user', 'value' => $videoActor, 'search' => $videoActor],
        ['label' => __('Author/Studio'), 'icon' => 'building', 'value' => $videoAuthor, 'search' => $videoAuthor],
        ['label' => __('Director'), 'icon' => 'video', 'value' => $videoDirector, 'search' => $videoDirector],
        ['label' => __('Country'), 'icon' => 'globe', 'value' => $video->localized_country ?: $video->avdb_country, 'search' => $video->avdb_country ?: $video->localized_country],
    ])->filter(fn ($row) => filled($row['value'] ?? null))->values();
    $seoService = app(\App\Services\SeoService::class);
    $videoDescriptionFallback = Str::limit($videoMetaDescription !== '' ? $videoMetaDescription : __('Watch :title on :site.', ['title' => $video->title, 'site' => config('app.name')]), 155);
    $videoSeoValues = $seoService->videoTemplateValues($video, $videoDescriptionFallback);
    // Time codes (watch-22): a code is a seek button only inside the video's length and on a player the page drives
    // itself; an Upload18 video plays in the provider's frame, which the page cannot seek
    // (resources/js/components/Videos/Player.tsx: is_upload18 + embed_url).
    $watchDurationSeconds = max(0, (int) $video->getRawOriginal('duration'));
    $watchSeekable = ! $video->is_upload18;
    // Sign-in prompts of the page bring the visitor back here, in their language (auth-18), through the header's own link
    // (ReturnPath::loginUrl(): ?redirect= is a path of this site, never the address as received; round 3 R3-079). Create
    // account carries no return address (the sign-up page reads none).
    $watchSignInUrl = \App\Http\Controllers\Auth\ReturnPath::loginUrl();
    $watchSignUpUrl = locale_url(route('registration'));
    // The page's canonical (round 4, R3M-01): an AVDBAPI import's page in a language other than the default one where it
    // is indexable (its translation's page, App\Support\VideoLanguages; the sitemaps and the hreflang links offer it) names
    // itself, in the form of its sitemap entry (SeoController::localizedAbsoluteUrl()); every other watch page names the
    // default-language page, as in 1.2.2 (a member's upload, another source's import, an import's page that answers
    // noindex). The controller left the import's indexable languages in the request (VideoLanguages::HREFLANG): no query.
    $watchLocale = app()->getLocale();
    $watchCanonical = $watchLocale !== site_default_locale()
        && in_array($watchLocale, (array) request()->attributes->get(\App\Support\VideoLanguages::HREFLANG, []), true)
        ? url('/'.$watchLocale.$seoService->videoPath($video))
        : $video->route;
@endphp

@section('title', $seoService->renderTemplate('video_meta_title_template', $videoSeoValues, $video->title, 180))
@section('description', $seoService->renderTemplate('video_meta_description_template', $videoSeoValues, $videoDescriptionFallback, 500))
@section('image'){{$video->thumbnail_url}}@stop
@section('canonical', $watchCanonical)
@section('og_type', 'video.other')
@section('rating', 'adult')

@push('structured-data')
    @if(config('site.video_schema_enabled', true))
        @php
            $jsonScriptFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
        @endphp
        <script type="application/ld+json">{!! json_encode(app(\App\Services\SeoService::class)->videoSchema($video, $watchCanonical), $jsonScriptFlags) !!}</script>
    @endif
@endpush

@push('seo-head')
    @if($video->is_upload18 && $video->embed_url)
        <link rel="preconnect" href="https://upload18.org">
        <link rel="dns-prefetch" href="https://upload18.org">
        <link rel="preconnect" href="https://helvid.com" crossorigin>
        <link rel="dns-prefetch" href="https://helvid.com">
        <link rel="preconnect" href="https://upload18.cc" crossorigin>
        <link rel="dns-prefetch" href="https://upload18.cc">
    @endif
@endpush

{{-- watch-7: the watch page's own rules (resources/css/watch-page.css) and its tools (resources/js/layout/watchTools.ts)
     are cached build files instead of 117 KB of inline style and script. Without a build entry (views newer than
     public/build) the page links neither and core.ts loads both itself. --}}
@php
    $watchAsset = static function (string $entry): ?string {
        try {
            return \Illuminate\Support\Facades\Vite::asset($entry);
        } catch (\Throwable) {
            return null;
        }
    };
    $watchPageCss = $watchAsset('resources/css/watch-page.css');
    $watchToolsSrc = $watchAsset('resources/js/layout/watchTools.ts');
    // The tools' settings, printed as JSON at the end of the page (#avd-watch-tools). Computed in this block, which
    // comes before the view's one-line php directives: Blade pairs a block's start with the next block end it finds.
    // (The ambient-glow switch is added where the JSON is printed: the view sets it further down.)
    $watchToolsSettings = [
        'title' => (string) $video->title,
        'uuid' => (string) $video->uuid,
        'embedUrl' => route('video.embed', ['video' => $video->uuid]),
        't' => [
                'Disabled' => (string) __('Disabled'),
                'Embed code copied to clipboard!' => (string) __('Embed code copied to clipboard!'),
                'Embed code copied!' => (string) __('Embed code copied!'),
                'Filter' => (string) __('Filter'),
                'No notes to copy yet' => (string) __('No notes to copy yet'),
                'No notes to export yet' => (string) __('No notes to export yet'),
                'Notes copied to clipboard!' => (string) __('Notes copied to clipboard!'),
                'Notes copied!' => (string) __('Notes copied!'),
                'Notes exported as .txt!' => (string) __('Notes exported as .txt!'),
                'Remove' => (string) __('Remove'),
                'Sleep' => (string) __('Sleep'),
                'Sleep: End' => (string) __('Sleep: End'),
                'Video link copied to clipboard!' => (string) __('Video link copied to clipboard!'),
                // R1-146 (owner proposal): the filters' names and the toast's word, read by watchTools.ts's __r()
                'Anime' => (string) __('Anime'),
                'Black and white' => (string) __('Black and white'),
                'Color filter' => (string) __('Color filter'),
                'Cyberpunk' => (string) __('Cyberpunk'),
                'Deep black' => (string) __('Deep black'),
                'Night' => (string) __('Night'),
                'Normal' => (string) __('Normal'),
                'Vivid' => (string) __('Vivid'),
                'Warm film' => (string) __('Warm film'),
                // 1.2.3 round 2 (R2A-059, R1R-15): the tools' names in their toasts, the phone sheet's badges and the
                // Video details dialog, as the dock, the sheet and the dialogs name them
                'Active' => (string) __('Active'),
                'Aspect ratio' => (string) __('Aspect ratio'),
                'At the end of the video' => (string) __('At the end of the video'),
                'Brightness' => (string) __('Brightness'),
                'Fill' => (string) __('Fill'),
                'Focus mode' => (string) __('Focus mode'),
                'Full screen' => (string) __('Full screen'),
                'Glow around the player' => (string) __('Glow around the player'),
                'Mirror the video' => (string) __('Mirror the video'),
                'Neon' => (string) __('Neon'),
                'Off' => (string) __('Off'),
                'On' => (string) __('On'),
                'Paused by the sleep timer' => (string) __('Paused by the sleep timer'),
                'Pulse' => (string) __('Pulse'),
                'Rotate the video' => (string) __('Rotate the video'),
                'Sleep timer' => (string) __('Sleep timer'),
                'Theme' => (string) __('Theme'),
                'Warm' => (string) __('Warm'),
                // R1-146: the player's kind on the dock's Tools menu (watchTools.ts avdUpdatePlayerCapabilities)
                'Built-in player' => (string) __('Built-in player'),
                'Player ready' => (string) __('Player ready'),
                'Upload18 player' => (string) __('Upload18 player'),
                // 1.2.3 round 3 (R2R-01, owner approved 2026-10-28): the tools the dock, the Tools menu and the sleep overlay
                // now name in plain words, named the same way in their toasts and labels (snapshot, loop, sound mode, sound
                // bars, mini player, reload, resume), and the browser's own copy box of the dock's Copy video link button
                'Bass boost' => (string) __('Bass boost'),
                'Clear voices' => (string) __('Clear voices'),
                'Copy link' => (string) __('Copy link'),
                'Loop the video' => (string) __('Loop the video'),
                'Looping works only with the built-in player' => (string) __('Looping works only with the built-in player'),
                'Mini player' => (string) __('Mini player'),
                'Night listening' => (string) __('Night listening'),
                'Normal sound' => (string) __('Normal sound'),
                'Snapshot saved' => (string) __('Snapshot saved'),
                'Snapshots work only with the built-in player' => (string) __('Snapshots work only with the built-in player'),
                'Sound bars' => (string) __('Sound bars'),
                'Sound modes work only with the built-in player' => (string) __('Sound modes work only with the built-in player'),
                'Video reloaded' => (string) __('Video reloaded'),
                'Video resumed' => (string) __('Video resumed'),
                // 1.2.3 round 3 (R3-048, R3X-03): a tool's name and its value in the reader's punctuation (watchTools.ts
                // avdPair), sentences with the time where the language puts it, and what a visitor can do where a tool needs
                // the built-in player
                ':label: :value' => (string) __(':label: :value'),
                'Bookmark saved at :time' => (string) __('Bookmark saved at :time'),
                'Bookmarks work only with the built-in player' => (string) __('Bookmarks work only with the built-in player'),
                'Jumped to :time' => (string) __('Jumped to :time'),
                'Playback speed' => (string) __('Playback speed'),
                'Resumed playback from :time' => (string) __('Resumed playback from :time'),
                'Use the gear icon inside the player to change the speed' => (string) __('Use the gear icon inside the player to change the speed'),
        ],
        // the sleep timer's lengths as its dialog shows them ("15 minutes"), for its toast (R2A-059)
        'sleepLengths' => collect([15, 30, 45])->mapWithKeys(fn (int $minutes) => [$minutes => trans_choice('1 minute|:count minutes', $minutes, ['count' => \App\Support\LocalNumber::format($minutes)])])->all(),
    ];
@endphp
@push('head-styles')
    {{-- Where the inline block was in the cascade: after every head stylesheet, before the body's own style blocks. --}}
    @if($watchPageCss)
        <link rel="stylesheet" href="{{ $watchPageCss }}" data-avd-watch-css>
    @endif
    @if($watchToolsSrc)
        <link rel="modulepreload" href="{{ $watchToolsSrc }}">
    @endif
@endpush

@section('class', 'mt-0 mt-lg-3 ms-0')

@section('content')
    {{-- The two entrances the player's notices and the mini player name (its guarded markup and rules); the rest of the
         watch page's own rules are the cached resources/css/watch-page.css. --}}
    <style>
        @keyframes avdPipSlide {
            from { transform: translateY(30px) scale(0.92); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        @keyframes avdFadeSlideDown {
            from { transform: translateY(-16px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
    @php
        $status = null;
        if ($video->is_planned) {
            // 1.2.3 round 3 (R3-062's class): the studio's words, the admin's date format in the reader's order and the
            // site's time zone ("Goes public Oct 31, 2026, 20:00 GMT+1"; it was "Planned - 31 Oct 2026 20:00" in every language)
            $status = ['class' => 'text-warning', 'icon' => 'clock', 'label' => $video->scheduled_at ? \App\Support\Studio\ZoneLabel::goesPublic($video->scheduled_at) : __('Planned')];
        } elseif ($video->is_banned) {
            $status = ['class' => 'text-danger', 'icon' => 'ban', 'label' => __('Banned')];
        } elseif ($video->is_private) {
            $status = ['class' => 'text-danger', 'icon' => 'lock', 'label' => __('Private')];
        } elseif ($video->is_unlisted) {
            $status = ['class' => 'text-muted', 'icon' => 'eye-slash', 'label' => __('Unlisted')];
        } elseif ($video->is_draft) {
            $status = ['class' => 'text-muted', 'icon' => 'file', 'label' => __('Draft')];
        }
        $watchTheaterEnabled = config('site.watch_theater_button_enabled', true);
        $watchShortcutsEnabled = config('site.watch_keyboard_shortcuts_enabled', true);
        $watchNextVideoEnabled = config('site.watch_next_video_enabled', true);
        $watchPlaylistPanelEnabled = config('site.watch_playlist_panel_enabled', true);
        $watchRelatedVideosEnabled = config('site.watch_related_videos_enabled', true);
        $watchSidebarAdsEnabled = config('site.watch_sidebar_ads_enabled', true);
        $watchReactionsEnabled = config('site.watch_reactions_enabled', true);
        $watchShareEnabled = config('site.watch_share_enabled', true);
        $watchSaveEnabled = config('site.watch_save_enabled', true);
        $watchReportEnabled = config('site.watch_report_enabled', true);
        $watchChannelPanelEnabled = config('site.watch_channel_panel_enabled', true);
        $watchDescriptionEnabled = config('site.watch_description_enabled', true);
        $watchMetadataChipsEnabled = config('site.watch_metadata_chips_enabled', true);
        $watchCommentsPreviewEnabled = config('site.watch_comments_preview_enabled', true);
        $siteBool = function (string $key, bool $fallback = true): bool {
            $value = config('site.' . $key, $fallback);

            if (is_bool($value)) {
                return $value;
            }

            return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        };
        $effectsEnabled = $siteBool('effects_enabled');
        $effectEnabled = fn (string $key): bool => $effectsEnabled && $siteBool($key);
        $watchPlayerFrameStyle = (string) config('site.watch_player_frame_style', 'cinematic');
        $watchPlayerFrameStyle = in_array($watchPlayerFrameStyle, ['cinematic', 'soft', 'minimal'], true) ? $watchPlayerFrameStyle : 'cinematic';
        $watchPlayerAmbientGlowEnabled = $effectEnabled('watch_player_ambient_glow_enabled');
        $watchFloatingMiniPlayerEnabled = $effectEnabled('watch_floating_mini_player_enabled');
        $watchAutoplayCountdownSeconds = max(0, min(30, (int) config('site.watch_autoplay_countdown_seconds', 5)));
        $watchActionPanelEnabled = $siteBool('watch_action_panel_enabled');
        $watchUpNextCardEnabled = $siteBool('watch_up_next_card_enabled');
        $watchDescriptionDefaultOpen = $siteBool('watch_description_default_open', false);
        $watchSidebarDensity = (string) config('site.watch_sidebar_density', 'comfortable');
        $watchSidebarDensity = in_array($watchSidebarDensity, ['compact', 'comfortable', 'relaxed'], true) ? $watchSidebarDensity : 'comfortable';
        $jsLazyCommentsEnabled = $effectEnabled('js_lazy_comments_enabled');
        $jsSmoothScrollEnabled = $effectEnabled('js_smooth_scroll_enabled');
        $scrollBehavior = $jsSmoothScrollEnabled ? 'smooth' : 'auto';
        $effectiveNextVideo = $watchNextVideoEnabled ? $nextVideo : null;
        $adsHiddenForViewer = config('site.ads_hide_for_premium', true) && auth()->user()?->is_premium;
        $watchSidebarAdVisible = $watchSidebarAdsEnabled
            && config('site.ads_enabled')
            && filled(config('site.ad_code_sidebar'))
            && ! $adsHiddenForViewer;
        $watchSidebarEnabled = ($effectiveNextVideo && $watchNextVideoEnabled)
            || ($watchPlaylistPanelEnabled && $playlist && $playlistVideos->isNotEmpty())
            || $watchRelatedVideosEnabled
            || $watchSidebarAdVisible;
        $watchActionControlsEnabled = $watchReactionsEnabled || $watchShareEnabled || $watchSaveEnabled || $watchReportEnabled;
        $watchRelatedHeadingEnabled = $siteBool('watch_related_heading_enabled', false);
        $watchRelatedTitle = trim((string) config('site.watch_related_title', 'Related videos')) ?: 'Related videos';
        $watchRelatedShowChannel = $siteBool('watch_related_show_channel_enabled');
        $watchRelatedShowMeta = $siteBool('watch_related_show_meta_enabled');
        $themePreviewRequested = request()->has('theme') || request()->has('theme_preset');
        $currentThemePreset = (string) request()->query('theme', request()->query('theme_preset', config('site.theme_preset', 'cyberpunk_neon')));
        $themeCatalogPreset = $themePreviewRequested ? \App\Support\ThemeCatalog::get($currentThemePreset) : null;
        $presetWatchLayout = $themeCatalogPreset['watch_layout_mode'] ?? null;
        $watchLayoutMode = (string) request()->query('watch_layout', $presetWatchLayout ?: config('site.watch_layout_mode', 'classic_two_column'));
        $watchLayoutMode = in_array($watchLayoutMode, ['classic_two_column', 'theater_cinema', 'floating_focus', 'studio_dashboard'], true) ? $watchLayoutMode : 'classic_two_column';
    @endphp


    <div
        class="row avd-watch-layout"
        data-avd-watch-layout="{{ $watchLayoutMode }}"
        data-avd-watch-player-style="{{ $watchPlayerFrameStyle }}"
        data-avd-watch-ambient="{{ $watchPlayerAmbientGlowEnabled ? 'on' : 'off' }}"
        data-avd-watch-action-panel="{{ $watchActionPanelEnabled ? 'on' : 'off' }}"
        data-avd-watch-sidebar-density="{{ $watchSidebarDensity }}"
    >
        <div @class([
            'avd-watch-primary px-0 px-lg-3',
            'col-12 avd-watch-contained' => in_array($watchLayoutMode, ['theater_cinema', 'floating_focus'], true),
            'col-12 col-xxl-10 offset-xxl-1' => ! $watchSidebarEnabled && ! in_array($watchLayoutMode, ['theater_cinema', 'floating_focus'], true),
            'col-lg-8 col-xl-8 col-xxl-8 offset-xxl-1' => $watchSidebarEnabled && ! in_array($watchLayoutMode, ['theater_cinema', 'floating_focus'], true),
        ])>
            <div class="avd-player-wrapper position-relative" id="avdPlayerWrapper">
                <!-- Dynamic Ambilight Radiant Backlight Halo -->
                <div class="avd-ambilight-glow{{ $watchPlayerAmbientGlowEnabled ? ' active' : '' }}" id="avdAmbilightGlow" aria-hidden="true"></div>

                <div class="avd-player-shell" id="avdMainPlayerShell">
                    <!-- Shutter Flash Effect -->
                    <div class="avd-camera-flash" id="avdCameraFlash" style="position: absolute; inset: 0; background: #ffffff; opacity: 0; pointer-events: none; z-index: 1060; transition: opacity 0.2s ease;"></div>

                    <!-- Floating Speed HUD Overlay -->
                    <div class="avd-speed-hud" id="avdSpeedHud" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); opacity: 0; pointer-events: none; z-index: 1050; background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.25); color: #fff; padding: 12px 24px; border-radius: 999px; font-weight: 700; font-size: 1.25rem; transition: transform 0.2s cubic-bezier(0.16,1,0.3,1), opacity 0.2s ease; box-shadow: 0 10px 30px rgba(0,0,0,0.7);">
                        <i class="fa-solid fa-gauge-high text-info me-2"></i><span id="avdSpeedHudText">1x</span>
                    </div>

                    <!-- Floating Aspect Ratio HUD Overlay -->
                    <div class="avd-aspect-hud" id="avdAspectHud" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); opacity: 0; pointer-events: none; z-index: 1050; background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.25); color: #fff; padding: 12px 24px; border-radius: 999px; font-weight: 700; font-size: 1.15rem; transition: transform 0.2s cubic-bezier(0.16,1,0.3,1), opacity 0.2s ease; box-shadow: 0 10px 30px rgba(0,0,0,0.7);">
                        <i class="fa-solid fa-arrows-up-down-left-right text-warning me-2"></i><span id="avdAspectHudText">16:9</span>
                    </div>

                    <!-- Audio Spectrum Visualizer Canvas Overlay -->
                    <canvas id="avdVisualizerCanvas" style="position: absolute; bottom: 0; left: 0; width: 100%; height: 50px; pointer-events: none; z-index: 10; opacity: 0; transition: opacity 0.3s ease;"></canvas>

                    <!-- Floating Volume Level HUD Overlay -->
                    <div class="avd-volume-hud" id="avdVolumeHud" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); opacity: 0; pointer-events: none; z-index: 1050; background: rgba(0,0,0,0.85); backdrop-filter: blur(14px); border: 1px solid rgba(255,255,255,0.25); color: #fff; padding: 12px 24px; border-radius: 999px; font-weight: 700; font-size: 1.15rem; transition: transform 0.2s cubic-bezier(0.16,1,0.3,1), opacity 0.2s ease; box-shadow: 0 10px 30px rgba(0,0,0,0.7); display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-volume-high text-info" id="avdVolumeIcon"></i>
                        <span id="avdVolumeText">100%</span>
                        <div style="width: 70px; height: 6px; background: rgba(255,255,255,0.2); border-radius: 3px; overflow: hidden; margin-left: 4px;">
                            <div id="avdVolumeBar" style="width: 100%; height: 100%; background: linear-gradient(90deg, #38bdf8, #818cf8); transition: width 0.1s ease;"></div>
                        </div>
                    </div>

                    <!-- Floating Seek HUD Overlay -->
                    <div class="avd-seek-hud" id="avdSeekHud" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); opacity: 0; pointer-events: none; z-index: 1050; background: rgba(0,0,0,0.85); backdrop-filter: blur(14px); border: 1px solid rgba(255,255,255,0.25); color: #fff; padding: 12px 24px; border-radius: 999px; font-weight: 700; font-size: 1.15rem; transition: transform 0.2s cubic-bezier(0.16,1,0.3,1), opacity 0.2s ease; box-shadow: 0 10px 30px rgba(0,0,0,0.7); display: flex; align-items: center; gap: 8px;">
                        <span id="avdSeekIcon"><i class="fa-solid fa-forward text-warning"></i></span>
                        <span id="avdSeekText">+5s</span>
                    </div>

                    <!-- Smart Auto-Resume Floating Pill -->
                    <div id="avdResumeBanner" class="position-absolute d-none align-items-center justify-content-between px-3 py-2 shadow-lg"
                         style="top: 14px; left: 16px; right: 16px; max-width: 440px; margin: 0 auto; z-index: 1040; background: rgba(15, 23, 42, 0.94); backdrop-filter: blur(16px); border: 1px solid rgba(56, 189, 248, 0.35); border-radius: 12px; animation: avdFadeSlideDown 0.4s cubic-bezier(0.16,1,0.3,1); box-shadow: 0 12px 32px rgba(0,0,0,0.65);">
                        <div class="d-flex align-items-center gap-2.5 text-white small pe-2 text-truncate">
                            <i class="fa-solid fa-clock-rotate-left text-info fs-6"></i>
                            <span class="text-truncate">{{ __('Continue where you left off?') }} <strong id="avdResumeTimeLabel" class="text-warning">00:00</strong></span>
                        </div>
                        <div class="d-flex align-items-center gap-1.5 flex-shrink-0">
                            <button type="button" class="btn btn-xs btn-primary rounded-pill px-2.5 py-1 fw-bold" onclick="window.avdAcceptResume()" style="font-size: 0.76rem;">
                                <i class="fa-solid fa-play me-1" aria-hidden="true"></i> {{ __('Resume') }}
                            </button>
                            <button type="button" class="btn btn-xs btn-link text-white-50 p-1" onclick="window.avdDismissResume()" title="{{ __('Dismiss') }}" aria-label="{{ __('Dismiss') }}">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Mini Player Floating Top Dock Controls (visible in PiP mode) -->
                    <div class="avd-pip-header d-none align-items-center justify-content-between px-3 py-1.5 text-white"
                         style="background: rgba(10, 12, 18, 0.92); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(255, 255, 255, 0.12); font-size: 0.75rem;">
                        <span class="text-truncate fw-semibold pe-2" style="max-width: 250px;">
                            <i class="fa-solid fa-play me-1 text-danger"></i> {{ $video->title }}
                        </span>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-xs btn-link text-white p-0" onclick="window.scrollTo({top:0, behavior:'smooth'}); window.avdTogglePip(false);" title="{{ __('Expand to the full player') }}">
                                <i class="fa-solid fa-up-right-and-down-left-from-center" aria-hidden="true"></i>
                            </button>
                            <button type="button" class="btn btn-xs btn-link text-white-50 p-0" onclick="window.avdTogglePip(false)" title="{{ __('Close the mini player') }}">
                                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="avd-player-frame">
                        <div class="ratio ratio-16x9">
                            @if($video->is_failed)
                                <div class="bg-light-dark border border-light d-flex justify-content-center align-items-center">
                                    <div class="text-center p-4">
                                        <div class="mb-2 text-danger fw-bold">{{__('The processing of your video failed.')}}</div>
                                        <div class="text-muted">
                                            {!! __('Try to re-upload your file, if the problem persist please contact your support..') !!}
                                            <hr>
                                            <a target="_blank" href="{{ (function_exists('locale_url') ? locale_url('/contact') : url('/contact')) }}" class="btn btn-primary btn-sm">{!! __('Contact support') !!}</a>
                                        </div>
                                    </div>
                                </div>
                            @elseif($video->is_uploading)
                                <div class="bg-light-dark border border-light d-flex justify-content-center align-items-center">
                                    <div class="text-center p-4">
                                        <div class="mb-2">{{__('Your video is processing')}} ...</div>
                                        <div class="text-muted text-sm">{{__('You will receive a notification once the processing is complete.')}}</div>
                                    </div>
                                </div>
                            @else
                                <video-player video_uuid="{{$video->uuid}}" />
                            @endif
                        </div>
                        <!-- Sleep Timer Finished Overlay (Works for Upload18 Iframes & HTML5 Streams) -->
                        <div id="avdSleepFinishedOverlay" class="d-none position-absolute top-0 start-0 w-100 h-100 d-flex flex-column align-items-center justify-content-center text-center p-4" style="background: rgba(5, 8, 17, 0.96); z-index: 80; backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);">
                            <div class="mb-3" style="font-size: 3.2rem; filter: drop-shadow(0 0 16px rgba(245, 158, 11, 0.5));">🌙</div>
                            <h3 class="h4 fw-bold text-white mb-2">{{ __('Paused by the sleep timer') }}</h3>
                            <p class="text-white-50 small mb-4" style="max-width: 420px; line-height: 1.5;">
                                {{ __('The time you set is up, so the video was paused.') }}
                            </p>
                            <button type="button" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-lg d-inline-flex align-items-center gap-2" onclick="window.avdResumeFromSleep()">
                                <i class="fa-solid fa-play" aria-hidden="true"></i>
                                <span>{{ __('Resume') }}</span>
                            </button>
                        </div>
                    </div>
                    @php
                        $watchSpeedControlsEnabled = config('site.watch_speed_controls_enabled', true);
                    @endphp
                    @if($watchTheaterEnabled || $watchShortcutsEnabled || $watchSpeedControlsEnabled)
                    <!-- Sticky Floating Mini Watch Bar on Scroll -->
                    <div id="avdStickyWatchBar" class="d-none position-fixed start-0 end-0 top-0 px-3 py-2 shadow-lg"
                         style="z-index: 1045; background: rgba(15, 23, 42, 0.94); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border-bottom: 1px solid rgba(255, 255, 255, 0.12); animation: avdFadeSlideDown 0.3s cubic-bezier(0.16,1,0.3,1);">
                        <div class="container-fluid d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2.5 min-w-0">
                                <button type="button" class="btn btn-xs btn-primary rounded-pill px-2.5 py-1 text-nowrap fw-bold" onclick="window.scrollTo({top: 0, behavior: 'smooth'})" style="font-size: 0.78rem;">
                                    <i class="fa-solid fa-arrow-up me-1" aria-hidden="true"></i> {{ __('Back to the video') }}
                                </button>
                                <span class="text-white fw-bold text-truncate small d-none d-sm-inline" style="max-width: 450px;">
                                    {{ $video->title }}
                                </span>
                            </div>
                            <div class="d-flex align-items-center gap-1.5">
                                <button type="button" class="btn btn-xs btn-outline-light rounded-pill px-2 py-1 avd-lights-out-btn" onclick="window.avdToggleLightsOut()" title="{{ __('Lights out') }} (L)">
                                    <i class="fa-solid fa-moon" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-light rounded-pill px-2 py-1" onclick="window.avdToggleZenMode()" title="{{ __('Focus mode') }} (Shift+F)">
                                    <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-light rounded-pill px-2 py-1" onclick="window.avdReloadPlayer()" title="{{ __('Reload the video') }}">
                                    <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Unified Modern Glassmorphic Cinema Dock -->
                    <div class="avd-cinema-dock-container px-3 px-lg-0 py-2">
                        <div class="avd-cinema-dock" id="avdCinemaDock">
                            <!-- Left Cluster: Core Cinema Experience Tools (Works 100% on All Video Types: Upload18 Iframes & HTML5) -->
                            <div class="avd-dock-cluster avd-dock-primary">
                                <!-- Mobile Cinema Suite Quick Trigger (Direct one-tap access on iOS & Android) -->
                                <button type="button" class="avd-dock-btn avd-dock-btn-tools-highlight d-md-none" onclick="window.avdOpenMobileTools && window.avdOpenMobileTools();" title="{{ __('Video tools') }}">
                                    <i class="fa-solid fa-sliders text-primary" aria-hidden="true"></i>
                                    <span>{{ __('Tools') }}</span>
                                </button>
                                @if($watchTheaterEnabled)
                                <button type="button" class="avd-dock-btn" data-theater-toggle aria-pressed="false" onclick="window.AvdAudio && window.AvdAudio.playWhoosh()" title="{{ __('Theater mode') }} (T)">
                                    <i class="fa-solid fa-expand" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="avd-dock-btn avd-lights-out-btn" id="avdLightsOutBtn" onclick="window.avdToggleLightsOut(); window.AvdAudio && window.AvdAudio.playWhoosh();" title="{{ __('Lights out') }} (L)">
                                    <i class="fa-solid fa-moon" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="avd-dock-btn" id="avdFullscreenBtn" onclick="window.avdToggleFullscreen()" title="{{ __('Full screen') }} (F)">
                                    <i class="fa-solid fa-maximize" aria-hidden="true"></i>
                                </button>
                                @if($watchFloatingMiniPlayerEnabled)
                                    <button type="button" class="avd-dock-btn" id="avdPipToggle" onclick="window.avdTogglePip()" title="{{ __('Mini player') }} (P)">
                                        <i class="fa-solid fa-clone" aria-hidden="true"></i>
                                    </button>
                                @endif
                                @if($watchPlayerAmbientGlowEnabled)
                                <button type="button" class="avd-dock-btn active" id="avdAmbilightToggle" aria-pressed="true" onclick="window.avdToggleAmbilight()" title="{{ __('Glow around the player') }} (A)">
                                    <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                                </button>
                                @endif
                                <button type="button" class="avd-dock-btn" id="avdAspectBtn" onclick="window.avdCycleAspectRatio()" title="{{ __('Aspect ratio') }} (W)">
                                    <i class="fa-solid fa-arrows-up-down-left-right" aria-hidden="true"></i>
                                    <span id="avdAspectLabel">16:9</span>
                                </button>
                                <button type="button" class="avd-dock-btn" id="avdFilterBtn" onclick="window.avdCycleVideoFilter()" title="{{ __('Color filter') }} (C)">
                                    <i class="fa-solid fa-palette text-primary" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="avd-dock-btn" id="avdSleepTimerBtn" onclick="window.avdToggleSleepTimerModal()" title="{{ __('Sleep timer') }} (Z)">
                                    <i class="fa-solid fa-clock" aria-hidden="true"></i>
                                    <span id="avdSleepTimerLabel">{{ __('Sleep') }}</span>
                                </button>
                                @endif
                            </div>

                            <!-- Subtle Visual Divider between Primary & Secondary Clusters -->
                            <div class="avd-dock-divider d-none d-sm-block"></div>

                            <!-- Right Cluster: Actions & Advanced Cinema Tools -->
                            <div class="avd-dock-cluster avd-dock-secondary ms-auto">
                                <!-- Stream Reload (Upload18 recovery button) -->
                                <button type="button" class="avd-dock-btn" onclick="window.avdReloadPlayer()" title="{{ __('Reload the video') }}">
                                    <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
                                </button>

                                <!-- Mirror Flip View (100% works on Upload18 iframe) -->
                                <button type="button" class="avd-dock-btn" id="avdMirrorBtn" onclick="window.avdToggleMirror()" title="{{ __('Mirror the video') }} (M)">
                                    <i class="fa-solid fa-arrows-split-up-and-left" aria-hidden="true"></i>
                                </button>

                                <!-- Pure Zen Mode -->
                                <button type="button" class="avd-dock-btn" id="avdZenBtn" onclick="window.avdToggleZenMode()" title="{{ __('Focus mode') }} (Shift+F)">
                                    <i class="fa-regular fa-eye-slash" aria-hidden="true"></i>
                                </button>

                                <!-- Quick Share Video Link -->
                                <button type="button" class="avd-dock-btn" onclick="window.avdQuickShare()" title="{{ __('Copy video link') }}">
                                    <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
                                </button>

                                @if($watchShortcutsEnabled)
                                <button type="button" class="avd-dock-btn d-none d-md-inline-flex" onclick="var o=document.getElementById('shortcuts-overlay'); if(o){ o.classList.add('active'); window.AvdAudio && window.AvdAudio.playClick(); }" title="{{ __('Keyboard shortcuts') }} (?)">
                                    <i class="fa-regular fa-keyboard" aria-hidden="true"></i>
                                </button>
                                @endif

                                <!-- More Tools & Settings Dropdown (Pops upwards on desktop, opens Bottom Sheet on mobile) -->
                                <div class="dropdown dropup d-inline-block">
                                    <button type="button" class="avd-dock-btn dropdown-toggle" id="avdMoreToolsBtn" onclick="if(window.innerWidth < 768){ window.avdOpenMobileTools && window.avdOpenMobileTools(); return false; }" data-bs-toggle="dropdown" data-bs-placement="top" data-bs-display="static" aria-expanded="false" title="{{ __('Video tools') }}">
                                        <i class="fa-solid fa-sliders" aria-hidden="true"></i>
                                        <span>{{ __('Tools') }}</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-lg avd-cinema-dropdown-menu" aria-labelledby="avdMoreToolsBtn" style="width: min(310px, calc(100vw - 1rem)); min-width: 0; z-index: 1055;">
                                        <li class="dropdown-header text-uppercase fw-bold small text-muted px-3 py-1.5 d-flex align-items-center justify-content-between">
                                            <span><i class="fa-solid fa-sliders me-1 text-primary" aria-hidden="true"></i>{{ __('Video tools') }}</span>
                                        </li>

                                        <!-- Real-Time Visual Grading / Cinema Color Filters (Works 100% on Upload18 Iframe!) -->
                                        <li class="px-3 py-2">
                                            <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                <span class="small fw-semibold text-muted d-flex align-items-center gap-1">
                                                    <i class="fa-solid fa-palette text-primary" aria-hidden="true"></i>{{ __('Color filter') }}
                                                </span>
                                            </div>
                                            <div class="d-flex flex-wrap gap-1">
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip active btn-primary px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('normal', this)">{{ __('Normal') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('vivid', this)">{{ __('Vivid') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('cinema', this)">{{ __('Warm film') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('cyberpunk', this)">{{ __('Cyberpunk') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('oled', this)">{{ __('Deep black') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('noir', this)">{{ __('Black and white') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('night', this)" title="{{ __(':label: :value', ['label' => __('Color filter'), 'value' => __('Night')]) }} (Shift+N)">🌙 {{ __('Night') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('anime', this)">✨ {{ __('Anime') }}</button>
                                            </div>
                                        </li>

                                        <!-- Video Frame Rotation & Vertical Video Mode (100% works on Upload18 iframe!) -->
                                        <li class="px-3 py-1.5">
                                            <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                <span class="small fw-semibold text-muted d-flex align-items-center gap-1">
                                                    <i class="fa-solid fa-arrows-rotate text-info" aria-hidden="true"></i>{{ __('Rotate the video') }}
                                                </span>
                                            </div>
                                            <div class="d-flex align-items-center gap-1">
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-rotate-chip active btn-primary px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" onclick="window.avdRotateVideo(0, this)">0°</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-rotate-chip px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" onclick="window.avdRotateVideo(90, this)">90°</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-rotate-chip px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" onclick="window.avdRotateVideo(180, this)">180°</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-rotate-chip px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" onclick="window.avdRotateVideo(270, this)">270°</button>
                                            </div>
                                        </li>

                                        <!-- Quick Brightness Adjustment (Works 100% on Upload18 iframe!) -->
                                        <li class="px-3 py-1.5">
                                            <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                <span class="small fw-semibold text-muted d-flex align-items-center gap-1">
                                                    <i class="fa-solid fa-sun text-warning" aria-hidden="true"></i>{{ __('Brightness') }}
                                                </span>
                                            </div>
                                            <div class="d-flex align-items-center gap-1">
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-bright-chip active btn-primary px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetBrightness('normal', this)">{{ __('Normal') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-bright-chip px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" dir="ltr" onclick="window.avdSetBrightness('boost', this)">+15%</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-bright-chip px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" dir="ltr" onclick="window.avdSetBrightness('dim', this)">-15%</button>
                                            </div>
                                        </li>

                                        @if($watchPlayerAmbientGlowEnabled)
                                        <!-- Ambilight Halo Glow Modes (Works 100% on Upload18 iframe!) -->
                                        <li class="px-3 py-1.5">
                                            <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                <span class="small fw-semibold text-muted d-flex align-items-center gap-1">
                                                    <i class="fa-solid fa-wand-magic-sparkles text-primary" aria-hidden="true"></i>{{ __('Glow around the player') }}
                                                </span>
                                            </div>
                                            <div class="d-flex flex-wrap gap-1">
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-ambi-chip active btn-primary px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetAmbiMode('theme', this)">{{ __('Theme') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-ambi-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetAmbiMode('pulse', this)">{{ __('Pulse') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-ambi-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetAmbiMode('neon', this)">{{ __('Neon') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-ambi-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetAmbiMode('warm', this)">{{ __('Warm') }}</button>
                                            </div>
                                        </li>
                                        @endif

                                        <li class="dropdown-divider my-1"></li>

                                        <!-- Mirror Flip & Zen Focus Options (100% works on Upload18 iframe) -->
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleMirror()">
                                                <i class="fa-solid fa-arrows-split-up-and-left text-info" style="width: 18px;" aria-hidden="true"></i>
                                                <span class="flex-grow-1">{{ __('Mirror the video') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">M</span>
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleZenMode()">
                                                <i class="fa-regular fa-eye-slash text-warning" style="width: 18px;" aria-hidden="true"></i>
                                                <span class="flex-grow-1">{{ __('Focus mode') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">Shift+F</span>
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleFullscreen()">
                                                <i class="fa-solid fa-maximize text-success" style="width: 18px;" aria-hidden="true"></i>
                                                <span class="flex-grow-1">{{ __('Full screen') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">F</span>
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdOpenEmbedModal()">
                                                <i class="fa-solid fa-code text-primary" style="width: 18px;" aria-hidden="true"></i>
                                                <span class="flex-grow-1">{{ __('Embed code') }}</span>
                                            </button>
                                        </li>

                                        <li class="dropdown-divider my-1"></li>

                                        <!-- Timestamp Quick-Notes Scratchpad (Always works) -->
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleNotes()">
                                                <i class="fa-regular fa-note-sticky text-warning" style="width: 18px;" aria-hidden="true"></i>
                                                <span class="flex-grow-1">{{ __('Notes') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">N</span>
                                            </button>
                                        </li>

                                        <!-- Video details -->
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleTelemetryModal()">
                                                <i class="fa-solid fa-circle-info text-primary" style="width: 18px;" aria-hidden="true"></i>
                                                <span class="flex-grow-1">{{ __('Video details') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">I</span>
                                            </button>
                                        </li>

                                        <!-- HTML5-Only Section (Auto-revealed ONLY when native HTML5 video element is active) -->
                                        <div id="avdHtml5OnlyTools" class="d-none">
                                            <li class="dropdown-divider my-1"></li>
                                            <li class="px-3 py-1 text-uppercase fw-bold text-muted" style="font-size: 0.65rem;">
                                                {{ __('More options') }}
                                            </li>

                                            <!-- Snapshot Frame Capture -->
                                            <li id="avdMenuSnapshotItem">
                                                <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdCaptureFrame()">
                                                    <i class="fa-solid fa-camera text-info" style="width: 18px;" aria-hidden="true"></i>
                                                    <span class="flex-grow-1">{{ __('Take a snapshot') }}</span>
                                                    <span class="badge bg-secondary-subtle text-secondary small">S</span>
                                                </button>
                                            </li>

                                            <!-- Video Loop Toggle -->
                                            <li id="avdMenuLoopItem">
                                                <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleLoop()">
                                                    <i class="fa-solid fa-repeat text-success" style="width: 18px;" aria-hidden="true"></i>
                                                    <span class="flex-grow-1">{{ __('Loop the video') }}</span>
                                                    <span class="badge bg-secondary-subtle text-secondary small">R</span>
                                                </button>
                                            </li>

                                            <!-- Audio Boost Equalizer -->
                                            <li id="avdMenuAudioItem">
                                                <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdCycleAudioBoost()">
                                                    <i class="fa-solid fa-headphones text-primary" style="width: 18px;" aria-hidden="true"></i>
                                                    <span class="flex-grow-1" id="avdMenuAudioLabel">{{ __('Sound mode') }}</span>
                                                    <span class="badge bg-secondary-subtle text-secondary small">B</span>
                                                </button>
                                            </li>

                                            <!-- Audio Spectrum Visualizer -->
                                            <li id="avdMenuVisualizerItem">
                                                <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleVisualizer()">
                                                    <i class="fa-solid fa-chart-simple text-danger" style="width: 18px;" aria-hidden="true"></i>
                                                    <span class="flex-grow-1">{{ __('Sound bars') }}</span>
                                                    <span class="badge bg-secondary-subtle text-secondary small">V</span>
                                                </button>
                                            </li>
                                        </div>

                                        <!-- Iframe Player Info Callout -->
                                        <li id="avdIframeHelperNotice" class="px-3 py-2">
                                            <div class="p-2 rounded-3 text-muted small" style="background: rgba(255, 255, 255, 0.04); font-size: 0.72rem; line-height: 1.4;">
                                                <i class="fa-solid fa-circle-check text-success me-1"></i>
                                                {{ __('This video plays in the Upload18 player: change its speed, volume and quality inside the player.') }}
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endif

                <!-- Smart Timestamp Quick-Bookmark Shelf -->
                <div id="avdBookmarkShelf" class="px-3 px-lg-0 py-2 d-none align-items-center gap-2 flex-wrap" style="background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; margin-top: 6px;">
                    <span class="text-white-50 small d-flex align-items-center gap-1.5 ps-1" style="font-size: 0.78rem;">
                        <i class="fa-solid fa-bookmark text-warning"></i>
                        <strong>{{ __('Bookmarks:') }}</strong>
                    </span>
                    <div id="avdBookmarkList" class="d-flex align-items-center gap-1.5 flex-wrap"></div>
                </div>
                @if(config('site.watch_theater_mode_default', false))
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        var btn = document.querySelector('[data-theater-toggle]');
                        if (btn && !document.body.classList.contains('theater-mode') && !document.body.classList.contains('avd-theater-mode')) {
                            btn.click();
                        }
                    });
                </script>
                @endif
            </div>
            </div>

            <div class="px-3 px-lg-0">
                <div class="mt-3 d-flex flex-column gap-3">
                    @if($status)
                        <div>
                            <span class="avd-status-chip {{$status['class']}}">
                                <i class="fa-solid fa-{{$status['icon']}}"></i>
                                <strong>{{$status['label']}}</strong>
                            </span>
                        </div>
                    @endif
                    <h1 class="avd-watch-title {{ \App\Support\TitleScript::cssClass($video->title) }}" data-avd-watch-title>
                        {{$video->title}}
                        @if($video->xvid_quality)
                            <span class="badge bg-danger text-uppercase fw-bold ms-2 align-middle px-2 py-1 shadow-sm" style="font-size:13px; letter-spacing:0.5px; vertical-align: middle;">{{$video->xvid_quality}}</span>
                        @endif
                    </h1>

                    @if($watchLayoutMode === 'studio_dashboard')
                    {{-- Studio facts: the stored source details in the theme's surface (no fixed dark box on light themes). --}}
                    <div class="avd-studio-telemetry avd-studio-facts my-3 p-3">
                        <div class="avd-studio-facts-head d-flex align-items-center justify-content-between pb-2 mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                                <strong class="small">{{ __('Video details') }}</strong>
                            </div>
                            <span class="avd-studio-facts-tag">{{ __('Source') }}</span>
                        </div>
                        @if(count($videoSourceDetails) > 0)
                            <dl class="row g-2 mb-0">
                                @foreach($videoSourceDetails as $detail)
                                    <div class="col-12 col-sm-6 col-md-4">
                                        <dt>{{ $detail['label'] }}</dt>
                                        <dd class="text-break mb-0">{{ $detail['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @else
                            <p class="text-muted small mb-0">{{ __('Unavailable') }}</p>
                        @endif
                    </div>
                    @endif
                </div>

                @if(in_array($watchLayoutMode, ['theater_cinema', 'floating_focus'], true))
                    @php
                        // Compact Up next strip: the next video, then the related list. Theater and floating focus put
                        // the side column below the page, so this keeps Up next on the first screen.
                        $watchUpNextStrip = collect();
                        if ($effectiveNextVideo && $effectiveNextVideo->video) {
                            $watchUpNextStrip->push(['route' => $effectiveNextVideo->route, 'title' => $effectiveNextVideo->title, 'video' => $effectiveNextVideo->video]);
                        }
                        if ($watchRelatedVideosEnabled) {
                            foreach (collect($videos ?? []) as $stripVideo) {
                                if ($watchUpNextStrip->count() >= 6) {
                                    break;
                                }
                                if (! $watchUpNextStrip->contains(fn ($item) => $item['video']->id === $stripVideo->id)) {
                                    $watchUpNextStrip->push(['route' => $stripVideo->route, 'title' => $stripVideo->title, 'video' => $stripVideo]);
                                }
                            }
                        }
                    @endphp
                    @if($watchUpNextStrip->count() >= 2)
                        <section class="avd-upnext-strip mt-3" aria-labelledby="avdUpNextStripTitle">
                            <h2 class="avd-upnext-strip-title" id="avdUpNextStripTitle"><i class="fa-solid fa-list" aria-hidden="true"></i> {{ __('Up next') }}</h2>
                            <ol class="avd-upnext-strip-list">
                                @foreach($watchUpNextStrip as $stripItem)
                                    <li>
                                        <a class="avd-upnext-strip-item" href="{{ $stripItem['route'] }}" data-avd-upnext-item>
                                            <span class="avd-upnext-strip-thumb">
                                                <img src="{{ $stripItem['video']->thumbnail_url }}" alt="" width="160" height="90" loading="lazy" decoding="async">
                                                @if($stripItem['video']->duration)
                                                    <span class="avd-duration">{{ $stripItem['video']->duration }}</span>
                                                @endif
                                            </span>
                                            <span class="avd-upnext-strip-text {{ \App\Support\TitleScript::cssClass($stripItem['title']) }}">{{ $stripItem['title'] }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endif
                @endif

                <div @class([
                    'mt-3 d-flex flex-column flex-xxl-row gap-3 justify-content-between align-items-start align-items-xxl-center',
                    'avd-watch-action-panel' => $watchActionPanelEnabled,
                ])>
                    <div class="avd-meta-chip">
                        <i class="fa-regular fa-eye"></i>
                        <span>{{trans_choice('views', $video->views, ['count' => \App\Support\LocalNumber::format($video->views)])}}</span>
                        <span aria-hidden="true">&middot;</span>
                        {{-- the admin's "Date format" in the reader's order and the site's time zone, as on the Library, History,
                             playlist and channel pages (R3-062; it was "18 九月 2026" in Chinese, "18 September 2026" in English) --}}
                        <span>{{ \App\Support\SiteDate::date($video->published_at ?? $video->created_at) }}</span>
                    </div>

                    @if($watchActionControlsEnabled)
                    @auth
                        <div class="avd-action-row col-12 col-sm-auto" data-avd-strip-yield>
                            @if($watchReactionsEnabled)
                            <interaction-buttons
                                model="{{get_class($video)}}"
                                target="{{$video->id}}"
                                likes="{{$video->likes_count}}"
                                dislikes="{{$video->dislikes_count}}"
                                liked="{{$video->liked_by_auth_user}}"
                                disliked="{{$video->disliked_by_auth_user}}"
                                show-count="{{ $video->show_likes ? 'true' : 'false' }}"
                            ></interaction-buttons>
                            @endif
                            @if($watchShareEnabled && $video->is_public)
                                <button class="btn bg-light-dark btn-sm px-3 flex-shrink-0" title="{{__('Share video')}}" data-bs-toggle="modal" data-bs-target="#share" data-url="{{$video->route}}">
                                    <i class="fa-solid fa-share"></i>
                                    <span>{{__('Share')}}</span>
                                </button>
                            @endif
                            @if($watchSaveEnabled)
                            <save-video class="flex-shrink-0" video="{{$video->id}}"></save-video>
                            @endif
                            @if($watchReportEnabled)
                            @can('report', $video)
                                <report-button
                                    button-class="btn btn-sm bg-light-dark px-3 flex-shrink-0"
                                    reported-class="avd-status-chip text-muted"
                                    id="{{$video->id}}"
                                    type="video"
                                ></report-button>
                            @else
                                @if($video->reportByAuthUser)
                                    <div class="avd-status-chip text-muted flex-shrink-0">
                                        <i class="fa-regular fa-flag"></i>
                                        <span>{{__('Reported')}} {{$video->reportByAuthUser->created_at->diffForHumans()}}</span>
                                    </div>
                                @endif
                            @endcan
                            @endif
                        </div>
                    @else
                        <div class="avd-action-row col-12 col-sm-auto" data-avd-strip-yield>
                            @if($watchReactionsEnabled)
                            @php
                                // R1-060: a name and the reader's count (the member's buttons, Interaction.tsx, read the same)
                                $guestLikes = $video->show_likes && $video->likes_count ? \App\Support\LocalNumber::short($video->likes_count) : null;
                                $guestDislikes = $video->show_likes && $video->dislikes_count ? \App\Support\LocalNumber::short($video->dislikes_count) : null;
                            @endphp
                            <div class="d-flex justify-content-between rounded-pill bg-body-element border">
                                <button
                                    type="button"
                                    tabindex="0"
                                    class="btn border-0 text-body px-3 rounded-start-pill"
                                    aria-label="{{ __('I like this') }}{{ $guestLikes !== null ? ' ('.$guestLikes.')' : '' }}"
                                    data-bs-toggle="popover"
                                    data-bs-placement="left"
                                    data-bs-title="{{ __('Like this video?') }}"
                                    data-bs-trigger="focus"
                                    data-bs-html="true"
                                    data-bs-content="{{ __('Sign in to make your opinion count.') }}<hr><a href='{{ $watchSignInUrl }}' class='btn btn-primary btn-sm'>{{ __('Sign in') }}</a>"
                                >
                                    <span class="d-flex gap-1 align-items-center">
                                        <i class="fa-regular fa-thumbs-up" aria-hidden="true"></i>
                                        @if($guestLikes !== null)
                                            <span>{{ $guestLikes }}</span>
                                        @endif
                                    </span>
                                </button>
                                <div class="vr h-75 my-auto"></div>
                                <button
                                    type="button"
                                    tabindex="0"
                                    class="btn border-0 text-body px-3 rounded-end-pill"
                                    aria-label="{{ __('I dislike this') }}{{ $guestDislikes !== null ? ' ('.$guestDislikes.')' : '' }}"
                                    data-bs-toggle="popover"
                                    data-bs-placement="right"
                                    data-bs-title="{{ __("Don't like this video?") }}"
                                    data-bs-trigger="focus"
                                    data-bs-html="true"
                                    data-bs-content="{{ __('Sign in to make your opinion count.') }}<hr><a href='{{ $watchSignInUrl }}' class='btn btn-primary btn-sm'>{{ __('Sign in') }}</a>"
                                >
                                    <span class="d-flex gap-1 align-items-center">
                                        <i class="fa-regular fa-thumbs-down" aria-hidden="true"></i>
                                        @if($guestDislikes !== null)
                                            <span>{{ $guestDislikes }}</span>
                                        @endif
                                    </span>
                                </button>
                            </div>
                            @endif
                            @if($watchShareEnabled)
                            <button class="btn border text-body btn-sm px-3 flex-shrink-0" title="{{__('Share video')}}" data-bs-toggle="modal" data-bs-target="#share" data-url="{{$video->route}}">
                                <i class="fa-solid fa-share"></i>
                                <span>{{__('Share')}}</span>
                            </button>
                            @endif
                            @if($watchSaveEnabled)
                            <button
                                type="button"
                                tabindex="0"
                                class="btn border text-body btn-sm px-3 flex-shrink-0"
                                data-bs-toggle="popover"
                                data-bs-placement="top"
                                data-bs-title="{{ __('Save to playlist?') }}"
                                data-bs-trigger="focus"
                                data-bs-html="true"
                                {{-- R1-147: the site's words ("Create account", "Watch later"), and no sign-up link while sign-ups are closed --}}
                                data-bs-content="{{ __('Sign in to save this video to Watch later or a playlist.') }}<hr><div class='d-flex flex-wrap gap-2'><a href='{{ $watchSignInUrl }}' class='btn btn-primary btn-sm'>{{ __('Sign in') }}</a>@if(config('site.registration_enabled', true))<a href='{{ $watchSignUpUrl }}' class='btn btn-outline-secondary btn-sm'>{{ __('Create account') }}</a>@endif</div>"
                            >
                                <i class="fa-regular fa-bookmark"></i>
                                <span>{{__('Save')}}</span>
                            </button>
                            @endif
                            @if($watchReportEnabled)
                            <button
                                type="button"
                                tabindex="0"
                                class="btn border text-body btn-sm px-3 flex-shrink-0"
                                data-bs-toggle="popover"
                                data-bs-placement="right"
                                data-bs-title="{{ __('Need to report this video?') }}"
                                data-bs-trigger="focus"
                                data-bs-html="true"
                                data-bs-content="{{ __('Sign in to report inappropriate content.') }}<hr><a href='{{ $watchSignInUrl }}' class='btn btn-primary btn-sm'>{{ __('Sign in') }}</a>"
                            >
                                <i class="fa-regular fa-flag"></i>
                                <span>{{__('Report')}}</span>
                            </button>
                            @endif
                        </div>
                    @endauth
                    @endif
                </div>

                @if($watchChannelPanelEnabled)
                @php
                    // One query for the Subscribe button and the count beside it (watch-17).
                    $watchViewer = auth()->user();
                    $watchCanSubscribe = $watchViewer && $video->user && $watchViewer->isNot($video->user);
                    $watchSubscribed = $watchCanSubscribe && $watchViewer->isSubscribeTo($video->user);
                    $watchIsOwner = $watchViewer && $video->user && $watchViewer->is($video->user);
                @endphp
                {{-- data-avd-strip-yield: the phone ad strip steps aside while this row is under it (watch-31: it covered
                     Subscribe on the first screen; resources/js/layout/core.ts initAdStrip). --}}
                <div class="avd-channel-panel avd-video-author-panel mt-4 d-flex gap-3 justify-content-between align-items-center" data-avd-strip-yield>
                    <div class="avd-video-author-summary d-flex gap-3 flex-grow-1 min-w-0">
                        @if($video->user)
                            <a class="avd-video-author-avatar-link flex-shrink-0" href="{{$video->user->route}}" aria-label="{{$video->user->username}}">
                                <img class="rounded-circle avd-video-author-avatar avd-avatar-media" src="{{$video->user->avatar_url}}" alt="{{$video->user->username}} avatar" width="52" height="52" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='{{ asset('images/default_men.png') }}';">
                            </a>
                            <div class="d-flex flex-column justify-content-center min-w-0">
                                <a href="{{$video->user->route}}" class="text-decoration-none fw-black text-truncate">{{$video->user->username}}</a>
                                @if($video->user->show_subscribers)
                                    @php
                                        $vSubCount = (int) ($video->user->subscribers_count ?? 0);
                                        $vSubLabel = fn (int $count): string => trans_choice('subscribers', $count, ['count' => trim(\App\Helpers\Number::humanize($count))]);
                                    @endphp
                                    {{-- watch-17: the count follows the Subscribe button (resources/js/layout/core.ts initSubscriberCounts):
                                         the two counts this member can see are printed, the button's state picks one. --}}
                                    <small class="text-muted d-block avd-subscriber-count" @if($watchCanSubscribe) data-avd-subscriber-count="{{ $video->user->id }}" data-avd-count-on="{{ $vSubLabel($watchSubscribed ? $vSubCount : $vSubCount + 1) }}" data-avd-count-off="{{ $vSubLabel($watchSubscribed ? max(0, $vSubCount - 1) : $vSubCount) }}" @endif>{{ $vSubLabel($vSubCount) }}</small>
                                @endif
                            </div>
                        @else
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center" style="width: 52px;height: 52px;">
                                    <i class="fa-solid fa-user-slash text-muted"></i>
                                </div>
                                <span class="text-muted">{{__('Deleted user')}}</span>
                            </div>
                        @endif
                    </div>
                    <div class="avd-video-author-action flex-shrink-0">
                        @if(Auth::guest())
                            <button
                                tabindex="0"
                                type="button"
                                class="avd-subscribe-button btn btn-primary px-3 fw-bold shadow-sm d-inline-flex align-items-center justify-content-center text-nowrap"
                                data-bs-toggle="popover"
                                data-bs-placement="right"
                                data-bs-title="{{ __('Want to subscribe to this channel?') }}"
                                data-bs-trigger="focus"
                                data-bs-html="true"
                                data-bs-content="{{ __('Sign in to subscribe to this channel.') }}<hr><a href='{{ $watchSignInUrl }}' class='btn btn-primary btn-sm'>{{ __('Sign in') }}</a>"
                            >
                                <span class="text-sm"><i class="fa-solid fa-user-plus me-1"></i>{{ __('Subscribe') }}</span>
                            </button>
                        @elseif($watchCanSubscribe)
                            {{-- Subscribe.tsx: the state by name and the channel's name for its
                                 "Unsubscribe from <name>?" question (member design 6.10, QA watch-35) --}}
                            <subscribe-button
                                subscribed="{{ $watchSubscribed ? '1' : '0' }}"
                                user="{{$video->user->id}}"
                                name="{{ $video->user->username }}"
                                class="avd-subscribe-host"
                            ></subscribe-button>
                        @elseif($watchIsOwner)
                            {{-- watch-40: the creator's own video leads to its studio pages. --}}
                            <div class="avd-owner-shortcuts d-flex flex-wrap gap-2 justify-content-end">
                                <a href="{{ route('user.videos.edit', $video) }}" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-2 text-nowrap">
                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                    <span>{{ __('Edit video') }}</span>
                                </a>
                                <a href="{{ route('user.videos.show', $video) }}" class="btn btn-sm bg-light-dark d-inline-flex align-items-center gap-2 text-nowrap">
                                    <i class="fa-solid fa-chart-simple" aria-hidden="true"></i>
                                    <span>{{ __('Statistics') }}</span>
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
                @endif

                @if($watchDescriptionEnabled)
                @if($video->description && $video->description_is_long)
                    {{-- R1-061: a click on the shortened text opens it, but never a click on its links or time codes, and an
                         open text closes only with its button (any click used to toggle it, also on a link). "Show more"
                         is a real button (it was a div no keyboard could reach). --}}
                    <div class="avd-description-panel my-4" data-clickable="{{ $watchDescriptionDefaultOpen ? 'false' : 'true' }}" :data-clickable="open ? 'false' : 'true'" x-data="{ open: {{ $watchDescriptionDefaultOpen ? 'true' : 'false' }} }" @click="if (!open && !$event.target.closest('a, button')) open = true">
                        <div id="avdDescriptionText">
                            <template x-if="open">
                                <p class="mb-0">{!! nl2br(\App\Parsers\TimeCodeParser::playable($video->parsed_description, $watchDurationSeconds, $watchSeekable)) !!}</p>
                            </template>
                            <template x-if="!open">
                                <p class="mb-0">{!! nl2br(\App\Parsers\TimeCodeParser::playable($video->short_description, $watchDurationSeconds, $watchSeekable)) !!}</p>
                            </template>
                        </div>
                        <button type="button" class="avd-description-toggle text-primary fw-bold mt-2" aria-controls="avdDescriptionText" aria-expanded="{{ $watchDescriptionDefaultOpen ? 'true' : 'false' }}" :aria-expanded="open ? 'true' : 'false'" @click.stop="open = !open" x-text="open ? @js(__('Show less')) : @js(__('Show more'))">{{ $watchDescriptionDefaultOpen ? __('Show less') : __('Show more') }}</button>
                    </div>
                @elseif($video->description)
                    <div class="avd-description-panel my-4">
                        {!! nl2br(\App\Parsers\TimeCodeParser::playable($video->parsed_description, $watchDurationSeconds, $watchSeekable)) !!}
                    </div>
                @else
                    <div class="avd-description-panel my-4">
                        <div class="text-muted mb-0">{{__('No description provided')}}</div>
                    </div>
                @endif
                @endif

                @if($watchMetadataChipsEnabled)
                @if($videoDetailRows->isNotEmpty() || $videoTagNames->isNotEmpty())
                    <div class="avd-description-panel avd-detail-panel my-4">
                        @if($videoDetailRows->isNotEmpty())
                            <div class="avd-detail-grid">
                                @foreach($videoDetailRows as $row)
                                    <div class="avd-detail-item">
                                        <span class="avd-detail-label">
                                            <i class="fa-solid fa-{{ $row['icon'] }}"></i>
                                            {{ $row['label'] }}
                                        </span>
                                        @if(filled($row['search'] ?? null))
                                            <a href="{{ locale_url(route('search.index')) }}?q={{ rawurlencode($row['search']) }}" class="text-decoration-none">
                                                <strong>{{ $row['value'] }}</strong>
                                            </a>
                                        @else
                                            <strong>{{ $row['value'] }}</strong>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if($videoTagNames->isNotEmpty())
                            <div class="avd-detail-tags @if($videoDetailRows->isNotEmpty()) mt-3 @endif">
                                <div class="avd-detail-label mb-2">
                                    <i class="fa-solid fa-tags"></i>
                                    {{__('Tags')}}
                                </div>
                                <div class="avd-chip-list">
                                    @foreach($videoTags->take(24) as $tag)
                                        @php($tagName = $tag->localized_name ?: $tag->name)
                                        @continue(!$tagName)
                                        <a href="{{ locale_url(route('search.index')) }}?q={{ rawurlencode($tagName) }}" class="avd-meta-chip text-decoration-none">
                                            <i class="fa-solid fa-hashtag"></i>
                                            <strong>{{ $tagName }}</strong>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                @if($videoCategoryShown || $video->language)
                    <div class="avd-chip-list">
                        @if($videoCategoryShown)
                            <a href="{{locale_url($video->category->route)}}" class="avd-meta-chip text-decoration-none">
                                <i class="fa-solid fa-{{$video->category->icon_name}}"></i>
                                <strong>{{ $videoCategoryTitle }}</strong>
                            </a>
                        @endif
                        @if($video->language)
                            <div class="avd-meta-chip">
                                <i class="fa-solid fa-language"></i>
                                <strong>{{$video->language_name}}</strong>
                            </div>
                        @endif
                    </div>
                @endif
                @endif

                <hr class="d-none d-lg-block mt-4">

                @if($watchCommentsPreviewEnabled && config('site.comments_enabled', true) && $video->allow_comments)
                    <div class="d-none d-lg-block" id="comments_area">
                        @unless($jsLazyCommentsEnabled)
                            <comments-area video='{{$video->uuid}}' default-sort='{{$video->default_comments_sort}}' count='{{ (int) $video->comments_count }}' />
                        @endunless
                    </div>
                    <div class="d-block d-lg-none avd-mobile-comments my-4">
                        <button type="button" class="d-flex align-items-center justify-content-between p-3 w-100 bg-transparent border-0 text-reset" data-bs-toggle="offcanvas" data-bs-target="#comments-offcanvas" aria-controls="comments-offcanvas">
                            <span>{{__('Comments')}} <span aria-hidden="true">&middot;</span> {{$video->comments_count}}</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </button>
                        <div class="offcanvas offcanvas-bottom avd-comments-offcanvas" tabindex="-1" id="comments-offcanvas">
                            <div class="offcanvas-header border d-flex justify-content-between align-items-center">
                                <h5 class="offcanvas-title">{{__('Comments')}}</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="{{__('Close')}}"></button>
                            </div>
                            <div id="offcanvas-body" class="offcanvas-body">
                                @unless($jsLazyCommentsEnabled)
                                    <comments-area video='{{$video->uuid}}' default-sort='{{$video->default_comments_sort}}' count='{{ (int) $video->comments_count }}' />
                                @endunless
                            </div>
                        </div>
                    </div>
                @elseif($watchCommentsPreviewEnabled && !config('site.comments_enabled', true))
                    <div class="alert alert-secondary text-center my-4 my-lg-0">
                        <strong>{{__('Comments are currently disabled by the administrator.')}}</strong>
                    </div>
                @elseif($watchCommentsPreviewEnabled)
                    <div class="alert alert-primary text-center my-4 my-lg-0">
                        <strong>{{__('Comments are turned off')}}</strong>
                    </div>
                @endif
            </div>
        </div>

        @if($watchSidebarEnabled)
        <div @class([
            'avd-watch-sidebar-col px-0 px-sm-3 px-lg-2',
            'col-12 avd-watch-contained' => in_array($watchLayoutMode, ['theater_cinema', 'floating_focus'], true),
            'col-lg-4 col-xl-4 col-xxl-3' => ! in_array($watchLayoutMode, ['theater_cinema', 'floating_focus'], true),
        ])>
            <aside class="avd-watch-sidebar">
                @if($effectiveNextVideo && $watchNextVideoEnabled)
                    @if($watchUpNextCardEnabled)
                        @php($upNextVideo = $effectiveNextVideo->video)
                        <section class="avd-up-next-card mx-2 mx-sm-0 mb-4" aria-label="{{__('Next video')}}">
                            <a href="{{$effectiveNextVideo->route}}" class="avd-up-next-link text-decoration-none" data-avd-upnext-item>
                                <div class="avd-up-next-kicker d-flex align-items-center justify-content-between mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge bg-primary bg-opacity-20 text-primary border border-primary border-opacity-30 rounded-pill px-2.5 py-1 text-uppercase fw-bold" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                                            <i class="fa-solid fa-forward-step me-1"></i> {{__('Up next')}}
                                        </span>
                                        <span class="avd-up-next-source badge rounded-pill bg-secondary bg-opacity-25 text-muted px-2 py-0.5" style="font-size: 0.68rem;">
                                            {{ $effectiveNextVideo->type === 'playlist' ? __('Playlist') : __('Recommended') }}
                                        </span>
                                    </div>
                                    @if($watchAutoplayCountdownSeconds > 0)
                                        <div class="avd-up-next-badge-autoplay d-none d-sm-flex align-items-center gap-1.5 small text-muted">
                                            <i class="fa-solid fa-circle-play text-primary" style="font-size: 0.75rem;"></i>
                                            <span style="font-size: 0.74rem;">{{ __('Autoplay next') }}</span>
                                        </div>
                                    @endif
                                </div>
                                <div class="avd-up-next-content">
                                    <div class="avd-up-next-thumb position-relative overflow-hidden">
                                        @include('videos.partials.thumb', ['src' => $upNextVideo->thumbnail_url, 'alt' => $effectiveNextVideo->title])
                                        <span class="avd-duration">{{$upNextVideo->duration}}</span>
                                        <div class="avd-thumb-play-center">
                                            <i class="fa-solid fa-play"></i>
                                        </div>
                                    </div>
                                    <div class="avd-up-next-body">
                                        <h4 class="avd-up-next-title fw-bold mb-1" title="{{$effectiveNextVideo->title}}">{{$effectiveNextVideo->title}}</h4>
                                        <div class="avd-channel-name text-muted d-flex align-items-center gap-1 mb-1">
                                            <span>{{$upNextVideo->user?->username ?? __('Deleted user')}}</span>
                                            @if(\App\Support\VerifiedCreator::is($upNextVideo->user))
                                                <i class="fa-solid fa-circle-check text-primary" style="font-size: 9px;" title="{{ __('Verified creator') }}" aria-label="{{ __('Verified creator') }}" role="img"></i>
                                            @endif
                                        </div>
                                        <div class="avd-video-meta text-muted small">
                                            {{trans_choice('views', $upNextVideo->views, ['count' => \App\Helpers\Number::humanize($upNextVideo->views)])}} <span aria-hidden="true">&middot;</span> {{ ($upNextVideo->published_at ?? $upNextVideo->created_at ?? $upNextVideo->date)?->diffForHumans() ?? __('Recently') }}
                                        </div>
                                    </div>
                                    <div class="avd-up-next-cta ms-auto d-none d-md-flex align-items-center pe-2">
                                        <span class="btn btn-primary rounded-pill px-3.5 py-2 fw-bold d-inline-flex align-items-center gap-2 shadow-sm text-nowrap" style="font-size: 0.84rem;">
                                            <i class="fa-solid fa-play"></i>
                                            <span>{{ __('Watch next') }}</span>
                                        </span>
                                    </div>
                                </div>
                            </a>
                        </section>
                    @else
                        <div class="d-flex align-items-center justify-content-between gap-2 px-2 px-sm-0 flex-wrap">
                            <a href="{{$effectiveNextVideo->route}}" class="btn btn-sm btn-primary d-flex align-items-center justify-content-center gap-2">
                                <i class="fa-solid fa-forward-step"></i>
                                <span>{{__('Next video')}}</span>
                            </a>
                        </div>
                        <hr>
                    @endif
                @endif

                @if($watchPlaylistPanelEnabled && $playlist && $playlistVideos->isNotEmpty())
                    <div x-data="{open: window.innerWidth > 992}" class="mx-2 mx-sm-0 mb-3">
                        <div class="avd-playlist-panel pb-0" x-show="open" x-cloak>
                            <div class="d-flex justify-content-between p-3 w-100">
                                <div class="d-flex flex-column gap-1 min-w-0">
                                    <a href="{{$playlist->route}}" class="fw-black text-decoration-none text-body text-truncate">{{$playlist->title}}</a>
                                    <div class="d-flex gap-1 align-items-center">
                                        @if($playlist->user)
                                            <a href="{{$playlist->user->route}}" class="text-sm text-decoration-none text-muted">{{$playlist->user->username}}</a>
                                        @else
                                            <span class="text-muted text-sm">{{__('Deleted user')}}</span>
                                        @endif
                                        <span aria-hidden="true">&middot;</span>
                                        <span class="text-muted text-sm">{{$currentIndex + 1}}/{{$playlist->videos_count}}</span>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" aria-label="{{__('Close')}}" @click="open=false"></button>
                            </div>
                            <div class="border-top overflow-y-auto">
                                @foreach($playlistVideos as $index => $playlistVideo)
                                    @if(($playlistVideo->user && $playlistVideo->user->is(Auth::user())) || $playlistVideo->is_public)
                                        <article @class(["px-2 py-2 playlist_video avd-playlist-row", 'selected' => $playlistVideo->is($video)])>
                                            <a href="{{$playlistVideo->routeWithParams(['list' => $playlist->uuid])}}" class="d-flex gap-2 text-decoration-none text-body">
                                                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                                    @if($playlistVideo->is($video))
                                                        <i class="fa-solid fa-play text-muted text-sm"></i>
                                                    @else
                                                        <small class="text-muted">{{$index + 1}}</small>
                                                    @endif
                                                    <div class="avd-playlist-thumb position-relative">
                                                        @include('videos.partials.thumb', ['src' => $playlistVideo->thumbnail_url, 'alt' => $playlistVideo->title])
                                                        <span class="avd-duration">{{$playlistVideo->duration}}</span>
                                                    </div>
                                                </div>
                                                <div class="min-w-0">
                                                    <h6 class="fw-bold text-sm mb-1 text-break" title="{{$playlistVideo->title}}">
                                                        {{Str::limit($playlistVideo->title, 54)}}
                                                    </h6>
                                                    <small class="text-muted text-sm" title="{{$playlistVideo->user?->username ?? __('Deleted user')}}">
                                                        {{$playlistVideo->user?->username ?? __('Deleted user')}}
                                                    </small>
                                                </div>
                                            </a>
                                        </article>
                                    @else
                                        <article class="d-flex w-100 align-items-center gap-3 px-2 py-2">
                                            <small class="text-muted">{{$index + 1}}</small>
                                            <div class="bg-secondary text-white d-flex flex-column justify-content-center align-items-center w-100 gap-2 rounded-3" style="height: 100px">
                                                <i class="fa-solid fa-lock fa-1x"></i>
                                                <div class="text-center text-sm">
                                                    <div class="text-sm fw-bold">{{__('This video is private')}}</div>
                                                    <div class="text-sm">{{__('The author update this video status to private')}}</div>
                                                </div>
                                            </div>
                                        </article>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                        <div class="avd-watch-sidebar-panel cursor-pointer p-3" x-show="!open" x-cloak @click="open=true">
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <div class="d-flex flex-column gap-1 min-w-0">
                                    @if($effectiveNextVideo)
                                        <div class="text-truncate"><span class="fw-bold">{{ __(':label:', ['label' => __('Next')]) }}</span>{{ \App\Support\LabelGap::member() }}{{$effectiveNextVideo->title}}</div>
                                    @endif
                                    <small class="text-sm">{{Str::limit($playlist->title, 45)}} <span aria-hidden="true">&middot;</span> <span class="text-muted text-sm">{{$currentIndex + 1}}/{{$playlist->videos_count}}</span></small>
                                </div>
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>
                        </div>
                    </div>
                @endif

                @if($watchRelatedVideosEnabled)
                    <div class="avd-related-section mb-4">
                        <div class="avd-related-header d-flex align-items-center justify-content-between mb-3 px-1">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avd-heading-icon d-flex align-items-center justify-content-center rounded-circle" style="width: 28px; height: 28px; background: rgba(var(--avd-brand-rgb, 255,61,97), 0.15); color: var(--avd-brand, #ff3d61);">
                                    <i class="fa-solid fa-film" style="font-size: 0.8rem;"></i>
                                </div>
                                <h5 class="fw-bold mb-0 text-body" style="font-size: 1.02rem; letter-spacing: -0.01em;">
                                    {{ __($watchRelatedTitle) }}
                                </h5>
                                <span class="badge rounded-pill bg-secondary bg-opacity-25 text-body-secondary px-2 py-0.5" style="font-size: 0.72rem;">
                                    {{ count($videos) }}
                                </span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge rounded-pill bg-primary bg-opacity-15 text-primary px-2.5 py-1 fw-semibold d-none d-sm-inline" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-wand-magic-sparkles me-1" aria-hidden="true"></i> {{ __('Picked for you') }}
                                </span>
                            </div>
                        </div>

                        <div class="avd-related-list px-0">
                            @foreach($videos as $relatedVideo)
                                @include('videos.card-secondary', [
                                    'video' => $relatedVideo,
                                    'showChannel' => $watchRelatedShowChannel,
                                    'showMeta' => $watchRelatedShowMeta,
                                ])
                                @if(\App\Support\FeedAdSlots::listAdAfter($loop->iteration, $loop->count, \App\Support\FeedAdSlots::adEvery(config('site.ad_grid_position', 6))))
                                    <x-feed-ad-slot context="watch_related" class="mb-3 w-100" />
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($watchSidebarAdVisible)
                    <div id="ad-slot-sidebar" class="ad-slot ad-slot-sidebar mt-3" role="complementary" aria-label="{{__('Advertisement')}}">
                        {!! config('site.ad_code_sidebar') !!}
                    </div>
                @endif
            </aside>
        </div>
        @endif
    </div>

    {{-- The sleep timer dialog: rendered the first time it opens (watch-7; window.avdLazy, core.ts). R2A-059: plain words in
         sentence case, as the tools sheet has them (it named the timer after the cinema, and its first option said off and
         disabled at once). --}}
    <template data-avd-lazy="avdSleepTimerModal">
    <div class="modal fade" id="avdSleepTimerModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(14px);">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
            <div class="modal-content text-white border-0 shadow-2xl" style="background: rgba(14, 18, 30, 0.96); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,0.8);">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title d-flex align-items-center gap-2 fw-bold">
                        <i class="fa-solid fa-moon text-info" aria-hidden="true"></i> {{ __('Sleep timer') }}
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    <p class="text-white-50 small mb-3">{{ __('The video pauses when the time is up.') }}</p>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="0" onclick="window.avdSetSleepTimer(0)">
                            <span><i class="fa-solid fa-ban me-2 text-danger" aria-hidden="true"></i> {{ __('Off') }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadge0"></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="15" onclick="window.avdSetSleepTimer(15)">
                            <span><i class="fa-regular fa-clock me-2 text-info" aria-hidden="true"></i> {{ trans_choice('1 minute|:count minutes', 15, ['count' => \App\Support\LocalNumber::format(15)]) }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadge15"></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="30" onclick="window.avdSetSleepTimer(30)">
                            <span><i class="fa-regular fa-clock me-2 text-warning" aria-hidden="true"></i> {{ trans_choice('1 minute|:count minutes', 30, ['count' => \App\Support\LocalNumber::format(30)]) }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadge30"></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="45" onclick="window.avdSetSleepTimer(45)">
                            <span><i class="fa-regular fa-clock me-2 text-success" aria-hidden="true"></i> {{ trans_choice('1 minute|:count minutes', 45, ['count' => \App\Support\LocalNumber::format(45)]) }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadge45"></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="-1" onclick="window.avdSetSleepTimer(-1)">
                            <span><i class="fa-solid fa-film me-2 text-primary" aria-hidden="true"></i> {{ __('At the end of the video') }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadgeEnd"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </template>

    {{-- The notes dialog: rendered the first time it opens (watch-7; window.avdLazy, core.ts). R2A-059: "Notes", as the tools
         sheet that opens it says (its title spoke of study notes and a scratchpad), and plain words for its buttons. --}}
    <template data-avd-lazy="avdNotesModal">
    <div class="modal fade" id="avdNotesModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(14px);">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
            <div class="modal-content text-white border-0 shadow-2xl" style="background: rgba(14, 18, 30, 0.96); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,0.8);">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title d-flex align-items-center gap-2 fw-bold">
                        <i class="fa-regular fa-note-sticky text-warning" aria-hidden="true"></i> {{ __('Notes') }}
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-white-50 small">{{ __('Saved in this browser for this video') }}</span>
                        <button type="button" class="btn btn-xs btn-outline-warning rounded-pill px-2.5 py-1" onclick="window.avdInsertNoteTimestamp()">
                            <i class="fa-solid fa-stopwatch me-1" aria-hidden="true"></i> {{ __('Add the current time') }}
                        </button>
                    </div>
                    <textarea id="avdNotesTextarea" class="form-control text-white border-0 shadow-none font-monospace p-3" rows="7" placeholder="{{ __('Write your notes here…') }}" aria-label="{{ __('Notes') }}" style="background: rgba(255,255,255,0.05); border-radius: 12px; font-size: 0.85rem; resize: vertical;" oninput="window.avdSaveNotes && window.avdSaveNotes()"></textarea>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex justify-content-between">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-info" onclick="window.avdCopyNotes()">
                            <i class="fa-regular fa-copy me-1" aria-hidden="true"></i> {{ __('Copy all') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white" onclick="window.avdDownloadNotes()">
                            <i class="fa-solid fa-download me-1" aria-hidden="true"></i> {{ __('Download') }}
                        </button>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    </template>

    {{-- Video details modal: rendered the first time it opens or when the page is idle (watch-7; core.ts, and watchTools.ts brings its badges up to date). --}}
    <template data-avd-lazy="avdTelemetryModal">
    <div class="modal fade" id="avdTelemetryModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(14px);">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
            <div class="modal-content text-white border-0 shadow-2xl" style="background: rgba(14, 18, 30, 0.96); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,0.8);">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title d-flex align-items-center gap-2 fw-bold font-monospace">
                        <i class="fa-solid fa-circle-info text-primary"></i> {{ __('Video details') }}
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body font-monospace" style="font-size: 0.8rem;">
                    <div class="p-2.5 rounded-3 mb-3" style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);">
                        <div class="text-truncate fw-bold text-white mb-1">{{ $video->title }}</div>
                        <div class="text-muted small text-truncate">{{ __('Source') }}</div>
                    </div>
                    <div class="list-group list-group-flush rounded-3 overflow-hidden border border-secondary border-opacity-25" style="background: rgba(0,0,0,0.3);">
                        @forelse($videoSourceDetails as $detail)
                            <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center gap-3 py-2 px-3 border-secondary border-opacity-25">
                                <span>{{ __(':label:', ['label' => $detail['label']]) }}</span>
                                <strong class="text-white text-break text-end">{{ $detail['value'] }}</strong>
                            </div>
                        @empty
                            <div class="list-group-item bg-transparent text-white-50 py-2 px-3 border-secondary border-opacity-25">
                                {{ __('Unavailable') }}
                            </div>
                        @endforelse
                        <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center py-2 px-3 border-secondary border-opacity-25">
                            <span>{{ __(':label:', ['label' => __('Color filter')]) }}</span>
                            <strong class="text-info" id="avdTelemFilter">{{ __('Normal') }}</strong>
                        </div>
                        <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center py-2 px-3 border-secondary border-opacity-25">
                            <span>{{ __(':label:', ['label' => __('Aspect ratio')]) }}</span>
                            <strong class="text-warning" id="avdTelemAspect">16:9</strong>
                        </div>
                        <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center py-2 px-3 border-secondary border-opacity-25">
                            <span>{{ __(':label:', ['label' => __('Mirror the video')]) }}</span>
                            <span class="badge bg-secondary-subtle text-secondary" id="avdTelemMirror">{{ __('Off') }}</span>
                        </div>
                        <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center py-2 px-3 border-secondary border-opacity-25">
                            <span>{{ __(':label:', ['label' => __('Glow around the player')]) }}</span>
                            <span class="text-white" id="avdTelemAmbilight">{{ $watchPlayerAmbientGlowEnabled ? __('Active') : __('Disabled') }}</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-sm btn-secondary w-100" data-bs-dismiss="modal">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
    </template>

    {{-- Direct Embed Code Generator Modal: rendered the first time it opens (watch-7; window.avdLazy, core.ts). --}}
    <template data-avd-lazy="avdEmbedModal">
    <div class="modal fade" id="avdEmbedModal" tabindex="-1" aria-labelledby="avdEmbedModalLabel" aria-hidden="true" style="backdrop-filter: blur(14px);">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
            <div class="modal-content rounded-4 border-0 shadow-lg" style="background: var(--avd-surface, #1e293b); color: var(--avd-text, #f8fafc); border: 1px solid var(--avd-border, rgba(255,255,255,0.12));">
                <div class="modal-header border-bottom border-secondary border-opacity-25 px-4 py-3">
                    <h5 class="modal-title h6 fw-bold d-flex align-items-center gap-2" id="avdEmbedModalLabel">
                        <i class="fa-solid fa-code text-primary" aria-hidden="true"></i> {{ __('Embed video player') }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">{{ __('Copy the responsive HTML code below to embed this video player on external blogs, forums, or websites:') }}</p>
                    <textarea id="avdEmbedCodeTextarea" class="form-control font-monospace text-sm p-3 mb-3" rows="4" readonly style="background: rgba(0,0,0,0.25); border: 1px solid var(--avd-border, rgba(255,255,255,0.15)); border-radius: 10px; color: var(--avd-text, #f8fafc); font-size: 0.8rem;"></textarea>
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" onclick="window.avdCopyEmbedCode()">
                            <i class="fa-regular fa-copy me-1" aria-hidden="true"></i> {{ __('Copy embed code') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </template>

    {{-- Mobile Cinema Suite Bottom Sheet (Specifically optimized for iOS & Android): rendered the first time it opens, not when the page is idle (watch-7: laid out while hidden, it would cost its whole layout on every phone visit; core.ts, and watchTools.ts brings its badges up to date). --}}
    <template data-avd-lazy="avdMobileToolsSheet" data-avd-lazy-idle="off">
    <div class="offcanvas offcanvas-bottom avd-mobile-sheet d-md-none" tabindex="-1" id="avdMobileToolsSheet" aria-labelledby="avdMobileToolsSheetLabel">
        <div class="avd-sheet-drag-handle mx-auto my-2"></div>
        <div class="offcanvas-header border-bottom border-secondary border-opacity-25 px-4 py-2">
            <h6 class="offcanvas-title fw-bold text-white d-flex align-items-center gap-2" id="avdMobileToolsSheetLabel">
                <i class="fa-solid fa-sliders text-primary" aria-hidden="true"></i> {{ __('Video tools') }}
            </h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools();" aria-label="{{ __('Close') }}"></button>
        </div>
        <div class="offcanvas-body px-4 py-3" style="max-height: 75vh; overflow-y: auto; -webkit-overflow-scrolling: touch;">
            <!-- Real-Time Color Grade Filter Chips -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-bold text-white-50 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-palette text-primary me-1" aria-hidden="true"></i> {{ __('Color filter') }}
                    </span>
                </div>
                <div class="d-grid gap-1.5 avd-mobile-filter-grid" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip active btn-primary py-2" onclick="window.avdSetVideoFilter('normal', this)">{{ __('Normal') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('vivid', this)">{{ __('Vivid') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('cinema', this)">{{ __('Warm film') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('cyberpunk', this)">{{ __('Cyberpunk') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('oled', this)">{{ __('Deep black') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('noir', this)">{{ __('Black and white') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('night', this)">🌙 {{ __('Night') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('anime', this)">✨ {{ __('Anime') }}</button>
                </div>
            </div>

            <!-- Frame Rotation -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-bold text-white-50 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-arrows-rotate text-info me-1" aria-hidden="true"></i> {{ __('Rotate the video') }}
                    </span>
                </div>
                <div class="row g-1">
                    <div class="col-3"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-rotate-chip active btn-primary py-2" onclick="window.avdRotateVideo(0, this)">0°</button></div>
                    <div class="col-3"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-rotate-chip py-2" onclick="window.avdRotateVideo(90, this)">90°</button></div>
                    <div class="col-3"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-rotate-chip py-2" onclick="window.avdRotateVideo(180, this)">180°</button></div>
                    <div class="col-3"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-rotate-chip py-2" onclick="window.avdRotateVideo(270, this)">270°</button></div>
                </div>
            </div>

            <!-- Brightness -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-bold text-white-50 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-sun text-warning me-1" aria-hidden="true"></i> {{ __('Brightness') }}
                    </span>
                </div>
                <div class="row g-1">
                    <div class="col-4"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-bright-chip active btn-primary py-2" onclick="window.avdSetBrightness('normal', this)">{{ __('Normal') }}</button></div>
                    {{-- R3-049: the sign stays before the number in right-to-left languages ("15%+" in Arabic) --}}
                    <div class="col-4"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-bright-chip py-2" dir="ltr" onclick="window.avdSetBrightness('boost', this)">+15%</button></div>
                    <div class="col-4"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-bright-chip py-2" dir="ltr" onclick="window.avdSetBrightness('dim', this)">-15%</button></div>
                </div>
            </div>

            @if($watchPlayerAmbientGlowEnabled)
            <!-- Ambilight Halo Glow -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-bold text-white-50 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-wand-magic-sparkles text-primary me-1" aria-hidden="true"></i> {{ __('Glow around the player') }}
                    </span>
                </div>
                <div class="row g-1">
                    <div class="col-3"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-ambi-chip active btn-primary py-2" onclick="window.avdSetAmbiMode('theme', this)">{{ __('Theme') }}</button></div>
                    <div class="col-3"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-ambi-chip py-2" onclick="window.avdSetAmbiMode('pulse', this)">{{ __('Pulse') }}</button></div>
                    <div class="col-3"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-ambi-chip py-2" onclick="window.avdSetAmbiMode('neon', this)">{{ __('Neon') }}</button></div>
                    <div class="col-3"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-ambi-chip py-2" onclick="window.avdSetAmbiMode('warm', this)">{{ __('Warm') }}</button></div>
                </div>
            </div>
            @endif

            <!-- Touch Action Items List with comfortable targets -->
            <div class="list-group list-group-flush rounded-3 overflow-hidden border border-secondary border-opacity-25 mb-2">
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdToggleMirror()">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-arrows-split-up-and-left text-info" style="width: 20px;" aria-hidden="true"></i>
                        <span>{{ __('Mirror the video') }}</span>
                    </div>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools(); window.avdToggleLightsOut();">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-moon text-info" style="width: 20px;" aria-hidden="true"></i>
                        <span>{{ __('Lights out') }}</span>
                    </div>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdToggleZenMode()">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-regular fa-eye-slash text-warning" style="width: 20px;" aria-hidden="true"></i>
                        <span>{{ __('Focus mode') }}</span>
                    </div>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdToggleFullscreen()">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-maximize text-success" style="width: 20px;" aria-hidden="true"></i>
                        <span>{{ __('Full screen') }}</span>
                    </div>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools(); window.avdToggleTelemetryModal();">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-circle-info text-primary" style="width: 20px;" aria-hidden="true"></i>
                        <span>{{ __('Video details') }}</span>
                    </div>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools(); window.avdToggleNotes();">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-regular fa-note-sticky text-warning" style="width: 20px;" aria-hidden="true"></i>
                        <span>{{ __('Notes') }}</span>
                    </div>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools(); window.avdOpenEmbedModal();">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-code text-primary" style="width: 20px;" aria-hidden="true"></i>
                        <span>{{ __('Embed code') }}</span>
                    </div>
                </button>
            </div>
        </div>
    </div>
    </template>

    @includeWhen($watchShareEnabled, 'modals.share')
@endsection

@pushIf($jsLazyCommentsEnabled && $watchCommentsPreviewEnabled && config('site.comments_enabled', true) && $video->allow_comments, 'scripts')
    <script>
        const commentsTarget = document.getElementById('comments_area');

        if (commentsTarget) {
            const observer = new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if(entry.isIntersecting) {
                        commentsTarget.innerHTML = "<comments-area video='{{$video->uuid}}' default-sort='{{$video->default_comments_sort}}' count='{{ (int) $video->comments_count }}' />";
                        observer.unobserve(entry.target);
                    }
                }
            }, {rootMargin: '0px 0px 400px 0px'});

            observer.observe(commentsTarget);
        }

        const commentsOffcanvas = document.getElementById('comments-offcanvas');
        let opened = false;

        if (commentsOffcanvas) {
            commentsOffcanvas.addEventListener('show.bs.offcanvas', () => {
                if(!opened) {
                    document.getElementById('offcanvas-body').innerHTML = "<comments-area video='{{$video->uuid}}' default-sort='{{$video->default_comments_sort}}' count='{{ (int) $video->comments_count }}' />";
                }

                opened = true;
            });
        }
    </script>
@endPushIf

@pushIf($video->is_uploaded, 'scripts')
    <script>
        // The comments' time codes read these (resources/js/components/Comments/ExpandComment.tsx).
        window.AVD_WATCH = {duration: {{ $watchDurationSeconds }}, seekable: {{ $watchSeekable ? 'true' : 'false' }}};

        function time(timecode) {
            const video = document.querySelector("#avdPlayerWrapper video") || document.querySelector("video");
            const length = window.AVD_WATCH.duration;

            if (!video || !window.AVD_WATCH.seekable || !(timecode >= 0) || (length > 0 && timecode >= length)) {
                return;
            }

            video.currentTime = timecode;
            video.play();
            video.scrollIntoView({
                behavior: '{{ $scrollBehavior }}',
                block: 'center',
                inline: 'center'
            });
        }
    </script>
@endPushIf

@push('scripts')
<script>
    // On phones the tool bar scrolls sideways; data-avd-overflow names the edge(s) with more buttons behind
    // them (the dock's CSS fades that edge). Read on scroll and resize, written only when it changes.
    (() => {
        const dock = document.getElementById('avdCinemaDock');
        if (!dock) {
            return;
        }
        let queued = false;
        const update = () => {
            queued = false;
            const max = dock.scrollWidth - dock.clientWidth;
            const at = Math.abs(dock.scrollLeft);
            const edges = [];
            if (max > 2 && at > 2) {
                edges.push('start');
            }
            if (max > 2 && at < max - 2) {
                edges.push('end');
            }
            const value = edges.join(' ');
            if ((dock.getAttribute('data-avd-overflow') || '') !== value) {
                if (value) {
                    dock.setAttribute('data-avd-overflow', value);
                } else {
                    dock.removeAttribute('data-avd-overflow');
                }
            }
        };
        const queue = () => {
            if (!queued) {
                queued = true;
                requestAnimationFrame(update);
            }
        };
        dock.addEventListener('scroll', queue, {passive: true});
        if ('ResizeObserver' in window) {
            new ResizeObserver(queue).observe(dock);
        } else {
            window.addEventListener('resize', queue, {passive: true});
        }
        queue();
    })();

    // Keep the fixed comments sheet outside contained and animated preview panels.
    (() => {
        const commentsSheet = document.getElementById('comments-offcanvas');
        if (commentsSheet && commentsSheet.parentElement !== document.body) {
            document.body.appendChild(commentsSheet);
        }
    })();
</script>
<style>
    /* Mobile Viewport Guard: Guarantee 0 Horizontal Overflow on iOS & Android */
    html, body {
        max-width: 100vw;
        overflow-x: clip !important;
    }
    @supports not (overflow-x: clip) {
        html, body {
            overflow-x: hidden !important;
        }
    }

    /* Filter-free ambilight halo: the blur is baked into a pre-softened gradient on a
       larger box (per-intensity box and opacity live with the intensity rules in the
       layout), so the breathing is a compositor-only transform. Normal blending: over the
       dark page (and inside the isolated wrapper when ambilight is on) it matches the old
       screen blend; screen is kept where it hid the halo on a light page. */
    .avd-ambilight-glow {
        position: absolute;
        inset: -138px -56px;
        border-radius: 28px;
        background: radial-gradient(closest-side, rgba(var(--avd-accent-rgb, 0, 240, 255), 1) 0%, rgba(var(--avd-brand-rgb, 255, 0, 85), 1) 44%, rgba(var(--avd-brand-rgb, 255, 0, 85), 0.716) 58%, rgba(var(--avd-brand-rgb, 255, 0, 85), 0.391) 72%, rgba(var(--avd-brand-rgb, 255, 0, 85), 0.147) 86%, rgba(var(--avd-brand-rgb, 255, 0, 85), 0) 100%);
        filter: none;
        opacity: 0;
        pointer-events: none;
        z-index: 0;
        transition: opacity 0.4s cubic-bezier(0.16, 1, 0.3, 1), transform 0.4s ease;
        mix-blend-mode: normal;
    }
    html[data-bs-theme="light"]:not([data-avd-ambilight="on"]) .avd-ambilight-glow {
        mix-blend-mode: screen;
    }
    .avd-ambilight-glow.active {
        opacity: 0.65;
        animation: avd-ambilight-glow-breathe 6s ease-in-out infinite alternate;
    }
    /* Transform only: every intensity level pins the opacity with !important. */
    @keyframes avd-ambilight-glow-breathe {
        0% { transform: scale(0.98); }
        50% { transform: scale(1.03); }
        100% { transform: scale(1); }
    }
    @media (prefers-reduced-motion: reduce) {
        .avd-ambilight-glow {
            mix-blend-mode: normal !important;
            animation: none !important;
        }
        /* Static glow as before (an active glow keeps its intensity box and opacity). */
        .avd-ambilight-glow:not(.active) {
            inset: -40px -30px;
            opacity: 0.42 !important;
        }
        .avd-ambilight-glow.glow-pulse:not(.active) {
            opacity: 0.48 !important;
        }
        .avd-ambilight-glow.active,
        .avd-ambilight-glow.glow-pulse {
            animation: none !important;
        }
    }
    .avd-player-frame iframe {
        contain: content;
        transform: translateZ(0);
        -webkit-overflow-scrolling: touch;
    }

    /* Keep the media slot stable while the async player resolves. The media
       surface and status layers share one fixed box, so fast scrolls cannot
       expose a transient black frame or collapse the ratio before readiness. */
    .avd-player-stage {
        isolation: isolate;
        contain: layout paint;
        overflow: hidden;
    }

    .avd-player-media-layer,
    .avd-player-status-layer,
    .avd-player-ad-layer {
        min-width: 0;
        min-height: 0;
    }

    .avd-player-native-media,
    .avd-player-media-layer iframe {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: contain;
        background: #000;
    }

    .avd-player-stage .avd-preroll-ad__creative,
    .avd-player-stage .avd-preroll-ad__video-shell {
        min-height: 0;
        max-height: 100%;
    }

    .avd-player-stage .avd-preroll-ad {
        padding-bottom: max(0.75rem, env(safe-area-inset-bottom, 0px));
    }

    .avd-player-stage .avd-preroll-ad__skip {
        right: max(0.85rem, env(safe-area-inset-right, 0px));
        bottom: max(0.85rem, env(safe-area-inset-bottom, 0px));
    }

    /* Active Playback & Offscreen Containment Performance Engine.
       Pause only the decorative loops while video plays. Scoped selectors instead of
       body.avd-playback-active *, so play / pause / ad transitions restyle a handful of
       elements instead of the whole page; spinners, toasts and HUDs keep running. */
    body.avd-playback-active .avd-watch-title,
    body.avd-playback-active .avd-tag.active,
    body.avd-playback-active .avd-sidebar-link.menu-selected i,
    body.avd-playback-active .avd-footer::before,
    body.avd-playback-active .avd-footer-accent::before,
    body.avd-playback-active.avd-app::before {
        animation-play-state: paused !important;
    }
    /* Every other decorative loop host marked by the motion scheduler ([data-avd-deco]).
       A layered !important also beats 'animation: ... !important' shorthands; the
       unlayered copy (id specificity) covers engines without @layer.
       The player shell is marked for its ::before/::after halos but its own animation is
       the functional mini-player slide-in (avdPipSlide), which starts during playback:
       keep the element-level selector off .avd-pip-active so the mini-player is not left
       frozen on its invisible first frame. The halos still pause via the pseudo selectors. */
    @layer avd-motion-gate {
        body.avd-playback-active [data-avd-deco]:not(.avd-pip-active),
        body.avd-playback-active [data-avd-deco]::before,
        body.avd-playback-active [data-avd-deco]::after {
            animation-play-state: paused !important;
        }
    }
    body.avd-playback-active:not(#avd-motion-gate) [data-avd-deco]:not(.avd-pip-active),
    body.avd-playback-active:not(#avd-motion-gate) [data-avd-deco]::before,
    body.avd-playback-active:not(#avd-motion-gate) [data-avd-deco]::after {
        animation-play-state: paused !important;
    }
    /* The cinema and ambilight halos are pseudo-elements of the player hosts. Retain
       their current glow while video plays; resume the same phase when it pauses. */
    body.avd-playback-active #avdPlayerWrapper::before,
    body.avd-playback-active #avdPlayerWrapper::after,
    body.avd-playback-active #avdMainPlayerShell::before,
    body.avd-playback-active #avdMainPlayerShell::after {
        animation-play-state: paused !important;
    }
    body.avd-playback-active .avd-up-next-card,
    body.avd-playback-active .avd-cinema-dock,
    body.avd-playback-active .avd-watch-sidebar,
    body.avd-playback-active .avd-description-panel,
    body.avd-playback-active .avd-detail-panel,
    body.avd-playback-active .avd-action-row,
    body.avd-playback-active .avd-channel-panel,
    body.avd-playback-active .modal,
    body.avd-playback-active .offcanvas {
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
    }
    body.avd-playback-active .avd-ambilight-glow {
        animation: none !important;
        opacity: 0.35 !important;
    }

    /* Keep the related list's real height through fast scroll reversals. */
    /* No paint containment: it clipped the comment and card "More actions" menus at the column's edge. */
    .avd-related-section,
    .avd-related-list {
        content-visibility: visible;
        contain-intrinsic-size: none;
        contain: layout style;
    }
    #comments_area,
    .avd-mobile-comments,
    .avd-comments-offcanvas {
        content-visibility: visible;
        contain-intrinsic-size: none;
        contain: layout style;
    }
    .avd-channel-panel,
    .avd-description-panel,
    .avd-detail-panel {
        content-visibility: visible;
        contain-intrinsic-size: none;
        contain: layout style;
    }
    /* Preserve the creative's own reservation instead of an estimated height. */
    #ad-slot-sidebar {
        content-visibility: visible;
        contain-intrinsic-size: none;
    }
    #ad-slot-below-player {
        content-visibility: auto;
        contain-intrinsic-size: 250px;
    }

    @media (prefers-reduced-motion: reduce) {
        .avd-up-next-card,
        .avd-cinema-dock,
        .avd-watch-sidebar,
        .avd-description-panel,
        .avd-detail-panel,
        .avd-action-row,
        .avd-channel-panel,
        .avd-cinema-dropdown-menu,
        .avd-mobile-sheet,
        .avd-speed-hud,
        .avd-aspect-hud,
        .avd-volume-hud,
        .avd-seek-hud,
        .avd-pip-header,
        .avd-preroll-ad__badge,
        .avd-preroll-ad__skip,
        #avdResumeBanner,
        #avdSleepFinishedOverlay,
        #avdStickyWatchBar,
        .modal,
        .modal-content {
            backdrop-filter: none !important;
            -webkit-backdrop-filter: none !important;
        }
    }

    .avd-player-control.active,
    .avd-dock-btn.active,
    .avd-dock-btn[aria-pressed="true"] {
        color: var(--avd-accent, #00f0ff) !important;
        border-color: rgba(var(--avd-cyan-rgb, 0, 240, 255), 0.5) !important;
        background: rgba(var(--avd-cyan-rgb, 0, 240, 255), 0.15) !important;
        box-shadow: 0 0 14px rgba(var(--avd-cyan-rgb, 0, 240, 255), 0.35) !important;
    }
    .avd-player-shell.avd-pip-active {
        position: fixed !important;
        bottom: calc(24px + env(safe-area-inset-bottom, 0px)) !important;
        right: max(24px, env(safe-area-inset-right, 0px)) !important;
        width: 360px !important;
        max-width: calc(100vw - 48px) !important;
        z-index: 1065 !important;
        border-radius: 16px !important;
        overflow: hidden !important;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.85), 0 0 25px rgba(var(--avd-brand-rgb, 255, 61, 97), 0.35) !important;
        border: 1px solid rgba(255, 255, 255, 0.18) !important;
        animation: avdPipSlide 0.28s cubic-bezier(0.16, 1, 0.3, 1) !important;
        background: #000000 !important;
    }
    .avd-player-shell.avd-pip-active .avd-pip-header {
        display: flex !important;
    }
    .avd-player-shell.avd-pip-active .avd-player-controls,
    .avd-player-shell.avd-pip-active .avd-cinema-dock-container {
        display: none !important;
    }

    @media (max-width: 767.98px) {
        body.has-bottom-nav .avd-player-shell.avd-pip-active {
            right: max(8px, env(safe-area-inset-right, 0px)) !important;
            bottom: calc(76px + env(safe-area-inset-bottom, 0px)) !important;
            width: 360px !important;
            max-width: calc(100vw - 16px) !important;
        }

        body.has-bottom-nav.has-mobile-ad .avd-player-shell.avd-pip-active {
            bottom: calc(150px + env(safe-area-inset-bottom, 0px)) !important;
        }
    }

    /* Glassmorphic Cinema Dock Styling */
    .avd-player-shell {
        overflow: visible !important;
    }

    /* Cinema Color Grading Filters (100% works on Upload18 iframe & HTML5) */
    .avd-player-frame.avd-filter-vivid video,
    .avd-player-frame.avd-filter-vivid iframe {
        filter: contrast(1.16) saturate(1.35) brightness(1.02) !important;
    }
    .avd-player-frame.avd-filter-cinema video,
    .avd-player-frame.avd-filter-cinema iframe {
        filter: sepia(0.18) contrast(1.12) saturate(1.1) brightness(0.98) !important;
    }
    .avd-player-frame.avd-filter-cyberpunk video,
    .avd-player-frame.avd-filter-cyberpunk iframe {
        filter: hue-rotate(25deg) contrast(1.25) saturate(1.4) !important;
    }
    .avd-player-frame.avd-filter-oled video,
    .avd-player-frame.avd-filter-oled iframe {
        filter: contrast(1.3) brightness(0.90) !important;
    }
    .avd-player-frame.avd-filter-noir video,
    .avd-player-frame.avd-filter-noir iframe {
        filter: grayscale(1) contrast(1.35) brightness(0.95) !important;
    }
    .avd-player-frame.avd-filter-night video,
    .avd-player-frame.avd-filter-night iframe {
        filter: sepia(0.42) hue-rotate(-15deg) brightness(0.95) contrast(0.98) !important;
    }
    .avd-player-frame.avd-filter-anime video,
    .avd-player-frame.avd-filter-anime iframe {
        filter: saturate(1.50) contrast(1.15) brightness(1.03) !important;
    }

    /* Screen Brightness Adjustments */
    .avd-player-frame.avd-bright-boost video,
    .avd-player-frame.avd-bright-boost iframe {
        filter: brightness(1.15) !important;
    }
    .avd-player-frame.avd-bright-dim video,
    .avd-player-frame.avd-bright-dim iframe {
        filter: brightness(0.85) !important;
    }

    /* Mirror Flip View (100% works on Upload18 iframe & HTML5) */
    .avd-player-frame.avd-mirrored video,
    .avd-player-frame.avd-mirrored iframe {
        transform: scaleX(-1) !important;
    }

    /* Video Frame Rotation (100% works on Upload18 iframe & HTML5) */
    .avd-player-frame.avd-rotate-90 video,
    .avd-player-frame.avd-rotate-90 iframe {
        transform: rotate(90deg) scale(0.5625) !important;
    }
    .avd-player-frame.avd-rotate-180 video,
    .avd-player-frame.avd-rotate-180 iframe {
        transform: rotate(180deg) !important;
    }
    .avd-player-frame.avd-rotate-270 video,
    .avd-player-frame.avd-rotate-270 iframe {
        transform: rotate(270deg) scale(0.5625) !important;
    }
    .avd-player-frame.avd-mirrored.avd-rotate-90 video,
    .avd-player-frame.avd-mirrored.avd-rotate-90 iframe {
        transform: scaleX(-1) rotate(90deg) scale(0.5625) !important;
    }
    .avd-player-frame.avd-mirrored.avd-rotate-180 video,
    .avd-player-frame.avd-mirrored.avd-rotate-180 iframe {
        transform: scaleX(-1) rotate(180deg) !important;
    }
    .avd-player-frame.avd-mirrored.avd-rotate-270 video,
    .avd-player-frame.avd-mirrored.avd-rotate-270 iframe {
        transform: scaleX(-1) rotate(270deg) scale(0.5625) !important;
    }

    /* Dynamic Multi-Mode Ambilight Styles */
    .avd-ambilight-glow.glow-pulse {
        animation: avdAmbiPulse 3.2s ease-in-out infinite alternate !important;
    }
    /* Same colours as before, filter-free (pre-softened, fitted to the blurred originals).
       Neon's off-centre glow needs a second, contained gradient for its left-side bloom. */
    .avd-ambilight-glow.glow-neon {
        background: radial-gradient(closest-side at 18.75% 50%, rgba(24, 200, 216, 0.4725) 0%, rgba(255, 61, 97, 0.2283) 55.6%, rgba(255, 61, 97, 0) 100%), radial-gradient(closest-side at 47% 50%, rgba(24, 200, 216, 0.45) 0%, rgba(255, 61, 97, 0.42) 41.25%, rgba(255, 61, 97, 0.348) 55.9%, rgba(255, 61, 97, 0.189) 70.6%, rgba(255, 61, 97, 0.0756) 85.3%, rgba(255, 61, 97, 0) 100%) !important;
        filter: none !important;
    }
    .avd-ambilight-glow.glow-warm {
        background: radial-gradient(closest-side, rgba(245, 158, 11, 0.45) 0%, rgba(217, 119, 6, 0.25) 49%, rgba(217, 119, 6, 0.214) 61.75%, rgba(217, 119, 6, 0.106) 74.5%, rgba(217, 119, 6, 0.0375) 87.25%, rgba(217, 119, 6, 0) 100%) !important;
        filter: none !important;
    }
    /* Transform only: the intensity levels pin opacity with !important, so animating it
       only forced this layer onto the main thread. */
    @keyframes avdAmbiPulse {
        from { transform: scale(1.02); }
        to { transform: scale(1.1); }
    }

    /* Zen Focus Immersion Mode */
    body.avd-zen-mode .avd-header,
    body.avd-zen-mode header,
    body.avd-zen-mode footer,
    body.avd-zen-mode .avd-watch-sidebar,
    body.avd-zen-mode .avd-watch-action-panel,
    body.avd-zen-mode .avd-watch-details-section,
    body.avd-zen-mode .avd-comment-section,
    body.avd-zen-mode .avd-related-videos,
    body.avd-zen-mode .avd-meta-chip,
    body.avd-zen-mode .avd-action-row,
    body.avd-zen-mode .avd-studio-telemetry,
    body.avd-zen-mode .avd-status-chip,
    body.avd-zen-mode #ad-slot-sidebar,
    body.avd-zen-mode #ad-slot-below-player,
    body.avd-zen-mode #avdBookmarkShelf,
    body.avd-zen-mode #avdStickyWatchBar {
        display: none !important;
    }
    body.avd-zen-mode {
        background: #000000 !important;
        overflow-x: hidden;
    }
    body.avd-zen-mode .avd-player-wrapper {
        margin: 2rem auto !important;
        max-width: 1400px;
    }

    /* iOS Safari & Mobile Pseudo-Fullscreen Engine */
    .avd-ios-fullscreen {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        width: 100vw !important;
        max-width: 100vw !important;
        height: 100dvh !important;
        height: 100vh !important;
        z-index: 999999 !important;
        background: #000000 !important;
        border-radius: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .avd-ios-fullscreen .avd-player-frame {
        width: 100% !important;
        height: 100% !important;
        max-height: 100% !important;
        border-radius: 0 !important;
        aspect-ratio: auto !important;
    }
    .avd-ios-fullscreen .avd-cinema-dock-container {
        position: absolute !important;
        bottom: calc(10px + env(safe-area-inset-bottom, 0px)) !important;
        left: 0 !important;
        right: 0 !important;
        z-index: 1000000 !important;
        background: transparent !important;
    }
    body.avd-fullscreen-active {
        overflow: hidden !important;
    }

    /* Watch controls keep their own narrow-screen contract. The player can be
       themed by the admin, but control geometry must still fit a 320px viewport. */
    .avd-player-wrapper,
    .avd-player-shell,
    .avd-player-frame,
    .avd-cinema-dock-container,
    .avd-cinema-dock {
        min-width: 0;
        max-width: 100%;
    }

    .avd-mobile-sheet-fallback-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1090;
        background: rgba(3, 7, 18, 0.68);
        -webkit-backdrop-filter: blur(2px);
        backdrop-filter: blur(2px);
    }

    body.avd-mobile-tools-open {
        overflow: hidden !important;
    }

    @media (max-width: 767.98px) {
        /* Filter-free; the active glow's box and opacity per intensity live in the layout. */
        .avd-ambilight-glow,
        .avd-ambilight-glow.glow-neon,
        .avd-ambilight-glow.glow-warm {
            inset: -55px -30px;
            filter: none !important;
            opacity: 0.27;
        }

        .avd-cinema-dock-container {
            padding-inline: max(0.5rem, env(safe-area-inset-left, 0px)) !important;
            overflow: visible;
        }

        .avd-cinema-dock {
            min-width: 0;
            overflow-x: auto !important;
            overflow-y: hidden !important;
            overscroll-behavior-x: contain;
            scroll-padding-inline: 0.35rem;
            touch-action: pan-x;
            -webkit-overflow-scrolling: touch;
        }

        .avd-dock-cluster {
            min-width: max-content;
            flex: 0 0 auto;
        }

        .avd-dock-secondary {
            margin-inline-start: 0 !important;
        }

        .avd-dock-btn {
            min-inline-size: 44px !important;
            min-block-size: 44px !important;
            flex: 0 0 auto;
            touch-action: manipulation;
        }

        .avd-dock-btn-tools-highlight {
            padding-inline: 0.75rem !important;
        }

        .avd-player-frame .ratio,
        .avd-player-frame iframe,
        .avd-player-frame video {
            min-width: 0;
            max-width: 100%;
        }

        .avd-mobile-sheet {
            height: min(88vh, 720px) !important;
            height: min(88dvh, 720px) !important;
            max-height: calc(100vh - env(safe-area-inset-top, 0px)) !important;
            max-height: calc(100dvh - env(safe-area-inset-top, 0px)) !important;
            overflow: hidden !important;
        }

        .avd-mobile-sheet.avd-mobile-sheet-fallback-open {
            display: flex !important;
            visibility: visible !important;
            transform: translateY(0) !important;
        }

        .avd-mobile-sheet .offcanvas-header {
            min-width: 0;
            gap: 0.5rem;
            padding-inline: clamp(1rem, 5vw, 1.5rem) !important;
        }

        .avd-mobile-sheet .offcanvas-title {
            min-width: 0;
            flex: 1 1 auto;
            overflow-wrap: anywhere;
        }

        .avd-mobile-sheet .offcanvas-title > i {
            flex: 0 0 auto;
        }

        .avd-mobile-sheet #avdMobilePlayerTypeBadge {
            flex: 0 0 auto;
            max-width: 34vw;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .avd-mobile-sheet .btn-close {
            flex: 0 0 44px;
            width: 44px;
            height: 44px;
            min-width: 44px;
            min-height: 44px;
            margin: -0.25rem -0.5rem -0.25rem 0;
            background-size: 0.8rem;
        }

        .avd-mobile-sheet .offcanvas-body {
            min-width: 0;
            min-height: 0;
            max-height: none !important;
            padding-inline: clamp(1rem, 6vw, 1.5rem) !important;
            overflow-x: hidden !important;
            overscroll-behavior: contain;
        }

        /* Chips never split a word: a filter column is as wide as its longest word at least, a chip row shares its
           width down to each chip's longest word (else it wraps), a word breaks only when wider than the sheet. */
        .avd-mobile-filter-grid {
            grid-template-columns: repeat(3, minmax(min-content, 1fr)) !important;
        }

        .avd-mobile-sheet .btn {
            min-width: 0;
            min-height: 44px;
            width: 100%;
            white-space: normal;
            overflow-wrap: break-word;
            line-height: 1.2;
            text-align: center;
        }

        .avd-mobile-sheet .row.g-1 > [class*="col-"] {
            flex: 1 1 0;
            width: auto;
        }

        .avd-mobile-sheet .list-group-item {
            min-width: 0;
            min-height: 52px;
            gap: 0.75rem;
        }

        .avd-mobile-sheet .list-group-item > div {
            min-width: 0;
            flex: 1 1 auto;
        }

        .avd-mobile-sheet .list-group-item > div span {
            overflow-wrap: anywhere;
        }

        .avd-mobile-sheet .list-group-item > .badge {
            flex: 0 0 auto;
        }

        [data-bs-theme="light"] .avd-mobile-sheet .btn-close-white {
            filter: none;
            opacity: 0.65;
        }
    }

    @media (max-width: 360px) {
        .avd-mobile-filter-grid {
            grid-template-columns: repeat(2, minmax(min-content, 1fr)) !important;
        }

        .avd-mobile-sheet .offcanvas-header,
        .avd-mobile-sheet .offcanvas-body {
            padding-inline: 1rem !important;
        }
    }

    /* Tablets keep the desktop control surface, but collapse labels before
       the two dock clusters can push the action edge outside the viewport. */
    @media (min-width: 768px) and (max-width: 1199.98px) {
        .avd-ambilight-glow,
        .avd-ambilight-glow.glow-neon,
        .avd-ambilight-glow.glow-warm {
            inset: -105px -64px;
            filter: none !important;
        }

        .avd-cinema-dock {
            justify-content: flex-start;
            gap: 0.25rem;
        }

        .avd-dock-btn > span {
            display: none !important;
        }

        .avd-dock-secondary {
            margin-inline-start: 0 !important;
        }

        .avd-dock-btn {
            min-inline-size: 40px;
            min-block-size: 40px;
            padding-inline: 0.45rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .avd-cinema-dock,
        .avd-cinema-dock *,
        .avd-mobile-sheet,
        .avd-mobile-sheet * {
            scroll-behavior: auto !important;
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
        }

        .avd-player-shell.avd-pip-active {
            animation: none !important;
        }
    }
</style>
{{-- watch-7: the tools' code is the cached module resources/js/layout/watchTools.ts (84 KB that every watch page
     used to carry inline). It reads these settings; the page still declares the mini-player switch inline. The texts are
     plain text (JSON_HEX_* flags: no value can end the <script> element); the module escapes the ones that go into the
     toasts' markup. --}}

{{-- The mini-player switch, and queue stubs for the names the page's onclick attributes call: a click before the
     module has run is queued and replayed by it (window.__avdWatchQ), as the layout's head stubs do for core.ts. --}}
<script>
    var avdPipAllowed = @json((bool) $watchFloatingMiniPlayerEnabled);
    (function (w) {
        var names = ['avdAcceptResume', 'avdCaptureFrame', 'avdCloseMobileTools', 'avdCopyEmbedCode', 'avdCopyNotes', 'avdCycleAspectRatio', 'avdCycleAudioBoost', 'avdCycleVideoFilter', 'avdDismissResume', 'avdDownloadNotes', 'avdInsertNoteTimestamp', 'avdOpenEmbedModal', 'avdOpenMobileTools', 'avdReloadPlayer', 'avdResumeFromSleep', 'avdRotateVideo', 'avdSaveNotes', 'avdSetAmbiMode', 'avdSetBrightness', 'avdSetSleepTimer', 'avdSetVideoFilter', 'avdToggleAmbilight', 'avdToggleFullscreen', 'avdToggleLoop', 'avdToggleMirror', 'avdToggleNotes', 'avdTogglePip', 'avdToggleSleepTimerModal', 'avdToggleTelemetryModal', 'avdToggleVisualizer', 'avdToggleZenMode'];
        for (var i = 0; i < names.length; i++) {
            (function (name) {
                if (typeof w[name] === 'function') return;
                var stub = function () {
                    (w.__avdWatchQ = w.__avdWatchQ || []).push([name, Array.prototype.slice.call(arguments)]);
                };
                stub.avdQueued = true;
                w[name] = stub;
            })(names[i]);
        }
    })(window);
</script>
<script type="application/json" id="avd-watch-tools">{!! json_encode($watchToolsSettings + ['ambientGlow' => (bool) $watchPlayerAmbientGlowEnabled], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
@if($watchToolsSrc)
<script type="module" src="{{ $watchToolsSrc }}" data-avd-watch-tools></script>
@endif
@endpush
