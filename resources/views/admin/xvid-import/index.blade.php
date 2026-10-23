@extends('layouts.admin')
@section('title', __('XVIDAPI Import'))

@section('content')
<style>
    .xvid-page {
        --xvid-card: rgba(255, 255, 255, 0.08);
        --xvid-input-bg: #111827;
        --xvid-line: var(--avd-border, rgba(255, 255, 255, 0.1));
        --xvid-muted: var(--avd-muted, #94a3b8);
        max-width: 100%;
        overflow-x: clip;
    }
    .xvid-page * {
        min-width: 0;
    }
    [data-bs-theme="light"] .xvid-page {
        --xvid-card: #fff;
        --xvid-input-bg: #fff;
        --xvid-line: rgba(15, 23, 42, 0.14);
        --xvid-muted: #445066;
    }
    .xvid-hero {
        position: relative;
        overflow: hidden;
        border: 1px solid var(--xvid-line);
        border-radius: 18px;
        background:
            radial-gradient(circle at 4% 0%, rgba(239, 68, 68, 0.15), transparent 22rem),
            radial-gradient(circle at 92% 10%, rgba(168, 85, 247, 0.15), transparent 22rem),
            linear-gradient(135deg, var(--avd-surface, #1e293b), var(--avd-surface-soft, #0f172a));
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
    }
    .xvid-hero h3 {
        font-size: 1.6rem;
        font-weight: 900;
    }
    .xvid-hero p {
        color: var(--xvid-muted) !important;
    }
    .xvid-card {
        border: 1px solid var(--xvid-line);
        border-radius: 14px;
        background: var(--xvid-card);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }
    .xvid-provider-tabs {
        display: inline-flex;
        background: rgba(0, 0, 0, 0.2);
        padding: 4px;
        border-radius: 12px;
        border: 1px solid var(--xvid-line);
    }
    .xvid-provider-tab {
        padding: 8px 18px;
        border-radius: 9px;
        font-weight: 700;
        font-size: 0.9rem;
        text-decoration: none;
        color: var(--xvid-muted);
        transition: background 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, color 0.2s ease;
    }
    .xvid-provider-tab:hover {
        color: #fff;
    }
    .xvid-provider-tab.active {
        background: linear-gradient(135deg, #ef4444, #f97316);
        color: #fff;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
    }
    .xvid-category-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(135px, 1fr));
        gap: 8px;
        max-height: 380px;
        overflow-y: auto;
        padding: 10px;
        border: 1px solid var(--xvid-line);
        border-radius: 12px;
        background: rgba(0, 0, 0, 0.15);
    }
    .xvid-cat-item {
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 44px;
        padding: 6px 10px;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid transparent;
        cursor: pointer;
        user-select: none;
        transition: background 0.15s ease, border-color 0.15s ease;
        font-size: 0.85rem;
    }
    .xvid-cat-item:hover {
        background: rgba(255, 255, 255, 0.08);
        border-color: var(--xvid-line);
    }
    .xvid-cat-item input[type="checkbox"]:checked + span {
        font-weight: 700;
        color: #f97316;
    }
    .xvid-cat-item.selected {
        background: rgba(249, 115, 22, 0.12);
        border-color: rgba(249, 115, 22, 0.4);
    }
    .xvid-provider-tab:focus-visible,
    .xvid-cat-item:focus-within,
    .xvid-page button:focus-visible,
    .xvid-page input:focus-visible,
    .xvid-page select:focus-visible {
        outline: 3px solid rgba(56, 189, 248, 0.85);
        outline-offset: 2px;
    }
    .xvid-terminal {
        background: #090d16;
        color: #38bdf8;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 0.82rem;
        border-radius: 10px;
        border: 1px solid #1e293b;
        padding: 12px;
        height: 280px;
        overflow-y: auto;
        white-space: pre-wrap;
        word-break: break-all;
    }
    @media (max-width: 767.98px) {
        .xvid-provider-tabs {
            display: flex;
            width: 100%;
        }
        .xvid-provider-tab {
            flex: 1 1 0;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding-inline: 10px;
            text-align: center;
        }
        .xvid-category-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            max-height: none;
        }
        .xvid-page .table-responsive {
            margin-inline: -0.25rem;
        }
    }
    @media (max-width: 420px) {
        .xvid-category-grid {
            grid-template-columns: 1fr;
        }
        .xvid-provider-tab {
            font-size: 0.78rem;
            padding-inline: 6px;
        }
    }
    @media (min-width: 1600px) {
        .xvid-page {
            max-width: 1800px;
            margin-inline: auto;
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .xvid-page *,
        .xvid-page *::before,
        .xvid-page *::after {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
        }
    }
</style>

<div class="xvid-page container-fluid px-3 px-lg-4 py-4">

    {{-- Provider Switcher Tabs --}}
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div class="xvid-provider-tabs">
            <a href="{{ route('admin.avdb-import.index') }}" class="xvid-provider-tab">
                <i class="fa-solid fa-cloud-arrow-down me-1"></i> {{ __('AVDBAPI Import') }}
            </a>
            <a href="{{ route('admin.xvid-import.index') }}" class="xvid-provider-tab active">
                <i class="fa-solid fa-bolt me-1"></i> {{ __('XVIDAPI Import') }}
            </a>
        </div>
        <div class="d-flex gap-2">
            <a href="https://xvidapi.com/index.php/label/help.html" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info">
                <i class="fa-solid fa-book-open me-1"></i> {{ __('API Documentation') }}
            </a>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="cleanAllXvidVideos()">
                <i class="fa-solid fa-trash-can me-1"></i> {{ __('Clean All XVIDAPI') }}
            </button>
            <button type="button" class="btn btn-sm btn-danger" onclick="forceStopImports()">
                <i class="fa-solid fa-stop me-1"></i> {{ __('Force Stop All') }}
            </button>
        </div>
    </div>

    {{-- Hero Section --}}
    <div class="xvid-hero p-4 mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-danger px-3 py-1 text-uppercase fw-bold">{{ __('New Provider') }}</span>
                    <span class="badge bg-dark border border-secondary">{{ __('N/A') }}</span>
                    <span class="badge bg-info text-dark"><i class="fa-solid fa-language me-1"></i>{{ __('Multi-language') }}</span>
                </div>
                <h3 class="text-white mb-2">{{ __('XVIDAPI Video Importer') }}</h3>
                <p class="mb-0">
                    {{ __('High-volume automated video catalog importer with 64 dynamic categories, automatic creator SVG avatars, 4K quality tags, multi-language tags and native Upload18 iframe video playback.') }}
                </p>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <button type="button" id="btnTestConnection" class="btn btn-primary px-4 py-2" onclick="testConnection()">
                    <i class="fa-solid fa-satellite-dish me-2"></i> {{ __('Test API Connection') }}
                </button>
            </div>
        </div>
        <div id="connectionStatus" class="mt-3" role="status" aria-live="polite" aria-atomic="true" style="display: none;"></div>
    </div>

    {{-- ═══════ XVIDAPI Live Ecosystem & Specifications Card ═══════ --}}
    <div class="card border-danger border-opacity-25 bg-dark-subtle shadow-sm mb-4" style="border-radius: 12px; background: linear-gradient(135deg, rgba(15, 23, 42, 0.75) 0%, rgba(30, 41, 59, 0.6) 100%);">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 mb-3 border-bottom border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-danger px-3 py-2 text-uppercase fw-bold"><i class="fa-solid fa-bolt me-1"></i>XVIDAPI Spec</span>
                    <span class="text-white fw-bold h5 mb-0">{{ __('XVIDAPI Live Ecosystem & Directory') }}</span>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="https://xvidapi.com/index.php/label/help.html" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light">
                        <i class="fa-solid fa-file-lines me-1 text-danger"></i>{{ __('Help Docs') }}
                    </a>
                    <a href="https://xvidapi.com/api.php/provide/vod/?ac=list" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-code me-1"></i>{{ __('Raw JSON Feed') }}
                    </a>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded bg-black bg-opacity-30 border border-secondary border-opacity-25">
                        <small class="text-muted text-uppercase d-block fw-semibold mb-1">{{ __('Total in API') }}</small>
                        <span class="h5 fw-black text-white mb-0">{{ ($apiSummary['available'] ?? false) ? number_format((int) $apiSummary['total']) : __('N/A') }}</span>
                        <small class="text-danger d-block">{{ __('Videos Available') }}</small>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded bg-black bg-opacity-30 border border-secondary border-opacity-25">
                        <small class="text-muted text-uppercase d-block fw-semibold mb-1">{{ __('Total Pages') }}</small>
                        <span class="h5 fw-black text-white mb-0">{{ ($apiSummary['available'] ?? false) ? number_format((int) $apiSummary['pages']) : __('N/A') }}</span>
                        @if($apiSummary['available'] ?? false)
                            <small class="text-white-50 d-block">{{ __(':count videos / page', ['count' => number_format((int) $apiSummary['page_size'])]) }}</small>
                        @endif
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded bg-black bg-opacity-30 border border-secondary border-opacity-25">
                        <small class="text-muted text-uppercase d-block fw-semibold mb-1">{{ __('API Protocol') }}</small>
                        <span class="h5 fw-black text-white mb-0">REST JSON</span>
                        <small class="text-white-50 d-block">{{ __('High-Speed Feed') }}</small>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded bg-black bg-opacity-30 border border-secondary border-opacity-25">
                        <small class="text-muted text-uppercase d-block fw-semibold mb-1">{{ __('Site Coverage') }}</small>
                        @php
                            $xvidImportedCount = (int) ($stats['total_videos'] ?? 0);
                        @endphp
                        <span class="h5 fw-black text-success mb-0">{{ number_format($xvidImportedCount) }}</span>
                        <small class="text-success-emphasis d-block">{{ __('Imported') }}</small>
                    </div>
                </div>
            </div>

            <div class="mt-3 pt-3 border-top border-secondary border-opacity-25">
                <small class="text-muted text-uppercase fw-bold d-block mb-2"><i class="fa-solid fa-layer-group me-1 text-danger"></i>{{ __('XVIDAPI Popular Catalog Categories (64 Global Genres):') }}</small>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-glasses text-info me-1"></i>VR (Virtual Reality)</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-tv text-warning me-1"></i>4K Ultra HD</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-globe-asia text-danger me-1"></i>Asian & Japanese</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-camera text-primary me-1"></i>Amateur & Real</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-heart text-danger me-1"></i>MILF & Mature</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-fire text-warning me-1"></i>Hardcore & Anal</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-robot text-info me-1"></i>AI Generated</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-user-group text-success me-1"></i>Lesbian & Bi</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1 text-muted">+56 More in selector</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Stats Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="xvid-card p-3">
                <div class="text-muted small text-uppercase fw-bold">{{ __('Total XVIDAPI Videos') }}</div>
                <div class="h3 fw-bold mb-0 text-white mt-1">{{ number_format($stats['total_videos']) }}</div>
                <div class="small text-muted mt-1">{{ __('Imported & published') }}</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="xvid-card p-3">
                <div class="text-muted small text-uppercase fw-bold">{{ __('Imported Today') }}</div>
                <div class="h3 fw-bold mb-0 text-success mt-1">+{{ number_format($stats['imported_today']) }}</div>
                <div class="small text-muted mt-1">{{ __('Since 00:00 midnight') }}</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="xvid-card p-3">
                <div class="text-muted small text-uppercase fw-bold">{{ __('Active Categories') }}</div>
                <div class="h3 fw-bold mb-0 text-primary mt-1">{{ $stats['active_categories'] }} / 64</div>
                <div class="small text-muted mt-1">{{ __('Auto-mapped in catalog') }}</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="xvid-card p-3">
                <div class="text-muted small text-uppercase fw-bold">{{ __('Active Runs') }}</div>
                <div class="h3 fw-bold mb-0 {{ $stats['active_runs'] > 0 ? 'text-warning' : 'text-muted' }} mt-1">
                    {{ $stats['active_runs'] }}
                </div>
                <div class="small text-muted mt-1">{{ __('Background tasks running') }}</div>
            </div>
        </div>
    </div>

    {{-- Import Configuration Form --}}
    <form id="xvidImportForm" onsubmit="event.preventDefault(); startImport();">
        <div class="row g-4 mb-4">

            {{-- Left Column: Category Selection --}}
            <div class="col-lg-7">
                <div class="xvid-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="fw-bold mb-1"><i class="fa-solid fa-list-check text-danger me-2"></i>{{ __('Categories Selection') }}</h5>
                            <div class="small text-muted">{{ __('Select target categories to import (64 available)') }}</div>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-xs btn-outline-secondary btn-sm" onclick="selectAllCategories(true)">{{ __('All') }}</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary btn-sm" onclick="selectAllCategories(false)">{{ __('None') }}</button>
                            <button type="button" class="btn btn-xs btn-outline-warning btn-sm" onclick="selectPopularCategories()">{{ __('Popular') }}</button>
                        </div>
                    </div>

                    {{-- Category Search Filter --}}
                    <div class="input-group input-group-sm mb-2">
                        <span class="input-group-text bg-dark border-secondary"><i class="fa-solid fa-magnifying-glass"></i></span>
                        <input type="text" id="catSearchInput" class="form-control bg-dark text-white border-secondary" placeholder="{{ __('Filter category names...') }}" oninput="filterCategoryList(this.value)">
                    </div>

                    {{-- Categories Grid --}}
                    <div class="xvid-category-grid" id="categoryGrid">
                        @foreach($xvidCategories as $catId => $catName)
                            <label class="xvid-cat-item" data-cat-id="{{ $catId }}" data-cat-name="{{ strtolower($catName) }}">
                                <input type="checkbox" name="categories[]" value="{{ $catId }}" class="form-check-input mt-0" {{ in_array($catId, [65, 49, 8, 23, 20, 44, 43, 9]) ? 'checked' : '' }}>
                                <span class="text-truncate">{{ $catName }}</span>
                            </label>
                        @endforeach
                    </div>

                    {{-- Or Specific IDs --}}
                    <div class="mt-3">
                        <label for="ad-xvid-import-ids" class="form-label small fw-bold text-muted">{{ __('Or Specific Video IDs (comma-separated, overrides categories):') }}</label>
                        <input id="ad-xvid-import-ids" type="text" name="ids" class="form-control form-control-sm bg-dark text-white border-secondary font-monospace" placeholder="e.g. 1380986, 1306510, 1332639">
                    </div>
                </div>
            </div>

            {{-- Right Column: Import Parameters --}}
            <div class="col-lg-5">
                <div class="xvid-card p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold mb-3"><i class="fa-solid fa-sliders text-primary me-2"></i>{{ __('Import Parameters') }}</h5>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="ad-xvid-import-start-page" class="form-label small fw-bold">{{ __('Start Page') }}</label>
                                <input id="ad-xvid-import-start-page" type="number" name="start_page" class="form-control bg-dark text-white border-secondary" value="1" min="1">
                            </div>
                            <div class="col-6">
                                <label for="ad-xvid-import-pages" class="form-label small fw-bold">{{ __('Pages to Import') }}</label>
                                <input id="ad-xvid-import-pages" type="number" name="pages" class="form-control bg-dark text-white border-secondary" value="2" min="1" max="50000">
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label for="ad-xvid-import-pagesize" class="form-label small fw-bold">{{ __('Videos Per Page') }}</label>
                                <input id="ad-xvid-import-pagesize" type="number" name="pagesize" class="form-control bg-dark text-white border-secondary" value="50" min="1" max="100">
                                <small class="text-muted" style="font-size: 0.75rem;">{{ __('API max: 100') }}</small>
                            </div>
                            <div class="col-6">
                                <label for="ad-xvid-import-delay" class="form-label small fw-bold">{{ __('Request Delay (ms)') }}</label>
                                <input id="ad-xvid-import-delay" type="number" name="delay" class="form-control bg-dark text-white border-secondary" value="0" min="0" step="50">
                                <small class="text-muted" style="font-size: 0.75rem;">{{ __('Throttle protection') }}</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="ad-xvid-import-skip-thumbs" class="form-label small fw-bold">{{ __('Thumbnails Storage') }}</label>
                            <select id="ad-xvid-import-skip-thumbs" name="skip_thumbs" class="form-select bg-dark text-white border-secondary">
                                <option value="1" selected>{{ __('Remote CDN (Zero Disk Usage, Recommended)') }}</option>
                                <option value="0">{{ __('Download Locally to Server Storage') }}</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="ad-xvid-import-starter-views-mode" class="form-label small fw-bold">{{ __('Starter Views Mode') }}</label>
                            @php
                                $starterViewsMode = old('starter_views_mode', $starterViewDefaults['mode'] ?? \App\Support\AvdbStarterViews::MODE_DISABLED);
                            @endphp
                            <select id="ad-xvid-import-starter-views-mode" name="starter_views_mode" class="form-select bg-dark text-white border-secondary">
                                @foreach($starterViewModes as $modeKey => $modeLabel)
                                    <option value="{{ $modeKey }}" @selected($modeKey === $starterViewsMode)>{{ $modeLabel }}</option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Language (XVIDAPI multi-language API) --}}
                        <div class="mb-3 p-2 rounded border border-info border-opacity-50" id="xvidLanguageBlock">
                            <label class="form-label small fw-bold mb-1" for="xvidSourceLanguage">
                                <i class="fa-solid fa-language me-1 text-info"></i> {{ __('Language') }}
                            </label>
                            <select name="search_source_language" id="xvidSourceLanguage" class="form-select form-select-sm bg-dark text-white border-secondary mb-1">
                                <option value="">{{ __('Any original language') }}</option>
                                <option value="ja">{{ __('Japanese') }}</option>
                                <option value="zh">{{ __('Chinese') }}</option>
                                <option value="ko">{{ __('Korean') }}</option>
                                <option value="vi">{{ __('Vietnamese') }}</option>
                                <option value="th">{{ __('Thai') }}</option>
                                <option value="id">{{ __('Indonesian') }}</option>
                                <option value="tl">{{ __('Filipino') }}</option>
                                <option value="hi">{{ __('Hindi') }}</option>
                                <option value="ar">{{ __('Arabic') }}</option>
                                <option value="ru">{{ __('Russian') }}</option>
                                <option value="de">{{ __('German') }}</option>
                                <option value="fr">{{ __('French') }}</option>
                                <option value="es">{{ __('Spanish') }}</option>
                                <option value="pt">{{ __('Portuguese') }}</option>
                                <option value="it">{{ __('Italian') }}</option>
                                <option value="en">{{ __('English') }}</option>
                            </select>
                            <div class="form-text text-muted small mb-2">{{ __('Original language of the videos to import (filters by the title language).') }}</div>
                            <div class="form-check mb-0">
                                <input type="hidden" name="translate_tags" value="0">
                                <input type="checkbox" name="translate_tags" value="1" class="form-check-input" id="translateTagsCheck" checked>
                                <label class="form-check-label small" for="translateTagsCheck">{{ __('Translate tags into all active site languages') }}</label>
                            </div>
                            <div class="form-text text-muted small mt-1">{{ __('Tags are shown in the language the visitor picks (Admin → Languages). Titles stay in their original language — XVIDAPI does not translate them.') }}</div>
                        </div>

                        {{-- Collapsible Advanced Filters --}}
                        <div class="accordion mb-3" id="advFilterAccordion">
                            <div class="accordion-item bg-dark border-secondary">
                                <h2 class="accordion-header" id="headingFilters">
                                    <button class="accordion-button collapsed bg-dark text-white p-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFilters">
                                        <i class="fa-solid fa-filter me-2 text-warning"></i> {{ __('Search & Content Filters') }}
                                    </button>
                                </h2>
                                <div id="collapseFilters" class="accordion-collapse collapse" data-bs-parent="#advFilterAccordion">
                                    <div class="accordion-body p-2">
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <input type="text" name="search_wd" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="{{ __('Keyword / Title') }}">
                                            </div>
                                            <div class="col-6">
                                                <input type="text" name="search_code" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="{{ __('Movie Code') }}">
                                            </div>
                                            <div class="col-6">
                                                <input type="text" name="search_actor" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="{{ __('Actor') }}">
                                            </div>
                                            <div class="col-6">
                                                <input type="text" name="search_writer" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="{{ __('Studio / Channel') }}">
                                            </div>
                                            <div class="col-6">
                                                <input type="text" name="year" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="{{ __('Year (e.g. 2025)') }}">
                                            </div>
                                            <div class="col-6">
                                                <input type="number" name="updated_hours" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="{{ __('Updated within hours') }}">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-3 mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="skip_tags" value="1" class="form-check-input" id="skipTagsCheck">
                                <label class="form-check-label small" for="skipTagsCheck">{{ __('Skip Tags') }}</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" name="dry_run" value="1" class="form-check-input" id="dryRunCheck">
                                <label class="form-check-label small text-warning" for="dryRunCheck">{{ __('Dry Run (Simulate Only)') }}</label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="btnStartImport" class="btn btn-danger btn-lg w-100 py-3 fw-bold shadow">
                        <i class="fa-solid fa-play me-2"></i> {{ __('Start XVIDAPI Import') }}
                    </button>
                </div>
            </div>
        </div>
    </form>

    {{-- Import Runs History & Schedules Tabs --}}
    <div class="xvid-card p-4">
        <ul class="nav nav-tabs border-secondary mb-3" id="historyTab" role="tablist">
            <li class="nav-item">
                <button class="nav-link active text-white" id="runs-tab" data-bs-toggle="tab" data-bs-target="#runs-pane" type="button">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i> {{ __('Import Runs History') }}
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link text-white" id="schedules-tab" data-bs-toggle="tab" data-bs-target="#schedules-pane" type="button">
                    <i class="fa-solid fa-calendar-check me-1"></i> {{ __('Automated Cron Schedules') }}
                </button>
            </li>
        </ul>

        <div class="tab-content" id="historyTabContent">
            {{-- Runs History Pane --}}
            <div class="tab-pane fade show active" id="runs-pane">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted small">{{ __('History of recent XVIDAPI imports') }}</span>
                    <form method="POST" action="{{ route('admin.xvid-import.runs.clean-finished') }}" onsubmit="return confirm('{{ __('Clear all finished/cancelled run logs?') }}')">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="fa-solid fa-trash me-1"></i> {{ __('Clean Finished Runs') }}
                        </button>
                    </form>
                </div>

                <div class="table-responsive" tabindex="0">
                    <table class="table table-dark table-hover align-middle">
                        <thead>
                            <tr class="text-muted small">
                                <th>#</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Category') }}</th>
                                <th>{{ __('Imported') }}</th>
                                <th>{{ __('Updated') }}</th>
                                <th>{{ __('Skipped') }}</th>
                                <th>{{ __('Failed') }}</th>
                                <th>{{ __('Progress') }}</th>
                                <th>{{ __('Started') }}</th>
                                <th>{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentRuns as $run)
                                <tr>
                                    <td class="font-monospace">#{{ $run->id }}</td>
                                    <td>
                                        <span class="badge bg-{{ match($run->status) { 'finished' => 'success', 'running' => 'primary', 'failed' => 'danger', 'cancelled' => 'secondary', default => 'info' } }}">
                                            {{ ucfirst($run->status) }}
                                        </span>
                                    </td>
                                    <td>{{ $run->current_category ?: __('Multiple') }}</td>
                                    <td class="text-success fw-bold">+{{ number_format($run->imported) }}</td>
                                    <td class="text-info">{{ number_format($run->updated) }}</td>
                                    <td class="text-muted">{{ number_format($run->skipped) }}</td>
                                    <td class="{{ $run->failed > 0 ? 'text-danger fw-bold' : 'text-muted' }}">{{ number_format($run->failed) }}</td>
                                    <td>
                                        <div class="progress" style="height: 6px; width: 80px;">
                                            <div class="progress-bar bg-danger" style="width: {{ $run->progress }}%"></div>
                                        </div>
                                    </td>
                                    <td class="small text-muted">{{ $run->created_at?->diffForHumans() }}</td>
                                    <td>
                                        <button type="button" class="btn btn-xs btn-outline-info btn-sm" onclick="openProgressModal({{ $run->id }})">
                                            <i class="fa-solid fa-terminal"></i>
                                        </button>
                                        @if(in_array($run->status, ['queued', 'starting', 'running']))
                                            <button type="button" class="btn btn-xs btn-outline-danger btn-sm" onclick="stopRun({{ $run->id }})">
                                                <i class="fa-solid fa-stop"></i>
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">{{ __('No XVIDAPI imports recorded yet.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Schedules Pane --}}
            <div class="tab-pane fade" id="schedules-pane">
                <div class="row">
                    <div class="col-lg-5 mb-4">
                        <div class="p-3 bg-dark border border-secondary rounded">
                            <h6 class="fw-bold mb-3"><i class="fa-solid fa-plus text-success me-2"></i>{{ __('Add New Scheduled Cron') }}</h6>
                            <form id="newScheduleForm" onsubmit="event.preventDefault(); createSchedule();">
                                <input type="hidden" name="start_page" value="1">
                                <input type="hidden" name="delay" value="0">
                                <div class="mb-2">
                                    <label for="ad-xvid-import-name" class="form-label small">{{ __('Schedule Name') }}</label>
                                    <input id="ad-xvid-import-name" type="text" name="name" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="e.g. Daily Top Teen & MILF" required>
                                </div>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label for="ad-xvid-import-frequency" class="form-label small">{{ __('Frequency') }}</label>
                                        <select id="ad-xvid-import-frequency" name="frequency" class="form-select form-select-sm bg-dark text-white border-secondary">
                                            <option value="hourly">{{ __('Hourly') }}</option>
                                            <option value="daily" selected>{{ __('Daily') }}</option>
                                            <option value="weekly">{{ __('Weekly') }}</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label for="ad-xvid-import-run-time" class="form-label small">{{ __('Run Time') }}</label>
                                        <input id="ad-xvid-import-run-time" type="text" name="run_time" class="form-control form-control-sm bg-dark text-white border-secondary" value="03:00">
                                    </div>
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label for="ad-xvid-import-pages-2" class="form-label small">{{ __('Pages') }}</label>
                                        <input id="ad-xvid-import-pages-2" type="number" name="pages" class="form-control form-control-sm bg-dark text-white border-secondary" value="1" min="1">
                                    </div>
                                    <div class="col-6">
                                        <label for="ad-xvid-import-pagesize-2" class="form-label small">{{ __('Page Size') }}</label>
                                        <input id="ad-xvid-import-pagesize-2" type="number" name="pagesize" class="form-control form-control-sm bg-dark text-white border-secondary" value="50" min="1" max="100">
                                    </div>
                                </div>
                                <div class="form-text text-muted small mb-2">{{ __('The schedule imports the categories ticked in the import form above. If none are ticked, the default category set is used.') }}</div>
                                <button type="submit" class="btn btn-sm btn-success w-100 fw-bold">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> {{ __('Save Cron Schedule') }}
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="table-responsive" tabindex="0">
                            <table class="table table-dark table-hover align-middle">
                                <thead>
                                    <tr class="text-muted small">
                                        <th>{{ __('Name') }}</th>
                                        <th>{{ __('Frequency') }}</th>
                                        <th>{{ __('Next Run') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($importSchedules as $schedule)
                                        <tr>
                                            <td class="fw-bold">{{ $schedule->name }}</td>
                                            <td class="small">{{ $schedule->scheduleLabel() }}</td>
                                            <td class="small font-monospace">{{ $schedule->next_run_at?->diffForHumans() ?: 'N/A' }}</td>
                                            <td>
                                                <span class="badge bg-{{ $schedule->is_active ? 'success' : 'secondary' }}">
                                                    {{ $schedule->is_active ? __('Active') : __('Paused') }}
                                                </span>
                                            </td>
                                            <td>
                                                <form method="POST" action="{{ route('admin.xvid-import.schedules.run-now', $schedule) }}" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-xs btn-outline-primary btn-sm" title="{{ __('Run Now') }}">
                                                        <i class="fa-solid fa-play"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.xvid-import.schedules.toggle', $schedule) }}" class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-xs btn-outline-warning btn-sm" title="{{ __('Toggle Pause') }}">
                                                        <i class="fa-solid fa-pause"></i>
                                                    </button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.xvid-import.schedules.destroy', $schedule) }}" class="d-inline" onsubmit="return confirm('{{ __('Delete schedule?') }}')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-xs btn-outline-danger btn-sm" title="{{ __('Delete') }}">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted">{{ __('No automated schedules configured.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Live Progress & Console Modal --}}
