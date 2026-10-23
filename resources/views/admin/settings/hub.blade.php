{{--
    Settings hub (/admin/settings): find any setting by name, old name or what it does, or pick a topic.
    The search runs on the server (no index to download); with JavaScript it updates while typing.
--}}
@extends('layouts.admin')
@section('title', __('Settings'))
@section('admin_page', 'settings.home')
@section('admin_width', 'form')

@section('content')
    @include('admin.settings.partials.header', [
        'page' => 'settings.home',
        'title' => __('Settings'),
        'description' => __('Find a setting by name, or pick a topic. Looking for colours and layouts? They are in Appearance.'),
    ])

    <form class="ad-hub-search" method="GET" action="{{ route('admin.settings.index') }}" role="search" data-hub-search>
        <label class="ad-bigsearch">
            <span class="ad-sr">{{ __('What do you want to change?') }}</span>
            <svg class="ad-i" aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ $query }}" autocomplete="off" spellcheck="false"
                   placeholder="{{ __('What do you want to change? Try "logo", "emails", "ads"') }}"
                   aria-controls="hub-results">
        </label>
    </form>

    <div id="hub-results" aria-live="polite" data-hub-results>
        @if ($query !== '')
            @include('admin.settings.partials.hub-results')
        @endif
    </div>

    <nav class="ad-setlist" aria-label="{{ __('Settings topics') }}" style="margin-top:20px">
        @foreach ($topics as $topic)
            <a href="{{ url($topic['url']) }}">
                <span class="ad-sym" aria-hidden="true">@if (method_exists(\App\Support\AdminTheme::class, 'icon')){{ \App\Support\AdminTheme::icon($topic['icon']) }}@else{{ \Illuminate\Support\Str::upper(mb_substr(__($topic['label']), 0, 1)) }}@endif</span>
                <span>
                    <b>{{ __($topic['label']) }}</b>
                    <span>{{ __($topic['description']) }}</span>
                    <small>{{ __($topic['examples']) }}</small>
                </span>
            </a>
        @endforeach
    </nav>
    <p class="ad-small ad-faint" style="margin-top:14px">{{ __('Ads and payments are in Money. Your logo, theme and layouts are in Appearance.') }}</p>

    <style>
        .ad-bigsearch input{flex:1;min-width:0;height:100%;border:0;background:transparent;color:var(--text);font:inherit;outline:0}
        .ad-bigsearch:focus-within{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}
        .ad-hub-results{margin-top:12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);overflow:hidden}
        .ad-hub-results a{display:block;padding:12px 20px;color:inherit;text-decoration:none;border-top:1px solid var(--border)}
        .ad-hub-results a:first-child{border-top:0}
        .ad-hub-results a:hover,.ad-hub-results a:focus-visible{background:var(--surface-2)}
        .ad-hub-results b{display:block;font-weight:600}
        .ad-hub-results small{display:block;color:var(--text-3);font-size:var(--fs-xs)}
        .ad-hub-results span{display:block;color:var(--text-2);font-size:var(--fs-sm)}
        .ad-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
    </style>
    <script>
    (function () {
        var form = document.querySelector('[data-hub-search]');
        var box = document.querySelector('[data-hub-results]');
        if (!form || !box || !window.fetch) return;
        var input = form.querySelector('input[name=q]');
        var timer = null;
        var last = input.value;
        function run() {
            var q = input.value.trim();
            if (q === last) return;
            last = q;
            if (q === '') { box.innerHTML = ''; history.replaceState(null, '', form.action); return; }
            fetch(form.action + '?q=' + encodeURIComponent(q) + '&partial=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (res) { return res.ok ? res.text() : ''; })
                .then(function (html) {
                    if (input.value.trim() !== q) return;
                    box.innerHTML = html;
                    history.replaceState(null, '', form.action + '?q=' + encodeURIComponent(q));
                });
        }
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 180); });
        form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); last = null; run(); });
    })();
    </script>
@endsection
