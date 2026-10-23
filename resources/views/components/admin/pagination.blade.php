@props([
    'paginator',
    'perPageOptions' => \App\Support\AdminPagination::perPageOptions(),
    'perPageParam' => 'per_page',
])

{{-- The new admin shows its own pager (rows per page: 50 or 100 next to the page's own size). --}}
@if($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && \App\Support\AdminPreferences::usesNewAdmin())
    <x-admin.list-pager :paginator="$paginator" :per-page="array_values(array_intersect([25, 50, 100], array_map('intval', (array) $perPageOptions)))" class="ad-legacy-pager" />
@elseif($paginator && method_exists($paginator, 'hasPages') && ($paginator->hasPages() || !empty($perPageOptions)))
    @php
        $pageName = $paginator->getPageName();
        $perPageOptions = collect($perPageOptions)
            ->map(fn ($option) => (int) $option)
            ->push((int) $paginator->perPage())
            ->filter(fn ($option) => $option > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $queryForPerPage = request()->except([$perPageParam, $pageName]);
        $renderHiddenInputs = function (array $values, string $prefix = '') use (&$renderHiddenInputs): void {
            foreach ($values as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
                if (is_array($value)) {
                    $renderHiddenInputs($value, $name);
                } elseif ($value !== null && $value !== '') {
                    echo '<input type="hidden" name="'.e($name).'" value="'.e((string) $value).'">';
                }
            }
        };
    @endphp
    <div {{ $attributes->merge(['class' => 'admin-pagination mt-4 rounded-3 border bg-body-tertiary px-3 py-3']) }}>
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
            <div class="d-flex flex-column flex-md-row align-items-md-center gap-3">
                <div class="small text-muted">
                    <span class="fw-semibold text-body">{{ __('Page') }} {{ $paginator->currentPage() }}</span>
                    <span class="mx-1">/</span>
                    <span>{{ $paginator->lastPage() }}</span>
                    <span class="mx-2">.</span>
                    <span>
                        {{ __('Showing') }}
                        <span class="fw-semibold text-body">{{ number_format($paginator->firstItem() ?? 0) }}</span>
                        {{ __('to') }}
                        <span class="fw-semibold text-body">{{ number_format($paginator->lastItem() ?? 0) }}</span>
                        {{ __('of') }}
                        <span class="fw-semibold text-body">{{ number_format($paginator->total()) }}</span>
                        {{ trans_choice('pagination.results', $paginator->total()) }}
                    </span>
                </div>

                @if(!empty($perPageOptions))
                    <form method="GET" action="{{ url()->current() }}" class="d-flex align-items-center gap-2 small text-muted mb-0">
                        @php($renderHiddenInputs($queryForPerPage))
                        <label for="adminPaginationPerPage-{{ $pageName }}" class="mb-0">{{ __('Per page') }}</label>
                        <select
                            id="adminPaginationPerPage-{{ $pageName }}"
                            name="{{ $perPageParam }}"
                            class="form-select form-select-sm w-auto"
                            aria-label="{{ __('Results per page') }}"
                            data-native-select
                            onchange="this.form.submit()"
                        >
                            @foreach($perPageOptions as $option)
                                <option value="{{ $option }}" @selected((int) request()->query($perPageParam, $paginator->perPage()) === (int) $option)>
                                    {{ number_format((int) $option) }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-sm btn-outline-primary" title="{{ __('Apply') }}" aria-label="{{ __('Apply') }}">
                            <i class="fa-solid fa-check"></i>
                        </button>
                    </form>
                @endif
            </div>

            @if($paginator->hasPages())
                <div class="admin-pagination-links">
                    {{ $paginator->withQueryString()->onEachSide(1)->links() }}
                </div>
            @endif
        </div>
    </div>
@endif