<div class="modal fade" id="progressModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title text-white">
                    <i class="fa-solid fa-bolt text-danger me-2"></i> {{ __('XVIDAPI Import Progress') }}
                    <span id="modalRunBadge" class="badge bg-primary ms-2">#0</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                {{-- Progress Bar --}}
                <div class="mb-3">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span id="modalStatusText">{{ __('Initializing...') }}</span>
                        <span id="modalPercentText">0%</span>
                    </div>
                    <div class="progress" style="height: 12px;">
                        <div id="modalProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-danger" style="width: 0%"></div>
                    </div>
                </div>

                {{-- Counters Row --}}
                <div class="row g-2 text-center mb-3">
                    <div class="col-3">
                        <div class="p-2 border border-secondary rounded">
                            <div class="small text-muted">{{ __('Imported') }}</div>
                            <div id="cntImported" class="h5 mb-0 fw-bold text-success">0</div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-2 border border-secondary rounded">
                            <div class="small text-muted">{{ __('Updated') }}</div>
                            <div id="cntUpdated" class="h5 mb-0 fw-bold text-info">0</div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-2 border border-secondary rounded">
                            <div class="small text-muted">{{ __('Skipped') }}</div>
                            <div id="cntSkipped" class="h5 mb-0 fw-bold text-muted">0</div>
                        </div>
                    </div>
                    <div class="col-3">
                        <div class="p-2 border border-secondary rounded">
                            <div class="small text-muted">{{ __('Failed') }}</div>
                            <div id="cntFailed" class="h5 mb-0 fw-bold text-danger">0</div>
                        </div>
                    </div>
                </div>

                {{-- Console Terminal --}}
                <div class="mb-2 d-flex justify-content-between align-items-center">
                    <span class="small fw-bold text-muted"><i class="fa-solid fa-terminal me-1"></i>{{ __('Execution Console Log') }}</span>
                    <button type="button" class="btn btn-xs btn-outline-secondary btn-sm" onclick="clearTerminal()">{{ __('Clear') }}</button>
                </div>
                <div id="modalTerminal" class="xvid-terminal"></div>
            </div>
            <div class="modal-footer border-secondary justify-content-between">
                <button type="button" id="btnModalStop" class="btn btn-outline-danger btn-sm" onclick="stopCurrentRun()">
                    <i class="fa-solid fa-stop me-1"></i> {{ __('Stop Import') }}
                </button>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">{{ __('Close Window') }}</button>
            </div>
        </div>
    </div>
