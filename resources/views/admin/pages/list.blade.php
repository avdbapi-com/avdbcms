{{--
    Content > Info pages in the redesigned admin (the old admin keeps pages/index.blade.php): the pages with their state
    and place, a row menu filled from one template (modules/list-rows.ts: publish / unpublish with Undo, delete with
    Undo), the pager and "New page".
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Info pages'))

@section('content')
    @php ob_start(); @endphp
    <x-admin.page-header page="content.pages"
        :description="trans_choice('1 page, such as About us or Terms.|:count pages, such as About us or Terms.', $pages->total(), ['count' => number_format($pages->total())])">
        <x-slot:actions>
            <a class="ad-btn is-primary" href="{{ route('admin.pages.create') }}">{{ AdminTheme::icon('plus') }}{{ __('New page') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-page-toggle" method="POST" action="{{ route('admin.pages.index', [], false) }}" data-ad-row-action="change" hidden></form>
    <form id="ad-page-delete" method="POST" action="{{ route('admin.pages.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Page deleted') }}"
          data-ad-confirm="{{ __('Delete this page?') }}" data-ad-confirm-body="{{ __('Its text is deleted too. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete page') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-page-menu">
        <a href="{{ route('admin.pages.edit', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('pencil') }}{{ __('Edit') }}</a>
        <hr>
        <button type="submit" form="ad-page-toggle" formaction="{{ route('admin.pages.toggle', ['id' => '__ID__'], false) }}" data-ad-when="draft" data-ad-expect="published" data-ad-done="{{ __('Page published') }}" data-ad-undo-url="{{ route('admin.pages.toggle', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('eye') }}{{ __('Publish') }}</button>
        <button type="submit" form="ad-page-toggle" formaction="{{ route('admin.pages.toggle', ['id' => '__ID__'], false) }}" data-ad-when="published" data-ad-expect="draft" data-ad-done="{{ __('Page unpublished') }}" data-ad-undo-url="{{ route('admin.pages.toggle', ['id' => '__ID__'], false) }}">{{ AdminTheme::icon('eye-off') }}{{ __('Unpublish') }}</button>
        <button type="submit" form="ad-page-delete" formaction="{{ route('admin.pages.destroy', ['id' => '__ID__'], false) }}" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete page...') }}</button>
    </template>

    <x-admin.table :paginator="$pages" :label="__('Info pages')" list="pages" table-class="is-list" row-menu="ad-page-menu">
        <x-slot:head>
            <th scope="col">{{ __('Page') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col" class="hide-tab">{{ __('Shown in') }}</th>
            <th scope="col" class="is-num hide-tab">{{ __('Order') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="file" :title="__('No info pages yet')" :action="__('Write your first page')" :href="route('admin.pages.create')">{{ __('Pages such as About us, Terms or Contact.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($pages as $page)
            @php
                $places = array_filter([$page->show_in_header ? __('Header') : null, $page->show_in_footer ? __('Footer') : null]);
            @endphp
            <tr>
                <td class="c-main"><p><a id="ad-pg-{{ $page->id }}" href="{{ route('admin.pages.edit', $page->id, false) }}">{{ $page->title }}</a><small>/page/{{ $page->slug }}</small></p></td>
                <td class="c-meta"><x-admin.status :type="$page->is_published ? 'ok' : 'off'">{{ $page->is_published ? __('Published') : __('Draft') }}</x-admin.status></td>
                <td class="hide-tab">{{ $places !== [] ? implode(', ', $places) : '-' }}</td>
                <td class="is-num hide-tab">{{ (int) $page->sort_order }}</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-pg-{{ $page->id }}" data-id="{{ $page->id }}" data-state="{{ $page->is_published ? 'published' : 'draft' }}"></button></td>
            </tr>
        @endforeach
    </x-admin.table>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
