{{--
    Ctrl+K search dialog (resources/admin/palette.ts fills it on first use). The shell renders it once.
    The index URL carries the index version and the language, so the browser keeps the index between pages.
--}}
@use('App\Support\AdminTheme')
@php($indexUrl = route('admin.shell.search-index', ['v' => \App\Support\AdminSearchIndex::version(auth()->user()), 'l' => app()->getLocale()]))
<dialog id="ad-palette" class="ad-palette" aria-label="{{ __('Search the admin') }}"
        data-index-url="{{ $indexUrl }}" data-search-url="{{ route('admin.shell.search') }}">
    <div class="ad-palette-in">
        {{ AdminTheme::icon('search') }}
        <input type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ad-palette-list"
               placeholder="{{ __('Search pages, settings, members, videos') }}" aria-label="{{ __('Search') }}" autocomplete="off" spellcheck="false">
        <span class="ad-kbd" aria-hidden="true">Esc</span>
    </div>
    <div id="ad-palette-list" class="ad-palette-list" role="listbox" aria-label="{{ __('Results') }}"></div>
    <div class="ad-palette-f" aria-hidden="true">
        <span><span class="ad-kbd">&uarr;</span> <span class="ad-kbd">&darr;</span> {{ __('to move') }}</span>
        <span><span class="ad-kbd">Enter</span> {{ __('to open') }}</span>
        <span>{{ __('Old names work too: try "Preflight" or "Mass Broadcast"') }}</span>
    </div>
</dialog>
