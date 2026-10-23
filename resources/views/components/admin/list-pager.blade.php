{{--
    The pager of the list pages: "Showing 1-25 of 433", the rows per page (a GET form; the select applies at once,
    data-ad-autosubmit) and the page links (first, last and the pages around the current one).
    <x-admin.list-pager :paginator="$videos" />          (<x-admin.table> prints it under its rows)
    Props: paginator (LengthAwarePaginator), perPage (choices; false hides them; the current size is always one).
--}}
@use('App\Support\AdminTheme')
@props(['paginator', 'perPage' => [25, 50, 100]])
@php
    $pageName = $paginator->getPageName();
    $perPageOptions = $perPage !== false
        ? collect((array) $perPage)->push($paginator->perPage())->map(fn ($n) => (int) $n)->filter(fn ($n) => $n > 0)->unique()->sort()->values()->all()
        : [];
    // The pages around the current one, the first and the last; gaps become an ellipsis.
    $pages = [];
    if ($paginator->hasPages()) {
        $last = $paginator->lastPage();
        $current = $paginator->currentPage();
        foreach (array_unique([1, $current - 1, $current, $current + 1, $last]) as $number) {
            if ($number >= 1 && $number <= $last) {
                $pages[] = $number;
            }
        }
        sort($pages);
    }
@endphp
@if ($paginator->total() > 0)
    <div {{ $attributes->class(['ad-pager']) }}>
        <span>{{ __('Showing :from-:to of :total', ['from' => number_format((int) $paginator->firstItem()), 'to' => number_format((int) $paginator->lastItem()), 'total' => number_format($paginator->total())]) }}</span>
        @if (count($perPageOptions) > 1 && $paginator->total() > min($perPageOptions))
            <form method="GET" action="{{ url()->current() }}" class="ad-pager-size">
                @foreach (\Illuminate\Support\Arr::dot(request()->except(['per_page', $pageName])) as $name => $value)
                    @if ($value !== null && $value !== '' && ! is_array($value))
                        <input type="hidden" name="{{ preg_replace('/\.([^.]+)/', '[$1]', (string) $name) }}" value="{{ $value }}">
                    @endif
                @endforeach
                <label class="ad-vh" for="ad-per-page-{{ $pageName }}">{{ __('Rows per page') }}</label>
                <select class="ad-select" id="ad-per-page-{{ $pageName }}" name="per_page" data-ad-autosubmit>
                    @foreach ($perPageOptions as $option)
                        <option value="{{ $option }}" @selected($option === $paginator->perPage())>{{ __(':count per page', ['count' => $option]) }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="ad-btn sm">{{ __('Show') }}</button></noscript>
            </form>
        @endif
        @if ($pages !== [])
            <nav class="ad-pages" aria-label="{{ __('Pages') }}">
                @if ($paginator->onFirstPage())
                    <span class="is-disabled" aria-hidden="true">{{ AdminTheme::icon('chev-left', 'flip-rtl') }}</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous page') }}">{{ AdminTheme::icon('chev-left', 'flip-rtl') }}</a>
                @endif
                @foreach ($pages as $i => $number)
                    @if ($i > 0 && $number - $pages[$i - 1] > 1)
                        <span class="is-gap" aria-hidden="true">&hellip;</span>
                    @endif
                    @if ($number === $paginator->currentPage())
                        <span class="is-active" aria-current="page">{{ $number }}</span>
                    @else
                        <a href="{{ $paginator->url($number) }}" aria-label="{{ __('Page :number', ['number' => $number]) }}">{{ $number }}</a>
                    @endif
                @endforeach
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Next page') }}">{{ AdminTheme::icon('chev-right', 'flip-rtl') }}</a>
                @else
                    <span class="is-disabled" aria-hidden="true">{{ AdminTheme::icon('chev-right', 'flip-rtl') }}</span>
                @endif
            </nav>
        @endif
    </div>
@endif
