@extends('layouts.admin')
@section('title', __('AVDBAPI Import Center'))

@section('content')
<style>
    .avdb-page {
        --avdb-card: rgba(255, 255, 255, 0.08);
        --avdb-input-bg: #111827;
        --avdb-line: var(--avd-border);
        --avdb-muted: var(--avd-muted);
        max-width: 100%;
        overflow-x: clip;
    }
    .avdb-page * {
        min-width: 0;
    }
    [data-bs-theme="light"] .avdb-page {
        --avdb-card: #fff;
        --avdb-input-bg: #fff;
        --avdb-line: rgba(15, 23, 42, 0.14);
        --avdb-muted: #445066;
    }
    .avdb-hero {
        position: relative;
        overflow: hidden;
        border: 1px solid var(--avdb-line);
        border-radius: 18px;
        background:
            radial-gradient(circle at 4% 0%, rgba(var(--avd-brand-rgb), 0.14), transparent 20rem),
            radial-gradient(circle at 92% 10%, rgba(var(--avd-cyan-rgb), 0.12), transparent 22rem),
            linear-gradient(135deg, var(--avd-surface), var(--avd-surface-soft));
        box-shadow: var(--avd-shadow-soft);
    }
    .avdb-hero h3 {
        font-size: 1.55rem;
        font-weight: 950;
    }
    .avdb-hero p {
        color: var(--avdb-muted) !important;
        overflow-wrap: anywhere;
    }
    .avdb-card {
        border: 1px solid var(--avdb-line);
        border-radius: 14px;
        background: var(--avdb-card);
        box-shadow: var(--avd-shadow-soft);
    }
    .avdb-stat-grid {
        --bs-gutter-x: 0.85rem;
        --bs-gutter-y: 0.85rem;
    }
    .avdb-stat-grid .avdb-card {
        min-height: 92px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }
    .avdb-panel {
        border: 1px solid var(--avdb-line);
        border-radius: 18px;
        background: var(--avdb-card);
        box-shadow: var(--avd-shadow-soft);
    }
    .avdb-config-stack {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    .avdb-config-section {
        padding: 1rem;
        border: 1px solid var(--avdb-line);
        border-radius: 10px;
        background: rgba(17, 24, 39, 0.32);
    }
    [data-bs-theme="light"] .avdb-config-section {
        background: rgba(248, 250, 252, 0.9);
    }
    .avdb-config-head {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid var(--avdb-line);
    }
    .avdb-config-head .avdb-section-title {
        margin-bottom: 0;
    }
    .avdb-section-note {
        max-width: 32rem;
        color: var(--avdb-muted);
        font-size: 0.8rem;
        line-height: 1.45;
    }
    .avdb-field-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem;
    }
    .avdb-field-span {
        grid-column: 1 / -1;
    }
    .avdb-cat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
        gap: 0.65rem;
    }
    .avdb-cat-card {
        display: grid;
        grid-template-columns: 1rem minmax(0, 1fr) max-content;
        align-items: center;
        gap: 0.65rem;
        min-height: 4.35rem;
        padding: 0.7rem 0.8rem;
        border: 1px solid var(--avdb-line);
        border-radius: 8px;
        background: var(--avd-surface-soft);
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s;
    }
    .avdb-cat-card .form-check-input {
        margin: 0;
    }
    .avdb-cat-card:hover {
        border-color: var(--avd-brand);
        background: var(--avd-brand-soft);
    }
    .avdb-cat-label {
        display: grid;
        grid-template-columns: 1rem minmax(0, 1fr);
        align-items: center;
        gap: 0.45rem;
        min-width: 0;
        line-height: 1.25;
    }
    .avdb-cat-label i {
        width: 1rem;
        text-align: center;
    }
    .avdb-cat-name {
        min-width: 0;
        overflow-wrap: anywhere;
    }
    .avdb-cat-card input:checked + .avdb-cat-label {
        color: var(--avd-brand);
        font-weight: 800;
    }
    .avdb-cat-card input:checked ~ .avdb-cat-count {
        border-color: rgba(255, 77, 109, 0.35);
        background: rgba(255, 77, 109, 0.14);
        color: var(--avd-brand);
    }
    .avdb-cat-card .avdb-cat-count {
        justify-self: end;
        margin-left: 0;
    }
    .avdb-cat-count {
        margin-left: auto;
        min-width: 4.6rem;
        padding: 0.28rem 0.5rem;
        border: 1px solid var(--avdb-line);
        border-radius: 8px;
        background: var(--avd-surface-hover);
        color: var(--avdb-muted);
        font-size: 0.7rem;
        font-weight: 800;
        line-height: 1;
        text-align: center;
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
    }
    .avdb-page a:focus-visible,
    .avdb-page button:focus-visible,
    .avdb-page input:focus-visible,
    .avdb-page select:focus-visible,
    .avdb-page textarea:focus-visible,
    .avdb-cat-card:focus-within {
        outline: 3px solid rgba(56, 189, 248, 0.85);
        outline-offset: 2px;
    }
    .avdb-section-title {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        margin-bottom: 1rem;
        font-size: 1rem;
        font-weight: 950;
    }
    .avdb-switch-card {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.62rem 0.75rem;
        border: 1px solid var(--avdb-line);
        border-radius: 14px;
        background: var(--avd-surface-soft);
    }
    .avdb-estimate-card {
        border-color: var(--avdb-line) !important;
        background: var(--avd-surface-soft);
    }
    .avdb-quick-controls {
        padding-top: 0.85rem;
        border-top: 1px dashed var(--avdb-line);
    }
    .avdb-action-strip {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
        padding: 0.9rem;
        border: 1px solid var(--avdb-line);
        border-radius: 10px;
        background: var(--avd-surface-soft);
    }
    .avdb-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.3rem 0.58rem;
        border-radius: 999px;
        border: 1px solid var(--avdb-line);
        background: var(--avd-surface-soft);
        color: var(--avdb-muted);
        font-size: 0.74rem;
        font-weight: 850;
    }
    .avdb-connection-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #64748b;
    }
    .avdb-connection-dot.connected {
        background: #22c55e;
        box-shadow: 0 0 8px rgba(34, 197, 94, 0.5);
    }
    .avdb-connection-dot.failed {
        background: #ef4444;
        box-shadow: 0 0 8px rgba(239, 68, 68, 0.4);
    }
    .avdb-log-box {
        max-height: 420px;
        overflow-y: auto;
        padding: 0.85rem;
        border: 1px solid var(--avdb-line);
        border-radius: 12px;
        background: #0d1117;
        color: #c9d1d9;
        font-family: 'JetBrains Mono', 'Fira Code', monospace;
        font-size: 0.78rem;
        line-height: 1.6;
        white-space: pre-wrap;
    }
    .avdb-progress-bar {
        height: 6px;
        border-radius: 999px;
        background: var(--avd-surface-hover);
        overflow: hidden;
    }
    .avdb-progress-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, var(--avd-brand), var(--avd-brand-2));
        transition: width 0.4s ease;
    }
    .avdb-page .form-control,
    .avdb-page .form-select {
        min-height: 42px;
        border-color: var(--avdb-line);
        background-color: var(--avdb-input-bg);
        color: var(--avd-text);
    }
    .avdb-page .form-control:focus,
    .avdb-page .form-select:focus {
        border-color: var(--avd-brand);
        background-color: var(--avdb-input-bg);
        color: var(--avd-text);
        box-shadow: 0 0 0 4px var(--avd-brand-soft);
    }
    [data-bs-theme="light"] .avdb-page .form-control,
    [data-bs-theme="light"] .avdb-page .form-select {
        background-color: #fff;
        color: #111827;
    }
    .avdb-queue-modal {
        position: fixed;
        inset: 0;
        z-index: 1085;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        background: rgba(3, 7, 18, 0.72);
        backdrop-filter: blur(8px);
    }
    .avdb-queue-modal.is-visible {
        display: flex;
    }
    .avdb-queue-dialog {
        width: min(440px, 100%);
        overflow: hidden;
        border: 1px solid rgba(var(--avd-brand-rgb), 0.42);
        border-radius: 18px;
        background:
            radial-gradient(circle at 12% 0%, rgba(var(--avd-brand-rgb), 0.16), transparent 16rem),
            var(--avd-surface);
        box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
    }
    .avdb-queue-dialog.avdb-duration-dialog {
        width: min(620px, 100%);
    }
    .avdb-queue-dialog-body {
        display: grid;
        gap: 1rem;
        padding: 1.35rem;
        text-align: center;
    }
    .avdb-queue-icon {
        width: 58px;
        height: 58px;
        margin: 0 auto;
        display: grid;
        place-items: center;
        border-radius: 999px;
        background: rgba(34, 197, 94, 0.14);
        color: #4ade80;
        font-size: 1.55rem;
        box-shadow: 0 0 0 8px rgba(34, 197, 94, 0.06);
    }
    .avdb-queue-title {
        margin: 0;
        font-size: 1.35rem;
        font-weight: 950;
    }
    .avdb-queue-text {
        margin: 0;
        color: var(--avdb-muted);
    }
    .avdb-queue-count {
        display: inline-grid;
        place-items: center;
        width: 48px;
        height: 48px;
        margin: 0 auto;
        border-radius: 999px;
        border: 1px solid rgba(var(--avd-brand-rgb), 0.38);
        background: var(--avd-brand-soft);
        color: var(--avd-text);
        font-size: 1.25rem;
        font-weight: 950;
        font-variant-numeric: tabular-nums;
    }
    .avdb-queue-actions {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 0.65rem;
    }
    .avdb-duration-progress {
        height: 10px;
        overflow: hidden;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.18);
    }
    .avdb-duration-progress-fill {
        width: 0;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, var(--avd-brand), #60a5fa);
        transition: width 180ms ease;
    }
    .avdb-duration-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.65rem;
    }
    .avdb-duration-stat {
        padding: 0.75rem;
        border: 1px solid var(--avdb-line);
        border-radius: 12px;
        background: rgba(15, 23, 42, 0.28);
    }
    @media (max-width: 560px) {
        .avdb-duration-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 767.98px) {
        .avdb-field-grid { grid-template-columns: 1fr; }
        .avdb-cat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 560px) {
        .avdb-cat-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 767.98px) {
        .avdb-page > .d-flex:first-child > .d-inline-flex {
            display: flex !important;
            width: 100%;
        }
        .avdb-page > .d-flex:first-child > .d-inline-flex > .btn {
            flex: 1 1 0;
            min-height: 44px;
            white-space: normal;
        }
        .avdb-hero .d-flex.flex-wrap.gap-2 > .btn {
            flex: 1 1 12rem;
            min-height: 44px;
        }
    }
    @media (min-width: 1600px) {
        .avdb-page {
            max-width: 1800px;
            margin-inline: auto;
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .avdb-page *,
        .avdb-page *::before,
        .avdb-page *::after {
            transition-duration: 0.01ms !important;
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
        }
    }
</style>

<div class="avdb-page">

    {{-- Provider Switcher Tabs & External Documentation / Support Links --}}
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3 mt-3">
        <div class="d-inline-flex bg-dark p-1 rounded-3 border border-secondary">
            <a href="{{ route('admin.avdb-import.index') }}" class="btn btn-sm btn-primary fw-bold">
                <i class="fa-solid fa-cloud-arrow-down me-1"></i> {{ __('AVDBAPI Import') }}
            </a>
            <a href="{{ route('admin.xvid-import.index') }}" class="btn btn-sm text-white fw-bold">
                <i class="fa-solid fa-bolt me-1 text-danger"></i> {{ __('XVIDAPI Import') }}
            </a>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="https://avdbapi.com/index.php/label/help.html" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info">
                <i class="fa-solid fa-book-open me-1"></i> {{ __('API Documentation') }}
            </a>
            <a href="https://t.me/avdb_api" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" style="color: #38bdf8; border-color: #0284c7;">
                <i class="fa-brands fa-telegram me-1"></i> {{ __('Telegram Support') }}
            </a>
        </div>
    </div>

    {{-- ═══════ Hero header ═══════ --}}
    <div class="avdb-hero p-3 p-md-4 mb-4">
        <div class="d-flex flex-column flex-xl-row align-items-xl-center justify-content-between gap-3">
            <div>
                <span class="avdb-status-pill mb-2">
                    <span class="avdb-connection-dot" id="connectionDot"></span>
                    <span id="connectionStatus">{{__('Not tested')}}</span>
                </span>
                <h3 class="mb-1"><i class="fa-solid fa-cloud-arrow-down me-2 text-primary"></i>{{__('AVDBAPI Import Center')}}</h3>
                <p class="text-muted mb-0">{{__('Import videos from AVDBAPI with full control over categories, languages, thumbnails, and automated scheduling.')}}</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary" id="btnTestConnection">
                    <i class="fa-solid fa-satellite-dish me-1"></i>{{__('Test Connection')}}
                </button>
                <button type="button" class="btn btn-outline-primary" id="btnSyncDurations" title="{{__('Start AVDBAPI duration sync for videos missing duration.')}}">
                    <i class="fa-solid fa-stopwatch me-1"></i>{{__('Sync Missing Durations')}}
                </button>
                <button type="button" class="btn btn-outline-danger" id="btnCleanAll" onclick="cleanAllVideos()">
                    <i class="fa-solid fa-trash-can me-1"></i>{{__('Clean All AVDBAPI')}}
                </button>
            </div>
        </div>
    </div>

    {{-- ═══════ AVDBAPI Live Ecosystem & Specifications Card ═══════ --}}
    <div class="card border-primary border-opacity-25 bg-dark-subtle shadow-sm mb-4" style="border-radius: 12px; background: linear-gradient(135deg, rgba(15, 23, 42, 0.75) 0%, rgba(30, 41, 59, 0.6) 100%);">
        <div class="card-body p-3 p-md-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 mb-3 border-bottom border-secondary border-opacity-25">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-primary px-3 py-2 text-uppercase fw-bold"><i class="fa-solid fa-bolt me-1"></i>AVDBAPI Spec</span>
                    <span class="text-white fw-bold h5 mb-0">{{ __('AVDBAPI Live Ecosystem & Directory') }}</span>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="https://avdbapi.com/index.php/label/help.html" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light">
                        <i class="fa-solid fa-file-lines me-1 text-info"></i>{{ __('Help Docs') }}
                    </a>
                    <a href="https://t.me/avdb_api" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light">
                        <i class="fa-brands fa-telegram me-1 text-primary"></i>{{ __('Telegram Group') }}
                    </a>
                    <a href="https://avdbapi.com/api.php/provide/vod/?ac=list" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <i class="fa-solid fa-code me-1"></i>{{ __('Raw JSON Feed') }}
                    </a>
                </div>
            </div>

            @php
                // The new admin (owner decision OD6): the provider numbers are the provider status probe's last answer
                // ($apiProbe); without one the page asks the probe after it appears and swaps this block in
                // (modules/list-probes.ts). The old admin asked the provider before answering ($apiSummary).
                $apiChecking = ! empty($newAdmin) && ($apiProbe === null || $apiProbe['stale']);
                $apiProbeUrl = route('admin.status.probe', ['probe' => 'provider'], false);
            @endphp
            <div @if (! empty($newAdmin)) data-ad-defer="avdb-api-summary" @if ($apiChecking) data-ad-defer-probes="{{ $apiProbe === null ? $apiProbeUrl : $apiProbeUrl.'?refresh=1' }}" aria-busy="true" @endif @endif>
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded bg-black bg-opacity-30 border border-secondary border-opacity-25">
                        <small class="text-muted text-uppercase d-block fw-semibold mb-1">{{ __('Total in API') }}</small>
                        <span class="h5 fw-black text-white mb-0">{{ $apiChecking ? __('Checking...') : (($apiSummary['available'] ?? false) ? number_format((int) $apiSummary['total']) : __('N/A')) }}</span>
                        <small class="text-info d-block">{{ __('Videos Available') }}</small>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded bg-black bg-opacity-30 border border-secondary border-opacity-25">
                        <small class="text-muted text-uppercase d-block fw-semibold mb-1">{{ __('Total Pages') }}</small>
                        <span class="h5 fw-black text-white mb-0">{{ $apiChecking ? __('Checking...') : (($apiSummary['available'] ?? false) ? number_format((int) $apiSummary['pages']) : __('N/A')) }}</span>
                        @if($apiSummary['available'] ?? false)
                            <small class="text-white-50 d-block">{{ __(':count videos / page', ['count' => number_format((int) $apiSummary['page_size'])]) }}</small>
                        @endif
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded bg-black bg-opacity-30 border border-secondary border-opacity-25">
                        <small class="text-muted text-uppercase d-block fw-semibold mb-1">{{ __('API Protocol') }}</small>
                        <span class="h5 fw-black text-white mb-0">REST JSON / XML</span>
                        <small class="text-white-50 d-block">{{ __('High-Speed Feed') }}</small>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="p-3 rounded bg-black bg-opacity-30 border border-secondary border-opacity-25">
                        <small class="text-muted text-uppercase d-block fw-semibold mb-1">{{ __('Site Coverage') }}</small>
                        @php
                            $importedCount = (int) ($stats['total_imported'] ?? 0);
                        @endphp
                        <span class="h5 fw-black text-success mb-0">{{ number_format($importedCount) }}</span>
                        <small class="text-success-emphasis d-block">{{ __('Imported') }}</small>
                    </div>
                </div>
            </div>
            @if (! empty($newAdmin))
                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    @if ($apiChecking)
                        <small class="text-white-50">{{ __('Asking AVDBAPI for its numbers...') }}</small>
                    @else
                        <small class="text-white-50">{{ ($apiSummary['available'] ?? false) ? '' : __('AVDBAPI did not send its numbers.').' ' }}{{ empty($apiProbe['checked_at']) ? __('Checked') : __('Checked :time', ['time' => \Illuminate\Support\Carbon::parse($apiProbe['checked_at'])->diffForHumans()]) }}</small>
                        <button type="button" class="btn btn-sm btn-outline-light" id="avdb-api-refresh" data-ad-probe-refresh="{{ $apiProbeUrl }}"><i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>{{ __('Refresh') }}</button>
                    @endif
                </div>
            @endif
            </div>

            <div class="mt-3 pt-3 border-top border-secondary border-opacity-25">
                    <small class="text-muted text-uppercase fw-bold d-block mb-2"><i class="fa-solid fa-layer-group me-1 text-primary"></i>{{ __('AVDBAPI Available Categories (7 Main Genres):') }}</small>
                <div class="d-flex flex-wrap gap-2">
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-lock text-warning me-1"></i>ID 1: Censored</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-lock-open text-success me-1"></i>ID 2: Uncensored</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-droplet text-danger me-1"></i>ID 3: Uncensored Leaked</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-user text-info me-1"></i>ID 4: Amateur</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-flag text-danger me-1"></i>ID 5: Chinese AV</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-wand-magic-sparkles text-primary me-1"></i>ID 6: Hentai</span>
                    <span class="badge bg-dark border border-secondary px-2 py-1"><i class="fa-solid fa-closed-captioning text-info me-1"></i>ID 7: English Subtitle</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Stats row --}}
    <div class="row g-3 mb-4 avdb-stat-grid">
        @foreach([
            ['label' => __('AVDBAPI Videos'), 'value' => number_format($stats['total_imported']), 'icon' => 'fa-solid fa-video', 'id' => 'stat-videos'],
            ['label' => __('Tags'), 'value' => number_format($stats['total_tags']), 'icon' => 'fa-solid fa-tags', 'id' => 'stat-tags'],
            ['label' => __('Categories'), 'value' => $stats['categories']->count(), 'icon' => 'fa-solid fa-folder', 'id' => 'stat-cats'],
        ] as $card)
            <div class="col-6 col-lg-4 col-xxl-3">
                <div class="avdb-card p-3" id="{{ $card['id'] }}">
                    <i class="{{ $card['icon'] }} text-primary mb-2"></i>
                    <div class="fw-black text-truncate">{{ $card['value'] }}</div>
                    <div class="small text-muted">{{ $card['label'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-4">
        {{-- ═══════ Left column – Import form ═══════ --}}
        <div class="col-xl-8">
            <div class="avdb-panel p-3 p-md-4 mb-4">
                <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
                    <div class="avdb-section-title mb-0">
                        <i class="fa-solid fa-sliders text-primary"></i>{{__('Import Configuration')}}
                    </div>
                    <div class="small text-muted">{{__('Choose the source, scan range, filters, language, safety, and cron settings.')}}</div>
                </div>

                <div class="avdb-config-stack">
                    <section class="avdb-config-section" id="avdb-source-section">
                        <div class="avdb-config-head">
                            <div class="avdb-section-title">
                                <i class="fa-solid fa-link text-primary"></i>{{__('API Source & Categories')}}
                            </div>
                            <div class="avdb-section-note">{{__('Set the provider endpoint and choose the AVDB categories to scan. Category counts refresh after the connection test.')}}</div>
                        </div>
                        <div class="mb-4">
                            <label for="apiBaseUrl" class="form-label fw-bold">{{__('AVDBAPI Endpoint URL')}}</label>
                            <input type="url" class="form-control" id="apiBaseUrl" value="{{ $defaultApiBase }}" maxlength="500" placeholder="https://avdbapi.com/api.php/provide/vod">
                            <small class="text-muted">{{__('Use a custom AVDB-compatible provide/vod endpoint. For translated endpoints, {locale} is supported.')}}</small>
                        </div>

                        <div class="mb-0">
                    <label class="form-label fw-bold d-flex align-items-center gap-2 mb-2">
                        <i class="fa-solid fa-folder-open text-primary"></i>{{__('AVDBAPI Categories')}}
                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" onclick="toggleAllCategories()">
                            <small>{{__('Toggle all')}}</small>
                        </button>
                    </label>
                    <div class="avdb-cat-grid" id="categoryGrid">
                        @foreach($avdbCategories as $catId => $cat)
                            <label class="avdb-cat-card">
                                <input type="checkbox" class="form-check-input" name="categories[]" value="{{ $catId }}" @checked((int) $catId !== 7)>
                                <span class="avdb-cat-label">
                                    <i class="fa-solid {{ $cat['icon'] }} text-xs"></i>
                                    <span class="avdb-cat-name">{{ $cat['name'] }}</span>
                                </span>
                                <span class="avdb-cat-count" id="cat-count-{{ $catId }}">—</span>
                            </label>
                        @endforeach
                    </div>
                        </div>
                    </section>

                    <section class="avdb-config-section" id="avdb-scan-section">
                        <div class="avdb-config-head">
                            <div class="avdb-section-title">
                                <i class="fa-solid fa-layer-group text-primary"></i>{{__('Scan Range & Quick Controls')}}
                            </div>
                            <div class="avdb-section-note">{{__('These are the controls you usually check before starting a run. The estimate updates as categories and page settings change.')}}</div>
                        </div>
                <div class="avdb-field-grid mb-3">
                    <div>
                        <label for="startPage" class="form-label fw-bold">{{__('Start Page')}}</label>
                        <input type="number" class="form-control" id="startPage" value="1" min="1">
                        <small class="text-muted">{{__('API page to start from')}}</small>
                    </div>
                    <div>
                        <label for="numPages" class="form-label fw-bold">{{__('Pages per Category')}}</label>
                        <input type="number" class="form-control" id="numPages" value="1" min="1" max="50000">
                        <small class="text-muted">{{__('Total API pages = selected categories x this value.')}}</small>
                    </div>
                    <div>
                        <label for="pageSize" class="form-label fw-bold">{{__('Page Size')}}</label>
                        <input type="number" class="form-control" id="pageSize" value="1000" min="1" max="1000" step="50" inputmode="numeric">
                        <small class="text-muted">{{__('Max 1000 per API page. Use a lower number on small VPS memory.')}}</small>
                    </div>
                    <div>
                        <label for="delayMs" class="form-label fw-bold">{{__('Delay (ms)')}}</label>
                        <input type="number" class="form-control" id="delayMs" value="500" min="0" max="10000" step="250">
                        <small class="text-muted">{{__('Pause between API calls')}}</small>
                    </div>
                    <div class="avdb-field-span">
                        <div class="small text-muted rounded border px-3 py-2 avdb-estimate-card" id="importEstimate">
                            {{__('Select categories and pages to estimate import size.')}}
                        </div>
                    </div>
                </div>
                        <div class="avdb-field-grid avdb-quick-controls">
                            <div>
                                <label for="sortDirection" class="form-label fw-bold">{{__('Sort Direction')}}</label>
                                <select class="form-select" id="sortDirection">
                                    <option value="desc" selected>{{__('Newest first')}}</option>
                                    <option value="asc">{{__('Oldest first')}}</option>
                                </select>
                                <small class="text-muted">{{__('Each import run uses a fresh random timeline in this direction to avoid same-day bulk publishing.')}}</small>
                            </div>
                            <div>
                                <label for="thumbnailMode" class="form-label fw-bold">{{__('Thumbnail Mode')}}</label>
                                <select class="form-select" id="thumbnailMode">
                                    <option value="remote">{{__('Use remote poster from API CDN (fast, no storage)')}}</option>
                                    <option value="download">{{__('Download to local server storage')}}</option>
                                    <option value="skip">{{__('Skip thumbnails entirely')}}</option>
                                </select>
                                <small class="text-muted">{{__('Remote = uses poster_url from upload18 CDN. Download = saves JPGs locally but uses disk space.')}}</small>
                            </div>
                        </div>
                    </section>

                    <section class="avdb-config-section" id="avdb-direct-section">
                        <div class="avdb-config-head">
                            <div class="avdb-section-title">
                                <i class="fa-solid fa-crosshairs text-primary"></i>{{__('Single / Direct Import')}}
                            </div>
                            <div class="avdb-section-note">{{__('Paste known AVDB IDs or detail URLs when you want to skip category/page scanning.')}}</div>
                        </div>
                <div class="avdb-field-grid mb-0">
                    <div class="avdb-field-span">
                        <label for="specificIds" class="form-label fw-bold">{{__('AVDB IDs or detail URLs')}}</label>
                        <textarea class="form-control" id="specificIds" rows="5" placeholder="564781&#10;https://avdbapi.com/api.php/provide/vod?ac=detail&amp;ids=564782&#10;https://avdbapi.com/api.php/provide/vod?ac=detail&amp;ids=564783"></textarea>
                        <small class="text-muted">{{__('Optional. Paste one ID or URL per line, or comma-separated IDs. Direct import ignores category/page scanning but keeps language, thumbnail, tag, and safety settings.')}}</small>
                    </div>
                    <div class="avdb-field-span d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="startImport('direct')">
                            <i class="fa-solid fa-bolt me-1"></i>{{__('Start Direct Import')}}
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="clearDirectIds()">
                            <i class="fa-solid fa-eraser me-1"></i>{{__('Clear Direct IDs')}}
                        </button>
                    </div>
                </div>
                    </section>

                    <section class="avdb-config-section" id="avdb-filters-section">
                        <div class="avdb-config-head">
                            <div class="avdb-section-title">
                                <i class="fa-solid fa-filter text-primary"></i>{{__('API Filters')}}
                            </div>
                            <div class="avdb-section-note">{{__('Optional AVDB API filters. Leave blank to scan normally.')}}</div>
                        </div>
                <div class="avdb-field-grid mb-0">
                    <div>
                        <label for="searchWd" class="form-label fw-bold">{{__('Keyword / Code')}}</label>
                        <input type="text" class="form-control" id="searchWd" maxlength="150" placeholder="miaa-818">
                        <small class="text-muted">{{__('Maps to wd. Best for general title and code search.')}}</small>
                    </div>
                    <div>
                        <label for="searchCode" class="form-label fw-bold">{{__('Exact JAV Code')}}</label>
                        <input type="text" class="form-control" id="searchCode" maxlength="100" placeholder="FNS-053">
                        <small class="text-muted">{{__('Maps to code. Use when the exact code is known.')}}</small>
                    </div>
                    <div>
                        <label for="searchActor" class="form-label fw-bold">{{__('Actor')}}</label>
                        <input type="text" class="form-control" id="searchActor" maxlength="150" placeholder="Yua Mikami">
                    </div>
                    <div>
                        <label for="searchDirector" class="form-label fw-bold">{{__('Director')}}</label>
                        <input type="text" class="form-control" id="searchDirector" maxlength="150" placeholder="Nagao">
                    </div>
                    <div>
                        <label for="searchWriter" class="form-label fw-bold">{{__('Writer / Studio')}}</label>
                        <input type="text" class="form-control" id="searchWriter" maxlength="150" placeholder="S1">
                    </div>
                    <div>
                        <label for="searchCategory" class="form-label fw-bold">{{__('Category Tag')}}</label>
                        <input type="text" class="form-control" id="searchCategory" maxlength="150" placeholder="Creampie">
                    </div>
                    <div>
                        <label for="updatedHours" class="form-label fw-bold">{{__('Updated Hours')}}</label>
                        <input type="number" class="form-control" id="updatedHours" value="0" min="0" max="8760">
                        <small class="text-muted">{{__('0 means no updated-time filter.')}}</small>
                    </div>
                    <div>
                        <label for="filterYear" class="form-label fw-bold">{{__('Year / Range')}}</label>
                        <input type="text" class="form-control" id="filterYear" maxlength="9" placeholder="2024 or 2020-2024">
                    </div>
                </div>
                    </section>

                    <section class="avdb-config-section" id="avdb-metadata-section">
                        <div class="avdb-config-head">
                            <div class="avdb-section-title">
                                <i class="fa-solid fa-heading text-primary"></i>{{__('Metadata & Starter Views')}}
                            </div>
                            <div class="avdb-section-note">{{__('Control imported titles, creator assignment, and optional starter view counters.')}}</div>
                        </div>
                <div class="avdb-section-title" style="font-size:0.9rem">
                    <i class="fa-solid fa-heading text-primary"></i>{{__('Title Settings')}}
                </div>
                <div class="avdb-field-grid mb-4">
                    <div class="avdb-field-span">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="useMovieCodeTitle">
                            <label class="form-check-label fw-bold" for="useMovieCodeTitle">
                                {{__('Use movie_code as video title')}}
                            </label>
                        </div>
                        <small class="text-muted"><i class="fa-solid fa-circle-info me-1"></i>{{__('Off = use API name. On = use API movie_code, for example BOKD-197, for imported base and localized titles.')}}</small>
                    </div>
                </div>

                {{-- Creator assignment --}}
                <div class="avdb-section-title mt-3">
                    <i class="fa-solid fa-user-pen text-primary"></i>{{__('Creator Assignment')}}
                </div>
                <div class="avdb-field-grid mb-4">
                    <div class="avdb-field-span">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="creatorAssignmentEnabled" checked>
                            <label class="form-check-label fw-bold" for="creatorAssignmentEnabled">
                                {{__('Auto-create creator profiles from AVDB metadata')}}
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1">
                            <i class="fa-solid fa-circle-info me-1"></i>{{__('On = assign imported videos to director, first actor, or a stable tag creator. Licensed avatar packs in storage/app/private/avdb-avatar-pack are used first; otherwise generated profiles receive deterministic animated brand-badge avatars. Existing manually assigned videos are never stolen.')}}
                        </small>
                    </div>
                </div>

                {{-- Starter views --}}
                <div class="avdb-section-title mt-3">
                    <i class="fa-solid fa-chart-line text-primary"></i>{{__('Starter Views')}}
                </div>
                <div class="avdb-field-grid mb-3">
                    <div>
                        <label for="starterViewsMode" class="form-label fw-bold">{{__('Generate views')}}</label>
                        <select class="form-select" id="starterViewsMode" onchange="toggleStarterViews()">
                            @foreach($starterViewModes as $mode => $label)
                                <option value="{{ $mode }}" @selected($mode === ($starterViewDefaults['mode'] ?? 'disabled'))>{{ __($label) }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">{{__('Adds starter public view counts during import without creating raw view-history rows.')}}</small>
                    </div>
                    <div>
                        <label class="form-label fw-bold">{{__('View range')}}</label>
                        <div class="d-flex gap-2">
                            <input type="number" class="form-control" id="starterViewsMin" value="{{ (int) ($starterViewDefaults['min'] ?? 25) }}" min="0" max="1000000000" placeholder="Min">
                            <input type="number" class="form-control" id="starterViewsMax" value="{{ (int) ($starterViewDefaults['max'] ?? 5000) }}" min="0" max="1000000000" placeholder="Max">
                        </div>
                        <small class="text-muted">{{__('Natural mode keeps most videos low and a small number high.')}}</small>
                    </div>
                </div>
                <div class="avdb-field-grid mb-4" id="starterViewsDetails">
                    <div>
                        <label for="starterViewsSpreadDays" class="form-label fw-bold">{{__('Daily stats spread')}}</label>
                        <input type="number" class="form-control" id="starterViewsSpreadDays" value="{{ (int) ($starterViewDefaults['spread_days'] ?? 90) }}" min="1" max="3650">
                        <small class="text-muted">{{__('Used only for daily analytics charts and trending signals.')}}</small>
                    </div>
                    <div class="avdb-switch-card">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="starterViewsDailyStats" @checked($starterViewDefaults['daily_stats'] ?? true)>
                            <label class="form-check-label fw-bold" for="starterViewsDailyStats">{{__('Seed daily analytics')}}</label>
                            <small class="text-muted d-block">{{__('Creates daily aggregate rows, not raw visitor records.')}}</small>
                        </div>
                    </div>
                    <div class="avdb-switch-card">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="starterViewsOnlyZero" @checked($starterViewDefaults['only_zero'] ?? true)>
                            <label class="form-check-label fw-bold" for="starterViewsOnlyZero">{{__('Only seed empty counters')}}</label>
                            <small class="text-muted d-block">{{__('Recommended to avoid adding views again on duplicate imports.')}}</small>
                        </div>
                    </div>
                    <div class="avdb-switch-card">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="starterViewsExistingZero" @checked($starterViewDefaults['apply_existing_zero'] ?? false)>
                            <label class="form-check-label fw-bold" for="starterViewsExistingZero">{{__('Apply to existing zero-view AVDB videos')}}</label>
                            <small class="text-muted d-block">{{__('Useful after a cleanup or first rollout; off by default.')}}</small>
                        </div>
                    </div>
                </div>
                    </section>

                    <section class="avdb-config-section" id="avdb-language-section">
                        <div class="avdb-config-head">
                            <div class="avdb-section-title">
                                <i class="fa-solid fa-globe text-primary"></i>{{__('Language & Visibility')}}
                            </div>
                            <div class="avdb-section-note">{{__('Control translated imports and which locale visitors can see the imported videos.')}}</div>
                        </div>
                <div class="avdb-section-title" style="font-size:0.9rem">
                    <i class="fa-solid fa-globe text-primary"></i>{{__('Content Language')}}
                </div>

                {{-- Full Multi-Language Toggle (comes first) --}}
                <div class="avdb-field-grid mb-3">
                    <div class="avdb-field-span">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="fullLangs" checked onchange="toggleFullLangsMode()">
                            <label class="form-check-label fw-bold" for="fullLangs">
                                <i class="fa-solid fa-earth-americas me-1"></i>{{__('Full 16-Language Mode')}}
                            </label>
                        </div>
                    </div>
                </div>

                {{-- Info panel when Full mode is ON --}}
                <div id="fullLangsInfo" class="mb-4 p-3 rounded border border-warning" style="background:rgba(255,193,7,0.06)">
                    <small class="text-warning d-block mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i><strong>{{__('Fast + background exact translations')}}</strong> - {{__('Videos import immediately, then exact locale titles are fetched from AVDB in a background backfill.')}}</small>
                    <small class="text-muted d-block mb-2">{{__('Until that backfill finishes, non-English pages may temporarily show the base English title while the videos stay visible in every locale.')}}</small>
                    <small class="text-muted d-block mt-1"><strong>{{__('Languages:')}}</strong> JA, ZH, KO, VI, TH, MS, ID, FR, DE, ES, PT, RU, AR, HI, TL, TR</small>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="verifyFullLangs" onchange="toggleFullLangsMode()">
                        <label class="form-check-label fw-bold" for="verifyFullLangs">{{__('Verify and save exact translations before import')}}</label>
                    </div>
                    <small class="text-muted d-block">{{__('Slow mode: fetches all selected locales from AVDB before each video is imported and skips incomplete items.')}}</small>
                </div>
                <div id="fullLocalesWrapper" class="avdb-field-grid mb-4" style="display:none">
                    <div class="avdb-field-span">
                        <label for="requiredLocales" class="form-label fw-bold">{{__('Required Translation Locales')}}</label>
                        <input type="text" class="form-control" id="requiredLocales" maxlength="120" placeholder="Blank = all 16. Example: ja,zh,ko,vi,th">
                        <small class="text-muted">{{__('Leave blank for all supported locales, or enter comma-separated locale codes.')}}</small>
                    </div>
                </div>

                {{-- Single language selector (hidden when Full mode is ON) --}}
                <div id="singleLangWrapper" class="avdb-field-grid mb-4" style="display:none">
                    <div class="avdb-field-span">
                        <label for="apiLang" class="form-label fw-bold">{{__('Fetch content in')}}</label>
                        <select class="form-select" id="apiLang">
                            <option value="en" selected>English (Default)</option>
                            <option value="ja">日本語 (Japanese)</option>
                            <option value="zh">中文 (Chinese)</option>
                            <option value="ko">한국어 (Korean)</option>
                            <option value="vi">Tiếng Việt (Vietnamese)</option>
                            <option value="th">ไทย (Thai)</option>
                            <option value="ms">Bahasa Melayu (Malay)</option>
                            <option value="id">Bahasa Indonesia</option>
                            <option value="fr">Français (French)</option>
                            <option value="de">Deutsch (German)</option>
                            <option value="es">Español (Spanish)</option>
                            <option value="pt">Português (Portuguese)</option>
                            <option value="ru">Русский (Russian)</option>
                            <option value="ar">العربية (Arabic)</option>
                            <option value="hi">हिन्दी (Hindi)</option>
                            <option value="tl">Filipino (Tagalog)</option>
                            <option value="tr">Türkçe (Turkish)</option>
                        </select>
                        <small class="text-muted"><i class="fa-solid fa-circle-info me-1"></i>{{__('Pick ONE language for all imported content (title, description, country, type_name). Uses API URL prefix e.g. /vi/api.php/...')}}</small>
                    </div>
                    <div class="avdb-field-span">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="translatedOnly" checked>
                            <label class="form-check-label fw-bold" for="translatedOnly">
                                {{__('Require real translation')}}
                            </label>
                        </div>
                        <small class="text-muted"><i class="fa-solid fa-circle-info me-1"></i>{{__('For non-English imports, skip videos when AVDB has no real translated title. This prevents locale pages falling back to English.')}}</small>
                    </div>
                </div>
                {{-- Visibility Language --}}
                <div class="avdb-section-title mt-3">
                    <i class="fa-solid fa-eye text-primary"></i>{{__('Visibility / Locale Filter')}}
                </div>
                <div class="avdb-field-grid mb-4">
                    <div>
                        <label for="languageMode" class="form-label fw-bold">{{__('Who can see imported videos?')}}</label>
                        <select class="form-select" id="languageMode" onchange="toggleGlobalLang()">
                            <option value="global" selected>{{__('Everyone — all locales (recommended)')}}</option>
                            <option value="auto">{{__('Auto-detect — match user\'s locale')}}</option>
                            <option value="fixed">{{__('Fixed locale')}}</option>
                        </select>
                        <small class="text-muted"><i class="fa-solid fa-circle-info me-1"></i>{{__('"Everyone" sets language=NULL. "Auto-detect" maps country to locale. "Fixed locale" pins visibility to one selected locale.')}}</small>
                    </div>
                    <div id="globalLangWrapper" style="display:none">
                        <label for="globalLanguage" class="form-label fw-bold">{{__('Fixed Locale')}}</label>
                        <select class="form-select" id="globalLanguage">
                            @foreach($languages as $code => $name)
                                @if($code !== 'auto')
                                    <option value="{{ $code }}">{{ $name }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                </div>
                    </section>

                {{-- Thumbnail mode --}}
                <div class="avdb-section-title mt-3" style="display:none">
                    <i class="fa-solid fa-image text-primary"></i>{{__('Thumbnail Settings')}}
                </div>
                <div class="avdb-field-grid mb-4" style="display:none">
                    <div class="avdb-field-span">
                        <label for="thumbnailModeMoved" class="form-label fw-bold">{{__('Thumbnail Mode')}}</label>
                        <select class="form-select" id="thumbnailModeMoved">
                            <option value="remote">{{__('🌐 Use remote poster from API CDN (fast, no storage)')}}</option>
                            <option value="download">{{__('💾 Download to local server storage')}}</option>
                            <option value="skip">{{__('⏭️ Skip thumbnails entirely')}}</option>
                        </select>
                        <small class="text-muted"><i class="fa-solid fa-circle-info me-1"></i>{{__('Remote = uses poster_url from upload18 CDN, zero disk usage. Download = saves to storage/thumbnails, uses disk space but independent of external CDN.')}}</small>
                    </div>
                </div>

                    <section class="avdb-config-section" id="avdb-performance-section">
                        <div class="avdb-config-head">
                            <div class="avdb-section-title">
                                <i class="fa-solid fa-gauge-high text-primary"></i>{{__('Performance / Safety')}}
                            </div>
                            <div class="avdb-section-note">{{__('Tune request batching and use dry-run or tag-skip options for safer test runs.')}}</div>
                        </div>
                <div class="avdb-field-grid mb-3">
                    <div>
                        <label for="translationChunk" class="form-label fw-bold">{{__('Translation ID Chunk')}}</label>
                        <input type="number" class="form-control" id="translationChunk" value="200" min="10" max="500" step="10">
                        <small class="text-muted">{{__('Higher is fewer requests; lower is safer on weak network/VPS. Full 16-language mode auto-caps to safe 5-ID batches.')}}</small>
                    </div>
                    <div>
                        <label for="translationConcurrency" class="form-label fw-bold">{{__('Locale Concurrency')}}</label>
                        <input type="number" class="form-control" id="translationConcurrency" value="8" min="1" max="8">
                        <small class="text-muted">{{__('Parallel translation requests. Full 16-language mode uses safe 5-ID batches; use 1-2 on low VPS.')}}</small>
                    </div>
                    <div class="avdb-field-span">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="skipTags">
                            <label class="form-check-label fw-bold" for="skipTags">{{__('Skip tag parsing')}}</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="dryRun">
                            <label class="form-check-label fw-bold" for="dryRun">{{__('Dry run only')}}</label>
                        </div>
                        <small class="text-muted d-block mt-2">{{__('Dry run tests API and filters without writing videos to the database.')}}</small>
                    </div>
                </div>
                    </section>

                <div class="avdb-action-strip">
                    <button type="button" class="btn btn-primary btn-lg" id="btnStartImport" onclick="startImport()">
                        <i class="fa-solid fa-rocket me-1"></i>{{__('Start Import')}}
                    </button>
                    <button type="button" class="btn btn-outline-warning" id="btnStopImport" onclick="stopImport()" style="display:none">
                        <i class="fa-solid fa-stop me-1"></i>{{__('Stop')}}
                    </button>
                </div>

                    <section class="avdb-config-section" id="avdb-cron-section">
                        <div class="avdb-config-head">
                            <div class="avdb-section-title">
                                <i class="fa-solid fa-clock text-primary"></i>{{__('Auto Cron')}}
                            </div>
                            <div class="avdb-section-note">{{__('Save the current import settings as a reusable scheduled import.')}}</div>
                        </div>
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label for="scheduleName" class="form-label fw-bold">{{__('Cron Name')}}</label>
                        <input type="text" class="form-control" id="scheduleName" maxlength="120" placeholder="{{__('Daily AVDB Import')}}">
                    </div>
                    <div class="col-md-3">
                        <label for="scheduleFrequency" class="form-label fw-bold">{{__('Repeat')}}</label>
                        <select class="form-select" id="scheduleFrequency" onchange="scheduleFrequencyChanged()">
                            <option value="daily">{{__('Daily')}}</option>
                            <option value="hourly">{{__('Hourly')}}</option>
                            <option value="weekly">{{__('Weekly')}}</option>
                            <option value="monthly">{{__('Monthly')}}</option>
                            <option value="interval">{{__('Every X minutes')}}</option>
                        </select>
                    </div>
                    <div class="col-md-3 cron-time-field">
                        <label for="scheduleTime" class="form-label fw-bold">{{__('Time')}}</label>
                        <input type="time" class="form-control" id="scheduleTime" value="02:00">
                    </div>
                    <div class="col-md-4 cron-weekly-field" style="display:none">
                        <label for="scheduleWeekday" class="form-label fw-bold">{{__('Weekday')}}</label>
                        <select class="form-select" id="scheduleWeekday">
                            <option value="1">{{__('Monday')}}</option>
                            <option value="2">{{__('Tuesday')}}</option>
                            <option value="3">{{__('Wednesday')}}</option>
                            <option value="4">{{__('Thursday')}}</option>
                            <option value="5">{{__('Friday')}}</option>
                            <option value="6">{{__('Saturday')}}</option>
                            <option value="0">{{__('Sunday')}}</option>
                        </select>
                    </div>
                    <div class="col-md-4 cron-monthly-field" style="display:none">
                        <label for="scheduleMonthDay" class="form-label fw-bold">{{__('Month Day')}}</label>
                        <input type="number" class="form-control" id="scheduleMonthDay" value="1" min="1" max="28">
                    </div>
                    <div class="col-md-4 cron-interval-field" style="display:none">
                        <label for="scheduleIntervalMinutes" class="form-label fw-bold">{{__('Interval Minutes')}}</label>
                        <input type="number" class="form-control" id="scheduleIntervalMinutes" value="1440" min="15" max="10080">
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="scheduleEnabled" checked>
                            <label class="form-check-label fw-bold" for="scheduleEnabled">{{__('Enabled')}}</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="scheduleSkipIfActive" checked>
                            <label class="form-check-label fw-bold" for="scheduleSkipIfActive">{{__('Skip if import is running')}}</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-outline-primary w-100" id="btnSaveSchedule" onclick="saveImportSchedule()">
                            <i class="fa-solid fa-calendar-plus me-1"></i>{{__('Add Current Settings to Cron')}}
                        </button>
                    </div>
                </div>
                    </section>
                </div>
            </div>
        </div>

        {{-- ═══════ Right column – Logs & Progress ═══════ --}}
        <div class="col-xl-4">
            <div class="avdb-panel p-3 p-md-4 mb-4" id="importProgressPanel" tabindex="-1">
                <div class="avdb-section-title">
                    <i class="fa-solid fa-chart-simple text-primary"></i>{{__('Import Progress')}}
                </div>
                <div class="avdb-progress-bar mb-3" id="progressWrapper" style="display:none">
                    <div class="avdb-progress-fill" id="progressFill" style="width:0%"></div>
                </div>
                <div class="d-flex flex-wrap gap-3 mb-3" id="importResultCards" style="display:none">
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-success" id="resultImported">0</div>
                            <div class="small text-muted">{{__('Imported')}}</div>
                        </div>
                    </div>
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-warning" id="resultSkipped">0</div>
                            <div class="small text-muted">{{__('Skipped')}}</div>
                        </div>
                    </div>
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-primary" id="resultUpdated">0</div>
                            <div class="small text-muted">{{__('Updated')}}</div>
                        </div>
                    </div>
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-danger" id="resultFailed">0</div>
                            <div class="small text-muted">{{__('Failed')}}</div>
                        </div>
                    </div>
                    <div class="avdb-switch-card flex-fill text-center" id="resultLangSkipCard" style="display:none">
                        <div>
                            <div class="fw-black text-info" id="resultLangSkipped">0</div>
                            <div class="small text-muted">{{__('No Full Lang')}}</div>
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3 mb-3" id="importMetaCards" style="display:none">
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-primary" id="resultPages">0 / 0</div>
                            <div class="small text-muted">{{__('Pages')}}</div>
                        </div>
                    </div>
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-primary" id="resultElapsed">0s</div>
                            <div class="small text-muted">{{__('Elapsed')}}</div>
                        </div>
                    </div>
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-primary" id="resultCurrent">Idle</div>
                            <div class="small text-muted">{{__('Current')}}</div>
                        </div>
                    </div>
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-primary" id="resultItems">0 / 0</div>
                            <div class="small text-muted">{{__('Items')}}</div>
                        </div>
                    </div>
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-primary" id="resultRate">0/min</div>
                            <div class="small text-muted">{{__('Rate')}}</div>
                        </div>
                    </div>
                    <div class="avdb-switch-card flex-fill text-center">
                        <div>
                            <div class="fw-black text-primary" id="resultEta">Calculating</div>
                            <div class="small text-muted">{{__('ETA')}}</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3" id="issueExportActions" style="display:none">
                    <a class="btn btn-sm btn-outline-danger" id="exportFailedIssues" href="#" target="_blank" rel="noopener">
                        <i class="fa-solid fa-file-arrow-down me-1"></i>{{__('Export Failed')}}
                    </a>
                    <a class="btn btn-sm btn-outline-info" id="exportNoLangIssues" href="#" target="_blank" rel="noopener">
                        <i class="fa-solid fa-language me-1"></i>{{__('Export No Full Lang')}}
                    </a>
                    <a class="btn btn-sm btn-outline-secondary" id="exportAllIssues" href="#" target="_blank" rel="noopener">
                        <i class="fa-solid fa-list-check me-1"></i>{{__('Export All Issues')}}
                    </a>
                </div>

                <div class="avdb-section-title mt-3">
                    <i class="fa-solid fa-terminal text-primary"></i>{{__('Activity Log')}}
                </div>
                <div class="avdb-log-box" id="logBox">Awaiting import command...</div>
            </div>

            <div class="avdb-panel p-3 p-md-4 mb-4">
                <div class="avdb-section-title">
                    <i class="fa-solid fa-calendar-days text-primary"></i>{{__('Auto Import Cron')}}
                </div>
                @php
                    $cronNeedsAttention = !$avdbCronHealth['recent'] || $avdbCronHealth['overdue_count'] > 0;
                @endphp
                <div class="alert alert-{{ $cronNeedsAttention ? 'warning' : 'success' }} py-2 mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <div class="fw-bold">
                                <i class="fa-solid fa-{{ $cronNeedsAttention ? 'triangle-exclamation' : 'circle-check' }} me-1"></i>
                                {{ $cronNeedsAttention ? __('Laravel scheduler needs attention') : __('Laravel scheduler is active') }}
                            </div>
                            <div class="small">
                                {{__('AVDB cron schedules only run when Laravel schedule:run is called every minute.')}}
                                @if($avdbCronHealth['last_seen_at'])
                                    {{__('Last scheduler activity')}}: {{ $avdbCronHealth['last_seen_at']->diffForHumans() }}.
                                @else
                                    {{__('No scheduler log activity has been detected yet.')}}
                                @endif
                            </div>
                            @if($avdbCronHealth['overdue_count'] > 0)
                                <div class="small fw-bold text-danger">
                                    {{__('Overdue AVDB schedules')}}: #{{ implode(', #', $avdbCronHealth['overdue_ids']) }}
                                </div>
                            @endif
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.avdb-import.schedules.run-due') }}">@csrf
                                <button class="btn btn-sm btn-outline-primary">
                                    <i class="fa-solid fa-forward-step me-1"></i>{{__('Run Due Now')}}
                                </button>
                            </form>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.cron.index') }}">
                                <i class="fa-solid fa-clock me-1"></i>{{__('Cron Control')}}
                            </a>
                        </div>
                    </div>
                    @if($cronNeedsAttention)
                        <details class="small mt-2">
                            <summary class="fw-bold cursor-pointer">{{__('Permanent scheduler command')}}</summary>
                            <div class="mt-2">{{__('Linux/macOS/BSD cron line')}}</div>
                            <pre class="bg-dark text-light p-2 rounded small mb-2" style="white-space:pre-wrap" tabindex="0">{{ $avdbCronHealth['unix_cron_command'] }}</pre>
                            <div>{{__('Windows local/testing alternative')}}</div>
                            <pre class="bg-dark text-light p-2 rounded small mb-0" style="white-space:pre-wrap" tabindex="0">{{ $avdbCronHealth['windows_task_command'] }}</pre>
                        </details>
                    @endif
                </div>
                <div class="table-responsive" tabindex="0">
                    <table class="table table-sm align-middle mb-0" style="font-size:0.82rem">
                        <thead>
                            <tr>
                                <th>{{__('Schedule')}}</th>
                                <th>{{__('Next')}}</th>
                                <th class="text-end">{{__('Actions')}}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($importSchedules as $schedule)
                            <tr>
                                <td>
                                    <div class="fw-bold">#{{ $schedule->id }} {{ $schedule->name }}</div>
                                    <div class="text-muted">{{ $schedule->scheduleLabel() }}</div>
                                    @foreach($schedule->settingsSummary() as $summaryLine)
                                        <div class="text-muted small">{{ $summaryLine }}</div>
                                    @endforeach
                                    <span class="badge bg-{{ $schedule->enabled ? 'success' : 'secondary' }}">{{ $schedule->enabled ? __('Enabled') : __('Paused') }}</span>
                                    @if($schedule->last_status)
                                        <span class="badge bg-dark">{{ $schedule->last_status }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div>{{ $schedule->next_run_at ? $schedule->next_run_at->diffForHumans() : __('Disabled') }}</div>
                                    @if($schedule->last_run_id)
                                        <div class="text-muted">{{__('Last')}}: #{{ $schedule->last_run_id }}</div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <form class="d-inline" method="POST" action="{{ route('admin.avdb-import.schedules.run-now', $schedule) }}">@csrf
                                        <button class="btn btn-sm btn-outline-primary mb-1" title="{{__('Run now')}}">
                                            <i class="fa-solid fa-play"></i>
                                        </button>
                                    </form>
                                    <form class="d-inline" method="POST" action="{{ route('admin.avdb-import.schedules.toggle', $schedule) }}">@csrf
                                        <button class="btn btn-sm btn-outline-{{ $schedule->enabled ? 'warning' : 'success' }} mb-1" title="{{ $schedule->enabled ? __('Pause') : __('Enable') }}">
                                            <i class="fa-solid fa-{{ $schedule->enabled ? 'pause' : 'forward' }}"></i>
                                        </button>
                                    </form>
                                    <form class="d-inline" method="POST" action="{{ route('admin.avdb-import.schedules.destroy', $schedule) }}" onsubmit='return confirm(@json(__('Delete this AVDB cron schedule? Import history and videos will stay.')))'>
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger mb-1" title="{{__('Delete')}}">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted py-3">{{__('No AVDB cron schedules yet.')}}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="avdb-panel p-3 p-md-4 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div class="avdb-section-title mb-0">
                        <i class="fa-solid fa-clock-rotate-left text-primary"></i>{{__('Recent Import Runs')}}
                    </div>
                    <form method="POST" action="{{ route('admin.avdb-import.runs.clean-finished') }}" onsubmit='return confirm(@json(__('Delete all finished/cancelled/failed import run history and related log/export files? Active runs will be kept.')))'>
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger">
                            <i class="fa-solid fa-broom me-1"></i>{{__('Clean Finished')}}
                        </button>
                    </form>
                </div>
                <div class="table-responsive" tabindex="0">
                    <table class="table table-sm align-middle mb-0" style="font-size:0.82rem">
                        <thead>
                            <tr>
                                <th>{{__('Run')}}</th>
                                <th>{{__('Status')}}</th>
                                <th>{{__('Result')}}</th>
                                <th class="text-end">{{__('Actions')}}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentRuns as $run)
                                @php
                                    $statusClass = [
                                        'completed' => 'success',
                                        'running' => 'primary',
                                        'starting' => 'info',
                                        'queued' => 'warning',
                                        'failed' => 'danger',
                                        'cancelled' => 'secondary',
                                    ][$run->status] ?? 'secondary';
                                    $issueCount = (int) $run->failed + (int) $run->lang_skipped;
                                    $runActive = in_array($run->status, ['queued', 'starting', 'running'], true);
                                @endphp
                                <tr>
                                    <td>
                                        <button type="button" class="btn btn-link btn-sm p-0 fw-bold" onclick="loadRun({{ $run->id }}, true)">
                                            #{{ $run->id }}
                                        </button>
                                        <div class="text-muted small">{{ optional($run->created_at)->diffForHumans() }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $statusClass }}">{{ ucfirst($run->status) }}</span>
                                        <div class="text-muted small">{{ (int) $run->progress }}%</div>
                                    </td>
                                    <td>
                                        <div>{{__('Imported')}}: {{ number_format((int) $run->imported) }}</div>
                                        <div class="text-muted">{{__('Failed')}}: {{ number_format((int) $run->failed) }} / {{__('No Full Lang')}}: {{ number_format((int) $run->lang_skipped) }}</div>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary mb-1" onclick="loadRun({{ $run->id }}, true)">
                                            <i class="fa-solid fa-chart-simple me-1"></i>{{__('View')}}
                                        </button>
                                        @if($issueCount > 0)
                                            <a class="btn btn-sm btn-outline-secondary mb-1" href="{{ route('admin.avdb-import.issues.export', [$run, 'all']) }}" target="_blank" rel="noopener">
                                                <i class="fa-solid fa-file-arrow-down me-1"></i>{{__('Export')}}
                                            </a>
                                        @endif
                                        @if($runActive)
                                            <button type="button" class="btn btn-sm btn-outline-secondary mb-1" disabled title="{{__('Active import runs cannot be deleted')}}">
                                                <i class="fa-solid fa-lock me-1"></i>{{__('Delete')}}
                                            </button>
                                        @else
                                            <form class="d-inline" method="POST" action="{{ route('admin.avdb-import.runs.destroy', $run) }}" onsubmit='return confirm(@json(__('Delete this import run history and its log/export files? Imported videos will not be deleted.')))'>
                                                @csrf @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger mb-1">
                                                    <i class="fa-solid fa-trash me-1"></i>{{__('Delete')}}
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-3">{{__('No import runs yet.')}}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Category breakdown --}}
            <div class="avdb-panel p-3 p-md-4 mb-4">
                <div class="avdb-section-title">
                    <i class="fa-solid fa-chart-pie text-primary"></i>{{__('Local Category Breakdown')}}
                </div>
                <div class="d-flex flex-column gap-2">
                    @foreach($stats['categories'] as $cat)
                        <div class="d-flex justify-content-between align-items-center py-1">
                            <span class="fw-bold small">{{ $cat->title }}</span>
                            <span class="avdb-cat-count">{{ number_format($cat->videos_count) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- API Reference --}}
            <div class="avdb-panel p-3 p-md-4 mb-4">
                <div class="avdb-section-title">
                    <i class="fa-solid fa-book text-primary"></i>{{__('API Reference')}}
                </div>
                <div class="small text-muted mb-3">{{ __('N/A') }}</div>
                <table class="table table-sm table-borderless mb-3" style="font-size:0.82rem">
                    <thead><tr><th>{{__('Cat')}}</th><th>{{__('Name')}}</th><th class="text-end">{{__('Videos')}}</th></tr></thead>
                    <tbody>
                        <tr><td>1</td><td>Censored</td><td class="text-end">{{ __('N/A') }}</td></tr>
                        <tr><td>2</td><td>Uncensored</td><td class="text-end">{{ __('N/A') }}</td></tr>
                        <tr><td>3</td><td>Uncensored Leaked</td><td class="text-end">{{ __('N/A') }}</td></tr>
                        <tr><td>4</td><td>Amateur</td><td class="text-end">{{ __('N/A') }}</td></tr>
                        <tr><td>5</td><td>Chinese AV</td><td class="text-end">{{ __('N/A') }}</td></tr>
                        <tr><td>6</td><td>Hentai</td><td class="text-end">{{ __('N/A') }}</td></tr>
                        <tr><td>7</td><td>English Subtitle</td><td class="text-end">{{ __('N/A') }}</td></tr>
                    </tbody>
                </table>
                <div class="avdb-section-title mt-2" style="font-size:0.85rem">
                    <i class="fa-solid fa-circle-question text-primary"></i>{{__('Quick Help')}}
                </div>
                <div class="small" style="line-height:1.8">
                    <div><strong>Endpoint:</strong> AVDBCMS imports from standard <code>provide</code> only: <code>/api.php/provide/vod</code>. You can override the URL in the form. Use <code>{locale}</code> in custom translated endpoints if needed. <code>provide1</code> is for bulk feeds and is not used here.</div>
                    <div><strong>Page Size:</strong> accepts 1-1000. Larger pages reduce page requests; use 100-300 on very small VPS if memory is tight.</div>
                    <div><strong>Content Language:</strong> API translates 4 fields per video: <code>title</code>, <code>description</code>, <code>country</code>, <code>type_name</code>. Other fields (actor, director, movie_code) stay the same.</div>
                    <div><strong>Title Source:</strong> Leave <code>movie_code</code> title mode off for full API names, or enable it to use short codes like <code>BOKD-197</code> as the displayed title.</div>
                    <div><strong>Full 16-Lang:</strong> checks all 16 locales in ID batches. Only imports if ALL have unique translations. Saves to <code>video_translations</code> table.</div>
                    <div><strong>Filters:</strong> supports API docs params <code>wd</code>, <code>code</code>, <code>actor</code>, <code>director</code>, <code>writer</code>, <code>category</code>, <code>h</code>, <code>year</code>, and <code>sort_direction</code>.</div>
                    <div><strong>Import dates:</strong> every run gets a fresh random timeline. <code>Newest first</code> spreads AVDB order from new to old; <code>Oldest first</code> spreads old to new. Exact timestamps are unique. AVDB release dates stay saved as metadata.</div>
                    <div><strong>Visibility:</strong> "Everyone" = <code>language=NULL</code> → visible to all site locales. "Fixed locale" pins imported videos to one locale.</div>
                    <div><strong>Thumbnails:</strong> "Remote" uses upload18 CDN posters. "Download" saves JPGs locally (~50KB each).</div>
                    <div><strong>Duplicates:</strong> Videos are matched by <code>avdb_id</code>. Re-importing the same pages will skip existing videos.</div>
                </div>
                <div class="d-flex gap-2 mt-3">
                    <a href="https://avdbapi.com/index.php/label/help.html" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info flex-grow-1">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>{{__('API Documentation')}}
                    </a>
                    <a href="https://t.me/avdb_api" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary flex-grow-1" style="color: #38bdf8; border-color: #0284c7;">
                        <i class="fa-brands fa-telegram me-1"></i>{{__('Telegram Support')}}
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="avdb-queue-modal" id="avdbQueueModal" role="dialog" aria-modal="true" aria-labelledby="avdbQueueModalTitle">
    <div class="avdb-queue-dialog">
        <div class="avdb-queue-dialog-body">
            <div class="avdb-queue-icon">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <h4 class="avdb-queue-title" id="avdbQueueModalTitle">{{__('Job added to queue')}}</h4>
                <p class="avdb-queue-text" id="avdbQueueModalText">{{__('The import job was accepted. This page will refresh shortly.')}}</p>
            </div>
            <div class="avdb-queue-count" id="avdbQueueCountdown">5</div>
            <p class="avdb-queue-text mb-0">{{__('Refreshing page in')}} <strong><span id="avdbQueueCountdownText">5</span>s</strong></p>
            <div class="avdb-queue-actions">
                <button type="button" class="btn btn-primary" id="avdbQueueRefreshNow">
                    <i class="fa-solid fa-rotate me-1"></i>{{__('Refresh now')}}
                </button>
            </div>
        </div>
    </div>
</div>

<div class="avdb-queue-modal" id="avdbDurationModal" role="dialog" aria-modal="true" aria-labelledby="avdbDurationTitle">
    <div class="avdb-queue-dialog avdb-duration-dialog">
        <div class="avdb-queue-dialog-body">
            <div class="avdb-queue-icon">
                <i class="fa-solid fa-stopwatch"></i>
            </div>
            <div>
                <h4 class="avdb-queue-title" id="avdbDurationTitle">{{__('Syncing missing durations')}}</h4>
                <p class="avdb-queue-text" id="avdbDurationText">{{__('Preparing duration sync...')}}</p>
            </div>
            <div class="avdb-duration-progress" aria-hidden="true">
                <div class="avdb-duration-progress-fill" id="avdbDurationProgressFill"></div>
            </div>
            <div class="avdb-duration-grid">
                <div class="avdb-duration-stat">
                    <div class="fw-black" id="avdbDurationSynced">0</div>
                    <small class="text-muted">{{__('Synced')}}</small>
                </div>
                <div class="avdb-duration-stat">
                    <div class="fw-black text-warning" id="avdbDurationMissing">0</div>
                    <small class="text-muted">{{__('Missing')}}</small>
                </div>
                <div class="avdb-duration-stat">
                    <div class="fw-black text-success" id="avdbDurationResolved">0</div>
                    <small class="text-muted">{{__('This run')}}</small>
                </div>
                <div class="avdb-duration-stat">
                    <div class="fw-black text-info" id="avdbDurationBatchNumber">0</div>
                    <small class="text-muted">{{__('Batch')}}</small>
                </div>
            </div>
            <p class="avdb-queue-text mb-0" id="avdbDurationBatchText">{{__('Runs in small browser-safe batches, so the page does not lock up.')}}</p>
            <div class="avdb-queue-actions">
                <button type="button" class="btn btn-outline-warning" id="avdbDurationStop">
                    <i class="fa-solid fa-pause me-1"></i>{{__('Stop after current batch')}}
                </button>
                <button type="button" class="btn btn-primary" id="avdbDurationRefresh">
                    <i class="fa-solid fa-rotate me-1"></i>{{__('Refresh page')}}
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    let importing = false;
    let currentRunId = null;
    let pollTimer = null;
    let queueRefreshTimer = null;
    let durationSyncRunning = false;
    let durationSyncStopRequested = false;
    let durationSyncResolvedTotal = 0;
    let durationSyncBatchTimer = null;
    let durationSyncStartedAt = 0;
    let lastRenderedRunLog = '';
    let connectionTestRunning = false;
    const latestRun = @json($latestRunPayload);
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const logBox = document.getElementById('logBox');

    function log(msg, type = 'info') {
        const now = new Date().toLocaleTimeString();
        const prefix = type === 'error' ? '❌' : type === 'success' ? '✅' : type === 'warning' ? '⚠️' : 'ℹ️';
        logBox.textContent += `[${now}] ${prefix} ${msg}\n`;
        logBox.scrollTop = logBox.scrollHeight;
    }

    function showImportQueuedModal(runId = null, message = '', autoRefresh = true) {
        const modal = document.getElementById('avdbQueueModal');
        const text = document.getElementById('avdbQueueModalText');
        const count = document.getElementById('avdbQueueCountdown');
        const countText = document.getElementById('avdbQueueCountdownText');
        const refreshNow = document.getElementById('avdbQueueRefreshNow');
        let seconds = 5;

        if (!modal || !text || !count || !countText) {
            window.setTimeout(() => window.location.reload(), seconds * 1000);
            return;
        }

        if (queueRefreshTimer) {
            clearInterval(queueRefreshTimer);
            queueRefreshTimer = null;
        }

        text.textContent = message || (runId
            ? `Import run #${runId} was accepted. This page will refresh shortly.`
            : 'The import job was accepted. This page will refresh shortly.');
        count.textContent = String(seconds);
        countText.textContent = String(seconds);
        modal.classList.add('is-visible');

        if (refreshNow) {
            refreshNow.onclick = () => window.location.reload();
        }

        if (!autoRefresh) {
            return;
        }

        queueRefreshTimer = setInterval(() => {
            seconds -= 1;
            const remaining = Math.max(seconds, 0);
            count.textContent = String(remaining);
            countText.textContent = String(remaining);

            if (seconds <= 0) {
                clearInterval(queueRefreshTimer);
                queueRefreshTimer = null;
                window.location.reload();
            }
        }, 1000);
    }

    function hideImportQueuedModal() {
        const modal = document.getElementById('avdbQueueModal');

        if (queueRefreshTimer) {
            clearInterval(queueRefreshTimer);
            queueRefreshTimer = null;
        }

        if (modal) {
            modal.classList.remove('is-visible');
        }
    }

    function formatDurationNumber(value) {
        return new Intl.NumberFormat().format(Math.max(0, parseInt(value, 10) || 0));
    }

    function formatDurationElapsed(ms) {
        const seconds = Math.max(0, Math.round((parseInt(ms, 10) || 0) / 1000));
        const minutes = Math.floor(seconds / 60);
        const rest = seconds % 60;

        return minutes > 0 ? `${minutes}m ${rest}s` : `${rest}s`;
    }

    function updateDurationSyncModal(stats = {}, message = '', batch = {}) {
        const modal = document.getElementById('avdbDurationModal');
        const text = document.getElementById('avdbDurationText');
        const fill = document.getElementById('avdbDurationProgressFill');
        const synced = document.getElementById('avdbDurationSynced');
        const missing = document.getElementById('avdbDurationMissing');
        const resolved = document.getElementById('avdbDurationResolved');
        const batchNumber = document.getElementById('avdbDurationBatchNumber');
        const batchText = document.getElementById('avdbDurationBatchText');

        if (modal) {
            modal.classList.add('is-visible');
        }

        const progress = Math.max(0, Math.min(100, Number(stats.progress || 0)));
        if (text) text.textContent = message || 'Duration sync is running.';
        if (fill) fill.style.width = `${progress}%`;
        if (synced) synced.textContent = formatDurationNumber(stats.synced);
        if (missing) missing.textContent = formatDurationNumber(stats.missing);
        if (resolved) resolved.textContent = formatDurationNumber(durationSyncResolvedTotal);
        if (batchNumber && batch.batch_index) batchNumber.textContent = formatDurationNumber(batch.batch_index);

        if (batchText) {
            const batchResolved = parseInt(batch.resolved, 10) || 0;
            const before = parseInt(batch.missing_before, 10);
            const after = parseInt(batch.missing_after, 10);
            const elapsed = batch.elapsed_ms ? ` in ${formatDurationElapsed(batch.elapsed_ms)}` : '';
            batchText.textContent = Number.isFinite(before) && Number.isFinite(after)
                ? `Last batch resolved ${formatDurationNumber(batchResolved)}${elapsed}. Missing ${formatDurationNumber(before)} -> ${formatDurationNumber(after)}.`
                : 'Runs in small browser-safe batches, so the page does not lock up.';
        }
    }

    function startDurationBatchHeartbeat(batchIndex, stats = {}) {
        const batchText = document.getElementById('avdbDurationBatchText');
        const batchNumber = document.getElementById('avdbDurationBatchNumber');
        const startedAt = Date.now();

        clearDurationBatchHeartbeat();
        if (batchNumber) batchNumber.textContent = formatDurationNumber(batchIndex);

        durationSyncBatchTimer = setInterval(() => {
            const elapsed = Date.now() - startedAt;
            const totalElapsed = durationSyncStartedAt > 0 ? Date.now() - durationSyncStartedAt : elapsed;
            updateDurationSyncModal(stats, `Running duration batch ${batchIndex}... waiting for server response.`, { batch_index: batchIndex });
            if (batchText) {
                batchText.textContent = `Batch ${formatDurationNumber(batchIndex)} is still running (${formatDurationElapsed(elapsed)}). Total elapsed ${formatDurationElapsed(totalElapsed)}.`;
            }
        }, 1000);
    }

    function clearDurationBatchHeartbeat() {
        if (durationSyncBatchTimer) {
            clearInterval(durationSyncBatchTimer);
            durationSyncBatchTimer = null;
        }
    }

    function finishDurationSync(message, type = 'success') {
        const button = document.getElementById('btnSyncDurations');
        durationSyncRunning = false;
        durationSyncStopRequested = false;
        clearDurationBatchHeartbeat();

        if (button) {
            button.disabled = false;
            button.innerHTML = '<i class="fa-solid fa-stopwatch me-1"></i>{{__('Sync Missing Durations')}}';
        }

        log(message, type);
    }

    async function pollBackgroundDurationSync(initialStats = {}) {
        let previousMissing = parseInt(initialStats.missing, 10) || 0;
        let unchangedPolls = 0;

        for (let i = 0; i < 60 && !durationSyncStopRequested; i++) {
            await new Promise(resolve => setTimeout(resolve, 5000));
            const data = await apiJson('{{ route("admin.cron.duration.status") }}');
            const stats = data.stats || {};
            const missing = parseInt(stats.missing, 10) || 0;

            updateDurationSyncModal(stats, 'Background duration sync is running. This page is only watching progress.');

            if (missing < 1) {
                finishDurationSync('Duration sync completed in the background.', 'success');
                return;
            }

            unchangedPolls = missing === previousMissing ? unchangedPolls + 1 : 0;
            previousMissing = missing;

            if (unchangedPolls >= 6) {
                finishDurationSync('Background sync is still running or waiting. Refresh later, or run browser-safe batches if counts do not move.', 'warning');
                return;
            }
        }

        finishDurationSync('Stopped watching duration sync. The background worker may still be running.', 'warning');
    }

    async function startDurationSync() {
        if (durationSyncRunning) return;

        const button = document.getElementById('btnSyncDurations');
        durationSyncRunning = true;
        durationSyncStopRequested = false;
        durationSyncResolvedTotal = 0;
        durationSyncStartedAt = Date.now();

        if (button) {
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>{{__('Syncing...')}}';
        }

        updateDurationSyncModal({}, 'Starting duration sync without reloading the page...');

        try {
            const start = await apiJson('{{ route("admin.cron.duration.runner.start") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ limit: 10000, chunk: 100 })
            });

            updateDurationSyncModal(start.stats || {}, start.message || 'Duration sync started.');

            if (start.done) {
                finishDurationSync(start.message || 'No AVDB videos need duration sync.', 'success');
                return;
            }

            if (start.mode === 'background') {
                await pollBackgroundDurationSync(start.stats || {});
                return;
            }

            const batchLimit = Math.max(1, Math.min(1000, parseInt(start.batch_limit, 10) || 500));
            const batchChunk = Math.max(1, Math.min(200, parseInt(start.batch_chunk, 10) || 100));
            let idleBatches = 0;

            for (let batchIndex = 1; batchIndex <= 200 && !durationSyncStopRequested; batchIndex++) {
                updateDurationSyncModal(start.stats || {}, `Running duration batch ${batchIndex}...`, { batch_index: batchIndex });
                startDurationBatchHeartbeat(batchIndex, start.stats || {});
                let data;
                try {
                    data = await apiJson('{{ route("admin.cron.duration.runner.batch") }}', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                        body: JSON.stringify({ limit: batchLimit, chunk: batchChunk })
                    });
                } finally {
                    clearDurationBatchHeartbeat();
                }

                const batch = data.batch || {};
                batch.batch_index = batchIndex;
                const resolved = parseInt(batch.resolved, 10) || 0;
                durationSyncResolvedTotal += resolved;
                updateDurationSyncModal(data.stats || {}, data.message || `Duration batch ${batchIndex} finished.`, batch);
                log(`Duration batch ${batchIndex}: resolved ${formatDurationNumber(resolved)}; missing ${formatDurationNumber((data.stats || {}).missing)}.`, resolved > 0 ? 'success' : 'warning');

                if (data.done) {
                    finishDurationSync('Duration sync completed. Refresh the page to see all duration badges.', 'success');
                    return;
                }

                idleBatches = resolved > 0 ? 0 : idleBatches + 1;
                if (!data.continue || idleBatches >= 2) {
                    finishDurationSync('Duration sync stopped because the latest batch did not resolve more videos. Remaining videos may need retry later or may not expose duration.', 'warning');
                    return;
                }

                await new Promise(resolve => setTimeout(resolve, 250));
            }

            finishDurationSync(durationSyncStopRequested ? 'Duration sync stopped after the current batch.' : 'Duration sync paused after many batches. Run it again to continue.', 'warning');
        } catch (e) {
            finishDurationSync('Duration sync failed: ' + e.message, 'error');
            updateDurationSyncModal({}, 'Duration sync failed: ' + e.message);
        }
    }

    async function apiJson(url, options = {}) {
        const headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers || {}),
        };

        const res = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers,
        });

        const contentType = res.headers.get('content-type') || '';
        const rawPayload = await res.text();
        let isJson = contentType.includes('application/json');
        let payload = rawPayload;

        if (isJson) {
            try {
                payload = rawPayload ? JSON.parse(rawPayload) : null;
            } catch (e) {
                isJson = false;
                payload = rawPayload;
            }
        }

        if (!res.ok) {
            if (isJson) {
                const message = payload.message
                    || Object.values(payload.errors || {}).flat().join(' ')
                    || `Request failed with HTTP ${res.status}`;
                throw new Error(message);
            }

            const text = String(payload || '').replace(/\s+/g, ' ').trim();
            const title = text.match(/<title[^>]*>(.*?)<\/title>/i)?.[1]?.trim();
            const isLogin = text.includes('name="username"') || text.includes('/login');
            const isExpired = res.status === 419 || /csrf|page expired/i.test(text);

            throw new Error(
                isExpired
                    ? 'Session expired or CSRF token changed. Refresh the admin page and try again.'
                    : isLogin
                        ? 'Admin session expired. Log in again and retry the import.'
                        : `Request returned HTML instead of JSON (HTTP ${res.status}${title ? `: ${title}` : ''}).`
            );
        }

        if (!isJson) {
            const text = String(payload || '').replace(/\s+/g, ' ').trim();
            const title = text.match(/<title[^>]*>(.*?)<\/title>/i)?.[1]?.trim();
            const isLogin = text.includes('name="username"') || text.includes('/login');
            const isExpired = res.status === 419 || /csrf|page expired/i.test(text);

            throw new Error(
                isExpired
                    ? 'Session expired or CSRF token changed. Refresh the admin page and try again.'
                    : isLogin
                        ? 'Admin session expired. Log in again and retry the import.'
                        : `Request returned HTML instead of JSON (HTTP ${res.status}${title ? `: ${title}` : ''}). Refresh the page and try again.`
            );
        }

        return payload;
    }

    function scrollToImportProgress() {
        const panel = document.getElementById('importProgressPanel');
        if (!panel) return;

        const prefersReducedMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        try {
            panel.scrollIntoView({
                behavior: prefersReducedMotion ? 'auto' : 'smooth',
                block: 'start',
                inline: 'nearest'
            });
        } catch (e) {
            panel.scrollIntoView(true);
        }

        window.setTimeout(() => {
            try {
                panel.focus({ preventScroll: true });
            } catch (e) {
                panel.focus();
            }
        }, prefersReducedMotion ? 0 : 350);
    }

    async function testConnection({ automatic = false } = {}) {
        if (connectionTestRunning) return;
        connectionTestRunning = true;

        const btn = document.getElementById('btnTestConnection');
        const dot = document.getElementById('connectionDot');
        const status = document.getElementById('connectionStatus');

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Testing...';
        }
        dot.className = 'avdb-connection-dot';
        status.textContent = 'Testing...';
        log(automatic ? 'Auto testing API connection...' : 'Testing API connection...');

        try {
            const data = await apiJson('{{ route("admin.avdb-import.test") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({
                    api_base_url: document.getElementById('apiBaseUrl').value.trim()
                })
            });

            if (data.success) {
                dot.className = 'avdb-connection-dot connected';
                status.textContent = 'Connected';
                log(`Connected! ${data.total_videos} videos available.`, 'success');

                // Update category counts
                if (data.category_counts) {
                    for (const [catId, count] of Object.entries(data.category_counts)) {
                        const el = document.getElementById(`cat-count-${catId}`);
                        if (el) el.textContent = new Intl.NumberFormat().format(count);
                    }
                }
            } else {
                dot.className = 'avdb-connection-dot failed';
                status.textContent = 'Failed';
                log(data.message, 'error');
            }
        } catch (e) {
            dot.className = 'avdb-connection-dot failed';
            status.textContent = 'Error';
            log('Network error: ' + e.message, 'error');
        } finally {
            connectionTestRunning = false;
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-satellite-dish me-1"></i>Test Connection';
            }
        }
    }

    document.getElementById('btnTestConnection').addEventListener('click', () => testConnection());

    function toggleGlobalLang() {
        const mode = document.getElementById('languageMode').value;
        document.getElementById('globalLangWrapper').style.display = mode === 'fixed' ? '' : 'none';
    }

    function toggleFullLangsMode() {
        const isOn = document.getElementById('fullLangs').checked;
        const verify = document.getElementById('verifyFullLangs')?.checked || false;
        // When full-langs is ON, hide the single-language selector (redundant)
        document.getElementById('singleLangWrapper').style.display = isOn ? 'none' : '';
        // Show/hide the info panel
        document.getElementById('fullLangsInfo').style.display = isOn ? '' : 'none';
        document.getElementById('fullLocalesWrapper').style.display = isOn && verify ? '' : 'none';
    }

    function toggleStarterViews() {
        const enabled = document.getElementById('starterViewsMode').value !== 'disabled';
        document.getElementById('starterViewsDetails').style.display = enabled ? 'grid' : 'none';
    }

    function toggleAllCategories() {
        const boxes = document.querySelectorAll('#categoryGrid input[type="checkbox"]');
        const allChecked = [...boxes].every(b => b.checked);
        boxes.forEach(b => b.checked = !allChecked);
        updateImportEstimate();
    }

    function selectedCategoryIds() {
        return [...document.querySelectorAll('#categoryGrid input:checked')].map(el => parseInt(el.value));
    }

    function boundedNumber(id, fallback, min, max) {
        const input = document.getElementById(id);
        const value = Math.max(min, Math.min(max, parseInt(input.value, 10) || fallback));
        input.value = value;
        return value;
    }

    function normalizeDirectIdsInput(raw) {
        const ids = new Set();
        String(raw || '').split(/[\s,;]+/).forEach(token => {
            token = token.trim();
            if (!token) return;

            try {
                const url = new URL(token);
                const urlIds = url.searchParams.get('ids');
                if (urlIds) {
                    urlIds.split(/[\s,;]+/).forEach(id => {
                        const numeric = String(id).replace(/\D+/g, '');
                        if (numeric) ids.add(numeric);
                    });
                    return;
                }
            } catch (e) {}

            const idsMatch = token.match(/[?&]ids=([^&]+)/i);
            if (idsMatch) {
                decodeURIComponent(idsMatch[1]).split(/[\s,;]+/).forEach(id => {
                    const numeric = String(id).replace(/\D+/g, '');
                    if (numeric) ids.add(numeric);
                });
                return;
            }

            const numeric = token.replace(/\D+/g, '');
            if (numeric) ids.add(numeric);
        });

        return [...ids].slice(0, 200).join(',');
    }

    function clearDirectIds() {
        const input = document.getElementById('specificIds');
        input.value = '';
        updateImportEstimate();
    }

    function updateImportEstimate() {
        const estimate = document.getElementById('importEstimate');
        if (!estimate) return;

        const ids = normalizeDirectIdsInput(document.getElementById('specificIds').value);
        if (ids) {
            const count = ids.split(',').filter(Boolean).length;
            estimate.textContent = `Direct ID mode: ${count} AVDB ID(s), fetched in batches of up to 100. Category/page settings are ignored.`;
            return;
        }

        const categories = selectedCategoryIds();
        const pages = Math.max(1, parseInt(document.getElementById('numPages').value, 10) || 1);
        const pageSize = Math.max(1, Math.min(1000, parseInt(document.getElementById('pageSize').value, 10) || 1000));
        const totalApiPages = categories.length * pages;
        const maxVideos = totalApiPages * pageSize;
        estimate.textContent = `${categories.length} categor${categories.length === 1 ? 'y' : 'ies'} x ${pages} page(s) = ${totalApiPages} API page(s), up to ${new Intl.NumberFormat().format(maxVideos)} videos scanned.`;
    }

    function scheduleFrequencyChanged() {
        const frequency = document.getElementById('scheduleFrequency').value;
        document.querySelectorAll('.cron-time-field').forEach(el => el.style.display = frequency === 'interval' ? 'none' : '');
        document.querySelectorAll('.cron-weekly-field').forEach(el => el.style.display = frequency === 'weekly' ? '' : 'none');
        document.querySelectorAll('.cron-monthly-field').forEach(el => el.style.display = frequency === 'monthly' ? '' : 'none');
        document.querySelectorAll('.cron-interval-field').forEach(el => el.style.display = frequency === 'interval' ? '' : 'none');
    }

    function buildCronImportPayload() {
        const idsInput = document.getElementById('specificIds');
        const ids = normalizeDirectIdsInput(idsInput.value);
        if (idsInput.value.trim() && ids) {
            idsInput.value = ids.split(',').join('\n');
        }

        const categories = selectedCategoryIds();
        if (categories.length === 0 && !ids) {
            log('Please select at least one category or enter AVDB IDs before adding cron.', 'warning');
            return null;
        }

        const pageSizeInput = document.getElementById('pageSize');
        const pageSize = Math.max(1, Math.min(1000, parseInt(pageSizeInput.value, 10) || 1000));
        pageSizeInput.value = pageSize;
        const numPages = boundedNumber('numPages', 1, 1, 50000);
        const startPage = boundedNumber('startPage', 1, 1, 999999999);
        const translationChunkInput = document.getElementById('translationChunk');
        const translationChunk = Math.max(10, Math.min(500, parseInt(translationChunkInput.value, 10) || 200));
        translationChunkInput.value = translationChunk;
        const translationConcurrencyInput = document.getElementById('translationConcurrency');
        const translationConcurrency = Math.max(1, Math.min(8, parseInt(translationConcurrencyInput.value, 10) || 8));
        translationConcurrencyInput.value = translationConcurrency;
        let starterViewsMin = boundedNumber('starterViewsMin', 25, 0, 1000000000);
        let starterViewsMax = boundedNumber('starterViewsMax', 5000, 0, 1000000000);
        if (starterViewsMin > starterViewsMax) {
            [starterViewsMin, starterViewsMax] = [starterViewsMax, starterViewsMin];
            document.getElementById('starterViewsMin').value = starterViewsMin;
            document.getElementById('starterViewsMax').value = starterViewsMax;
        }

        return {
            api_base_url: document.getElementById('apiBaseUrl').value.trim(),
            categories: categories,
            pages: numPages,
            start_page: startPage,
            pagesize: pageSize,
            delay: parseInt(document.getElementById('delayMs').value),
            api_lang: document.getElementById('apiLang').value,
            ids: ids,
            search_wd: document.getElementById('searchWd').value.trim(),
            search_code: document.getElementById('searchCode').value.trim(),
            search_actor: document.getElementById('searchActor').value.trim(),
            search_director: document.getElementById('searchDirector').value.trim(),
            search_writer: document.getElementById('searchWriter').value.trim(),
            search_category: document.getElementById('searchCategory').value.trim(),
            updated_hours: Math.max(0, Math.min(8760, parseInt(document.getElementById('updatedHours').value, 10) || 0)),
            year: document.getElementById('filterYear').value.trim(),
            sort_direction: document.getElementById('sortDirection').value,
            language_mode: document.getElementById('languageMode').value,
            global_language: document.getElementById('globalLanguage').value,
            thumbnail_mode: document.getElementById('thumbnailMode').value,
            locales: document.getElementById('requiredLocales').value.trim(),
            translation_chunk: translationChunk,
            translation_concurrency: translationConcurrency,
            use_movie_code_title: document.getElementById('useMovieCodeTitle').checked,
            creator_assignment_enabled: document.getElementById('creatorAssignmentEnabled').checked,
            skip_tags: document.getElementById('skipTags').checked,
            dry_run: document.getElementById('dryRun').checked,
            translated_only: document.getElementById('translatedOnly').checked,
            full_langs: document.getElementById('fullLangs').checked,
            verify_full_langs: document.getElementById('verifyFullLangs').checked,
            starter_views_mode: document.getElementById('starterViewsMode').value,
            starter_views_min: starterViewsMin,
            starter_views_max: starterViewsMax,
            starter_views_spread_days: boundedNumber('starterViewsSpreadDays', 90, 1, 3650),
            starter_views_daily_stats: document.getElementById('starterViewsDailyStats').checked,
            starter_views_only_zero: document.getElementById('starterViewsOnlyZero').checked,
            starter_views_existing_zero: document.getElementById('starterViewsExistingZero').checked,
            schedule_name: document.getElementById('scheduleName').value.trim(),
            schedule_frequency: document.getElementById('scheduleFrequency').value,
            schedule_time: document.getElementById('scheduleTime').value || '02:00',
            schedule_weekday: parseInt(document.getElementById('scheduleWeekday').value, 10) || 0,
            schedule_month_day: Math.max(1, Math.min(28, parseInt(document.getElementById('scheduleMonthDay').value, 10) || 1)),
            schedule_interval_minutes: Math.max(15, Math.min(10080, parseInt(document.getElementById('scheduleIntervalMinutes').value, 10) || 1440)),
            schedule_enabled: document.getElementById('scheduleEnabled').checked,
            schedule_skip_if_active: document.getElementById('scheduleSkipIfActive').checked,
        };
    }

    async function saveImportSchedule() {
        const payload = buildCronImportPayload();
        if (!payload) return;

        const btn = document.getElementById('btnSaveSchedule');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';
        log('Saving AVDB cron schedule...');

        try {
            const data = await apiJson('{{ route("admin.avdb-import.schedules.store") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify(payload)
            });
            if (data.success) {
                log(data.message, 'success');
                window.setTimeout(() => window.location.reload(), 700);
            } else {
                log(data.message || 'Could not save cron schedule.', 'error');
            }
        } catch (e) {
            log('Cron save failed: ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-calendar-plus me-1"></i>{{__('Add Current Settings to Cron')}}';
        }
    }

    async function startImport(mode = 'auto') {
        if (importing) return;

        const idsInput = document.getElementById('specificIds');
        const ids = normalizeDirectIdsInput(idsInput.value);
        if (idsInput.value.trim() && ids) {
            idsInput.value = ids.split(',').join('\n');
        }

        const categories = selectedCategoryIds();
        if (mode === 'direct' && !ids) {
            log('Paste at least one AVDB numeric ID or detail URL before starting direct import.', 'warning');
            return;
        }

        if (categories.length === 0 && !ids) {
            log('Please select at least one category.', 'warning');
            return;
        }

        const directFastMode = mode === 'direct' && !!ids;
        importing = true;
        currentRunId = null;
        lastRenderedRunLog = '';
        document.getElementById('btnStartImport').style.display = 'none';
        document.getElementById('btnStopImport').style.display = '';
        document.getElementById('progressWrapper').style.display = '';
        document.getElementById('importResultCards').style.display = '';
        document.getElementById('importMetaCards').style.display = '';
        document.getElementById('progressFill').style.width = '2%';
        document.getElementById('resultImported').textContent = '0';
        document.getElementById('resultSkipped').textContent = '0';
        document.getElementById('resultUpdated').textContent = '0';
        document.getElementById('resultFailed').textContent = '0';
        document.getElementById('resultLangSkipped').textContent = '0';
        document.getElementById('resultLangSkipCard').style.display = 'none';
        document.getElementById('resultPages').textContent = '0 / 0';
        document.getElementById('resultElapsed').textContent = '0s';
        document.getElementById('resultCurrent').textContent = 'Starting';
        document.getElementById('resultItems').textContent = '0 / 0';
        document.getElementById('resultRate').textContent = '0/min';
        document.getElementById('resultEta').textContent = 'Calculating';
        updateIssueExportLinks(null);
        scrollToImportProgress();
        showImportQueuedModal(null, 'Import request submitted. This page will refresh automatically in 5 seconds.', true);

        const pageSizeInput = document.getElementById('pageSize');
        const pageSize = Math.max(1, Math.min(1000, parseInt(pageSizeInput.value, 10) || 1000));
        pageSizeInput.value = pageSize;
        const numPages = boundedNumber('numPages', 1, 1, 50000);
        const startPage = boundedNumber('startPage', 1, 1, 999999999);
        const translationChunkInput = document.getElementById('translationChunk');
        const translationChunk = Math.max(10, Math.min(500, parseInt(translationChunkInput.value, 10) || 200));
        translationChunkInput.value = translationChunk;
        const translationConcurrencyInput = document.getElementById('translationConcurrency');
        const translationConcurrency = Math.max(1, Math.min(8, parseInt(translationConcurrencyInput.value, 10) || 8));
        translationConcurrencyInput.value = translationConcurrency;
        let starterViewsMin = boundedNumber('starterViewsMin', 25, 0, 1000000000);
        let starterViewsMax = boundedNumber('starterViewsMax', 5000, 0, 1000000000);
        if (starterViewsMin > starterViewsMax) {
            [starterViewsMin, starterViewsMax] = [starterViewsMax, starterViewsMin];
            document.getElementById('starterViewsMin').value = starterViewsMin;
            document.getElementById('starterViewsMax').value = starterViewsMax;
        }
        const starterViewsSpreadDays = boundedNumber('starterViewsSpreadDays', 90, 1, 3650);

        log(ids
            ? `Starting ${directFastMode ? 'fast ' : ''}direct import for AVDB IDs: ${ids}${directFastMode ? ' (full-language validation disabled)' : ''}`
            : `Starting import: ${categories.length} categories x ${numPages} page(s) = ${categories.length * numPages} API page(s), pages ${startPage}-${startPage + numPages - 1}`
        );

        try {
            const data = await apiJson('{{ route("admin.avdb-import.start") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({
                    api_base_url: document.getElementById('apiBaseUrl').value.trim(),
                    categories: categories,
                    pages: numPages,
                    start_page: startPage,
                    pagesize: pageSize,
                    delay: parseInt(document.getElementById('delayMs').value),
                    api_lang: directFastMode ? 'en' : document.getElementById('apiLang').value,
                    ids: ids,
                    search_wd: document.getElementById('searchWd').value.trim(),
                    search_code: document.getElementById('searchCode').value.trim(),
                    search_actor: document.getElementById('searchActor').value.trim(),
                    search_director: document.getElementById('searchDirector').value.trim(),
                    search_writer: document.getElementById('searchWriter').value.trim(),
                    search_category: document.getElementById('searchCategory').value.trim(),
                    updated_hours: Math.max(0, Math.min(8760, parseInt(document.getElementById('updatedHours').value, 10) || 0)),
                    year: document.getElementById('filterYear').value.trim(),
                    sort_direction: document.getElementById('sortDirection').value,
                    language_mode: document.getElementById('languageMode').value,
                    global_language: document.getElementById('globalLanguage').value,
                    thumbnail_mode: document.getElementById('thumbnailMode').value,
                    locales: document.getElementById('requiredLocales').value.trim(),
                    translation_chunk: translationChunk,
                    translation_concurrency: translationConcurrency,
                    use_movie_code_title: document.getElementById('useMovieCodeTitle').checked,
                    creator_assignment_enabled: document.getElementById('creatorAssignmentEnabled').checked,
                    skip_tags: document.getElementById('skipTags').checked,
                    dry_run: document.getElementById('dryRun').checked,
                    translated_only: directFastMode ? false : document.getElementById('translatedOnly').checked,
                    full_langs: directFastMode ? false : document.getElementById('fullLangs').checked,
                    verify_full_langs: directFastMode ? false : document.getElementById('verifyFullLangs').checked,
                    starter_views_mode: document.getElementById('starterViewsMode').value,
                    starter_views_min: starterViewsMin,
                    starter_views_max: starterViewsMax,
                    starter_views_spread_days: starterViewsSpreadDays,
                    starter_views_daily_stats: document.getElementById('starterViewsDailyStats').checked,
                    starter_views_only_zero: document.getElementById('starterViewsOnlyZero').checked,
                    starter_views_existing_zero: document.getElementById('starterViewsExistingZero').checked,
                })
            });

            const startedRunId = data.run?.id || data.run_id;
            if (startedRunId) {
                currentRunId = startedRunId;
                log(data.message || `Import run #${startedRunId} queued.`, data.success ? 'success' : 'warning');
                if (data.run) {
                    applyRun(data.run);
                    syncImportButtons(data.run);
                }
                startPolling();
                showImportQueuedModal(startedRunId, data.message || `Import run #${startedRunId} was added to the queue.`, true);
            } else if (data.success) {
                log(data.message || 'Import queued, waiting for progress...', 'success');
                importing = true;
                syncImportButtons({ status: 'queued' });
                showImportQueuedModal(null, data.message || 'Import job was added to the queue.', true);
            } else {
                hideImportQueuedModal();
                log('Import failed: ' + (data.message || 'Unknown error'), 'error');
                importing = false;
                document.getElementById('btnStartImport').style.display = '';
                document.getElementById('btnStopImport').style.display = 'none';
            }
        } catch (e) {
            hideImportQueuedModal();
            log('Import request failed: ' + e.message, 'error');
            importing = false;
            document.getElementById('btnStartImport').style.display = '';
            document.getElementById('btnStopImport').style.display = 'none';
        }
    }

    function startPolling() {
        if (pollTimer) clearInterval(pollTimer);
        pollImport();
        pollTimer = setInterval(pollImport, 1500);
    }

    async function loadRun(runId, shouldScroll = false) {
        if (!runId) return;

        try {
            const data = await apiJson(`{{ url('/admin/avdb-import/runs') }}/${runId}`);
            if (!data.success || !data.run) {
                log(`Could not load import run #${runId}.`, 'warning');
                return;
            }

            currentRunId = data.run.id;
            applyRun(data.run);
            syncImportButtons(data.run);
            if (['queued', 'starting', 'running'].includes(data.run.status)) {
                importing = true;
                startPolling();
            }
            if (shouldScroll) scrollToImportProgress();
        } catch (e) {
            log('Run load failed: ' + e.message, 'warning');
        }
    }

    async function pollImport() {
        if (!currentRunId) return;

        try {
            const data = await apiJson(`{{ url('/admin/avdb-import/runs') }}/${currentRunId}`);
            if (!data.success || !data.run) return;

            applyRun(data.run);

            if (['completed', 'failed', 'cancelled'].includes(data.run.status)) {
                clearInterval(pollTimer);
                pollTimer = null;
                importing = false;
                syncImportButtons(data.run);
                refreshStats();
                log(data.run.message || `Run ${data.run.status}.`, data.run.status === 'completed' ? 'success' : 'warning');
            } else {
                syncImportButtons(data.run);
            }
        } catch (e) {
            log('Progress check failed: ' + e.message, 'warning');
        }
    }

    function applyRun(run) {
        if (!run) return;

        document.getElementById('progressWrapper').style.display = '';
        document.getElementById('importResultCards').style.display = '';
        document.getElementById('importMetaCards').style.display = '';
        document.getElementById('progressFill').style.width = `${Math.max(2, run.progress || 0)}%`;
        document.getElementById('resultImported').textContent = run.imported || 0;
        document.getElementById('resultSkipped').textContent = run.skipped || 0;
        document.getElementById('resultUpdated').textContent = run.updated || 0;
        document.getElementById('resultFailed').textContent = run.failed || 0;
        document.getElementById('importMetaCards').style.display = '';
        document.getElementById('resultPages').textContent = run.pages_label || `${run.completed_pages || 0} / ${run.total_pages || 0}`;
        document.getElementById('resultElapsed').textContent = run.elapsed_label || '0s';
        document.getElementById('resultCurrent').textContent = run.current_category
            ? `Cat ${run.current_category} / Page ${run.current_page || '-'}`
            : (run.current_page ? `Item ${run.current_page}` : (run.status || 'Idle'));
        document.getElementById('resultItems').textContent = run.items_label || `${run.processed_items || 0} / ${run.estimated_items || 0}`;
        document.getElementById('resultRate').textContent = run.rate_label || 'Calculating';
        document.getElementById('resultEta').textContent = run.eta_label || 'Calculating';

        if ((run.lang_skipped || 0) > 0) {
            document.getElementById('resultLangSkipCard').style.display = '';
            document.getElementById('resultLangSkipped').textContent = run.lang_skipped;
        }

        updateIssueExportLinks(run);

        if (typeof run.log === 'string' && run.log !== lastRenderedRunLog) {
            lastRenderedRunLog = run.log;
            logBox.textContent = run.log + "\n";
            logBox.scrollTop = logBox.scrollHeight;
        }
    }

    function syncImportButtons(run) {
        const active = run && ['queued', 'starting', 'running'].includes(run.status);
        document.getElementById('btnStartImport').style.display = active ? 'none' : '';
        document.getElementById('btnStopImport').style.display = active ? '' : 'none';
    }

    function updateIssueExportLinks(run) {
        const wrapper = document.getElementById('issueExportActions');
        if (!wrapper) return;

        if (!run || !run.issue_exports) {
            wrapper.style.display = 'none';
            return;
        }

        const failed = run.issue_exports.failed || {};
        const noFullLang = run.issue_exports.no_full_lang || {};
        const all = run.issue_exports.all || {};
        const hasIssues = (all.count || 0) > 0 || failed.available || noFullLang.available || all.available;

        wrapper.style.display = hasIssues ? '' : 'none';
        setIssueExportLink('exportFailedIssues', failed, 'Export Failed');
        setIssueExportLink('exportNoLangIssues', noFullLang, 'Export No Full Lang');
        setIssueExportLink('exportAllIssues', all, 'Export All Issues');
    }

    function setIssueExportLink(id, exportInfo, label) {
        const link = document.getElementById(id);
        if (!link) return;

        const count = exportInfo.count || 0;
        link.href = exportInfo.url || '#';
        link.classList.toggle('disabled', !exportInfo.url);
        link.setAttribute('aria-disabled', exportInfo.url ? 'false' : 'true');
        const icon = link.querySelector('i');
        link.innerHTML = `${icon ? icon.outerHTML : ''}${label} (${count})`;
    }

    async function stopImport() {
        if (!currentRunId) {
            importing = false;
            document.getElementById('btnStartImport').style.display = '';
            document.getElementById('btnStopImport').style.display = 'none';
            return;
        }

        try {
            const data = await apiJson(`{{ url('/admin/avdb-import/runs') }}/${currentRunId}/stop`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken }
            });
            if (data.run) applyRun(data.run);
            log(data.message || 'Stop requested.', 'warning');
        } catch (e) {
            log('Stop request failed: ' + e.message, 'error');
        }
    }

    async function refreshStats() {
        try {
            const data = await apiJson('{{ route("admin.avdb-import.stats") }}');
            document.querySelector('#stat-videos .fw-black').textContent = new Intl.NumberFormat().format(data.total_videos);
            document.querySelector('#stat-tags .fw-black').textContent = new Intl.NumberFormat().format(data.total_tags);
        } catch (e) {}
    }

    async function cleanAllVideos() {
        if (!confirm('{{ __("This will permanently delete ALL AVDB imported videos, their tags, and thumbnails. Are you sure?") }}')) return;

        log('Cleaning all AVDB videos...');
        try {
            const data = await apiJson('{{ route("admin.avdb-import.clean") }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken }
            });
            if (data.success) {
                log(data.message, 'success');
                refreshStats();
            } else {
                log('Clean failed.', 'error');
            }
        } catch (e) {
            log('Clean failed: ' + e.message, 'error');
        }
    }

    ['startPage', 'numPages', 'pageSize', 'specificIds'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', updateImportEstimate);
            el.addEventListener('change', updateImportEstimate);
        }
    });
    document.querySelectorAll('#categoryGrid input[type="checkbox"]').forEach(el => {
        el.addEventListener('change', updateImportEstimate);
    });
    document.getElementById('btnSyncDurations')?.addEventListener('click', startDurationSync);
    document.getElementById('avdbDurationStop')?.addEventListener('click', () => {
        durationSyncStopRequested = true;
        log('Duration sync will stop after the current batch finishes.', 'warning');
    });
    document.getElementById('avdbDurationRefresh')?.addEventListener('click', () => window.location.reload());
    toggleFullLangsMode();
    toggleStarterViews();
    scheduleFrequencyChanged();
    updateImportEstimate();
    testConnection({ automatic: true });
    if (latestRun) {
        currentRunId = latestRun.id;
        applyRun(latestRun);
        syncImportButtons(latestRun);
        if (['queued', 'starting', 'running'].includes(latestRun.status)) {
            importing = true;
            startPolling();
        }
    }
</script>
@endpush
@endsection
