{{--
    Settings > Languages > translate one language, in the redesigned admin (the old admin keeps edit.blade.php):
    100 strings per page, a search and a filter (missing, translated, JSON / PHP keys); "Save this page" saves the
    strings shown (partial=1: LanguageController::update() merges them into the language files).
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Translate :language', ['language' => $language->label]))

@section('content')
    @php
        $filters = ['all' => __('All strings'), 'missing' => __('Missing only'), 'filled' => __('Translated only'), 'json' => __('JSON keys only'), 'php' => __('PHP file keys only')];
    @endphp

    <x-admin.page-header page="settings.languages"
        :title="__('Translate :language', ['language' => trim($language->emoji.' '.$language->label)])"
        :description="__(':done of :total strings translated, :missing missing.', ['done' => number_format($total - $missing), 'total' => number_format($total), 'missing' => number_format($missing)])"
        :tabs="[]">
        <x-slot:actions>
            <a class="ad-btn" href="{{ route('admin.languages.export', $locale) }}">{{ AdminTheme::icon('download') }}{{ __('Export JSON') }}</a>
            <a class="ad-btn" href="{{ route('admin.languages.edit-meta', $language) }}">{{ AdminTheme::icon('gear') }}{{ __('Language Settings') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.table :paginator="$rows" :label="__(':label translation', ['label' => $language->label])" :per-page="false" class="ad-translate">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.languages.edit', $locale) }}" class="ad-filter" role="search" aria-label="{{ __('Find strings') }}">
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search keys or translations...') }}" aria-label="{{ __('Search keys or translations...') }}">
                </span>
                <select class="ad-select" name="show" aria-label="{{ __('Show') }}" data-ad-autosubmit>
                    @foreach ($filters as $value => $label)
                        <option value="{{ $value }}" @selected($show === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <span class="ad-spacer"></span>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th scope="col">{{ __('English (reference)') }}</th>
            <th scope="col">{{ __(':label translation', ['label' => $language->label]) }}</th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="search" :title="__('No strings match')">{{ __('Try another word, or show all strings.') }}</x-admin.empty>
        </x-slot:empty>

        {{-- No whitespace compaction here: it would change multi-line translations inside the fields. --}}
        @foreach ($rows as $row)
            @php $field = 'ad-t-'.$loop->index; @endphp
            <tr @class(['is-missing' => $row['missing']])>
                <td class="ad-translate-source">
                    <label for="{{ $field }}">{{ $row['english'] }}</label>
                    @if ($row['key'] !== $row['english'])
                        <code class="ad-vid-sub">{{ $row['key'] }}</code>
                    @endif
                </td>
                <td class="ad-translate-field">
                    <textarea class="ad-textarea" id="{{ $field }}" name="translations[{{ $row['key'] }}]" form="ad-translate-form" rows="{{ strlen((string) $row['english']) > 90 || str_contains((string) $row['english'], "\n") ? 3 : 1 }}" placeholder="{{ $row['missing'] ? __('Missing') : '' }}">{{ $row['translation'] }}</textarea>
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    @if ($rows->count() > 0)
        <form id="ad-translate-form" method="POST" action="{{ route('admin.languages.update', $locale) }}" class="ad-translate-save">
            @csrf
            @method('PUT')
            <input type="hidden" name="partial" value="1">
            <span class="ad-small ad-muted">{{ __('Saves the :count strings of this page. Empty fields fall back to English.', ['count' => $rows->count()]) }}</span>
            <button type="submit" class="ad-btn is-primary">{{ __('Save this page') }}</button>
        </form>
    @endif
@endsection
