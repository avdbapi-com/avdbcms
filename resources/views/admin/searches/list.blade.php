{{--
    Content > Views & searches > Searches, in the redesigned admin (the old admin keeps searches/index.blade.php): what
    visitors searched for, most searched first, all or only the searches that found nothing (each opens the site's
    results in a new tab), clearing the history, the pager.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Views & searches'))

@section('content')
    <x-admin.list-page>
    <x-admin.page-header page="content.stats"
        :description="trans_choice('1 search in the history.|:count searches in the history.', $stats['total'], ['count' => number_format($stats['total'])]).($stats['zero'] > 0 ? ' '.trans_choice('1 found nothing.|:count found nothing.', $stats['zero'], ['count' => number_format($stats['zero'])]) : '')">
        <x-slot:actions>
            @if ($stats['total'] > 0)
                <form method="POST" action="{{ route('admin.searches.clear') }}"
                      data-ad-confirm="{{ __('Clear the search history?') }}" data-ad-confirm-body="{{ __('This cannot be undone.') }}" data-ad-confirm-label="{{ __('Clear history') }}">
                    @csrf @method('DELETE')
                    <button type="submit" class="ad-btn is-danger">{{ AdminTheme::icon('trash') }}{{ __('Clear history...') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.table :paginator="$queries" :label="__('Searches')" list="searches" table-class="is-list">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.searches.index') }}" class="ad-filter" aria-label="{{ __('Filter searches') }}">
                <select class="ad-select" name="none" aria-label="{{ __('Show') }}" data-ad-autosubmit>
                    <option value="">{{ __('All searches') }}</option>
                    <option value="1" @selected($none)>{{ __('Searches that found nothing') }}</option>
                </select>
                <noscript><button type="submit" class="ad-btn">{{ __('Show') }}</button></noscript>
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th scope="col">{{ __('Searched for') }}</th>
            <th scope="col">{{ __('Found') }}</th>
            <th scope="col" class="is-num">{{ __('Times') }}</th>
            <th scope="col">{{ __('Last time') }}</th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="search" :title="$none ? __('Every search found something') : __('No searches yet')">{{ __('What visitors type in the search box is listed here.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($queries as $search)
            @php $last = \Illuminate\Support\Carbon::parse($search->last_searched); @endphp
            <tr>
                <td class="c-main"><p><a href="{{ route('search.index', ['q' => $search->query], false) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit((string) $search->query, 90) }}</a></p></td>
                <td class="c-meta"><x-admin.status :type="(int) $search->max_results > 0 ? 'ok' : 'warn'">{{ (int) $search->max_results > 0 ? trans_choice('1 result|:count results', (int) $search->max_results, ['count' => number_format((int) $search->max_results)]) : __('Nothing found') }}</x-admin.status><small class="ad-phone-only">{{ trans_choice('Searched once|Searched :count times', (int) $search->count, ['count' => number_format((int) $search->count)]) }}</small></td>
                <td class="is-num">{{ number_format((int) $search->count) }}</td>
                <td class="is-date" title="{{ $last->translatedFormat('j M Y, H:i') }}">{{ $last->diffForHumans(short: true) }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    </x-admin.list-page>
@endsection
