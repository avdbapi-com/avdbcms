{{--
    Content > Categories in the redesigned admin (the old admin keeps categories/index.blade.php): search, the categories
    with their icon, menu place and number of videos, a row menu filled from one template (modules/list-rows.ts), the
    pager, and the order of the menu (moved up and down with buttons, modules/list-order.ts, then saved at once).
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Categories'))

@section('content')
    @php
        $search = (string) request('search', '');
        // A category's place in the menu, counted in the menu's order (1 = first).
        $places = $menu->pluck('id')->flip();
    @endphp

    <x-admin.page-header page="content.categories"
        :description="trans_choice('1 category. The ones in the menu show on every page of your site.|:count categories. The ones in the menu show on every page of your site.', $categories->total(), ['count' => number_format($categories->total())])">
        <x-slot:actions>
            <a class="ad-btn is-primary" href="{{ route('admin.categories.create') }}">{{ AdminTheme::icon('plus') }}{{ __('New category') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-category-delete" method="POST" action="{{ route('admin.categories.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Category deleted') }}"
          data-ad-confirm="{{ __('Delete this category?') }}" data-ad-confirm-body="{{ __('Only a category without videos can be deleted. You can undo for a few seconds after this.') }}" data-ad-confirm-label="{{ __('Delete category') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-category-menu">
        <a href="{{ route('admin.categories.edit', ['category' => '__ID__'], false) }}">{{ AdminTheme::icon('pencil') }}{{ __('Edit') }}</a>
        <a href="{{ route('admin.videos.index', ['category' => '__ID__'], false) }}" data-ad-if="videos">{{ AdminTheme::icon('film') }}{{ __('Its videos') }}</a>
        <a href="__URL__" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('Open on site') }}</a>
        <hr>
        <button type="submit" form="ad-category-delete" formaction="{{ route('admin.categories.destroy', ['category' => '__ID__'], false) }}" class="is-danger" data-ad-when="empty">{{ AdminTheme::icon('trash') }}{{ __('Delete category...') }}</button>
    </template>

    <x-admin.table :paginator="$categories" :label="__('Categories')" list="categories" table-class="is-list" row-menu="ad-category-menu">
        <x-slot:toolbar>
            <form method="GET" action="{{ route('admin.categories.index', [], false) }}" class="ad-filter" role="search" aria-label="{{ __('Find categories') }}">
                <span class="ad-search-field">
                    {{ AdminTheme::icon('search', 'sm') }}
                    <input class="ad-input" type="search" name="search" value="{{ $search }}" placeholder="{{ __('Name or address') }}" aria-label="{{ __('Search categories') }}">
                </span>
                <span class="ad-spacer"></span>
                <button type="submit" class="ad-btn">{{ __('Search') }}</button>
                @if ($search !== '')
                    <a class="ad-btn is-ghost" href="{{ route('admin.categories.index', [], false) }}">{{ __('Clear filters') }}</a>
                @endif
            </form>
        </x-slot:toolbar>

        <x-slot:head>
            <th scope="col">{{ __('Category') }}</th>
            <th scope="col">{{ __('In the menu') }}</th>
            <th scope="col" class="hide-tab">{{ __('Address') }}</th>
            <th scope="col" class="is-num">{{ __('Videos') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            @if ($search !== '')
                <x-admin.empty icon="search" :title="__('No categories match')">{{ __('Try another word, or clear the filters.') }}</x-admin.empty>
            @else
                <x-admin.empty icon="folder" :title="__('No categories yet')" :action="__('Create your first category')" :href="route('admin.categories.create')">{{ __('Categories group your videos and fill the menu of your site.') }}</x-admin.empty>
            @endif
        </x-slot:empty>

        @foreach ($categories as $category)
            @php($videos = (int) $category->videos_count)
            <tr>
                <td class="c-main"><div class="ad-vid"><span class="ad-sym" aria-hidden="true"><i class="fa-solid fa-{{ $category->icon_name }}"></i></span><p><a id="ad-c-{{ $category->id }}" href="{{ route('admin.categories.edit', $category, false) }}">{{ $category->title }}</a><small class="ad-phone-only">{{ trans_choice('1 video|:count videos', $videos, ['count' => number_format($videos)]) }}</small></p></div></td>
                <td class="c-meta"><x-admin.status :type="$category->in_menu ? 'ok' : 'off'">{{ $category->in_menu && isset($places[$category->id]) ? __('Shown, place :place', ['place' => $places[$category->id] + 1]) : __('Not shown') }}</x-admin.status></td>
                <td class="hide-tab ad-muted is-date">/categories/{{ $category->slug }}</td>
                <td class="is-num">@if ($videos > 0)<a href="{{ route('admin.videos.index', ['category' => $category->id], false) }}">{{ number_format($videos) }}</a>@else 0 @endif</td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-c-{{ $category->id }}" data-id="{{ $category->id }}" data-state="{{ $videos > 0 ? 'used' : 'empty' }}" data-url="{{ preg_replace('#^https?://[^/]+#', '', $category->route) }}"@if ($videos > 0) data-videos="1"@endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>

    @if ($menu->isNotEmpty())
        {{-- The menu's order: Up / Down move a category (modules/list-order.ts), Save order sends the new order. --}}
        <section class="ad-panel ad-console-block ad-order" aria-labelledby="ad-categories-order">
            <div class="ad-panel-h">
                <div class="ad-home-grow">
                    <h2 id="ad-categories-order">{{ __('Order in the menu') }}</h2>
                    <p class="ad-small ad-muted">{{ __('Visitors see the categories of the menu in this order.') }}</p>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.categories.organize') }}" data-ad-order>
                @csrf
                <ol class="ad-list ad-order-list" data-ad-order-list>
                    @foreach ($menu as $category)
                        <li data-ad-order-item>
                            <input type="hidden" name="categories[]" value="{{ $category->id }}">
                            <span class="ad-sym" aria-hidden="true"><i class="fa-solid fa-{{ $category->icon_name }}"></i></span>
                            <span class="ad-li-text"><b id="ad-o-{{ $category->id }}">{{ $category->title }}</b></span>
                            <button type="button" class="ad-iconbtn" data-ad-order-move="-1" aria-labelledby="ad-l-up ad-o-{{ $category->id }}" @disabled($loop->first)>{{ AdminTheme::icon('chev-down', 'is-up') }}</button>
                            <button type="button" class="ad-iconbtn" data-ad-order-move="1" aria-labelledby="ad-l-down ad-o-{{ $category->id }}" @disabled($loop->last)>{{ AdminTheme::icon('chev-down') }}</button>
                        </li>
                    @endforeach
                </ol>
                <span hidden id="ad-l-up">{{ __('Move up:') }}</span><span hidden id="ad-l-down">{{ __('Move down:') }}</span>
                <div class="ad-panel-f">
                    <span class="ad-small ad-muted" data-ad-order-status data-moved="{{ __(':name: place :place of :total') }}" aria-live="polite"></span>
                    <span class="ad-spacer"></span>
                    <button type="submit" class="ad-btn is-primary sm">{{ __('Save order') }}</button>
                </div>
            </form>
        </section>
    @endif
@endsection
