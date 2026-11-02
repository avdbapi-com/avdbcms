{{-- The header search before its component has loaded (watch-8): a plain GET form to the search page with the same
     field and button classes as resources/js/components/Search/SearchBar.tsx, so the header keeps its size and a
     visitor can search before any script runs. The component takes over the typed text and the focus when it loads
     (resources/js/components/index.ts). --}}
<form method="GET" action="{{ locale_url(route('search.index')) }}" class="avd-search-wrap avd-search-responsive w-100 mb-0" role="search" autocomplete="off">
    <div class="input-group flex-nowrap">
        <input class="form-control" type="search" name="q" value="{{ is_scalar(request()->query('q')) && request()->routeIs('search.index') ? \Illuminate\Support\Str::limit((string) request()->query('q'), 255, '') : '' }}" placeholder="{{ __('Search') }}" aria-label="{{ __('Search') }}" maxlength="255" autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" inputmode="search">
        <button class="btn" type="submit" aria-label="{{ __('Search') }}"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></button>
    </div>
</form>
