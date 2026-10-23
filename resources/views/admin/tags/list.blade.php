{{--
    Content > Tags in the redesigned admin (the old admin keeps tags/index.blade.php): Used / Not used tabs with counts,
    search, "Add a tag", each tag with its videos and a rename box, a row menu filled from one template
    (modules/list-rows.ts), the pager, merging two tags, and deleting the tags no video uses.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Tags'))

@section('content')
    @php ob_start(); @endphp
    @php
        $usage = (string) request('usage', '');
        $search = (string) request('search', '');
        $tab = fn (string $value, int $count, string $label) => [
            'label' => $label,
            'url' => route('admin.tags.index', array_filter(['usage' => $value]), false),
            'active' => $usage === $value,
            'count' => number_format($count),
        ];
    @endphp

    <x-admin.page-header page="content.tags"
        :description="trans_choice('1 tag. Videos get their tags when they are imported or uploaded.|:count tags. Videos get their tags when they are imported or uploaded.', $stats['total'], ['count' => number_format($stats['total'])])"
        :tabs="[$tab('', $stats['total'], __('All')), $tab('used', $stats['used'], __('Used')), $tab('unused', $stats['unused'], __('Not used'))]">
        <x-slot:actions>
            @if ($stats['unused'] > 0)
                <form method="POST" action="{{ route('admin.tags.delete-unused') }}"
                      data-ad-confirm="{{ trans_choice('Delete 1 tag no video uses?|Delete :count tags no video uses?', $stats['unused'], ['count' => number_format($stats['unused'])]) }}"
                      data-ad-confirm-body="{{ __('Videos keep all their tags. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete unused tags') }}">
                    @csrf
                    <button type="submit" class="ad-btn">{{ AdminTheme::icon('trash') }}{{ __('Delete unused tags') }}</button>
                </form>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-tag-delete" method="POST" action="{{ route('admin.tags.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Tag deleted') }}"
          data-ad-confirm="{{ __('Delete this tag?') }}" data-ad-confirm-body="{{ __('Its videos lose it. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete tag') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-tag-menu">
        <a href="{{ route('search.index', ['q' => '__Q__'], false) }}" target="_blank" rel="noopener" data-ad-if="used">{{ AdminTheme::icon('external') }}{{ __('Its videos on the site') }}</a>
        <hr>
        <button type="submit" form="ad-tag-delete" formaction="{{ route('admin.tags.destroy', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete tag...') }}</button>
    </template>

    <x-admin.table :paginator="$tags" :label="__('Tags')" list="tags" table-class="is-list" row-menu="ad-tag-menu">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.tags.index', [], false) }}" class="ad-filter" role="search" aria-label="{{ __('Find tags') }}">
                @if ($usage !== '')
                    <input type="hidden" name="usage" value="{{ $usage }}">
                @endif
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="search" value="{{ $search }}" placeholder="{{ __('Search tags') }}" aria-label="{{ __('Search tags') }}">
                </span>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
                @if ($search !== '')
                    <a class="ad-btn is-ghost" href="{{ route('admin.tags.index', array_filter(['usage' => $usage]), false) }}">{{ __('Clear filters') }}</a>
                @endif
            </form>
            <form method="POST" action="{{ route('admin.tags.store') }}" class="ad-filter ad-add">
                @csrf
                <input class="ad-input" type="text" name="name" maxlength="100" required placeholder="{{ __('New tag') }}" aria-label="{{ __('New tag') }}">
                <button type="submit" class="ad-btn">{{ AdminTheme::icon('plus') }}{{ __('Add tag') }}</button>
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th scope="col">{{ __('Tag') }}</th>
            <th scope="col" class="is-num">{{ __('Videos') }}</th>
            <th scope="col" class="hide-tab is-num">ID</th>
            <th scope="col" class="hide-tab">{{ __('Added') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            @if ($search !== '' || $usage !== '')
                <x-admin.empty icon="search" :title="__('No tags match')">{{ __('Try another word, or clear the filters.') }}</x-admin.empty>
            @else
                <x-admin.empty icon="tag" :title="__('No tags yet')">{{ __('Videos get their tags when they are imported or uploaded. You can add one above.') }}</x-admin.empty>
            @endif
        </x-slot:empty>

        @foreach ($tags as $tag)
            @php($used = (int) $tag->usage_count)
            <tr>
                <td class="c-main"><p class="ad-tag-name"><b id="ad-t-{{ $tag->id }}">{{ $tag->name }}</b> <code class="hide-phone">{{ $tag->slug }}</code></p>
                    <details class="ad-rename"><summary>{{ __('Rename') }}<span class="ad-vh"> {{ $tag->name }}</span></summary><form method="POST" action="{{ route('admin.tags.update', $tag->id, false) }}" class="ad-filter">@csrf @method('PUT')<input class="ad-input" type="text" name="name" value="{{ $tag->name }}" maxlength="100" required aria-label="{{ __('New name for :name', ['name' => $tag->name]) }}"><button type="submit" class="ad-btn sm">{{ __('Save') }}</button></form></details></td>
                <td class="c-meta is-num"><span class="ad-phone-only">{{ trans_choice('1 video|:count videos', $used, ['count' => number_format($used)]) }}</span><span class="hide-phone">{{ number_format($used) }}</span></td>
                <td class="hide-tab is-num">{{ $tag->id }}</td>
                <td class="hide-tab is-date">{{ \Illuminate\Support\Carbon::parse($tag->created_at)->translatedFormat('j M Y') }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-t-{{ $tag->id }}" data-id="{{ $tag->id }}" data-q="{{ rawurlencode($tag->name) }}"@if ($used > 0) data-used="1"@endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>

    <section class="ad-panel ad-console-block" aria-labelledby="ad-tags-merge">
        <div class="ad-panel-h">
            <div class="ad-home-grow">
                <h2 id="ad-tags-merge">{{ __('Merge two tags') }}</h2>
                <p class="ad-small ad-muted">{{ __('The videos of the first tag get the second one, then the first tag is deleted. The IDs are in the list above.') }}</p>
            </div>
        </div>
        <form method="POST" action="{{ route('admin.tags.merge') }}" class="ad-panel-b ad-record-fields is-3"
              data-ad-confirm="{{ __('Merge these tags?') }}" data-ad-confirm-body="{{ __('The first tag is deleted. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Merge tags') }}" data-ad-confirm-tone="primary">
            @csrf
            <x-admin.field name="source_id" type="number" :label="__('Tag to merge (ID)')" />
            <x-admin.field name="target_id" type="number" :label="__('Merge into (ID)')" />
            <div class="ad-record-actions"><button type="submit" class="ad-btn">{{ __('Merge tags') }}</button></div>
        </form>
    </section>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
