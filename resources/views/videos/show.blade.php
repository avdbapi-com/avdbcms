@use('App\Models\Video')

@extends('layouts.default')

@php
    $videoDescription = trim(strip_tags((string) $video->description));
    $videoCategoryRawTitle = $video->category ? decode_html_entities($video->category->title) : null;
    $videoCategoryTitle = $video->localized_type_name ?: ($videoCategoryRawTitle ? __($videoCategoryRawTitle) : null);
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
    $videoUuid = trim((string) $video->uuid);
    $videoSourceDetails = collect([
        ['label' => __('Source').' '.__('Quality'), 'value' => $videoQuality],
        ['label' => __('Duration'), 'value' => $videoDuration],
        ['label' => __('UUID'), 'value' => $videoUuid],
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
@endphp

@section('title', $seoService->renderTemplate('video_meta_title_template', $videoSeoValues, $video->title, 180))
@section('description', $seoService->renderTemplate('video_meta_description_template', $videoSeoValues, $videoDescriptionFallback, 500))
@section('image'){{$video->thumbnail_url}}@stop
@section('canonical', $video->route)
@section('og_type', 'video.other')
@section('rating', 'adult')

@push('structured-data')
    @if(config('site.video_schema_enabled', true))
        @php
            $jsonScriptFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
        @endphp
        <script type="application/ld+json">{!! json_encode(app(\App\Services\SeoService::class)->videoSchema($video), $jsonScriptFlags) !!}</script>
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

@section('class', 'mt-0 mt-lg-3 ms-0')

@section('content')
    @php
        $status = null;
        if ($video->is_planned) {
            $status = ['class' => 'text-warning', 'icon' => 'clock', 'label' => __('Planned').' - '.($video->scheduled_at?->translatedFormat('d M Y H:i') ?? '')];
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

    <style id="avdWatchEnhancedStyles">
        /* =========================================================================
           AVD WATCH PAGE & RELATED VIDEOS ENHANCED RESPONSIVE DESIGN SYSTEM
           Guarantees stunning presentation across all 120+ themes and all layouts.
           ========================================================================= */

        /* Theater and floating focus: the layout container caps its width so the 16:9 player is at most 62% of
           the viewport height and the title, actions and Up next stay on the first screen; floating focus also
           stops at 980px. Only the container is sized: the player inside keeps its own rules. Important and
           specific on purpose: older layout rules pin these columns to 100% / 1140px with !important. */
        .avd-watch-layout[data-avd-watch-layout] > .avd-watch-contained.col-12 {
            margin-inline: auto !important;
            max-width: min(100%, calc(62vh * 16 / 9)) !important;
        }

        .avd-watch-layout[data-avd-watch-layout="floating_focus"] > .avd-watch-contained.col-12 {
            max-width: min(980px, 100%, calc(62vh * 16 / 9)) !important;
        }

        /* Compact Up next strip under the title (theater / floating focus). */
        .avd-upnext-strip-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 0 0.6rem;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .avd-upnext-strip-list {
            display: grid;
            grid-auto-columns: minmax(150px, 1fr);
            grid-auto-flow: column;
            gap: 0.75rem;
            margin: 0;
            padding: 0 0 0.25rem;
            list-style: none;
            overflow-x: auto;
            overscroll-behavior-x: contain;
            scrollbar-width: thin;
        }

        .avd-upnext-strip-item {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            min-width: 0;
            color: var(--avd-text, inherit);
            text-decoration: none;
        }

        .avd-upnext-strip-thumb {
            position: relative;
            flex: none;
            width: 96px;
            aspect-ratio: 16 / 9;
            overflow: hidden;
            border-radius: var(--avd-r-thumb, 8px);
            background: var(--avd-surface-2, var(--bs-tertiary-bg));
        }

        .avd-upnext-strip-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avd-upnext-strip-thumb .avd-duration {
            inset-inline-end: 4px;
            bottom: 4px;
            padding: 2px 4px;
            font-size: 10px;
        }

        /* Studio facts (studio_dashboard): the stored source details on the theme's surface. */
        .avd-studio-facts {
            border: 1px solid var(--avd-border);
            border-radius: var(--avd-r-card, 12px);
            background: var(--avd-surface-1);
        }

        .avd-studio-facts-head {
            border-bottom: 1px solid var(--avd-border);
        }

        .avd-studio-facts-tag {
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--avd-surface-2);
            color: var(--avd-muted);
            font-size: 0.72rem;
            font-weight: 700;
        }

        .avd-studio-facts dt {
            color: var(--avd-muted);
            font-size: 0.72rem;
            font-weight: 600;
        }

        .avd-studio-facts dd {
            font-size: 0.85rem;
            font-weight: 600;
        }

        .avd-upnext-strip-text {
            display: -webkit-box;
            min-width: 0;
            overflow: hidden;
            font-size: 0.8rem;
            font-weight: 600;
            line-height: 1.3;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        /* --- 1. Featured Up Next Spotlight Card --- */
        .avd-up-next-card {
            background: linear-gradient(135deg, rgba(var(--avd-brand-rgb, 255, 61, 97), 0.08) 0%, rgba(var(--avd-brand-rgb, 255, 61, 97), 0.02) 60%, rgba(255, 255, 255, 0.02) 100%) !important;
            border: 1px solid rgba(var(--avd-brand-rgb, 255, 61, 97), 0.26) !important;
            border-radius: 16px !important;
            padding: 0.9rem 1.1rem !important;
            transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.28s ease, box-shadow 0.28s ease !important;
            position: relative !important;
            overflow: hidden !important;
        }
        .avd-up-next-card:hover {
            border-color: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.55) !important;
            box-shadow: 0 12px 28px -6px rgba(0, 0, 0, 0.55), 0 0 24px -4px rgba(var(--avd-brand-rgb, 255, 61, 97), 0.3) !important;
            transform: translateY(-2px) !important;
        }
        .avd-up-next-content {
            display: flex !important;
            align-items: center !important;
            gap: 1.1rem !important;
            min-width: 0 !important;
        }
        .avd-up-next-thumb {
            position: relative !important;
            flex-shrink: 0 !important;
            width: 220px !important;
            max-width: 240px !important;
            aspect-ratio: 16 / 9 !important;
            border-radius: 10px !important;
            overflow: hidden !important;
            background: rgba(0, 0, 0, 0.35) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3) !important;
        }
        .avd-up-next-thumb img,
        .avd-up-next-thumb image-loaded,
        .avd-up-next-thumb .avd-image-loaded {
            display: block !important;
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }
        .avd-up-next-card:hover .avd-up-next-thumb img {
            transform: scale(1.05) !important;
        }
        .avd-up-next-body {
            flex: 1 1 auto !important;
            min-width: 0 !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 0.25rem !important;
        }
        .avd-up-next-title {
            font-size: 1.02rem !important;
            font-weight: 800 !important;
            line-height: 1.35 !important;
            color: var(--avd-text, #fff) !important;
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important;
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
            transition: color 0.2s ease !important;
        }
        .avd-up-next-card:hover .avd-up-next-title {
            color: var(--avd-brand, #ff3d61) !important;
        }
        .avd-up-next-cta .btn {
            box-shadow: 0 4px 14px rgba(var(--avd-brand-rgb, 255, 61, 97), 0.35) !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease !important;
        }
        .avd-up-next-card:hover .avd-up-next-cta .btn {
            transform: scale(1.04) !important;
            box-shadow: 0 6px 18px rgba(var(--avd-brand-rgb, 255, 61, 97), 0.5) !important;
        }

        /* --- 2. Wide / Below-Player Layout: Multi-Column Responsive Grid --- */
        body.theater-mode .avd-watch-sidebar-col .avd-related-list,
        .avd-watch-sidebar-col.col-12 .avd-related-list,
        .avd-watch-layout[data-avd-watch-layout="theater_cinema"] .avd-related-list,
        .avd-watch-layout[data-avd-watch-layout="floating_focus"] .avd-related-list {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(255px, 1fr)) !important;
            gap: 1.25rem 1rem !important;
            width: 100% !important;
        }

        /* Vertical Card in Wide Mode */
        body.theater-mode .avd-watch-sidebar-col .suggested_video.avd-related-card,
        .avd-watch-sidebar-col.col-12 .suggested_video.avd-related-card,
        .avd-watch-layout[data-avd-watch-layout="theater_cinema"] .suggested_video.avd-related-card,
        .avd-watch-layout[data-avd-watch-layout="floating_focus"] .suggested_video.avd-related-card {
            display: flex !important;
            flex-direction: column !important;
            grid-template-columns: none !important;
            background: var(--avd-surface-card, rgba(255, 255, 255, 0.025)) !important;
            border: 1px solid var(--avd-border, rgba(255, 255, 255, 0.07)) !important;
            border-radius: 14px !important;
            padding: 0.55rem !important;
            margin-bottom: 0 !important;
            height: 100% !important;
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.25s ease, border-color 0.25s ease, background 0.25s ease !important;
        }

        body.theater-mode .avd-watch-sidebar-col .suggested_video.avd-related-card:hover,
        .avd-watch-sidebar-col.col-12 .suggested_video.avd-related-card:hover,
        .avd-watch-layout[data-avd-watch-layout="theater_cinema"] .suggested_video.avd-related-card:hover,
        .avd-watch-layout[data-avd-watch-layout="floating_focus"] .suggested_video.avd-related-card:hover {
            transform: translateY(-4px) !important;
            background: var(--avd-surface-hover, rgba(255, 255, 255, 0.05)) !important;
            border-color: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.45) !important;
            box-shadow: 0 14px 28px -6px rgba(0, 0, 0, 0.55), 0 0 18px -2px rgba(var(--avd-brand-rgb, 255, 61, 97), 0.25) !important;
        }

        body.theater-mode .avd-watch-sidebar-col .suggested_video.avd-related-card .avd-thumb,
        .avd-watch-sidebar-col.col-12 .suggested_video.avd-related-card .avd-thumb,
        .avd-watch-layout[data-avd-watch-layout="theater_cinema"] .suggested_video.avd-related-card .avd-thumb,
        .avd-watch-layout[data-avd-watch-layout="floating_focus"] .suggested_video.avd-related-card .avd-thumb {
            width: 100% !important;
            max-width: 100% !important;
            aspect-ratio: 16 / 9 !important;
            border-radius: 10px !important;
            flex-shrink: 0 !important;
            position: relative !important;
            overflow: hidden !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25) !important;
        }

        .avd-watch-sidebar-col.col-12 .suggested_video.avd-related-card .avd-related-info,
        .avd-watch-layout[data-avd-watch-layout="theater_cinema"] .suggested_video.avd-related-card .avd-related-info,
        .avd-watch-layout[data-avd-watch-layout="floating_focus"] .suggested_video.avd-related-card .avd-related-info {
            padding: 0.5rem 0.2rem 0.2rem !important;
            display: flex !important;
            flex-direction: column !important;
            flex-grow: 1 !important;
        }

        .avd-watch-sidebar-col.col-12 .suggested_video.avd-related-card .avd-related-title,
        .avd-watch-layout[data-avd-watch-layout="theater_cinema"] .suggested_video.avd-related-card .avd-related-title,
        .avd-watch-layout[data-avd-watch-layout="floating_focus"] .suggested_video.avd-related-card .avd-related-title {
            font-size: 0.92rem !important;
            font-weight: 750 !important;
            line-height: 1.32 !important;
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important;
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
            margin-bottom: 0.3rem !important;
            color: var(--avd-text, #fff) !important;
        }

        /* --- 3. Standard 2-Column Sidebar Layout (Classic Two Column & Studio Dashboard on Desktop) --- */
        @media (min-width: 992px) {
            .avd-watch-sidebar-col:not(.col-12) .avd-related-list {
                display: flex !important;
                flex-direction: column !important;
                gap: 0.65rem !important;
            }

            .avd-watch-sidebar-col:not(.col-12) .suggested_video.avd-related-card {
                display: flex !important;
                flex-direction: row !important;
                grid-template-columns: none !important;
                align-items: flex-start !important;
                gap: 0.75rem !important;
                padding: 0.45rem 0.55rem !important;
                border-radius: 12px !important;
                border: 1px solid transparent !important;
                transition: transform 0.2s ease, background-color 0.2s ease, border-color 0.2s ease !important;
            }

            .avd-watch-sidebar-col:not(.col-12) .suggested_video.avd-related-card:hover {
                background: var(--avd-surface-hover, rgba(255, 255, 255, 0.05)) !important;
                border-color: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.28) !important;
                transform: translateX(3px) !important;
            }

            .avd-watch-sidebar-col:not(.col-12) .suggested_video.avd-related-card .avd-thumb {
                width: 148px !important;
                min-width: 148px !important;
                max-width: 148px !important;
                aspect-ratio: 16 / 9 !important;
                border-radius: 9px !important;
                flex-shrink: 0 !important;
            }

            .avd-watch-sidebar-col:not(.col-12) .suggested_video.avd-related-card .avd-related-info {
                flex: 1 1 auto !important;
                min-width: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                gap: 0.18rem !important;
            }

            .avd-watch-sidebar-col:not(.col-12) .suggested_video.avd-related-card .avd-related-title {
                font-size: 0.85rem !important;
                font-weight: 700 !important;
                line-height: 1.28 !important;
                display: -webkit-box !important;
                -webkit-line-clamp: 2 !important;
                -webkit-box-orient: vertical !important;
                overflow: hidden !important;
                color: var(--avd-text, #fff) !important;
            }

            .avd-watch-sidebar-col:not(.col-12) .avd-up-next-thumb {
                width: 135px !important;
                min-width: 135px !important;
                max-width: 135px !important;
            }
            .avd-watch-sidebar-col:not(.col-12) .avd-up-next-cta {
                display: none !important;
            }
        }

        /* --- 4. Responsive Viewports for Mobile / Tablet --- */
        @media (max-width: 991.98px) {
            .avd-related-list {
                display: grid !important;
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)) !important;
                gap: 1rem !important;
                width: 100% !important;
            }
            .suggested_video.avd-related-card {
                display: flex !important;
                flex-direction: column !important;
                grid-template-columns: none !important;
                background: var(--avd-surface-card, rgba(255, 255, 255, 0.025)) !important;
                border: 1px solid var(--avd-border, rgba(255, 255, 255, 0.07)) !important;
                border-radius: 12px !important;
                padding: 0.5rem !important;
            }
            .suggested_video.avd-related-card .avd-thumb {
                width: 100% !important;
                max-width: 100% !important;
                aspect-ratio: 16 / 9 !important;
            }
            .avd-up-next-content {
                flex-direction: column !important;
                align-items: stretch !important;
            }
            .avd-up-next-thumb {
                width: 100% !important;
                max-width: 100% !important;
            }
        }

        @media (max-width: 575.98px) {
            .avd-related-list {
                grid-template-columns: 1fr !important;
            }
        }

        /* Thumbnail Image & Custom Tags Universal Fit */
        .suggested_video.avd-related-card .avd-thumb image-loaded,
        .suggested_video.avd-related-card .avd-thumb .avd-image-loaded,
        .suggested_video.avd-related-card .avd-thumb img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            display: block !important;
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1) !important;
        }

        .suggested_video.avd-related-card:hover .avd-thumb img {
            transform: scale(1.05) !important;
        }

        /* Duration badges: the theme's badge (video.scss: tokens, inline-end corner, no blur). */

        /* Center Play Icon Hover Watermark */
        .avd-thumb-play-center {
            position: absolute !important;
            inset: 0 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background: rgba(0, 0, 0, 0.32) !important;
            color: #ffffff !important;
            font-size: 1.5rem !important;
            opacity: 0 !important;
            pointer-events: none !important;
            transition: opacity 0.22s ease, transform 0.22s ease !important;
            transform: scale(0.85) !important;
            z-index: 3 !important;
        }
        .suggested_video.avd-related-card:hover .avd-thumb-play-center,
        .avd-up-next-card:hover .avd-thumb-play-center {
            opacity: 1 !important;
            transform: scale(1) !important;
        }

        /* Light Theme Styling Adapters */
        [data-bs-theme="light"] .suggested_video.avd-related-card {
            background: #ffffff !important;
            border-color: rgba(0, 0, 0, 0.08) !important;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04) !important;
        }
        [data-bs-theme="light"] .suggested_video.avd-related-card:hover {
            background: #ffffff !important;
            border-color: var(--avd-brand, #ff3d61) !important;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.08) !important;
        }
        [data-bs-theme="light"] .avd-up-next-card {
            background: linear-gradient(135deg, rgba(var(--avd-brand-rgb, 255, 61, 97), 0.06), #ffffff) !important;
            border-color: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.24) !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05) !important;
        }
        [data-bs-theme="light"] .avd-related-title,
        [data-bs-theme="light"] .avd-up-next-title {
            color: #0f172a !important;
        }
        [data-bs-theme="light"] .avd-channel-name,
        [data-bs-theme="light"] .avd-video-meta {
            color: var(--avd-muted, #64748b) !important;
        }

        /* OLED Pure Black Theme Adapter */
        html[data-avd-oled="on"][data-bs-theme="dark"] .suggested_video.avd-related-card {
            background: #080808 !important;
            border-color: #181818 !important;
        }
        html[data-avd-oled="on"][data-bs-theme="dark"] .avd-up-next-card {
            background: #060606 !important;
            border-color: #222222 !important;
        }
    </style>

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
                        <i class="fa-solid fa-gauge-high text-info me-2"></i><span id="avdSpeedHudText">1.0x Speed</span>
                    </div>

                    <!-- Floating Aspect Ratio HUD Overlay -->
                    <div class="avd-aspect-hud" id="avdAspectHud" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) scale(0.9); opacity: 0; pointer-events: none; z-index: 1050; background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.25); color: #fff; padding: 12px 24px; border-radius: 999px; font-weight: 700; font-size: 1.15rem; transition: transform 0.2s cubic-bezier(0.16,1,0.3,1), opacity 0.2s ease; box-shadow: 0 10px 30px rgba(0,0,0,0.7);">
                        <i class="fa-solid fa-arrows-up-down-left-right text-warning me-2"></i><span id="avdAspectHudText">Fit (16:9)</span>
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
                                <i class="fa-solid fa-play me-1"></i> {{ __('Resume') }}
                            </button>
                            <button type="button" class="btn btn-xs btn-link text-white-50 p-1" onclick="window.avdDismissResume()" title="{{ __('Dismiss') }}">
                                <i class="fa-solid fa-xmark"></i>
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
                            <button type="button" class="btn btn-xs btn-link text-white p-0" onclick="window.scrollTo({top:0, behavior:'smooth'}); window.avdTogglePip(false);" title="{{ __('Expand to Full Player') }}">
                                <i class="fa-solid fa-up-right-and-down-left-from-center"></i>
                            </button>
                            <button type="button" class="btn btn-xs btn-link text-white-50 p-0" onclick="window.avdTogglePip(false)" title="{{ __('Close Mini Player') }}">
                                <i class="fa-solid fa-xmark"></i>
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
                            <h3 class="h4 fw-bold text-white mb-2">{{ __('Stream Paused by Cinema Sleep Timer') }}</h3>
                            <p class="text-white-50 small mb-4" style="max-width: 420px; line-height: 1.5;">
                                {{ __('Your scheduled sleep timer reached zero. Video playback and audio were paused automatically to save bandwidth and ensure peaceful sleep.') }}
                            </p>
                            <button type="button" class="btn btn-primary rounded-pill px-4 py-2 fw-bold shadow-lg d-inline-flex align-items-center gap-2" onclick="window.avdResumeFromSleep()">
                                <i class="fa-solid fa-play"></i>
                                <span>{{ __('Resume Playback') }}</span>
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
                                    <i class="fa-solid fa-arrow-up me-1"></i> {{ __('Back to Video') }}
                                </button>
                                <span class="text-white fw-bold text-truncate small d-none d-sm-inline" style="max-width: 450px;">
                                    {{ $video->title }}
                                </span>
                            </div>
                            <div class="d-flex align-items-center gap-1.5">
                                <button type="button" class="btn btn-xs btn-outline-light rounded-pill px-2 py-1 avd-lights-out-btn" onclick="window.avdToggleLightsOut()" title="{{ __('Lights Out (L)') }}">
                                    <i class="fa-solid fa-moon"></i>
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-light rounded-pill px-2 py-1" onclick="window.avdToggleZenMode()" title="{{ __('Zen Mode (Shift+F)') }}">
                                    <i class="fa-regular fa-eye-slash"></i>
                                </button>
                                <button type="button" class="btn btn-xs btn-outline-light rounded-pill px-2 py-1" onclick="window.avdReloadPlayer()" title="{{ __('Reload Stream') }}">
                                    <i class="fa-solid fa-rotate-right"></i>
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
                                <button type="button" class="avd-dock-btn avd-dock-btn-tools-highlight d-md-none" onclick="window.avdOpenMobileTools && window.avdOpenMobileTools();" title="{{ __('Cinema Suite & Tools') }}">
                                    <i class="fa-solid fa-sliders text-primary"></i>
                                    <span>{{ __('Tools') }}</span>
                                    <span class="badge rounded-pill bg-primary text-white" style="font-size: 0.62rem; padding: 0.2em 0.5em;">PRO</span>
                                </button>
                                @if($watchTheaterEnabled)
                                <button type="button" class="avd-dock-btn" data-theater-toggle aria-pressed="false" onclick="window.AvdAudio && window.AvdAudio.playWhoosh()" title="{{ __('Theater Cinema Mode (T)') }}">
                                    <i class="fa-solid fa-expand"></i>
                                </button>
                                <button type="button" class="avd-dock-btn avd-lights-out-btn" id="avdLightsOutBtn" onclick="window.avdToggleLightsOut(); window.AvdAudio && window.AvdAudio.playWhoosh();" title="{{ __('Lights Out Ambient Spotlight (L)') }}">
                                    <i class="fa-solid fa-moon"></i>
                                </button>
                                <button type="button" class="avd-dock-btn" id="avdFullscreenBtn" onclick="window.avdToggleFullscreen()" title="{{ __('True Fullscreen (F)') }}">
                                    <i class="fa-solid fa-maximize"></i>
                                </button>
                                @if($watchFloatingMiniPlayerEnabled)
                                    <button type="button" class="avd-dock-btn" id="avdPipToggle" onclick="window.avdTogglePip()" title="{{ __('Mini Player Floating PiP (P)') }}">
                                        <i class="fa-solid fa-clone"></i>
                                    </button>
                                @endif
                                @if($watchPlayerAmbientGlowEnabled)
                                <button type="button" class="avd-dock-btn active" id="avdAmbilightToggle" aria-pressed="true" onclick="window.avdToggleAmbilight()" title="{{ __('Ambient Backlight Halo (A)') }}">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                </button>
                                @endif
                                <button type="button" class="avd-dock-btn" id="avdAspectBtn" onclick="window.avdCycleAspectRatio()" title="{{ __('Cinema Aspect: 16:9 / 21:9 / Fill / Zoom (W)') }}">
                                    <i class="fa-solid fa-arrows-up-down-left-right"></i>
                                    <span id="avdAspectLabel">16:9</span>
                                </button>
                                <button type="button" class="avd-dock-btn" id="avdFilterBtn" onclick="window.avdCycleVideoFilter()" title="{{ __('Cinema Color Grading: Vivid / 35mm / Cyberpunk / OLED (C)') }}">
                                    <i class="fa-solid fa-palette text-primary"></i>
                                </button>
                                <button type="button" class="avd-dock-btn" id="avdSleepTimerBtn" onclick="window.avdToggleSleepTimerModal()" title="{{ __('Cinema Sleep Timer (Z)') }}">
                                    <i class="fa-solid fa-clock"></i>
                                    <span id="avdSleepTimerLabel">{{ __('Sleep') }}</span>
                                </button>
                                @endif
                            </div>

                            <!-- Subtle Visual Divider between Primary & Secondary Clusters -->
                            <div class="avd-dock-divider d-none d-sm-block"></div>

                            <!-- Right Cluster: Actions & Advanced Cinema Tools -->
                            <div class="avd-dock-cluster avd-dock-secondary ms-auto">
                                <!-- Stream Reload (Upload18 recovery button) -->
                                <button type="button" class="avd-dock-btn" onclick="window.avdReloadPlayer()" title="{{ __('Reload Player Stream') }}">
                                    <i class="fa-solid fa-rotate-right"></i>
                                </button>

                                <!-- Mirror Flip View (100% works on Upload18 iframe) -->
                                <button type="button" class="avd-dock-btn" id="avdMirrorBtn" onclick="window.avdToggleMirror()" title="{{ __('Mirror Horizontal Flip (M)') }}">
                                    <i class="fa-solid fa-arrows-split-up-and-left"></i>
                                </button>

                                <!-- Pure Zen Mode -->
                                <button type="button" class="avd-dock-btn" id="avdZenBtn" onclick="window.avdToggleZenMode()" title="{{ __('Zen Focus Immersion (Shift+F)') }}">
                                    <i class="fa-regular fa-eye-slash"></i>
                                </button>

                                <!-- Quick Share Video Link -->
                                <button type="button" class="avd-dock-btn" onclick="window.avdQuickShare()" title="{{ __('Share Video Link') }}">
                                    <i class="fa-solid fa-share-nodes"></i>
                                </button>

                                @if($watchShortcutsEnabled)
                                <button type="button" class="avd-dock-btn d-none d-md-inline-flex" onclick="var o=document.getElementById('shortcuts-overlay'); if(o){ o.classList.add('active'); window.AvdAudio && window.AvdAudio.playClick(); }" title="{{ __('Keyboard Shortcuts (?)') }}">
                                    <i class="fa-regular fa-keyboard"></i>
                                </button>
                                @endif

                                <!-- More Tools & Settings Dropdown (Pops upwards on desktop, opens Bottom Sheet on mobile) -->
                                <div class="dropdown dropup d-inline-block">
                                    <button type="button" class="avd-dock-btn dropdown-toggle" id="avdMoreToolsBtn" onclick="if(window.innerWidth < 768){ window.avdOpenMobileTools && window.avdOpenMobileTools(); return false; }" data-bs-toggle="dropdown" data-bs-placement="top" data-bs-display="static" aria-expanded="false" title="{{ __('More Cinema Tools & Controls') }}">
                                        <i class="fa-solid fa-sliders"></i>
                                        <span>{{ __('Tools') }}</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-lg avd-cinema-dropdown-menu" aria-labelledby="avdMoreToolsBtn" style="width: min(310px, calc(100vw - 1rem)); min-width: 0; z-index: 1055;">
                                        <li class="dropdown-header text-uppercase fw-bold small text-muted px-3 py-1.5 d-flex align-items-center justify-content-between">
                                            <span><i class="fa-solid fa-clapperboard me-1 text-primary"></i>{{ __('Cinema Suite') }}</span>
                                            <span class="badge rounded-pill bg-body-secondary text-muted font-monospace" id="avdPlayerTypeBadge" style="font-size: 0.65rem;">Auto</span>
                                        </li>

                                        <!-- Real-Time Visual Grading / Cinema Color Filters (Works 100% on Upload18 Iframe!) -->
                                        <li class="px-3 py-2">
                                            <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                <span class="small fw-semibold text-muted d-flex align-items-center gap-1">
                                                    <i class="fa-solid fa-palette text-primary"></i>{{ __('Color Grade Filter') }}
                                                </span>
                                                <span class="badge bg-primary text-white font-monospace" id="avdCurrentFilterBadge" style="font-size: 0.68rem;">Normal</span>
                                            </div>
                                            <div class="d-flex flex-wrap gap-1">
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip active btn-primary px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('normal', this)">{{ __('Normal') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('vivid', this)">{{ __('Vivid HDR') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('cinema', this)">{{ __('35mm Warm') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('cyberpunk', this)">{{ __('Cyberpunk') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('oled', this)">{{ __('OLED Dark') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('noir', this)">{{ __('Noir B&W') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('night', this)" title="{{ __('Eye-Care Warm Blue Light Filter (Shift+N)') }}">🌙 {{ __('Night Shift') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-filter-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetVideoFilter('anime', this)">✨ {{ __('Anime Pop') }}</button>
                                            </div>
                                        </li>

                                        <!-- Video Frame Rotation & Vertical Video Mode (100% works on Upload18 iframe!) -->
                                        <li class="px-3 py-1.5">
                                            <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                <span class="small fw-semibold text-muted d-flex align-items-center gap-1">
                                                    <i class="fa-solid fa-arrows-rotate text-info"></i>{{ __('Rotate Video Frame (O)') }}
                                                </span>
                                                <span class="badge bg-secondary-subtle text-secondary font-monospace" id="avdCurrentRotationBadge" style="font-size: 0.68rem;">0°</span>
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
                                                    <i class="fa-solid fa-sun text-warning"></i>{{ __('Screen Brightness') }}
                                                </span>
                                                <span class="badge bg-secondary-subtle text-secondary font-monospace" id="avdCurrentBrightBadge" style="font-size: 0.68rem;">Normal</span>
                                            </div>
                                            <div class="d-flex align-items-center gap-1">
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-bright-chip active btn-primary px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetBrightness('normal', this)">{{ __('Normal') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-bright-chip px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetBrightness('boost', this)">+15%</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-bright-chip px-2 py-0.5 flex-fill text-center" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetBrightness('dim', this)">-15%</button>
                                            </div>
                                        </li>

                                        @if($watchPlayerAmbientGlowEnabled)
                                        <!-- Ambilight Halo Glow Modes (Works 100% on Upload18 iframe!) -->
                                        <li class="px-3 py-1.5">
                                            <div class="d-flex align-items-center justify-content-between mb-1.5">
                                                <span class="small fw-semibold text-muted d-flex align-items-center gap-1">
                                                    <i class="fa-solid fa-wand-magic-sparkles text-primary"></i>{{ __('Ambilight Mode (A)') }}
                                                </span>
                                                <span class="badge bg-primary text-white font-monospace" id="avdCurrentAmbiBadge" style="font-size: 0.68rem;">Theme</span>
                                            </div>
                                            <div class="d-flex flex-wrap gap-1">
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-ambi-chip active btn-primary px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetAmbiMode('theme', this)">{{ __('Theme Sync') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-ambi-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetAmbiMode('pulse', this)">{{ __('Pulse') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-ambi-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetAmbiMode('neon', this)">{{ __('Neon Aura') }}</button>
                                                <button type="button" class="btn btn-xs btn-outline-secondary avd-ambi-chip px-2 py-0.5" style="font-size: 11px; border-radius: 8px;" onclick="window.avdSetAmbiMode('warm', this)">{{ __('Warm Hearth') }}</button>
                                            </div>
                                        </li>
                                        @endif

                                        <li class="dropdown-divider my-1"></li>

                                        <!-- Mirror Flip & Zen Focus Options (100% works on Upload18 iframe) -->
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleMirror()">
                                                <i class="fa-solid fa-arrows-split-up-and-left text-info" style="width: 18px;"></i>
                                                <span class="flex-grow-1">{{ __('Mirror Flip View') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">M</span>
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleZenMode()">
                                                <i class="fa-regular fa-eye-slash text-warning" style="width: 18px;"></i>
                                                <span class="flex-grow-1">{{ __('Zen Focus Immersion') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">Shift+F</span>
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleFullscreen()">
                                                <i class="fa-solid fa-maximize text-success" style="width: 18px;"></i>
                                                <span class="flex-grow-1">{{ __('True Fullscreen') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">F</span>
                                            </button>
                                        </li>
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdOpenEmbedModal()">
                                                <i class="fa-solid fa-code text-primary" style="width: 18px;"></i>
                                                <span class="flex-grow-1">{{ __('Embed Video Player') }}</span>
                                                <span class="badge bg-primary-subtle text-primary small">&lt;/&gt;</span>
                                            </button>
                                        </li>

                                        <li class="dropdown-divider my-1"></li>

                                        <!-- Timestamp Quick-Notes Scratchpad (Always works) -->
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleNotes()">
                                                <i class="fa-regular fa-note-sticky text-warning" style="width: 18px;"></i>
                                                <span class="flex-grow-1">{{ __('Quick-Notes Scratchpad') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">N</span>
                                            </button>
                                        </li>

                                        <!-- Video details -->
                                        <li>
                                            <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleTelemetryModal()">
                                                <i class="fa-solid fa-circle-info text-primary" style="width: 18px;"></i>
                                                <span class="flex-grow-1">{{ __('Video details') }}</span>
                                                <span class="badge bg-secondary-subtle text-secondary small">I</span>
                                            </button>
                                        </li>

                                        <!-- HTML5-Only Section (Auto-revealed ONLY when native HTML5 video element is active) -->
                                        <div id="avdHtml5OnlyTools" class="d-none">
                                            <li class="dropdown-divider my-1"></li>
                                            <li class="px-3 py-1 text-uppercase fw-bold text-muted" style="font-size: 0.65rem;">
                                                {{ __('HTML5 Stream Controls') }}
                                            </li>

                                            <!-- Snapshot Frame Capture -->
                                            <li id="avdMenuSnapshotItem">
                                                <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdCaptureFrame()">
                                                    <i class="fa-solid fa-camera text-info" style="width: 18px;"></i>
                                                    <span class="flex-grow-1">{{ __('Capture Snapshot') }}</span>
                                                    <span class="badge bg-secondary-subtle text-secondary small">S</span>
                                                </button>
                                            </li>

                                            <!-- Video Loop Toggle -->
                                            <li id="avdMenuLoopItem">
                                                <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleLoop()">
                                                    <i class="fa-solid fa-repeat text-success" style="width: 18px;"></i>
                                                    <span class="flex-grow-1">{{ __('Infinite Loop') }}</span>
                                                    <span class="badge bg-secondary-subtle text-secondary small">R</span>
                                                </button>
                                            </li>

                                            <!-- Audio Boost Equalizer -->
                                            <li id="avdMenuAudioItem">
                                                <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdCycleAudioBoost()">
                                                    <i class="fa-solid fa-headphones text-primary" style="width: 18px;"></i>
                                                    <span class="flex-grow-1" id="avdMenuAudioLabel">{{ __('Audio EQ Boost') }}</span>
                                                    <span class="badge bg-secondary-subtle text-secondary small">B</span>
                                                </button>
                                            </li>

                                            <!-- Audio Spectrum Visualizer -->
                                            <li id="avdMenuVisualizerItem">
                                                <button type="button" class="dropdown-item py-2 px-3 d-flex align-items-center gap-2" onclick="window.avdToggleVisualizer()">
                                                    <i class="fa-solid fa-chart-simple text-danger" style="width: 18px;"></i>
                                                    <span class="flex-grow-1">{{ __('Spectrum Visualizer') }}</span>
                                                    <span class="badge bg-secondary-subtle text-secondary small">V</span>
                                                </button>
                                            </li>
                                        </div>

                                        <!-- Iframe Player Info Callout -->
                                        <li id="avdIframeHelperNotice" class="px-3 py-2">
                                            <div class="p-2 rounded-3 text-muted small" style="background: rgba(255, 255, 255, 0.04); font-size: 0.72rem; line-height: 1.4;">
                                                <i class="fa-solid fa-circle-check text-success me-1"></i>
                                                {{ __('Upload18 Stream Active. Internal playback speed, volume & quality are managed directly inside the video player frame.') }}
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
                        <span>{{trans_choice('views', $video->views, ['count' => number_format($video->views)])}}</span>
                        <span aria-hidden="true">&middot;</span>
                        <span>{{ ($video->published_at ?? $video->created_at)?->translatedFormat('d F Y') ?? '' }}</span>
                    </div>

                    @if($watchActionControlsEnabled)
                    @auth
                        <div class="avd-action-row col-12 col-sm-auto">
                            @if($watchReactionsEnabled)
                            <interaction-buttons
                                model="{{get_class($video)}}"
                                target="{{$video->id}}"
                                likes="{{$video->likes_count}}"
                                dislikes="{{$video->dislikes_count}}"
                                liked="{{$video->liked_by_auth_user}}"
                                disliked="{{$video->disliked_by_auth_user}}"
                                show-count="{{!$video->show_likes}}"
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
                        <div class="avd-action-row col-12 col-sm-auto">
                            @if($watchReactionsEnabled)
                            <div class="d-flex justify-content-between rounded-pill bg-body-element border">
                                <button
                                    type="button"
                                    tabindex="0"
                                    class="btn border-0 text-body px-3 rounded-start-pill"
                                    data-bs-toggle="popover"
                                    data-bs-placement="left"
                                    data-bs-title="{{__('Like this video ?')}}"
                                    data-bs-trigger="focus"
                                    data-bs-html="true"
                                    data-bs-content="{{ __('Sign in to make your opinion count.') }}<hr><a href='{{ route('login') }}' class='btn btn-primary btn-sm'>{{__('Sign In')}}</a>"
                                >
                                    <span class="d-flex gap-1 align-items-center">
                                        <i class="fa-regular fa-thumbs-up"></i>
                                        @if($video->show_likes && $video->likes_count)
                                            <span>@abbreviate($video->likes_count)</span>
                                        @endif
                                    </span>
                                </button>
                                <div class="vr h-75 my-auto"></div>
                                <button
                                    type="button"
                                    tabindex="0"
                                    class="btn border-0 text-body px-3 rounded-end-pill"
                                    data-bs-toggle="popover"
                                    data-bs-placement="right"
                                    data-bs-title="{{ __("Don't like this video ?") }}"
                                    data-bs-trigger="focus"
                                    data-bs-html="true"
                                    data-bs-content="{{ __('Sign in to make your opinion count.') }}<hr><a href='{{ route('login') }}' class='btn btn-primary btn-sm'>{{__('Sign In')}}</a>"
                                >
                                    <span class="d-flex gap-1 align-items-center">
                                        <i class="fa-regular fa-thumbs-down"></i>
                                        @if($video->show_likes && $video->dislikes_count)
                                            <span>@abbreviate($video->dislikes_count)</span>
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
                                data-bs-title="{{ __('Save to playlist ?') }}"
                                data-bs-trigger="focus"
                                data-bs-html="true"
                                data-bs-content="{{ __('Sign in to save this video to Watch Later or a playlist.') }}<hr><div class='d-flex gap-2'><a href='{{ route('login') }}' class='btn btn-primary btn-sm'>{{__('Sign In')}}</a><a href='{{ route('registration') }}' class='btn btn-outline-secondary btn-sm'>{{__('Sign Up')}}</a></div>"
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
                                data-bs-title="{{ __('Need to report this video ?') }}"
                                data-bs-trigger="focus"
                                data-bs-html="true"
                                data-bs-content="{{ __('Sign in to report inappropriate content.') }}<hr><a href='{{ route('login') }}' class='btn btn-primary btn-sm'>{{__('Sign In')}}</a>"
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
                <div class="avd-channel-panel avd-video-author-panel mt-4 d-flex gap-3 justify-content-between align-items-center">
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
                                    @endphp
                                    <small class="text-muted d-block">{{trans_choice('subscribers', $vSubCount, ['count' => \App\Helpers\Number::humanize($vSubCount)])}}</small>
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
                                data-bs-title="{{__('Want to subscribe to this channel ?')}}"
                                data-bs-trigger="focus"
                                data-bs-html="true"
                                data-bs-content="{{ __('Sign in to subscribe to this channel.') }}<hr><a href='{{ route('login') }}' class='btn btn-primary btn-sm'>{{__('Sign In')}}</a>"
                            >
                                <span class="text-sm"><i class="fa-solid fa-user-plus me-1"></i>{{ __('Subscribe') }}</span>
                            </button>
                        @elseif($video->user && auth()->user()->isNot($video->user))
                            <subscribe-button
                                @if(!Auth()->user()->isSubscribeTo($video->user)) is-subscribe @endif
                                user="{{$video->user->id}}"
                                class="avd-subscribe-host"
                            ></subscribe-button>
                        @endif
                    </div>
                </div>
                @endif

                @if($watchDescriptionEnabled)
                @if($video->description && $video->description_is_long)
                    <div class="avd-description-panel my-4" data-clickable="true" x-data="{ open: {{ $watchDescriptionDefaultOpen ? 'true' : 'false' }} }" @click="open=!open">
                        <template x-if="open">
                            <p class="mb-0">{!! nl2br($video->parsed_description) !!}</p>
                        </template>
                        <template x-if="!open">
                            <p class="mb-0">{!! nl2br($video->short_description) !!}</p>
                        </template>
                        <div class="text-primary fw-bold mt-2" x-text="open ? '{{__('Show less')}}' : '{{__('Show more')}}'"></div>
                    </div>
                @elseif($video->description)
                    <div class="avd-description-panel my-4">
                        {!! nl2br($video->parsed_description) !!}
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

                @if($video->category || $video->language)
                    <div class="avd-chip-list">
                        @if($video->category)
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
                        <section class="avd-up-next-card mx-2 mx-sm-0 mb-4" aria-label="{{__('Next Video')}}">
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
                                        <image-loaded source="{{$upNextVideo->thumbnail_url}}" title="{{$effectiveNextVideo->title}}"></image-loaded>
                                        <span class="avd-duration">{{$upNextVideo->duration}}</span>
                                        <div class="avd-thumb-play-center">
                                            <i class="fa-solid fa-play"></i>
                                        </div>
                                    </div>
                                    <div class="avd-up-next-body">
                                        <h4 class="avd-up-next-title fw-bold mb-1" title="{{$effectiveNextVideo->title}}">{{$effectiveNextVideo->title}}</h4>
                                        <div class="avd-channel-name text-muted d-flex align-items-center gap-1 mb-1">
                                            <span>{{$upNextVideo->user?->username ?? __('Deleted user')}}</span>
                                            <i class="fa-solid fa-circle-check text-primary" style="font-size: 9px;" title="{{ __('Verified') }}"></i>
                                        </div>
                                        <div class="avd-video-meta text-muted small">
                                            {{trans_choice('views', $upNextVideo->views, ['count' => \App\Helpers\Number::humanize($upNextVideo->views)])}} <span aria-hidden="true">&middot;</span> {{ ($upNextVideo->published_at ?? $upNextVideo->created_at ?? $upNextVideo->date)?->diffForHumans() ?? __('Recently') }}
                                        </div>
                                    </div>
                                    <div class="avd-up-next-cta ms-auto d-none d-md-flex align-items-center pe-2">
                                        <span class="btn btn-primary rounded-pill px-3.5 py-2 fw-bold d-inline-flex align-items-center gap-2 shadow-sm text-nowrap" style="font-size: 0.84rem;">
                                            <i class="fa-solid fa-play"></i>
                                            <span>{{ __('Play Next') }}</span>
                                        </span>
                                    </div>
                                </div>
                            </a>
                        </section>
                    @else
                        <div class="d-flex align-items-center justify-content-between gap-2 px-2 px-sm-0 flex-wrap">
                            <a href="{{$effectiveNextVideo->route}}" class="btn btn-sm btn-primary d-flex align-items-center justify-content-center gap-2">
                                <i class="fa-solid fa-forward-step"></i>
                                <span>{{__('Next Video')}}</span>
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
                                                        <image-loaded source="{{$playlistVideo->thumbnail_url}}" title="{{$playlistVideo->title}}"></image-loaded>
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
                                        <div class="text-truncate"><span class="fw-bold">{{__('Next')}}:</span> {{$effectiveNextVideo->title}}</div>
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
                                    <i class="fa-solid fa-wand-magic-sparkles me-1"></i> {{ __('Curated For You') }}
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

    <!-- Cinema Sleep Timer Popover Modal -->
    <div class="modal fade" id="avdSleepTimerModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(14px);">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 380px;">
            <div class="modal-content text-white border-0 shadow-2xl" style="background: rgba(14, 18, 30, 0.96); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,0.8);">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title d-flex align-items-center gap-2 fw-bold">
                        <i class="fa-solid fa-moon text-info"></i> {{ __('Cinema Sleep Timer') }}
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-white-50 small mb-3">{{ __('Automatically fade out audio and pause video when timer reaches zero.') }}</p>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="0" onclick="window.avdSetSleepTimer(0)">
                            <span><i class="fa-solid fa-ban me-2 text-danger"></i> {{ __('Off (Disabled)') }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadge0"></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="15" onclick="window.avdSetSleepTimer(15)">
                            <span><i class="fa-regular fa-clock me-2 text-info"></i> {{ __('15 Minutes') }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadge15"></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="30" onclick="window.avdSetSleepTimer(30)">
                            <span><i class="fa-regular fa-clock me-2 text-warning"></i> {{ __('30 Minutes') }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadge30"></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="45" onclick="window.avdSetSleepTimer(45)">
                            <span><i class="fa-regular fa-clock me-2 text-success"></i> {{ __('45 Minutes') }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadge45"></span>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white text-start d-flex justify-content-between align-items-center p-2.5 rounded-3 avd-sleep-opt" data-mins="-1" onclick="window.avdSetSleepTimer(-1)">
                            <span><i class="fa-solid fa-film me-2 text-primary"></i> {{ __('End of Video') }}</span>
                            <span class="badge bg-secondary" id="avdSleepBadgeEnd"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Video Timestamp Notes Scratchpad Modal -->
    <div class="modal fade" id="avdNotesModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(14px);">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
            <div class="modal-content text-white border-0 shadow-2xl" style="background: rgba(14, 18, 30, 0.96); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,0.8);">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title d-flex align-items-center gap-2 fw-bold">
                        <i class="fa-regular fa-note-sticky text-warning"></i> {{ __('Video Study Notes & Scratchpad') }}
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-white-50 small">{{ __('Autosaved locally for this video') }}</span>
                        <button type="button" class="btn btn-xs btn-outline-warning rounded-pill px-2.5 py-1" onclick="window.avdInsertNoteTimestamp()">
                            <i class="fa-solid fa-stopwatch me-1"></i> {{ __('Insert Timestamp') }}
                        </button>
                    </div>
                    <textarea id="avdNotesTextarea" class="form-control text-white border-0 shadow-none font-monospace p-3" rows="7" placeholder="{{ __('Type your timestamped notes, scene analysis, or reminders here...') }}" style="background: rgba(255,255,255,0.05); border-radius: 12px; font-size: 0.85rem; resize: vertical;" oninput="window.avdSaveNotes && window.avdSaveNotes()"></textarea>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex justify-content-between">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-info" onclick="window.avdCopyNotes()">
                            <i class="fa-regular fa-copy me-1"></i> {{ __('Copy All') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-white" onclick="window.avdDownloadNotes()">
                            <i class="fa-solid fa-download me-1"></i> {{ __('Export .txt') }}
                        </button>
                    </div>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
                        {{ __('Close') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Video details modal -->
    <div class="modal fade" id="avdTelemetryModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(14px);">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 480px;">
            <div class="modal-content text-white border-0 shadow-2xl" style="background: rgba(14, 18, 30, 0.96); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 18px; box-shadow: 0 20px 40px rgba(0,0,0,0.8);">
                <div class="modal-header border-0 pb-0">
                    <h6 class="modal-title d-flex align-items-center gap-2 fw-bold font-monospace">
                        <i class="fa-solid fa-circle-info text-primary"></i> {{ __('Video details') }}
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body font-monospace" style="font-size: 0.8rem;">
                    <div class="p-2.5 rounded-3 mb-3" style="background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08);">
                        <div class="text-truncate fw-bold text-white mb-1">{{ $video->title }}</div>
                        <div class="text-muted small text-truncate">{{ __('Source') }}</div>
                    </div>
                    <div class="list-group list-group-flush rounded-3 overflow-hidden border border-secondary border-opacity-25" style="background: rgba(0,0,0,0.3);">
                        @forelse($videoSourceDetails as $detail)
                            <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center gap-3 py-2 px-3 border-secondary border-opacity-25">
                                <span>{{ $detail['label'] }}:</span>
                                <strong class="text-white text-break text-end">{{ $detail['value'] }}</strong>
                            </div>
                        @empty
                            <div class="list-group-item bg-transparent text-white-50 py-2 px-3 border-secondary border-opacity-25">
                                {{ __('Unavailable') }}
                            </div>
                        @endforelse
                        <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center py-2 px-3 border-secondary border-opacity-25">
                            <span>{{ __('Cinema Color Grading') }}:</span>
                            <strong class="text-info" id="avdTelemFilter">{{ __('Normal') }}</strong>
                        </div>
                        <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center py-2 px-3 border-secondary border-opacity-25">
                            <span>{{ __('Aspect Ratio') }}:</span>
                            <strong class="text-warning" id="avdTelemAspect">16:9 {{ __('Standard') }}</strong>
                        </div>
                        <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center py-2 px-3 border-secondary border-opacity-25">
                            <span>{{ __('Mirror Flip View') }}:</span>
                            <span class="badge bg-secondary-subtle text-secondary" id="avdTelemMirror">{{ __('Standard') }}</span>
                        </div>
                        <div class="list-group-item bg-transparent text-white-50 d-flex justify-content-between align-items-center py-2 px-3 border-secondary border-opacity-25">
                            <span>{{ __('Player Ambient Glow') }}:</span>
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

    <!-- Direct Embed Code Generator Modal -->
    <div class="modal fade" id="avdEmbedModal" tabindex="-1" aria-labelledby="avdEmbedModalLabel" aria-hidden="true" style="backdrop-filter: blur(14px);">
        <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
            <div class="modal-content rounded-4 border-0 shadow-lg" style="background: var(--avd-surface, #1e293b); color: var(--avd-text, #f8fafc); border: 1px solid var(--avd-border, rgba(255,255,255,0.12));">
                <div class="modal-header border-bottom border-secondary border-opacity-25 px-4 py-3">
                    <h5 class="modal-title h6 fw-bold d-flex align-items-center gap-2" id="avdEmbedModalLabel">
                        <i class="fa-solid fa-code text-primary"></i> {{ __('Embed Video Player') }}
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">{{ __('Copy the responsive HTML code below to embed this video player on external blogs, forums, or websites:') }}</p>
                    <textarea id="avdEmbedCodeTextarea" class="form-control font-monospace text-sm p-3 mb-3" rows="4" readonly style="background: rgba(0,0,0,0.25); border: 1px solid var(--avd-border, rgba(255,255,255,0.15)); border-radius: 10px; color: var(--avd-text, #f8fafc); font-size: 0.8rem;"></textarea>
                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-sm btn-primary rounded-pill px-3 fw-bold" onclick="window.avdCopyEmbedCode()">
                            <i class="fa-regular fa-copy me-1"></i> {{ __('Copy Embed Code') }}
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Cinema Suite Bottom Sheet (Specifically optimized for iOS & Android) -->
    <div class="offcanvas offcanvas-bottom avd-mobile-sheet d-md-none" tabindex="-1" id="avdMobileToolsSheet" aria-labelledby="avdMobileToolsSheetLabel">
        <div class="avd-sheet-drag-handle mx-auto my-2"></div>
        <div class="offcanvas-header border-bottom border-secondary border-opacity-25 px-4 py-2">
            <h6 class="offcanvas-title fw-bold text-white d-flex align-items-center gap-2" id="avdMobileToolsSheetLabel">
                <i class="fa-solid fa-clapperboard text-primary"></i> {{ __('Cinema Suite & Tools') }}
            </h6>
            <span class="badge rounded-pill bg-body-secondary text-muted font-monospace" id="avdMobilePlayerTypeBadge" style="font-size: 0.68rem;">Upload18</span>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools();" aria-label="{{ __('Close') }}"></button>
        </div>
        <div class="offcanvas-body px-4 py-3" style="max-height: 75vh; overflow-y: auto; -webkit-overflow-scrolling: touch;">
            <!-- Real-Time Color Grade Filter Chips -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-bold text-white-50 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-palette text-primary me-1"></i> {{ __('Color Grade Filter Shaders') }}
                    </span>
                    <span class="badge bg-primary text-white font-monospace" id="avdMobileFilterBadge" style="font-size: 0.7rem;">Normal</span>
                </div>
                <div class="d-grid gap-1.5 avd-mobile-filter-grid" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip active btn-primary py-2" onclick="window.avdSetVideoFilter('normal', this)">{{ __('Normal') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('vivid', this)">{{ __('Vivid HDR') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('cinema', this)">{{ __('35mm Warm') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('cyberpunk', this)">{{ __('Cyberpunk') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('oled', this)">{{ __('OLED Dark') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('noir', this)">{{ __('Noir B&W') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('night', this)">🌙 {{ __('Night') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary avd-mob-filter-chip py-2" onclick="window.avdSetVideoFilter('anime', this)">✨ {{ __('Anime') }}</button>
                </div>
            </div>

            <!-- Frame Rotation -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-bold text-white-50 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-arrows-rotate text-info me-1"></i> {{ __('Video Frame Rotation') }}
                    </span>
                    <span class="badge bg-secondary-subtle text-secondary font-monospace" id="avdMobileRotateBadge">0°</span>
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
                        <i class="fa-solid fa-sun text-warning me-1"></i> {{ __('Display Brightness') }}
                    </span>
                    <span class="badge bg-secondary-subtle text-secondary font-monospace" id="avdMobileBrightBadge">Normal</span>
                </div>
                <div class="row g-1">
                    <div class="col-4"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-bright-chip active btn-primary py-2" onclick="window.avdSetBrightness('normal', this)">{{ __('Normal') }}</button></div>
                    <div class="col-4"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-bright-chip py-2" onclick="window.avdSetBrightness('boost', this)">+15%</button></div>
                    <div class="col-4"><button type="button" class="btn btn-sm btn-outline-secondary w-100 avd-mob-bright-chip py-2" onclick="window.avdSetBrightness('dim', this)">-15%</button></div>
                </div>
            </div>

            @if($watchPlayerAmbientGlowEnabled)
            <!-- Ambilight Halo Glow -->
            <div class="mb-3">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-bold text-white-50 text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-wand-magic-sparkles text-primary me-1"></i> {{ __('Ambilight Halo Mode') }}
                    </span>
                    <span class="badge bg-primary text-white font-monospace" id="avdMobileAmbiBadge">Theme</span>
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
                        <i class="fa-solid fa-arrows-split-up-and-left text-info" style="width: 20px;"></i>
                        <span>{{ __('Mirror Horizontal Flip') }}</span>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary">Toggle</span>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools(); window.avdToggleLightsOut();">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-moon text-info" style="width: 20px;"></i>
                        <span>{{ __('Lights Out Ambient Spotlight') }}</span>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary">{{ __('Spotlight') }}</span>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdToggleZenMode()">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-regular fa-eye-slash text-warning" style="width: 20px;"></i>
                        <span>{{ __('Zen Focus Immersion') }}</span>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary">OLED</span>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdToggleFullscreen()">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-maximize text-success" style="width: 20px;"></i>
                        <span>{{ __('True Fullscreen') }}</span>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary">Fullscreen</span>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools(); window.avdToggleTelemetryModal();">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-circle-info text-primary" style="width: 20px;"></i>
                        <span>{{ __('Video details') }}</span>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary">Inspect</span>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools(); window.avdToggleNotes();">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-regular fa-note-sticky text-warning" style="width: 20px;"></i>
                        <span>{{ __('Quick-Notes Scratchpad') }}</span>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary">Notes</span>
                </button>
                <button type="button" class="list-group-item list-group-item-action bg-transparent text-white d-flex align-items-center justify-content-between py-2.5 px-3 border-secondary border-opacity-25" onclick="window.avdCloseMobileTools && window.avdCloseMobileTools(); window.avdOpenEmbedModal();">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-code text-primary" style="width: 20px;"></i>
                        <span>{{ __('Embed Player Code') }}</span>
                    </div>
                    <span class="badge bg-secondary-subtle text-secondary">&lt;/&gt;</span>
                </button>
            </div>
        </div>
    </div>

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
        function time(timecode) {
            const video = document.querySelector("video");

            if (!video) {
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
    .avd-related-section,
    .avd-related-list {
        content-visibility: visible;
        contain-intrinsic-size: none;
        contain: layout style paint;
    }
    #comments_area,
    .avd-mobile-comments,
    .avd-comments-offcanvas {
        content-visibility: visible;
        contain-intrinsic-size: none;
        contain: layout style paint;
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
    .avd-cinema-dock-container {
        width: 100%;
        max-width: 100%;
        position: relative;
        z-index: 20;
    }
    .avd-cinema-dock {
        width: 100%;
        max-width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.35rem;
        padding: 0.35rem 0.65rem;
        border-radius: 999px;
        background: rgba(15, 23, 42, 0.85);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.10);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.12);
        overflow: visible;
        white-space: nowrap;
    }
    .avd-dock-divider {
        width: 1px;
        height: 18px;
        background: rgba(255, 255, 255, 0.12);
        margin: 0 0.2rem;
        flex-shrink: 0;
    }
    @media (max-width: 767.98px) {
        .avd-cinema-dock {
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch !important;
            touch-action: pan-x !important;
            scrollbar-width: none !important;
            -ms-overflow-style: none !important;
            padding: 0.32rem 0.55rem !important;
            gap: 0.38rem !important;
            justify-content: flex-start;
        }
    }
    .avd-cinema-dock::-webkit-scrollbar {
        display: none;
    }
    .avd-dock-cluster {
        display: flex;
        align-items: center;
        gap: 0.3rem;
        flex-shrink: 0;
    }
    .avd-dock-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        min-width: 34px;
        min-height: 34px;
        padding: 0.25rem 0.52rem;
        border-radius: 999px;
        border: 1px solid transparent;
        background: transparent;
        color: var(--avd-muted, #94a3b8);
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.2px;
        transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
        cursor: pointer;
        user-select: none;
        white-space: nowrap;
        text-decoration: none !important;
    }
    .avd-dock-btn:hover {
        background: rgba(255, 255, 255, 0.08);
        color: #ffffff;
        border-color: rgba(255, 255, 255, 0.14);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }
    .avd-dock-btn:active {
        transform: translateY(0) scale(0.97);
    }
    .avd-dock-btn-tools-highlight {
        background: rgba(var(--avd-brand-rgb, 255, 61, 97), 0.18) !important;
        border: 1px solid rgba(var(--avd-brand-rgb, 255, 61, 97), 0.55) !important;
        color: #ffffff !important;
        box-shadow: 0 0 14px rgba(var(--avd-brand-rgb, 255, 61, 97), 0.35) !important;
        font-weight: 800 !important;
    }
    @media (max-width: 767.98px) {
        .avd-dock-btn {
            min-height: 40px !important;
            padding: 0.32rem 0.65rem !important;
        }
    }
    .avd-cinema-dropdown-menu {
        background: rgba(15, 23, 42, 0.96) !important;
        backdrop-filter: blur(20px) !important;
        -webkit-backdrop-filter: blur(20px) !important;
        border: 1px solid rgba(255, 255, 255, 0.14) !important;
        border-radius: 16px !important;
        padding: 0.5rem !important;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.6) !important;
        margin-bottom: 8px !important;
        max-height: 460px;
        max-height: min(460px, calc(100vh - 160px));
        overflow-y: auto !important;
        overscroll-behavior: contain;
        scrollbar-width: thin;
        right: 0 !important;
        left: auto !important;
    }
    .avd-cinema-dropdown-menu::-webkit-scrollbar {
        width: 6px;
    }
    .avd-cinema-dropdown-menu::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 999px;
    }
    @media (max-width: 767.98px) {
        .avd-cinema-dropdown-menu {
            display: none !important;
        }
    }

    /* Mobile Cinema Suite Bottom Sheet for iOS & Android */
    .avd-mobile-sheet {
        background: rgba(14, 18, 30, 0.98) !important;
        backdrop-filter: blur(25px) !important;
        -webkit-backdrop-filter: blur(25px) !important;
        border-top: 1px solid rgba(255, 255, 255, 0.18) !important;
        border-radius: 24px 24px 0 0 !important;
        padding-bottom: calc(24px + env(safe-area-inset-bottom, 12px)) !important;
        z-index: 1095 !important;
        box-shadow: 0 -15px 40px rgba(0, 0, 0, 0.8) !important;
    }
    .avd-sheet-drag-handle {
        width: 44px;
        height: 5px;
        background: rgba(255, 255, 255, 0.28);
        border-radius: 999px;
    }
    [data-bs-theme="light"] .avd-mobile-sheet {
        background: rgba(255, 255, 255, 0.98) !important;
        border-top: 1px solid rgba(15, 23, 42, 0.14) !important;
        color: #0f172a !important;
    }
    [data-bs-theme="light"] .avd-mobile-sheet .text-white {
        color: #0f172a !important;
    }
    [data-bs-theme="light"] .avd-mobile-sheet .text-white-50 {
        color: #64748b !important;
    }
    [data-bs-theme="light"] .avd-sheet-drag-handle {
        background: rgba(15, 23, 42, 0.25);
    }
    .avd-cinema-dropdown-menu .dropdown-item {
        color: #e2e8f0;
        border-radius: 10px;
        font-size: 0.82rem;
        font-weight: 600;
        transition: transform 0.15s ease, background-color 0.15s ease, color 0.15s ease;
    }
    .avd-cinema-dropdown-menu .dropdown-item:hover {
        background: rgba(255, 255, 255, 0.08);
        color: #ffffff;
        transform: translateX(2px);
    }

    /* Light Theme Styling for Cinema Dock & Dropup Menu */
    [data-bs-theme="light"] .avd-cinema-dock {
        background: rgba(255, 255, 255, 0.94) !important;
        border: 1px solid rgba(15, 23, 42, 0.12) !important;
        box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08) !important;
    }
    [data-bs-theme="light"] .avd-dock-divider {
        background: rgba(15, 23, 42, 0.15) !important;
    }
    [data-bs-theme="light"] .avd-dock-btn {
        color: #334155 !important;
    }
    [data-bs-theme="light"] .avd-dock-btn:hover {
        background: rgba(15, 23, 42, 0.06) !important;
        color: #0f172a !important;
    }
    [data-bs-theme="light"] .avd-dock-btn.active {
        color: var(--avd-primary, #ff3d61) !important;
        background: rgba(var(--avd-primary-rgb, 255, 61, 97), 0.12) !important;
        border-color: rgba(var(--avd-primary-rgb, 255, 61, 97), 0.25) !important;
    }
    [data-bs-theme="light"] .avd-cinema-dropdown-menu {
        background: rgba(255, 255, 255, 0.98) !important;
        border: 1px solid rgba(15, 23, 42, 0.14) !important;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.18) !important;
    }
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .dropdown-header {
        color: #64748b !important;
    }
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .dropdown-item {
        color: #0f172a !important;
    }
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .dropdown-item:hover {
        background: rgba(15, 23, 42, 0.06) !important;
        color: #0f172a !important;
    }
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .btn-outline-secondary {
        color: #334155 !important;
        border-color: rgba(15, 23, 42, 0.22) !important;
        background: rgba(15, 23, 42, 0.03) !important;
    }
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .btn-outline-secondary:hover {
        background: rgba(15, 23, 42, 0.08) !important;
        color: #0f172a !important;
    }
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .btn-primary,
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .avd-filter-chip.active,
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .avd-rotate-chip.active,
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .avd-bright-chip.active,
    [data-bs-theme="light"] .avd-cinema-dropdown-menu .avd-ambi-chip.active {
        background: var(--avd-primary, #ff3d61) !important;
        color: #ffffff !important;
        border-color: var(--avd-primary, #ff3d61) !important;
    }

    @keyframes avdPipSlide {
        from { transform: translateY(30px) scale(0.92); opacity: 0; }
        to { transform: translateY(0) scale(1); opacity: 1; }
    }
    @keyframes avdFadeSlideDown {
        from { transform: translateY(-16px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
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

        .avd-mobile-filter-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        }

        .avd-mobile-sheet .btn {
            min-width: 0;
            min-height: 44px;
            width: 100%;
            white-space: normal;
            overflow-wrap: anywhere;
            line-height: 1.2;
            text-align: center;
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
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
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
<script>
    (function(){
        // CSS mini-player state. The provider component mounts asynchronously,
        // so this state is bound to the media node after every DOM update.
        var avdPipAllowed = @json($watchFloatingMiniPlayerEnabled);
        var avdPipShell = null;
        var avdPipWrapper = null;
        var avdPipVideo = null;
        var avdPipFrame = null;
        var avdPipReady = false;
        var avdPipPlaying = false;
        var avdPipManualClosed = false;
        var avdPipManualActive = false;
        var avdPipOriginalMinHeight = '';
        var avdPipTick = 0;
        var avdPipObserver = null;
        var avdPipMutationObserver = null;
        var avdPipMediaSignature = '';

        function avdPipResolveElements() {
            if (!avdPipAllowed) return false;
            avdPipShell = document.getElementById('avdMainPlayerShell');
            avdPipWrapper = document.getElementById('avdPlayerWrapper');
            return !!(avdPipShell && avdPipWrapper);
        }

        function avdPipClearPlaceholder() {
            if (!avdPipWrapper || avdPipWrapper.dataset.pipPlaceholder !== 'true') return;
            avdPipWrapper.style.minHeight = avdPipOriginalMinHeight;
            delete avdPipWrapper.dataset.pipPlaceholder;
        }

        function avdPipSetActive(active, manual) {
            if (!avdPipResolveElements()) return false;
            var rect = avdPipWrapper.getBoundingClientRect();
            if (active) {
                if (!manual && (window.innerWidth < 992 || avdPipManualClosed || !avdPipReady || !avdPipPlaying || rect.bottom > 0)) {
                    return false;
                }
                if (!avdPipShell.classList.contains('avd-pip-active')) {
                    var shellHeight = avdPipWrapper.getBoundingClientRect().height;
                    if (shellHeight > 0) {
                        avdPipOriginalMinHeight = avdPipWrapper.style.minHeight;
                        avdPipWrapper.style.minHeight = shellHeight + 'px';
                        avdPipWrapper.dataset.pipPlaceholder = 'true';
                    }
                    avdPipShell.classList.add('avd-pip-active');
                }
                return true;
            }
            avdPipShell.classList.remove('avd-pip-active');
            avdPipClearPlaceholder();
            return false;
        }

        function avdPipEvaluate() {
            avdPipTick = 0;
            if (!avdPipResolveElements()) return;

            var playerStage = avdPipShell.querySelector('.avd-player-stage');
            if (playerStage && playerStage.dataset.playerState === 'ad') {
                if (avdPipShell.classList.contains('avd-pip-active')) avdPipSetActive(false, false);
                return;
            }

            // Do not read layout on every fast scroll while the async player
            // is still mounting. PiP becomes eligible after media readiness.
            if (!avdPipReady && !avdPipManualActive) return;

            var rect = avdPipWrapper.getBoundingClientRect();
            var inView = rect.top < window.innerHeight && rect.bottom > 0;
            var outAbove = rect.bottom <= 0;
            // A manual mini-player choice must survive scrolling on every
            // breakpoint.  Previously the first scroll past the player reset
            // this flag and the mobile-only guard below immediately closed the
            // floating player, even though the UI still reported it as active.
            if (avdPipManualActive) {
                avdPipSetActive(true, true);
                return;
            }
            if (inView) {
                avdPipManualClosed = false;
                if (avdPipShell.classList.contains('avd-pip-active')) avdPipSetActive(false, false);
                return;
            }
            if (window.innerWidth < 992 || !outAbove || !avdPipReady || !avdPipPlaying || avdPipManualClosed) {
                if (avdPipShell.classList.contains('avd-pip-active') && (window.innerWidth < 992 || !avdPipPlaying)) {
                    avdPipSetActive(false, false);
                }
                return;
            }
            avdPipSetActive(true, false);
        }

        function avdPipRequestUpdate() {
            if (!avdPipTick) avdPipTick = window.requestAnimationFrame(avdPipEvaluate);
        }

        function avdPipMarkReady() {
            avdPipReady = true;
            if (avdPipShell) avdPipShell.dataset.playerReady = 'true';
            avdPipRequestUpdate();
        }

        function avdPipMarkPlaying() {
            avdPipPlaying = true;
            avdPipRequestUpdate();
        }

        function avdPipMarkPaused() {
            avdPipPlaying = false;
            avdPipRequestUpdate();
        }

        function avdPipFrameSource() {
            var source = avdPipFrame && avdPipFrame.getAttribute('src');
            if (!source || !source.trim()) return null;
            try {
                var url = new URL(source, window.location.href);
                return /^(https?:)$/.test(url.protocol) ? url : null;
            } catch (sourceError) { return null; }
        }

        function avdPipBindMedia(records) {
            if (!avdPipResolveElements()) return;
            // Preroll media is a separate layer. Its removal must not reset the
            // retained provider frame or claim that the content is playing.
            var mediaLayer = avdPipShell.querySelector('.avd-player-frame .avd-player-media-layer');
            var nextVideo = mediaLayer && mediaLayer.querySelector('video');
            var nextFrame = mediaLayer && mediaLayer.querySelector('iframe');
            var nextSource = nextVideo && nextVideo.querySelector('source');
            var nextSignature = nextVideo
                ? 'video:' + (nextVideo.getAttribute('src') || nextVideo.querySelector('source')?.getAttribute('src') || '')
                : nextFrame
                    ? 'frame:' + (nextFrame.getAttribute('src') || '')
                    : '';
            var sourceChanged = (records || []).some(function(record) {
                return record.type === 'attributes'
                    && record.attributeName === 'src'
                    && (record.target === nextVideo || record.target === nextFrame || record.target === nextSource);
            });
            if (nextVideo !== avdPipVideo || nextFrame !== avdPipFrame || sourceChanged || nextSignature !== avdPipMediaSignature) {
                avdPipVideo = nextVideo;
                avdPipFrame = nextFrame;
                avdPipMediaSignature = nextSignature;
                avdPipReady = false;
                avdPipPlaying = false;
                avdPipManualActive = false;
                avdPipShell.dataset.playerReady = 'false';
                avdPipSetActive(false, false);
                avdSetPlaybackActive(false);
                if (avdPipVideo && !sourceChanged) {
                    avdPipReady = avdPipVideo.readyState >= 2;
                    avdPipPlaying = !avdPipVideo.paused && !avdPipVideo.ended;
                    avdPipShell.dataset.playerReady = avdPipReady ? 'true' : 'false';
                }
                avdPipRequestUpdate();
            }
        }

        function avdPipInit() {
            if (!avdPipAllowed) return;
            if (!avdPipResolveElements()) return;
            avdPipBindMedia();
            if ('IntersectionObserver' in window) {
                avdPipObserver = new IntersectionObserver(avdPipRequestUpdate, { threshold: [0, 0.1, 1] });
                avdPipObserver.observe(avdPipWrapper);
            }
            if ('MutationObserver' in window) {
                avdPipMutationObserver = new MutationObserver(avdPipBindMedia);
                var observedPlayerFrame = avdPipShell.querySelector('.avd-player-frame');
                if (observedPlayerFrame) {
                    avdPipMutationObserver.observe(observedPlayerFrame, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
                }
            }
            // Capture media events once on the stable shell, including nodes
            // mounted or replaced later. Detached provider nodes are ignored.
            avdPipShell.addEventListener('load', function(event) {
                avdPipBindMedia();
                if (event.target === avdPipFrame && avdPipFrameSource()) avdPipMarkReady();
            }, true);
            ['loadeddata', 'canplay', 'play', 'playing', 'timeupdate', 'pause', 'ended', 'emptied', 'error'].forEach(function(name) {
                avdPipShell.addEventListener(name, function(event) {
                    avdPipBindMedia();
                    if (event.target !== avdPipVideo || !avdPipVideo) return;
                    avdPipReady = name !== 'emptied' && !avdPipVideo.error && (avdPipReady || avdPipVideo.readyState >= 2);
                    avdPipPlaying = !avdPipVideo.paused && !avdPipVideo.ended && !avdPipVideo.error;
                    avdPipShell.dataset.playerReady = avdPipReady ? 'true' : 'false';
                    avdSetPlaybackActive(avdPipPlaying);
                    avdPipRequestUpdate();
                }, true);
            });
            window.addEventListener('scroll', avdPipRequestUpdate, { passive: true });
            window.addEventListener('resize', avdPipRequestUpdate, { passive: true });
        }

        // Ambilight Backlight Engine
        var avdAmbientGlowAllowed = @json($watchPlayerAmbientGlowEnabled);
        var avdAmbientGlowDisabledLabel = @json(__('Disabled'));
        window.avdToggleAmbilight = function(force) {
            var glow = document.getElementById('avdAmbilightGlow');
            var btn = document.getElementById('avdAmbilightToggle');
            if (!glow) return;
            if (!avdAmbientGlowAllowed) {
                glow.classList.remove('active');
                if (btn) {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-pressed', 'false');
                }
                var disabledTelemetry = document.getElementById('avdTelemAmbilight');
                if (disabledTelemetry) disabledTelemetry.textContent = avdAmbientGlowDisabledLabel;
                return;
            }
            var isActive = (force !== undefined) ? force : !glow.classList.contains('active');
            glow.classList.toggle('active', isActive);
            if (btn) {
                btn.classList.toggle('active', isActive);
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            }
            var telemAmbi = document.getElementById('avdTelemAmbilight');
            if (telemAmbi) telemAmbi.textContent = isActive ? 'Active' : 'Disabled';
            if (window.AvdAudio) window.AvdAudio.playClick();
            if (window.showCopyToast) {
                window.showCopyToast(isActive ? '{{ __("Ambilight Ambient Glow ON (Press A)") }}' : '{{ __("Ambilight Glow OFF") }}');
            }
            try {
                localStorage.setItem('avd_ambilight_enabled', isActive ? '1' : '0');
            } catch(e) {}
        };

        // Cinema Snapshot Frame Capture Tool
        window.avdCaptureFrame = function() {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ {{ __("Snapshot is active for direct HTML5 video streams") }}');
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }

            var flash = document.getElementById('avdCameraFlash');
            if (flash) {
                flash.style.opacity = '0.9';
                setTimeout(function(){ flash.style.opacity = '0'; }, 200);
            }
            if (window.AvdAudio) window.AvdAudio.playShutter();

            var canvas = document.createElement('canvas');
            var w = (vid && vid.videoWidth) ? vid.videoWidth : 1280;
            var h = (vid && vid.videoHeight) ? vid.videoHeight : 720;
            canvas.width = w;
            canvas.height = h;
            var ctx = canvas.getContext('2d');

            var captured = false;
            if (vid && vid.readyState >= 2) {
                try {
                    ctx.drawImage(vid, 0, 0, w, h);
                    captured = true;
                } catch(e) {}
            }

            if (!captured) {
                var grad = ctx.createLinearGradient(0, 0, w, h);
                grad.addColorStop(0, '#0a0d14');
                grad.addColorStop(1, '#1b162b');
                ctx.fillStyle = grad;
                ctx.fillRect(0, 0, w, h);

                ctx.fillStyle = '#ff3d61';
                ctx.font = 'bold ' + Math.round(h * 0.08) + 'px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('AVDBCMS Cinema Capture', w/2, h/2 - 20);
                ctx.fillStyle = '#ffffff';
                ctx.font = Math.round(h * 0.04) + 'px sans-serif';
                ctx.fillText('{{ addslashes($video->title) }}', w/2, h/2 + 40);
            }

            var badgeH = Math.round(h * 0.12);
            var pad = Math.round(w * 0.03);
            var curTime = (vid && vid.currentTime) ? vid.currentTime : 0;
            var mins = Math.floor(curTime / 60);
            var secs = Math.floor(curTime % 60);
            var hrs = Math.floor(mins / 60);
            mins = mins % 60;
            var timeStr = (hrs > 0 ? String(hrs).padStart(2, '0') + ':' : '') + String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');

            var barGrad = ctx.createLinearGradient(0, h - badgeH - pad, 0, h);
            barGrad.addColorStop(0, 'rgba(0,0,0,0)');
            barGrad.addColorStop(1, 'rgba(0,0,0,0.85)');
            ctx.fillStyle = barGrad;
            ctx.fillRect(0, h - badgeH - pad, w, badgeH + pad);

            var pillX = pad;
            var pillY = h - badgeH;
            var pillW = Math.round(w * 0.45);
            var pillH = Math.round(badgeH * 0.7);
            ctx.fillStyle = 'rgba(15, 20, 32, 0.85)';
            ctx.strokeStyle = 'rgba(255, 61, 97, 0.6)';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.roundRect(pillX, pillY, pillW, pillH, 8);
            ctx.fill();
            ctx.stroke();

            ctx.fillStyle = '#ff3d61';
            ctx.font = 'bold ' + Math.round(pillH * 0.38) + 'px sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText('AVDBCMS 4K', pillX + 16, pillY + Math.round(pillH * 0.42));

            ctx.fillStyle = '#ffffff';
            ctx.font = '600 ' + Math.round(pillH * 0.32) + 'px sans-serif';
            var shortTitle = '{{ addslashes($video->title) }}';
            if (shortTitle.length > 28) shortTitle = shortTitle.substring(0, 28) + '...';
            ctx.fillText(shortTitle + '  [' + timeStr + ']', pillX + 16, pillY + Math.round(pillH * 0.82));

            var a = document.createElement('a');
            a.download = 'avdbcms-snapshot-' + Date.now() + '.png';
            a.href = canvas.toDataURL('image/png');
            document.body.appendChild(a);
            a.click();
            a.remove();

            if (window.showCopyToast) {
                window.showCopyToast('{{ __("📸 Cinema Snapshot Saved!") }} [' + timeStr + ']');
            }
        };

        // Direct Playback Rate Setter with Toast & Badge update
        window.avdSetPlaybackRate = function(rate, btn) {
            var vid = document.querySelector('video');
            if (vid) {
                vid.playbackRate = rate;
                if (window.showCopyToast) window.showCopyToast('⏩ ' + rate + 'x ' + '{{ __("Playback Speed") }}');
            } else {
                if (window.showCopyToast) window.showCopyToast('ℹ️ {{ __("Upload18 iframe: speed is adjusted inside player frame") }}');
            }

            var speedBadge = document.getElementById('avdMenuCurrentSpeed');
            if (speedBadge) speedBadge.textContent = rate + 'x';

            document.querySelectorAll('.avd-speed-chip').forEach(function(b) {
                var r = parseFloat(b.textContent);
                if (Math.abs(r - rate) < 0.05) {
                    b.classList.add('active', 'btn-primary');
                    b.classList.remove('btn-outline-secondary');
                } else {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-outline-secondary');
                }
            });

            var hud = document.getElementById('avdSpeedHud');
            var txt = document.getElementById('avdSpeedHudText');
            if (hud && txt) {
                txt.textContent = rate + 'x Speed';
                hud.style.opacity = '1';
                hud.style.transform = 'translate(-50%, -50%) scale(1)';
                clearTimeout(window._avdSpeedTimeout);
                window._avdSpeedTimeout = setTimeout(function(){
                    hud.style.opacity = '0';
                    hud.style.transform = 'translate(-50%, -50%) scale(0.9)';
                }, 1200);
            }
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Playback Speed Controller with HUD Toast (Shortcut: [ and ])
        window.avdChangeSpeed = function(delta) {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ {{ __("Upload18 iframe: speed is adjusted via gear icon inside player") }}');
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }
            var speeds = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0];
            var cur = (vid && vid.playbackRate) ? vid.playbackRate : 1.0;
            var idx = speeds.indexOf(cur);
            if (idx === -1) idx = 2;
            var nextIdx = Math.max(0, Math.min(speeds.length - 1, idx + delta));
            var newRate = speeds[nextIdx];
            window.avdSetPlaybackRate(newRate);
        };

        // Picture-in-Picture Engine
        window.avdTogglePip = function(force) {
            if (!avdPipResolveElements()) return;
            var isPip = (force !== undefined) ? !!force : !avdPipShell.classList.contains('avd-pip-active');
            var playerStage = avdPipShell.querySelector('.avd-player-stage');
            if (isPip && playerStage && playerStage.dataset.playerState === 'ad') return;
            if (isPip && !avdPipReady) {
                // Manual PiP before the media surface is ready would force a
                // fixed-position flash over the loading placeholder.
                avdPipRequestUpdate();
                return;
            }
            avdPipManualClosed = !isPip;
            avdPipManualActive = isPip;
            avdPipSetActive(isPip, true);
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) {
                window.showCopyToast(isPip ? '{{ __("Mini Player Mode Active (Press P to Restore)") }}' : '{{ __("Restored to Standard Player") }}');
            }
        };

        // Keyboard Shortcuts (Cinema Experience: C=Filter, M=Mirror, F=Fullscreen, Shift+F=Zen, I=Telemetry, W=Aspect, Z=Sleep)
        document.addEventListener('keydown', function(e) {
            var tag = e.target && e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable)) return;
            if ((e.key === 'p' || e.key === 'P') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                window.avdTogglePip && window.avdTogglePip();
            } else if ((e.key === 'r' || e.key === 'R') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleLoop && window.avdToggleLoop();
            } else if (e.key === '.' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdStepFrame && window.avdStepFrame(1/30);
            } else if (e.key === ',' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdStepFrame && window.avdStepFrame(-1/30);
            } else if ((e.key === 'z' || e.key === 'Z') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleSleepTimerModal && window.avdToggleSleepTimerModal();
            } else if ((e.key === 'b' || e.key === 'B') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdCycleAudioBoost && window.avdCycleAudioBoost();
            } else if ((e.key === 'c' || e.key === 'C') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdCycleVideoFilter && window.avdCycleVideoFilter();
            } else if ((e.key === 'm' || e.key === 'M') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleMirror && window.avdToggleMirror();
            } else if ((e.key === 'f' || e.key === 'F') && e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleZenMode && window.avdToggleZenMode();
            } else if ((e.key === 'f' || e.key === 'F') && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleFullscreen && window.avdToggleFullscreen();
            } else if ((e.key === 'i' || e.key === 'I') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleTelemetryModal && window.avdToggleTelemetryModal();
            } else if ((e.key === 'o' || e.key === 'O') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdCycleRotation && window.avdCycleRotation();
            } else if ((e.key === 'a' || e.key === 'A') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleAmbilight && window.avdToggleAmbilight();
            } else if ((e.key === 't' || e.key === 'T') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                var tBtn = document.querySelector('[data-theater-toggle]');
                if (tBtn) tBtn.click();
            } else if ((e.key === 'l' || e.key === 'L') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleLightsOut && window.avdToggleLightsOut();
            } else if ((e.key === 'n' || e.key === 'N') && e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdSetVideoFilter && window.avdSetVideoFilter('night');
            } else if (e.key === 'Escape') {
                var mobileToolsSheet = document.getElementById('avdMobileToolsSheet');
                if (mobileToolsSheet && (mobileToolsSheet.classList.contains('show') || mobileToolsSheet.classList.contains('avd-mobile-sheet-fallback-open'))) {
                    e.preventDefault();
                    window.avdCloseMobileTools && window.avdCloseMobileTools();
                    return;
                }
                if (document.body.classList.contains('avd-zen-mode')) {
                    window.avdToggleZenMode();
                }
            } else if ((e.key === 'w' || e.key === 'W') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdCycleAspectRatio && window.avdCycleAspectRatio();
            } else if ((e.key === 'v' || e.key === 'V') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleVisualizer && window.avdToggleVisualizer();
            } else if ((e.key === 'n' || e.key === 'N') && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleNotes && window.avdToggleNotes();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                window.avdAdjustVolume && window.avdAdjustVolume(0.05);
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                window.avdAdjustVolume && window.avdAdjustVolume(-0.05);
            } else if (e.key === 'ArrowRight' && !e.shiftKey) {
                e.preventDefault();
                window.avdRelativeSeek && window.avdRelativeSeek(5);
            } else if (e.key === 'ArrowLeft' && !e.shiftKey) {
                e.preventDefault();
                window.avdRelativeSeek && window.avdRelativeSeek(-5);
            }
        });

        // Infinite Video Loop Controller
        window.avdToggleLoop = function() {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ {{ __("Upload18 iframe: loop is controlled inside player frame") }}');
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }
            vid.loop = !vid.loop;
            var item = document.getElementById('avdMenuLoopItem');
            if (item) item.classList.toggle('active', vid.loop);
            if (window.AvdAudio) window.AvdAudio.playClick();
            if (window.showCopyToast) {
                window.showCopyToast(vid.loop ? '{{ __("🔁 Infinite Loop Enabled") }}' : '{{ __("Infinite Loop Disabled") }}');
            }
        };

        // Frame-by-frame Precision Stepping
        window.avdStepFrame = function(stepSeconds) {
            var vid = document.querySelector('video');
            if (!vid) return;
            if (!vid.paused) vid.pause();
            var dt = (typeof stepSeconds === 'number') ? stepSeconds : (1 / 30);
            vid.currentTime = Math.max(0, Math.min(vid.duration || 999999, vid.currentTime + dt));
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Cinema Sleep Timer Engine
        window._avdSleepInterval = null;
        window._avdSleepTarget = 0;
        window._avdSleepFadeStarted = false;

        window.avdToggleSleepTimerModal = function() {
            var modalEl = document.getElementById('avdSleepTimerModal');
            if (!modalEl || typeof bootstrap === 'undefined') return;
            var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        window.avdSetSleepTimer = function(mins) {
            var modalEl = document.getElementById('avdSleepTimerModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }

            clearInterval(window._avdSleepInterval);
            var label = document.getElementById('avdSleepTimerLabel');
            var btn = document.getElementById('avdSleepTimerBtn');
            var vid = document.querySelector('video');

            if (mins === 0) {
                if (label) label.textContent = '{{ __("Sleep") }}';
                if (btn) btn.classList.remove('active', 'text-warning');
                if (window.showCopyToast) window.showCopyToast('{{ __("Cinema Sleep Timer Disabled") }}');
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }

            if (mins === -1) {
                if (label) label.textContent = '{{ __("Sleep: End") }}';
                if (btn) btn.classList.add('active', 'text-warning');
                if (window.showCopyToast) window.showCopyToast('{{ __("Sleep Timer: Pause at End of Video") }}');
                if (vid) {
                    vid.onended = function() {
                        vid.pause();
                        if (window.showCopyToast) window.showCopyToast('🌙 {{ __("Video Finished: Goodnight!") }}');
                        if (window.AvdAudio) window.AvdAudio.playChime();
                        if (label) label.textContent = '{{ __("Sleep") }}';
                        if (btn) btn.classList.remove('active', 'text-warning');
                    };
                }
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }

            window._avdSleepTarget = Date.now() + mins * 60 * 1000;
            if (btn) btn.classList.add('active', 'text-warning');
            if (window.showCopyToast) window.showCopyToast('🌙 {{ __("Sleep Timer Set:") }} ' + mins + ' {{ __("Minutes") }}');
            if (window.AvdAudio) window.AvdAudio.playChime();

            function updateSleep() {
                var diff = Math.max(0, Math.floor((window._avdSleepTarget - Date.now()) / 1000));
                var m = Math.floor(diff / 60);
                var s = diff % 60;
                if (label) label.textContent = m + 'm ' + s + 's';

                var vid = document.querySelector('video');
                if (diff <= 15 && diff > 0 && vid && !window._avdSleepFadeStarted) {
                    window._avdSleepFadeStarted = true;
                }

                if (diff <= 0) {
                    clearInterval(window._avdSleepInterval);
                    if (vid) {
                        vid.pause();
                    } else {
                        var ifr = document.querySelector('.avd-player-frame iframe');
                        if (ifr) {
                            ifr.setAttribute('data-saved-src', ifr.src);
                            ifr.src = 'about:blank';
                        }
                    }
                    var overlay = document.getElementById('avdSleepFinishedOverlay');
                    if (overlay) overlay.classList.remove('d-none');

                    if (label) label.textContent = '{{ __("Sleep") }}';
                    if (btn) btn.classList.remove('active', 'text-warning');
                    if (window.showCopyToast) window.showCopyToast('🌙 {{ __("Goodnight! Video paused by Sleep Timer.") }}');
                    if (window.AvdAudio) window.AvdAudio.playChime();
                }
            }

            updateSleep();
            window._avdSleepInterval = setInterval(updateSleep, 1000);
        };

        // Resume Stream After Sleep Timer
        window.avdResumeFromSleep = function() {
            var overlay = document.getElementById('avdSleepFinishedOverlay');
            if (overlay) overlay.classList.add('d-none');
            var vid = document.querySelector('video');
            if (vid) {
                vid.play().catch(function(){});
            } else {
                var ifr = document.querySelector('.avd-player-frame iframe');
                if (ifr) {
                    var savedSrc = ifr.getAttribute('data-saved-src');
                    if (savedSrc && savedSrc !== 'about:blank') {
                        ifr.src = savedSrc;
                    } else {
                        window.avdReloadPlayer();
                    }
                }
            }
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('▶️ {{ __("Stream resumed!") }}');
        };

        // Spatial Audio Equalizer Simulator
        var avdAudioModes = [
            { id: 'standard', name: '{{ __("Standard Stereo") }}', tag: 'Audio' },
            { id: 'bass', name: '{{ __("Cinema Bass Boost 🔊") }}', tag: 'Bass 🔊' },
            { id: 'vocal', name: '{{ __("Vocal Clarity 🎙️") }}', tag: 'Vocal 🎙️' },
            { id: 'night', name: '{{ __("Night Listening 🌙") }}', tag: 'Night 🌙' }
        ];
        var avdAudioModeIdx = 0;

        window.avdCycleAudioBoost = function() {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ {{ __("Audio equalizer is active for native video streams") }}');
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }
            avdAudioModeIdx = (avdAudioModeIdx + 1) % avdAudioModes.length;
            var mode = avdAudioModes[avdAudioModeIdx];
            var label = document.getElementById('avdMenuAudioLabel');
            if (label) label.textContent = mode.name;
            if (window.showCopyToast) window.showCopyToast('🎧 ' + mode.name);
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Timestamp Quick-Bookmark Engine
        var avdVideoUuid = '{{ $video->uuid }}';
        var avdBookmarkStorageKey = 'avd_bkm_' + avdVideoUuid;
        window.avdBookmarks = [];

        try {
            var rawBkm = localStorage.getItem(avdBookmarkStorageKey);
            if (rawBkm) window.avdBookmarks = JSON.parse(rawBkm);
        } catch(e) {}

        function avdFormatTime(secs) {
            var s = Math.floor(secs);
            var m = Math.floor(s / 60);
            var sec = s % 60;
            return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
        }

        window.renderBookmarks = function() {
            var shelf = document.getElementById('avdBookmarkShelf');
            var list = document.getElementById('avdBookmarkList');
            if (!shelf || !list) return;

            if (!window.avdBookmarks || window.avdBookmarks.length === 0) {
                shelf.classList.add('d-none');
                shelf.classList.remove('d-flex');
                list.innerHTML = '';
                return;
            }

            shelf.classList.remove('d-none');
            shelf.classList.add('d-flex');
            list.innerHTML = '';

            window.avdBookmarks.forEach(function(bkm, idx) {
                var pill = document.createElement('div');
                pill.className = 'badge rounded-pill bg-dark border border-secondary text-white px-2.5 py-1.5 cursor-pointer d-inline-flex align-items-center gap-1.5 shadow-sm';
                pill.style.cssText = 'font-size: 0.76rem; cursor: pointer; transition: transform 0.15s ease, background 0.15s ease;';
                pill.innerHTML = '<i class="fa-solid fa-play text-warning" style="font-size:0.65rem;"></i> ' +
                                 '<span>' + bkm.timeStr + '</span>' +
                                 '<i class="fa-solid fa-xmark text-white-50 ms-1" style="cursor:pointer;" title="{{ __("Remove") }}"></i>';

                pill.addEventListener('click', function(e) {
                    if (e.target.classList.contains('fa-xmark')) {
                        e.stopPropagation();
                        window.avdDeleteBookmark(idx);
                    } else {
                        window.avdSeekTo(bkm.seconds);
                    }
                });
                list.appendChild(pill);
            });
        };

        window.avdAddBookmark = function() {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ {{ __("Bookmarks require direct HTML5 video stream") }}');
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }
            var curTime = (vid && vid.currentTime) ? vid.currentTime : 0;
            var timeStr = avdFormatTime(curTime);

            window.avdBookmarks.push({
                seconds: Math.floor(curTime),
                timeStr: timeStr,
                timestamp: Date.now()
            });

            // Keep max 10 bookmarks
            if (window.avdBookmarks.length > 10) window.avdBookmarks.shift();

            try {
                localStorage.setItem(avdBookmarkStorageKey, JSON.stringify(window.avdBookmarks));
            } catch(e) {}

            window.renderBookmarks();
            if (window.AvdAudio) window.AvdAudio.playChime();
            if (window.showCopyToast) window.showCopyToast('📌 {{ __("Bookmark saved at") }} ' + timeStr);
        };

        window.avdSeekTo = function(secs) {
            var vid = document.querySelector('video');
            if (vid) {
                vid.currentTime = secs;
                if (vid.paused) vid.play();
            }
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('⏩ {{ __("Jumped to") }} ' + avdFormatTime(secs));
        };

        window.avdDeleteBookmark = function(idx) {
            window.avdBookmarks.splice(idx, 1);
            try {
                localStorage.setItem(avdBookmarkStorageKey, JSON.stringify(window.avdBookmarks));
            } catch(e) {}
            window.renderBookmarks();
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Render any existing bookmarks on load
        window.renderBookmarks();

        // Cinema Zoom & Aspect Ratio Stretcher Controller (Shortcut: W)
        var avdAspectModes = [
            { id: 'fit', label: '16:9', hud: 'Fit (16:9)', fit: 'contain', transform: 'scale(1)' },
            { id: 'cinemascope', label: '21:9', hud: 'Cinemascope (21:9)', fit: 'cover', transform: 'scale(1, 0.77)' },
            { id: 'fill', label: 'Fill', hud: 'Fill Frame (Stretch)', fit: 'cover', transform: 'scale(1.15)' },
            { id: 'zoom', label: '1.25x', hud: 'Cinema Zoom 1.25x', fit: 'cover', transform: 'scale(1.25)' }
        ];
        var avdCurrentAspectIndex = 0;
        var avdAspectHudTimer = null;

        window.avdCycleAspectRatio = function() {
            avdCurrentAspectIndex = (avdCurrentAspectIndex + 1) % avdAspectModes.length;
            var mode = avdAspectModes[avdCurrentAspectIndex];
            var vid = document.querySelector('video');
            var ifr = document.querySelector('.avd-player-frame iframe');
            var target = vid || ifr;
            if (target) {
                target.style.transition = 'transform 0.35s cubic-bezier(0.16,1,0.3,1), object-fit 0.35s ease';
                if (target.tagName === 'VIDEO') {
                    target.style.objectFit = mode.fit;
                }
                target.style.transform = mode.transform;
                target.style.transformOrigin = 'center center';
            }

            var btnLabel = document.getElementById('avdAspectLabel');
            if (btnLabel) btnLabel.textContent = mode.label;
            var telemAspect = document.getElementById('avdTelemAspect');
            if (telemAspect) telemAspect.textContent = mode.hud;

            var hud = document.getElementById('avdAspectHud');
            var hudText = document.getElementById('avdAspectHudText');
            if (hud && hudText) {
                hudText.textContent = mode.hud;
                hud.style.opacity = '1';
                hud.style.transform = 'translate(-50%, -50%) scale(1)';
                if (avdAspectHudTimer) clearTimeout(avdAspectHudTimer);
                avdAspectHudTimer = setTimeout(function() {
                    hud.style.opacity = '0';
                    hud.style.transform = 'translate(-50%, -50%) scale(0.9)';
                }, 1300);
            }

            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('📐 ' + mode.hud);
        };

        // Player Stream Reload Engine (Crucial for Upload18 iframes)
        window.avdReloadPlayer = function() {
            var ifr = document.querySelector('.avd-player-frame iframe');
            if (ifr) {
                var src = ifr.src;
                ifr.src = '';
                setTimeout(function() { ifr.src = src; }, 80);
                if (window.showCopyToast) window.showCopyToast('🔄 {{ __("Player stream reloaded!") }}');
                if (window.AvdAudio) window.AvdAudio.playWhoosh();
                return;
            }
            var vid = document.querySelector('video');
            if (vid) {
                vid.load();
                vid.play().catch(function(){});
                if (window.showCopyToast) window.showCopyToast('🔄 {{ __("Video stream reloaded!") }}');
                if (window.AvdAudio) window.AvdAudio.playWhoosh();
            }
        };

        // Quick Share Video URL
        window.avdQuickShare = function() {
            var url = window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    if (window.showCopyToast) window.showCopyToast('🔗 {{ __("Video link copied to clipboard!") }}');
                }).catch(function() {
                    prompt('Copy link:', url);
                });
            } else {
                prompt('Copy link:', url);
            }
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Cinema Color Grading Engine (100% works on Upload18 iframe & HTML5!)
        var avdFilters = ['normal', 'vivid', 'cinema', 'cyberpunk', 'oled', 'noir', 'night', 'anime'];
        var avdFilterNames = {
            'normal': 'Normal',
            'vivid': 'Vivid HDR',
            'cinema': '35mm Warm',
            'cyberpunk': 'Cyberpunk',
            'oled': 'OLED Dark',
            'noir': 'Noir B&W',
            'night': 'Night Shift',
            'anime': 'Anime Pop'
        };
        var avdConfiguredFilter = {
            'none': 'normal',
            'hdr_vibrant': 'vivid',
            'warm_35mm': 'cinema',
            'cyber_glow': 'cyberpunk',
            'cool_scifi': 'night',
            'monochrome_noir': 'noir'
        }[document.documentElement.getAttribute('data-avd-cinema-filter') || 'none'] || 'normal';
        var avdCurrentFilterIdx = Math.max(0, avdFilters.indexOf(avdConfiguredFilter));

        // Mobile Bottom Sheet Trigger (For iPhone, Android, and mobile viewports).
        // Bootstrap is the primary implementation, with a small scoped fallback
        // so a delayed/missing bundle cannot strand the mobile controls.
        window.avdCloseMobileTools = function() {
            var sheetEl = document.getElementById('avdMobileToolsSheet');
            if (!sheetEl) return;

            if (window.bootstrap && bootstrap.Offcanvas) {
                var instance = bootstrap.Offcanvas.getInstance(sheetEl);
                if (instance) {
                    instance.hide();
                    return;
                }
            }

            sheetEl.classList.remove('show', 'avd-mobile-sheet-fallback-open');
            sheetEl.removeAttribute('aria-modal');
            sheetEl.removeAttribute('role');
            sheetEl.style.visibility = '';
            sheetEl.style.transform = '';
            document.body.classList.remove('avd-mobile-tools-open');
            var moreButton = document.getElementById('avdMoreToolsBtn');
            if (moreButton) moreButton.setAttribute('aria-expanded', 'false');
            var backdrop = document.getElementById('avdMobileToolsBackdrop');
            if (backdrop) backdrop.remove();
        };

        window.avdOpenMobileTools = function() {
            var sheetEl = document.getElementById('avdMobileToolsSheet');
            if (!sheetEl) return;

            if (window.bootstrap && bootstrap.Offcanvas) {
                var sheet = bootstrap.Offcanvas.getInstance(sheetEl) || new bootstrap.Offcanvas(sheetEl);
                document.body.classList.add('avd-mobile-tools-open');
                sheet.show();
            } else {
                sheetEl.classList.add('show', 'avd-mobile-sheet-fallback-open');
                sheetEl.setAttribute('aria-modal', 'true');
                sheetEl.setAttribute('role', 'dialog');
                sheetEl.style.visibility = 'visible';
                sheetEl.style.transform = 'translateY(0)';
                document.body.classList.add('avd-mobile-tools-open');
                var moreButton = document.getElementById('avdMoreToolsBtn');
                if (moreButton) moreButton.setAttribute('aria-expanded', 'true');

                var backdrop = document.getElementById('avdMobileToolsBackdrop');
                if (!backdrop) {
                    backdrop = document.createElement('div');
                    backdrop.id = 'avdMobileToolsBackdrop';
                    backdrop.className = 'avd-mobile-sheet-fallback-backdrop';
                    backdrop.addEventListener('click', window.avdCloseMobileTools);
                    document.body.appendChild(backdrop);
                }

                var closeButton = sheetEl.querySelector('.btn-close');
                if (closeButton) window.setTimeout(function() { closeButton.focus(); }, 0);
            }

            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Prevent the desktop dropup's delegated Bootstrap handler from also
        // running after the mobile trigger has opened the sheet.
        (function bindMobileCinemaControls() {
            var moreButton = document.getElementById('avdMoreToolsBtn');
            var sheetEl = document.getElementById('avdMobileToolsSheet');
            if (moreButton) {
                moreButton.addEventListener('click', function(event) {
                    if (window.innerWidth < 768) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.avdOpenMobileTools();
                    }
                }, true);
            }
            if (sheetEl) {
                sheetEl.addEventListener('hidden.bs.offcanvas', function() {
                    document.body.classList.remove('avd-mobile-tools-open');
                });
            }
        }());

        // Keep the desktop menu inside the visual viewport when the trigger is
        // near an edge. The mobile path is always the bottom sheet above.
        function avdClampCinemaDropdown(menu) {
            if (!menu || window.innerWidth < 768 || !menu.classList.contains('show')) return;
            menu.style.translate = '0 0';
            var rect = menu.getBoundingClientRect();
            var gutter = 8;
            var dx = rect.left < gutter ? gutter - rect.left : (rect.right > window.innerWidth - gutter ? (window.innerWidth - gutter) - rect.right : 0);
            var dy = rect.top < gutter ? gutter - rect.top : (rect.bottom > window.innerHeight - gutter ? (window.innerHeight - gutter) - rect.bottom : 0);
            if (dx || dy) menu.style.translate = dx + 'px ' + dy + 'px';
        }

        document.addEventListener('shown.bs.dropdown', function(event) {
            var menu = event.target && event.target.parentElement ? event.target.parentElement.querySelector('.avd-cinema-dropdown-menu') : null;
            window.setTimeout(function() { avdClampCinemaDropdown(menu); }, 0);
        });
        window.addEventListener('resize', function() {
            var openMenu = document.querySelector('.avd-cinema-dropdown-menu.show');
            avdClampCinemaDropdown(openMenu);
        }, { passive: true });

        window.avdSetVideoFilter = function(preset, btn) {
            var frame = document.querySelector('.avd-player-frame');
            if (!frame) return;
            avdFilters.forEach(function(f) {
                frame.classList.remove('avd-filter-' + f);
            });
            if (preset !== 'normal') {
                frame.classList.add('avd-filter-' + preset);
            }
            avdCurrentFilterIdx = avdFilters.indexOf(preset);
            if (avdCurrentFilterIdx === -1) avdCurrentFilterIdx = 0;

            // Update badge & chips in menu & mobile sheet
            var badge = document.getElementById('avdCurrentFilterBadge');
            if (badge) badge.textContent = avdFilterNames[preset] || preset;
            var mobBadge = document.getElementById('avdMobileFilterBadge');
            if (mobBadge) mobBadge.textContent = avdFilterNames[preset] || preset;
            var dockLabel = document.getElementById('avdFilterLabel');
            if (dockLabel) dockLabel.textContent = preset === 'normal' ? '{{ __("Filter") }}' : (avdFilterNames[preset] || preset);
            var dockBtn = document.getElementById('avdFilterBtn');
            if (dockBtn) {
                dockBtn.classList.toggle('active', preset !== 'normal');
                dockBtn.title = '{{ __("Cinema Color Grading") }}: ' + (avdFilterNames[preset] || preset) + ' (C)';
            }

            // Telemetry modal update
            var telemFilter = document.getElementById('avdTelemFilter');
            if (telemFilter) telemFilter.textContent = (avdFilterNames[preset] || preset) + ' (Active)';

            document.querySelectorAll('.avd-filter-chip, .avd-mob-filter-chip').forEach(function(chip) {
                chip.classList.remove('active', 'btn-primary');
                chip.classList.add('btn-outline-secondary');
            });
            document.querySelectorAll('.avd-filter-chip[onclick*="' + preset + '"], .avd-mob-filter-chip[onclick*="' + preset + '"]').forEach(function(chip) {
                chip.classList.add('active', 'btn-primary');
                chip.classList.remove('btn-outline-secondary');
            });

            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('🎨 Filter: ' + (avdFilterNames[preset] || preset));
        };

        // Mirror the persisted appearance filter in both control surfaces on
        // first paint; clicking a chip continues to use the interactive cycle.
        (function() {
            var preset = avdConfiguredFilter;
            var label = avdFilterNames[preset] || preset;
            ['avdCurrentFilterBadge', 'avdMobileFilterBadge'].forEach(function(id) {
                var badge = document.getElementById(id);
                if (badge) badge.textContent = label;
            });
            var telemetry = document.getElementById('avdTelemFilter');
            if (telemetry) telemetry.textContent = label + ' (Active)';
            document.querySelectorAll('.avd-filter-chip, .avd-mob-filter-chip').forEach(function(chip) {
                var active = chip.getAttribute('onclick') && chip.getAttribute('onclick').indexOf("'" + preset + "'") !== -1;
                chip.classList.toggle('active', active);
                chip.classList.toggle('btn-primary', active);
                chip.classList.toggle('btn-outline-secondary', !active);
            });
        }());

        window.avdCycleVideoFilter = function() {
            avdCurrentFilterIdx = (avdCurrentFilterIdx + 1) % avdFilters.length;
            var nextPreset = avdFilters[avdCurrentFilterIdx];
            var targetBtn = document.querySelector('.avd-filter-chip[onclick*="' + nextPreset + '"]');
            window.avdSetVideoFilter(nextPreset, targetBtn);
        };

        // Frame Rotation Engine (Works 100% on Upload18 iframe & HTML5!)
        var avdRotations = [0, 90, 180, 270];
        var avdCurrentRotation = 0;
        window.avdRotateVideo = function(deg, btn) {
            var frame = document.querySelector('.avd-player-frame');
            if (!frame) return;
            avdRotations.forEach(function(r) {
                if (r !== 0) frame.classList.remove('avd-rotate-' + r);
            });
            if (deg !== 0) {
                frame.classList.add('avd-rotate-' + deg);
            }
            avdCurrentRotation = deg;
            var badge = document.getElementById('avdCurrentRotationBadge');
            if (badge) badge.textContent = deg + '°';
            var mobRotateBadge = document.getElementById('avdMobileRotateBadge');
            if (mobRotateBadge) mobRotateBadge.textContent = deg + '°';

            document.querySelectorAll('.avd-rotate-chip, .avd-mob-rotate-chip').forEach(function(c) {
                c.classList.remove('active', 'btn-primary');
                c.classList.add('btn-outline-secondary');
            });
            document.querySelectorAll('.avd-rotate-chip[onclick*="' + deg + '"], .avd-mob-rotate-chip[onclick*="' + deg + '"]').forEach(function(c) {
                c.classList.add('active', 'btn-primary');
                c.classList.remove('btn-outline-secondary');
            });

            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('🔄 Frame Rotation: ' + deg + '°');
        };

        window.avdCycleRotation = function() {
            var nextIdx = (avdRotations.indexOf(avdCurrentRotation) + 1) % avdRotations.length;
            var nextDeg = avdRotations[nextIdx];
            var targetBtn = document.querySelector('.avd-rotate-chip[onclick*="' + nextDeg + '"]');
            window.avdRotateVideo(nextDeg, targetBtn);
        };

        // Screen Brightness Engine (Works 100% on Upload18 iframe & HTML5!)
        window.avdSetBrightness = function(level, btn) {
            var frame = document.querySelector('.avd-player-frame');
            if (!frame) return;
            frame.classList.remove('avd-bright-boost', 'avd-bright-dim');
            if (level === 'boost') frame.classList.add('avd-bright-boost');
            if (level === 'dim') frame.classList.add('avd-bright-dim');

            var badge = document.getElementById('avdCurrentBrightBadge');
            if (badge) badge.textContent = level === 'boost' ? '+15%' : (level === 'dim' ? '-15%' : 'Normal');
            var mobBrightBadge = document.getElementById('avdMobileBrightBadge');
            if (mobBrightBadge) mobBrightBadge.textContent = level === 'boost' ? '+15%' : (level === 'dim' ? '-15%' : 'Normal');

            document.querySelectorAll('.avd-bright-chip, .avd-mob-bright-chip').forEach(function(c) {
                c.classList.remove('active', 'btn-primary');
                c.classList.add('btn-outline-secondary');
            });
            document.querySelectorAll('.avd-bright-chip[onclick*="' + level + '"], .avd-mob-bright-chip[onclick*="' + level + '"]').forEach(function(c) {
                c.classList.add('active', 'btn-primary');
                c.classList.remove('btn-outline-secondary');
            });

            if (window.AvdAudio) window.AvdAudio.playClick();
            if (window.showCopyToast) window.showCopyToast('☀️ Brightness: ' + (level === 'boost' ? '+15%' : (level === 'dim' ? '-15%' : 'Normal')));
        };

        // Ambilight Multi-Glow Engine (Works 100% on Upload18 iframe & HTML5!)
        var avdAmbiModes = ['theme', 'pulse', 'neon', 'warm'];
        window.avdSetAmbiMode = function(mode, btn) {
            if (!avdAmbientGlowAllowed) return;
            var glow = document.getElementById('avdAmbilightGlow');
            if (!glow) return;
            glow.classList.remove('glow-pulse', 'glow-neon', 'glow-warm');
            if (mode === 'pulse') glow.classList.add('glow-pulse');
            if (mode === 'neon') glow.classList.add('glow-neon');
            if (mode === 'warm') glow.classList.add('glow-warm');

            var names = {'theme': 'Theme', 'pulse': 'Pulse', 'neon': 'Neon', 'warm': 'Warm'};
            var badge = document.getElementById('avdCurrentAmbiBadge');
            if (badge) badge.textContent = names[mode] || mode;
            var mobAmbiBadge = document.getElementById('avdMobileAmbiBadge');
            if (mobAmbiBadge) mobAmbiBadge.textContent = names[mode] || mode;

            document.querySelectorAll('.avd-ambi-chip, .avd-mob-ambi-chip').forEach(function(c) {
                c.classList.remove('active', 'btn-primary');
                c.classList.add('btn-outline-secondary');
            });
            document.querySelectorAll('.avd-ambi-chip[onclick*="' + mode + '"], .avd-mob-ambi-chip[onclick*="' + mode + '"]').forEach(function(c) {
                c.classList.add('active', 'btn-primary');
                c.classList.remove('btn-outline-secondary');
            });

            window.avdToggleAmbilight(true);
            if (window.AvdAudio) window.AvdAudio.playChime();
            if (window.showCopyToast) window.showCopyToast('✨ Ambilight Glow: ' + (names[mode] || mode));
        };

        // Embed Code Generator (Opens modal & pre-populates iframe code)
        window.avdOpenEmbedModal = function() {
            var modalEl = document.getElementById('avdEmbedModal');
            if (!modalEl || typeof bootstrap === 'undefined') return;
            var ta = document.getElementById('avdEmbedCodeTextarea');
            if (ta) {
                var embedUrl = '{{ route("video.embed", ["video" => $video->uuid]) }}';
                ta.value = '<iframe src="' + embedUrl + '" width="100%" height="480" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
            }
            var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        window.avdCopyEmbedCode = function() {
            var ta = document.getElementById('avdEmbedCodeTextarea');
            if (!ta) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ta.value).then(function() {
                    if (window.showCopyToast) window.showCopyToast('📋 {{ __("Embed code copied to clipboard!") }}');
                    if (window.AvdAudio) window.AvdAudio.playChime();
                });
            } else {
                ta.select();
                document.execCommand('copy');
                if (window.showCopyToast) window.showCopyToast('📋 {{ __("Embed code copied!") }}');
            }
        };

        // Sticky Mini Watch Bar. It shows once the page has scrolled past
        // max(450px, player bottom - 50px): scrollY > 450 and the player's
        // bottom edge above 50px from the viewport top. Two IntersectionObservers
        // track both edges, so scrolling runs no script here and the class is only
        // written on a transition. The 450px sentinel spans the document top, so it
        // is in view at scrollY 0 on any viewport height and every crossing of 450
        // flips it, jumps included (scroll restoration, End key, scrollbar drag);
        // an edge-adjacent target still intersects, so it reproduces scrollY > 450
        // exactly. overflow-anchor:none keeps it from becoming the scroll anchor (it
        // never moves with the content). Zen mode hides the bar through CSS
        // (body.avd-zen-mode #avdStickyWatchBar).
        var avdStickyBar = document.getElementById('avdStickyWatchBar');
        var avdStickyPlayerWrapper = document.getElementById('avdPlayerWrapper');
        var avdStickyVisible = false;
        var avdStickyPast450 = false;
        var avdStickyPlayerAbove = !avdStickyPlayerWrapper;
        function avdSetStickyWatchBar(shouldShow) {
            if (shouldShow === avdStickyVisible) return;
            avdStickyVisible = shouldShow;
            avdStickyBar.classList.toggle('d-none', !shouldShow);
        }
        if (avdStickyBar && typeof window.IntersectionObserver === 'function') {
            var avdStickySentinel = document.createElement('div');
            avdStickySentinel.setAttribute('aria-hidden', 'true');
            avdStickySentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:450px;pointer-events:none;overflow-anchor:none';
            document.body.insertBefore(avdStickySentinel, document.body.firstChild);
            new IntersectionObserver(function(entries) {
                var entry = entries[entries.length - 1];
                avdStickyPast450 = !entry.isIntersecting;
                avdSetStickyWatchBar(avdStickyPast450 && avdStickyPlayerAbove);
            }).observe(avdStickySentinel);
            if (avdStickyPlayerWrapper) {
                new IntersectionObserver(function(entries) {
                    var entry = entries[entries.length - 1];
                    avdStickyPlayerAbove = !entry.isIntersecting && entry.boundingClientRect.bottom < 50;
                    avdSetStickyWatchBar(avdStickyPast450 && avdStickyPlayerAbove);
                }, { rootMargin: '-50px 0px 0px 0px' }).observe(avdStickyPlayerWrapper);
            }
        } else if (avdStickyBar) {
            // Without IntersectionObserver: one rAF-coalesced check per scroll, with
            // the threshold measured at start and on resize (engines without
            // IntersectionObserver have no ResizeObserver either).
            var avdStickyFrame = 0;
            var avdStickyThreshold = 450;
            var avdStickyNeedsMeasure = true;
            var avdUpdateStickyWatchBar = function() {
                avdStickyFrame = 0;
                if (avdStickyNeedsMeasure) {
                    if (avdStickyPlayerWrapper) {
                        avdStickyThreshold = Math.max(450, window.scrollY + avdStickyPlayerWrapper.getBoundingClientRect().bottom - 50);
                    }
                    avdStickyNeedsMeasure = false;
                }
                avdSetStickyWatchBar(window.scrollY > avdStickyThreshold);
            };
            var avdRequestStickyWatchBarUpdate = function(measure) {
                if (measure) avdStickyNeedsMeasure = true;
                if (avdStickyFrame) return;
                avdStickyFrame = window.requestAnimationFrame(avdUpdateStickyWatchBar);
            };
            window.addEventListener('scroll', function() { avdRequestStickyWatchBarUpdate(false); }, { passive: true });
            window.addEventListener('resize', function() { avdRequestStickyWatchBarUpdate(true); }, { passive: true });
            avdRequestStickyWatchBarUpdate(true);
        }

        // Mirror Flip View (100% works on Upload18 iframe & HTML5)
        window.avdToggleMirror = function() {
            var frame = document.querySelector('.avd-player-frame');
            var btn = document.getElementById('avdMirrorBtn');
            var telemMirror = document.getElementById('avdTelemMirror');
            if (!frame) return;
            var isMirrored = frame.classList.toggle('avd-mirrored');
            if (btn) btn.classList.toggle('active', isMirrored);
            if (telemMirror) {
                telemMirror.textContent = isMirrored ? 'Mirrored (Flipped)' : 'Standard';
                telemMirror.className = 'badge ' + (isMirrored ? 'bg-warning text-dark' : 'bg-secondary-subtle text-secondary');
            }
            if (window.AvdAudio) window.AvdAudio.playClick();
            if (window.showCopyToast) window.showCopyToast(isMirrored ? '🪞 {{ __("Mirror Flip: On") }}' : '🪞 {{ __("Mirror Flip: Off") }}');
        };

        // True Fullscreen Engine with iOS Safari pseudo-fullscreen fallback
        window.avdToggleFullscreen = function() {
            var shell = document.getElementById('avdMainPlayerShell') || document.querySelector('.avd-player-frame');
            if (!shell) return;

            // Check if native video element has webkitEnterFullscreen (iOS Safari native video)
            var videoEl = document.querySelector('.avd-player-frame video, #player video');
            if (videoEl && videoEl.webkitEnterFullscreen && !document.fullscreenElement) {
                try {
                    videoEl.webkitEnterFullscreen();
                    return;
                } catch(e){}
            }

            if (!document.fullscreenElement && !document.webkitFullscreenElement && !shell.classList.contains('avd-ios-fullscreen')) {
                var enterFallback = function() {
                    shell.classList.add('avd-ios-fullscreen');
                    document.body.classList.add('avd-fullscreen-active');
                };

                if (shell.requestFullscreen) {
                    shell.requestFullscreen().catch(enterFallback);
                } else if (shell.webkitRequestFullscreen) {
                    try {
                        shell.webkitRequestFullscreen();
                    } catch(e) {
                        enterFallback();
                    }
                } else if (shell.msRequestFullscreen) {
                    shell.msRequestFullscreen();
                } else {
                    enterFallback();
                }
                if (window.AvdAudio) window.AvdAudio.playWhoosh();
                if (window.showCopyToast) window.showCopyToast('⛶ {{ __("Fullscreen Active") }}');
            } else {
                if (shell.classList.contains('avd-ios-fullscreen')) {
                    shell.classList.remove('avd-ios-fullscreen');
                    document.body.classList.remove('avd-fullscreen-active');
                } else if (document.exitFullscreen) {
                    document.exitFullscreen().catch(function(){});
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
                if (window.AvdAudio) window.AvdAudio.playClick();
                if (window.showCopyToast) window.showCopyToast('⛶ {{ __("Fullscreen Exited") }}');
            }
        };

        // Zen Focus Immersion Engine (Shortcut: Shift+F or ESC)
        window.avdToggleZenMode = function() {
            var isZen = document.body.classList.toggle('avd-zen-mode');
            var btn = document.getElementById('avdZenBtn');
            if (btn) btn.classList.toggle('active', isZen);
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) {
                window.showCopyToast(isZen ? '🧘 {{ __("Zen Immersion Active (Shift+F or ESC to exit)") }}' : '🧘 {{ __("Zen Immersion Exited") }}');
            }
        };

        // Video details modal (Shortcut: I)
        window.avdToggleTelemetryModal = function() {
            var modalEl = document.getElementById('avdTelemetryModal');
            if (!modalEl || typeof bootstrap === 'undefined') return;
            var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Smart Runtime Detection of Player Engine (Iframe vs Native HTML5)
        function avdUpdatePlayerCapabilities() {
            var vid = document.querySelector('video');
            var ifr = document.querySelector('.avd-player-frame iframe') || document.querySelector('iframe');
            var isIframe = !vid && !!ifr;
            var badge = document.getElementById('avdPlayerTypeBadge');
            var iframeNotice = document.getElementById('avdIframeHelperNotice');
            var html5Tools = document.getElementById('avdHtml5OnlyTools');

            if (badge) {
                badge.textContent = isIframe ? 'Upload18 Stream' : (vid ? 'HTML5 Native' : 'Player Ready');
                badge.className = 'badge rounded-pill ' + (isIframe ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-success-subtle text-success border border-success-subtle');
            }
            var mobBadge = document.getElementById('avdMobilePlayerTypeBadge');
            if (mobBadge) {
                mobBadge.textContent = isIframe ? 'Upload18 Stream' : (vid ? 'HTML5 Native' : 'Player Ready');
                mobBadge.className = 'badge rounded-pill ' + (isIframe ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-success-subtle text-success border border-success-subtle');
            }

            if (iframeNotice) {
                if (isIframe) {
                    iframeNotice.classList.remove('d-none');
                } else {
                    iframeNotice.classList.add('d-none');
                }
            }

            if (html5Tools) {
                if (vid) {
                    html5Tools.classList.remove('d-none');
                } else {
                    html5Tools.classList.add('d-none');
                }
            }

        }

        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(avdUpdatePlayerCapabilities, 1200);
            setTimeout(avdUpdatePlayerCapabilities, 3500);
        });

        // Audio Spectrum Visualizer Controller (Shortcut: V)
        var avdVisualizerActive = false;
        var avdVisualizerAnimId = null;
        var avdVisualizerBars = 48;

        window.avdToggleVisualizer = function() {
            avdVisualizerActive = !avdVisualizerActive;
            var canvas = document.getElementById('avdVisualizerCanvas');
            var btn = document.getElementById('avdVisualizerBtn');
            if (!canvas) return;

            if (btn) {
                btn.classList.toggle('active', avdVisualizerActive);
                btn.classList.toggle('text-info', avdVisualizerActive);
            }

            if (avdVisualizerActive) {
                canvas.style.opacity = '0.85';
                if (window.AvdAudio) window.AvdAudio.playChime();
                if (window.showCopyToast) window.showCopyToast('📊 {{ __("Audio Spectrum Visualizer: Active") }}');
                avdStartVisualizerAnimation();
            } else {
                canvas.style.opacity = '0';
                if (avdVisualizerAnimId) {
                    cancelAnimationFrame(avdVisualizerAnimId);
                    avdVisualizerAnimId = null;
                }
                var ctx = canvas.getContext('2d');
                if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
                if (window.AvdAudio) window.AvdAudio.playClick();
                if (window.showCopyToast) window.showCopyToast('📊 {{ __("Audio Spectrum Visualizer: Off") }}');
            }
        };

        function avdStartVisualizerAnimation() {
            var canvas = document.getElementById('avdVisualizerCanvas');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var phase = 0;

            function render() {
                if (!avdVisualizerActive) return;
                var vid = document.querySelector('video');
                var isPlaying = vid && !vid.paused && vid.currentTime > 0;

                if (canvas.width !== canvas.clientWidth) canvas.width = canvas.clientWidth || 800;
                if (canvas.height !== 50) canvas.height = 50;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                var barWidth = canvas.width / avdVisualizerBars;
                phase += isPlaying ? 0.08 : 0.02;

                var grad = ctx.createLinearGradient(0, canvas.height, 0, 0);
                grad.addColorStop(0, 'rgba(56, 189, 248, 0.2)');
                grad.addColorStop(0.5, 'rgba(168, 85, 247, 0.7)');
                grad.addColorStop(1, 'rgba(236, 72, 153, 0.95)');

                for (var i = 0; i < avdVisualizerBars; i++) {
                    var factor = Math.sin(i * 0.25 + phase) * 0.5 + 0.5;
                    var harmonic = Math.cos(i * 0.4 - phase * 1.5) * 0.3;
                    var rawHeight = (factor + harmonic) * (canvas.height - 8);
                    if (!isPlaying) rawHeight = Math.max(3, rawHeight * 0.25);
                    var barH = Math.max(2, rawHeight);

                    var x = i * barWidth;
                    var y = canvas.height - barH;

                    ctx.fillStyle = grad;
                    ctx.fillRect(x + 1, y, Math.max(1, barWidth - 2), barH);

                    // Peak glowing cap dot
                    ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
                    ctx.fillRect(x + 1, Math.max(0, y - 2), Math.max(1, barWidth - 2), 2);
                }

                avdVisualizerAnimId = requestAnimationFrame(render);
            }

            if (avdVisualizerAnimId) cancelAnimationFrame(avdVisualizerAnimId);
            render();
        }

        // Video Timestamp Notes & Scratchpad Controller (Shortcut: N)
        var avdNotesStorageKey = 'avd_notes_' + avdVideoUuid;

        window.avdToggleNotes = function() {
            var modalEl = document.getElementById('avdNotesModal');
            if (!modalEl) return;
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (!modal) modal = new bootstrap.Modal(modalEl);

            var isShown = modalEl.classList.contains('show');
            if (isShown) {
                modal.hide();
            } else {
                var ta = document.getElementById('avdNotesTextarea');
                if (ta) {
                    try {
                        ta.value = localStorage.getItem(avdNotesStorageKey) || '';
                    } catch(e) {}
                }
                modal.show();
                if (window.AvdAudio) window.AvdAudio.playClick();
                setTimeout(function() { if (ta) ta.focus(); }, 350);
            }
        };

        window.avdInsertNoteTimestamp = function() {
            var vid = document.querySelector('video');
            var curTime = (vid && vid.currentTime) ? vid.currentTime : 0;
            var timeStr = avdFormatTime(curTime);
            var ta = document.getElementById('avdNotesTextarea');
            if (!ta) return;

            var insertText = (ta.value.length > 0 && !ta.value.endsWith('\n') ? '\n' : '') + '⏱️ [' + timeStr + '] ';
            var startPos = ta.selectionStart !== undefined ? ta.selectionStart : ta.value.length;
            var endPos = ta.selectionEnd !== undefined ? ta.selectionEnd : ta.value.length;

            ta.value = ta.value.substring(0, startPos) + insertText + ta.value.substring(endPos);
            ta.selectionStart = ta.selectionEnd = startPos + insertText.length;
            ta.focus();
            window.avdSaveNotes();
            if (window.AvdAudio) window.AvdAudio.playChime();
        };

        window.avdSaveNotes = function() {
            var ta = document.getElementById('avdNotesTextarea');
            if (!ta) return;
            try {
                localStorage.setItem(avdNotesStorageKey, ta.value);
            } catch(e) {}
        };

        window.avdCopyNotes = function() {
            var ta = document.getElementById('avdNotesTextarea');
            if (!ta || !ta.value.trim()) {
                if (window.showCopyToast) window.showCopyToast('⚠️ {{ __("No notes to copy yet") }}');
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ta.value).then(function() {
                    if (window.showCopyToast) window.showCopyToast('📋 {{ __("Notes copied to clipboard!") }}');
                    if (window.AvdAudio) window.AvdAudio.playChime();
                });
            } else {
                ta.select();
                document.execCommand('copy');
                if (window.showCopyToast) window.showCopyToast('📋 {{ __("Notes copied!") }}');
                if (window.AvdAudio) window.AvdAudio.playChime();
            }
        };

        window.avdDownloadNotes = function() {
            var ta = document.getElementById('avdNotesTextarea');
            var text = ta ? ta.value : '';
            if (!text.trim()) {
                if (window.showCopyToast) window.showCopyToast('⚠️ {{ __("No notes to export yet") }}');
                return;
            }
            var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'video_' + avdVideoUuid.substring(0, 8) + '_notes.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            if (window.showCopyToast) window.showCopyToast('💾 {{ __("Notes exported as .txt!") }}');
            if (window.AvdAudio) window.AvdAudio.playChime();
        };

        function avdGetVideo() {
            var v = document.querySelector('video');
            if (v) return v;
            var ifrs = document.querySelectorAll('iframe');
            for (var i = 0; i < ifrs.length; i++) {
                try {
                    var iv = ifrs[i].contentDocument?.querySelector('video') || ifrs[i].contentWindow?.document?.querySelector('video');
                    if (iv) return iv;
                } catch(e) {}
            }
            return null;
        }

        // Floating Volume HUD & Arrow Key Controller
        var avdVolumeHudTimer = null;
        window._avdVirtualVolume = 1.0;
        window.avdAdjustVolume = function(delta) {
            var vid = avdGetVideo();
            var curVol = vid ? vid.volume : (window._avdVirtualVolume !== undefined ? window._avdVirtualVolume : 1.0);
            if (vid && vid.muted) vid.muted = false;
            var newVol = Math.max(0, Math.min(1, Math.round((curVol + delta) * 100) / 100));
            window._avdVirtualVolume = newVol;
            if (vid) vid.volume = newVol;

            var hud = document.getElementById('avdVolumeHud');
            var text = document.getElementById('avdVolumeText');
            var bar = document.getElementById('avdVolumeBar');
            var icon = document.getElementById('avdVolumeIcon');
            var pct = Math.round(newVol * 100);

            if (text) text.textContent = pct + '%';
            if (bar) bar.style.width = pct + '%';
            if (icon) {
                icon.className = pct === 0 ? 'fa-solid fa-volume-xmark text-danger' :
                                 (pct < 50 ? 'fa-solid fa-volume-low text-info' : 'fa-solid fa-volume-high text-info');
            }

            if (hud) {
                hud.style.opacity = '1';
                hud.style.transform = 'translate(-50%, -50%) scale(1)';
                if (avdVolumeHudTimer) clearTimeout(avdVolumeHudTimer);
                avdVolumeHudTimer = setTimeout(function() {
                    hud.style.opacity = '0';
                    hud.style.transform = 'translate(-50%, -50%) scale(0.9)';
                }, 1100);
            }
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Floating Seek HUD Controller
        var avdSeekHudTimer = null;
        window._avdVirtualTime = 0;
        window.avdRelativeSeek = function(deltaSecs) {
            var vid = avdGetVideo();
            var curTime = vid ? vid.currentTime : (window._avdVirtualTime || 0);
            var maxTime = (vid && vid.duration) ? vid.duration : 999999;
            var targetTime = Math.max(0, Math.min(maxTime, curTime + deltaSecs));
            window._avdVirtualTime = targetTime;
            if (vid) vid.currentTime = targetTime;

            var hud = document.getElementById('avdSeekHud');
            var text = document.getElementById('avdSeekText');
            var icon = document.getElementById('avdSeekIcon');

            if (text) {
                var sign = deltaSecs > 0 ? '+' : '';
                text.textContent = sign + deltaSecs + 's (' + avdFormatTime(targetTime) + ')';
            }
            if (icon) {
                icon.innerHTML = deltaSecs > 0 ? '<i class="fa-solid fa-forward text-warning"></i>' : '<i class="fa-solid fa-backward text-warning"></i>';
            }

            if (hud) {
                hud.style.opacity = '1';
                hud.style.transform = 'translate(-50%, -50%) scale(1)';
                if (avdSeekHudTimer) clearTimeout(avdSeekHudTimer);
                avdSeekHudTimer = setTimeout(function() {
                    hud.style.opacity = '0';
                    hud.style.transform = 'translate(-50%, -50%) scale(0.9)';
                }, 1100);
            }
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
        };

        // Smart Playback Resume Engine
        var avdResumeStorageKey = 'avd_resume_' + avdVideoUuid;
        var avdPendingResumeTime = 0;

        try {
            var savedPos = parseInt(localStorage.getItem(avdResumeStorageKey), 10);
            if (!isNaN(savedPos) && savedPos > 10) {
                avdPendingResumeTime = savedPos;
                var banner = document.getElementById('avdResumeBanner');
                var label = document.getElementById('avdResumeTimeLabel');
                if (banner && label) {
                    label.textContent = avdFormatTime(savedPos);
                    banner.classList.remove('d-none');
                    banner.classList.add('d-flex');
                    setTimeout(function() {
                        if (banner && !banner.classList.contains('d-none')) {
                            banner.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                            banner.style.opacity = '0';
                            banner.style.transform = 'translateY(-16px)';
                            setTimeout(function() { banner.classList.add('d-none'); }, 400);
                        }
                    }, 12000);
                }
            }
        } catch(e) {}

        window.avdAcceptResume = function() {
            var banner = document.getElementById('avdResumeBanner');
            if (banner) banner.classList.add('d-none');
            var vid = document.querySelector('video');
            if (vid && avdPendingResumeTime > 0) {
                vid.currentTime = avdPendingResumeTime;
                if (vid.paused) vid.play();
                if (window.AvdAudio) window.AvdAudio.playWhoosh();
                if (window.showCopyToast) window.showCopyToast('⏩ {{ __("Resumed playback from") }} ' + avdFormatTime(avdPendingResumeTime));
            }
        };

        window.avdDismissResume = function() {
            var banner = document.getElementById('avdResumeBanner');
            if (banner) banner.classList.add('d-none');
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Track playback position periodically for resume
        setInterval(function() {
            var vid = document.querySelector('video');
            if (!vid || vid.paused || !vid.currentTime || !vid.duration) return;
            if (vid.currentTime > 10 && vid.currentTime < vid.duration - 15) {
                try {
                    localStorage.setItem(avdResumeStorageKey, Math.floor(vid.currentTime));
                } catch(e) {}
            } else if (vid.currentTime >= vid.duration - 10) {
                try {
                    localStorage.removeItem(avdResumeStorageKey);
                } catch(e) {}
            }
        }, 3000);

        // Automatic PiP on scroll past the stable player slot. The provider's
        // <video-player> mounts asynchronously, so bind it after each update.
        avdPipInit();

        // Active Playback Performance Engine: pause decorative animations when media plays
        function avdSetPlaybackActive(active) {
            document.body.classList.toggle('avd-playback-active', !!active);
        }

        window.addEventListener('message', function(e) {
            if (!e.data || !avdPipFrame || e.source !== avdPipFrame.contentWindow) return;
            avdPipBindMedia();
            // A frame keeps its WindowProxy while navigating. Ignore queued
            // messages from its old document until the current source loads.
            if (!avdPipReady) return;
            try {
                var frameSource = avdPipFrameSource();
                if (!frameSource) return;
                var frameOrigin = frameSource.origin;
                var approvedProviderOrigins = [
                    'https://upload18.org', 'https://upload18.net', 'https://upload18.com',
                    'https://upload18.cc', 'https://helvid.com', 'https://321watch.workers.dev'
                ];
                if (e.origin !== frameOrigin && approvedProviderOrigins.indexOf(e.origin) === -1) return;
            } catch (originError) { return; }
            try {
                var data = typeof e.data === 'string' ? JSON.parse(e.data) : e.data;
                var evt = (data.event || data.type || (data.name ? data.name : '')).toString().toLowerCase();
                if (/^(play|playing|timeupdate)$/.test(evt)) {
                    avdPipMarkPlaying();
                    avdSetPlaybackActive(true);
                } else if (/^(pause|complete|ended|finish|stop)$/.test(evt)) {
                    avdPipMarkPaused();
                    avdSetPlaybackActive(false);
                }
            } catch(err) {}
        });

    })();
</script>
@endpush
