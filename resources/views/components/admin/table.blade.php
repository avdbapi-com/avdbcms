{{--
    Data table: toolbar (search, filters, sort, count), bulk bar on selection, sticky header, rows, pagination.
    Never wider than the content: hide low-value columns (class hide-tab / hide-phone on th + td).
    <x-admin.table :paginator="$videos" :label="__('Videos')" list="videos" class="is-list">
        <x-slot:toolbar><input class="ad-input" type="search" name="q" ...> ...</x-slot:toolbar>
        <x-slot:bulk><button class="ad-btn sm" form="videos-bulk" name="action" value="publish">Make public</button> ...</x-slot:bulk>
        <x-slot:head><th class="is-check"><input type="checkbox" class="ad-check" data-ad-select-all aria-label="Select all"></th><th>Video</th>...</x-slot:head>
        @foreach ($videos as $video)
            <tr><td class="is-check"><input type="checkbox" class="ad-check" name="ids[]" value="{{ $video->id }}" form="videos-bulk" data-ad-select aria-label="..."></td>...</tr>
        @endforeach
    </x-admin.table>
    Selection (modules/table.ts): ticking [data-ad-select] boxes shows the bulk bar with "N selected" and a Clear
    button, marks the rows (tr.is-selected), keeps [data-ad-select-all] ticked/empty/mixed; Shift+click ticks a range;
    the table sends "ad:selection" {count, values}. The boxes are plain form fields: put them (form="...") and the
    bulk buttons in one form, e.g. <form id="videos-bulk" method="POST" action="..."> elsewhere on the page.
    Props: paginator (LengthAwarePaginator: pager under the table), label (accessible table name), list (an id: the
    table is fetched again after a row action, modules/list-rows.ts), perPage (the choices of the pager's "per page";
    false hides it), tableClass (classes of the <table>: is-list = rows become two-line list items on phones),
    rowMenu (the <template> id of the rows' menus: each row then needs only <button class="ad-iconbtn is-more"
    data-id data-state ...>, modules/list-rows.ts; the script adds aria-haspopup when a row leaves it out).
    Row cells on phones (is-list): .c-main (first line), .c-meta (second line), .is-check and .is-actions keep their
    place, other cells hide. An empty body shows the "empty" slot (use <x-admin.empty>). The pager: <x-admin.list-pager>.
--}}
@use('App\Support\AdminTheme')
@props(['paginator' => null, 'label' => null, 'list' => null, 'perPage' => [25, 50, 100], 'tableClass' => null, 'rowMenu' => null])
@php($paged = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
<div {{ $attributes->class(['ad-tablewrap'])->merge(['data-ad-list' => $list, 'data-ad-failed' => __('That did not work. Reload the page and try again.')]) }}>
    @isset($toolbar)
        <div class="ad-toolbar">{{ $toolbar }}</div>
    @endisset
    @isset($bulk)
        <div class="ad-bulk" data-ad-bulk hidden role="region" aria-label="{{ __('Bulk actions') }}" data-one="{{ __('1 selected') }}" data-many="{{ __(':count selected') }}">
            <b data-ad-bulk-count aria-live="polite"></b>
            {{ $bulk }}
            <span class="ad-spacer"></span>
            <button type="button" class="ad-iconbtn" data-ad-bulk-clear aria-label="{{ __('Clear selection') }}">{{ AdminTheme::icon('x') }}</button>
        </div>
    @endisset
    @if (trim((string) $slot) === '' && isset($empty))
        {{ $empty }}
    @else
        <table @class(['ad-table', $tableClass]) @if($label) aria-label="{{ $label }}" @endif @if($rowMenu) data-ad-row-menu="{{ $rowMenu }}" @endif>
            @isset($head)
                <thead><tr>{{ $head }}</tr></thead>
            @endisset
            <tbody>{{ $slot }}</tbody>
        </table>
    @endif
    @if ($paged)
        <x-admin.list-pager :paginator="$paginator" :per-page="$perPage" />
    @endif
</div>
