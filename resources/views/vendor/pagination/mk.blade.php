{{-- Member list pagination (resources/views/vendor/pagination/mk.blade.php): $items->links('vendor.pagination.mk').
     Nothing is printed for a single page (studio-39: two disabled buttons on phones). Phones keep prev / current / next. --}}
@if ($paginator->hasPages())
    <nav class="mk-pager" aria-label="{{ __('Pages') }}">
        @if (method_exists($paginator, 'total'))
            <span>{{ __('Showing :from–:to of :total', ['from' => \App\Support\Studio\StudioFormat::number((int) $paginator->firstItem()), 'to' => \App\Support\Studio\StudioFormat::number((int) $paginator->lastItem()), 'total' => \App\Support\Studio\StudioFormat::number((int) $paginator->total())]) }}</span>
        @else
            <span></span>
        @endif
        <div class="mk-pager__pages">
            @if ($paginator->onFirstPage())
                <span class="mk-icon-btn mk-icon-btn--muted" aria-disabled="true" aria-label="{{ __('Previous page') }}" style="opacity:.4"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></span>
            @else
                <a class="mk-icon-btn mk-icon-btn--muted" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous page') }}"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
            @endif
            @isset($elements)
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="mk-pager__page" aria-hidden="true">…</span>
                    @endif
                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="mk-pager__page" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="mk-pager__page" href="{{ $url }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            @endisset
            @if ($paginator->hasMorePages())
                <a class="mk-icon-btn mk-icon-btn--muted" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Next page') }}"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
            @else
                <span class="mk-icon-btn mk-icon-btn--muted" aria-disabled="true" aria-label="{{ __('Next page') }}" style="opacity:.4"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span>
            @endif
        </div>
    </nav>
@endif