</div>

<script>
    let activeRunId = null;
    let pollTimer = null;
    let _progressModalInstance = null;

    function getProgressModal() {
        if (_progressModalInstance) return _progressModalInstance;
        const el = document.getElementById('progressModal');
        if (!el) return null;

        const bs = window.bootstrap || (typeof bootstrap !== 'undefined' ? bootstrap : null);
        if (bs && bs.Modal) {
            _progressModalInstance = bs.Modal.getOrCreateInstance(el);
            return _progressModalInstance;
        }

        // Resilient fallback if Bootstrap JS is not yet loaded or missing
        return {
            show: () => {
                el.classList.add('show');
                el.style.display = 'block';
                document.body.classList.add('modal-open');
                let backdrop = document.getElementById('modalBackdropFallback');
                if (!backdrop) {
                    backdrop = document.createElement('div');
                    backdrop.id = 'modalBackdropFallback';
                    backdrop.className = 'modal-backdrop fade show';
                    document.body.appendChild(backdrop);
                }
            },
            hide: () => {
                el.classList.remove('show');
                el.style.display = 'none';
                document.body.classList.remove('modal-open');
                const backdrop = document.getElementById('modalBackdropFallback');
                if (backdrop) backdrop.remove();
            }
        };
    }

    async function testConnection() {
        const btn = document.getElementById('btnTestConnection');
        const statusDiv = document.getElementById('connectionStatus');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> {{ __("Testing...") }}';
        statusDiv.style.display = 'block';
        statusDiv.innerHTML = '<div class="alert alert-info py-2 mb-0"><i class="fa-solid fa-spinner fa-spin me-2"></i> {{ __("Connecting to XVIDAPI server...") }}</div>';

        try {
            const res = await fetch('{{ route("admin.xvid-import.test") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ api_base_url: '{{ $defaultApiBase }}' })
            });
            const data = await res.json();
            if (data.success) {
                statusDiv.innerHTML = `<div class="alert alert-success py-2 mb-0 d-flex align-items-center justify-content-between">
                    <div>
                        <i class="fa-solid fa-circle-check text-success me-2"></i>
                        <strong>${data.message}</strong>
                    </div>
                    <span class="badge bg-dark">${data.response_time_ms}ms latency</span>
                </div>`;
            } else {
                statusDiv.innerHTML = `<div class="alert alert-danger py-2 mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i> ${data.message}</div>`;
            }
        } catch (e) {
            statusDiv.innerHTML = `<div class="alert alert-danger py-2 mb-0"><i class="fa-solid fa-triangle-exclamation me-2"></i> ${e.message}</div>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-satellite-dish me-2"></i> {{ __("Test API Connection") }}';
        }
    }

    function selectAllCategories(state) {
        document.querySelectorAll('#categoryGrid input[type="checkbox"]').forEach(cb => {
            cb.checked = state;
            updateCatItemVisual(cb);
        });
    }

    function selectPopularCategories() {
        const popularIds = [65, 49, 8, 23, 20, 44, 43, 9, 24, 22, 17, 18];
        document.querySelectorAll('#categoryGrid input[type="checkbox"]').forEach(cb => {
            cb.checked = popularIds.includes(parseInt(cb.value));
            updateCatItemVisual(cb);
        });
    }

    function updateCatItemVisual(checkbox) {
        const parent = checkbox.closest('.xvid-cat-item');
        if (parent) {
            if (checkbox.checked) {
                parent.classList.add('selected');
            } else {
                parent.classList.remove('selected');
            }
        }
    }

    document.querySelectorAll('#categoryGrid input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', () => updateCatItemVisual(cb));
        updateCatItemVisual(cb);
    });

    function filterCategoryList(query) {
        query = query.toLowerCase().trim();
        document.querySelectorAll('.xvid-cat-item').forEach(el => {
            const name = el.getAttribute('data-cat-name') || '';
            el.style.display = name.includes(query) ? 'flex' : 'none';
        });
    }

    async function startImport() {
        const btn = document.getElementById('btnStartImport');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> {{ __("Starting Import...") }}';

        const form = document.getElementById('xvidImportForm');
        const formData = new FormData(form);
        const jsonBody = {};

        formData.forEach((val, key) => {
            if (key.endsWith('[]')) {
                const k = key.slice(0, -2);
                if (!jsonBody[k]) jsonBody[k] = [];
                jsonBody[k].push(parseInt(val));
            } else {
                jsonBody[key] = val;
            }
        });

        try {
            const res = await fetch('{{ route("admin.xvid-import.start") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(jsonBody)
            });
            const data = await res.json();
            if (data.success && data.run_id) {
                openProgressModal(data.run_id);
            } else {
                alert(data.message || 'Could not start import.');
            }
        } catch (e) {
            alert('Error: ' + e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-play me-2"></i> {{ __("Start XVIDAPI Import") }}';
        }
    }

    function openProgressModal(runId) {
        activeRunId = runId;
        const badge = document.getElementById('modalRunBadge');
        if (badge) badge.innerText = '#' + runId;
        const modal = getProgressModal();
        if (modal) modal.show();
        pollProgress();
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollProgress, 1200);
    }

    async function pollProgress() {
        if (!activeRunId) return;

        try {
            const res = await fetch(`{{ url('/admin/xvid-import/runs') }}/${activeRunId}`);
            const data = await res.json();
            if (!data.success || !data.run) return;

            const run = data.run;
            const statusEl = document.getElementById('modalStatusText');
            const percentEl = document.getElementById('modalPercentText');
            const barEl = document.getElementById('modalProgressBar');

            if (statusEl) statusEl.innerText = run.message || run.status;
            if (percentEl) percentEl.innerText = (run.progress || 0) + '%';
            if (barEl) barEl.style.width = (run.progress || 0) + '%';

            const cImp = document.getElementById('cntImported');
            const cUpd = document.getElementById('cntUpdated');
            const cSkp = document.getElementById('cntSkipped');
            const cFld = document.getElementById('cntFailed');

            if (cImp) cImp.innerText = run.imported || 0;
            if (cUpd) cUpd.innerText = run.updated || 0;
            if (cSkp) cSkp.innerText = run.skipped || 0;
            if (cFld) cFld.innerText = run.failed || 0;

            const term = document.getElementById('modalTerminal');
            if (term && run.log) {
                term.innerText = run.log;
                term.scrollTop = term.scrollHeight;
            }

            const stopBtn = document.getElementById('btnModalStop');
            if (['finished', 'failed', 'cancelled'].includes(run.status)) {
                if (pollTimer) clearInterval(pollTimer);
                if (stopBtn) stopBtn.disabled = true;
                if (barEl) barEl.classList.remove('progress-bar-animated');
            } else {
                if (stopBtn) stopBtn.disabled = false;
            }
        } catch (e) {
            // Ignore temporary network blips during polling
        }
    }

    async function stopRun(runId) {
        if (!runId) return;
        if (!confirm('{{ __("Cancel this import run?") }}')) return;

        try {
            const res = await fetch(`{{ url('/admin/xvid-import/runs') }}/${runId}/stop`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            });
            const data = await res.json();
            if (activeRunId === runId) {
                pollProgress();
            } else {
                window.location.reload();
            }
        } catch (e) {
            alert('Could not cancel: ' + e.message);
        }
    }

    async function stopCurrentRun() {
        if (!activeRunId) return;
        await stopRun(activeRunId);
    }

    async function forceStopImports() {
        if (!confirm('{{ __("Force stop all active XVIDAPI imports?") }}')) return;
        try {
            const res = await fetch('{{ route("admin.xvid-import.force-stop") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            });
            const data = await res.json();
            alert(data.message);
            window.location.reload();
        } catch (e) {
            alert('Error: ' + e.message);
        }
    }

    async function cleanAllXvidVideos() {
        if (!confirm('{{ __("This will permanently delete ALL XVIDAPI imported videos, their tags, and thumbnails. Are you sure?") }}')) return;
        try {
            const res = await fetch('{{ route("admin.xvid-import.clean") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            });
            const data = await res.json();
            alert(data.message);
            window.location.reload();
        } catch (e) {
            alert('Error: ' + e.message);
        }
    }

    function clearTerminal() {
        const term = document.getElementById('modalTerminal');
        if (term) term.innerText = '';
    }

    const modalEl = document.getElementById('progressModal');
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (pollTimer) clearInterval(pollTimer);
        });
    }

    async function createSchedule() {
        const form = document.getElementById('newScheduleForm');
        const formData = new FormData(form);
        const jsonBody = {};
        formData.forEach((val, key) => jsonBody[key] = val);
        // A schedule reuses the categories ticked in the import form above; with none ticked
        // the server falls back to the default category set instead of importing nothing.
        jsonBody.categories = Array.from(document.querySelectorAll('input[name="categories[]"]:checked')).map(el => el.value);

        try {
            const res = await fetch('{{ route("admin.xvid-import.schedules.store") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(jsonBody)
            });
            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.message);
            }
        } catch (e) {
            alert('Error: ' + e.message);
        }
    }

    // Explicitly attach all functions to window to ensure global HTML event handlers work seamlessly
    window.testConnection = testConnection;
    window.selectAllCategories = selectAllCategories;
    window.selectPopularCategories = selectPopularCategories;
    window.filterCategoryList = filterCategoryList;
    window.startImport = startImport;
    window.openProgressModal = openProgressModal;
    window.pollProgress = pollProgress;
    window.stopRun = stopRun;
    window.stopCurrentRun = stopCurrentRun;
    window.forceStopImports = forceStopImports;
    window.cleanAllXvidVideos = cleanAllXvidVideos;
    window.clearTerminal = clearTerminal;
    window.createSchedule = createSchedule;
</script>
@endsection
