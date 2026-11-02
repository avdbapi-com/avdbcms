{{-- The script of the channel, library and playlist pages (resources/js/member/library-pages.js: their small actions,
     the channel's tabs, History), printed once per page by every page that needs a part of it. One cached file instead
     of 14 to 31 KB of inline script in every page (R1-069, member design 4.3): a classic script where the inline ones
     were, so its Alpine components are ready when Alpine starts, with a preload in the head so it loads early. A build
     without the file prints nothing (the page is served all the same). --}}
@once
    @php
        try {
            $libraryPagesScript = \Illuminate\Support\Facades\Vite::asset('resources/js/member/library-pages.js');
        } catch (\Throwable) {
            $libraryPagesScript = '';
        }
    @endphp
    @if($libraryPagesScript !== '')
        @push('preload')
            <link rel="preload" as="script" href="{{ $libraryPagesScript }}">
        @endpush
        @push('scripts')
            <script src="{{ $libraryPagesScript }}"></script>
        @endpush
    @endif
@endonce
