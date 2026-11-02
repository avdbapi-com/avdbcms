{{-- The studio's script (resources/js/member/studio-pages.js), printed once per page by layouts/user.blade.php: the member
     bars on every back-office page, and the studio parts the page asked for (@include('users.partials.studio-scripts',
     ['studioScriptsParts' => [...]]) pushed their names to the mk-studio-parts stack), with the texts it shows. One cached
     file instead of 2 to 15 KB of inline script in every studio page (1.2.3 round 1b, R1R-01; member design 4.3): a
     classic script where the inline ones were (at the end of the page), so its Alpine components are ready when Alpine
     starts, with a preload in the head so it loads early. A build without the file prints nothing (the page is served
     all the same). --}}
@once
    @php
        try {
            $studioPagesScript = \Illuminate\Support\Facades\Vite::asset('resources/js/member/studio-pages.js');
        } catch (\Throwable) {
            $studioPagesScript = '';
        }
        $studioPagesParts = array_values(array_unique(array_filter(preg_split('/[\s,]+/', (string) $__env->yieldPushContent('mk-studio-parts')) ?: [])));
    @endphp
    @if($studioPagesScript !== '')
        @push('preload')
            <link rel="preload" as="script" href="{{ $studioPagesScript }}">
        @endpush
        {{-- the parts and the words they show (Read more / Show less only with the readmore part) --}}
        <script src="{{ $studioPagesScript }}"@if($studioPagesParts !== []) data-parts="{{ implode(',', $studioPagesParts) }}" data-copied="{{ __('Link copied') }}"{!! in_array('readmore', $studioPagesParts, true) ? ' data-more="'.e(__('Read more')).'" data-less="'.e(__('Show less')).'"' : '' !!}@endif></script>
    @endif
@endonce
