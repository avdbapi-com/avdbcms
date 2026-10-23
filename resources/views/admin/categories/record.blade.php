{{--
    Content > Categories > one category (new or existing), in the redesigned admin (the old admin keeps
    categories/create and edit): name, address, icon (with its preview) and menu switch, saved with the save bar,
    Ctrl+S or the header's button; beside them its facts; Delete in the header's More menu with its confirmation.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', $category->exists ? $category->title : __('New category'))

@section('content')
    @php
        $videos = (int) ($category->videos_count ?? 0);
        $title = $category->exists ? $category->title : __('New category');
    @endphp

    <x-admin.page-header page="content.categories" :title="$title"
        :description="$category->exists ? trans_choice('1 video in this category.|:count videos in this category.', $videos, ['count' => number_format($videos)]) : __('Categories group your videos and fill the menu of your site.')"
        :crumbs="[['label' => __('Content'), 'url' => route('admin.videos.index')], ['label' => __('Categories'), 'url' => route('admin.categories.index')], ['label' => \Illuminate\Support\Str::limit($title, 40), 'url' => null]]"
        :tabs="[]">
        <x-slot:actions>
            @if ($category->exists)
                <a class="ad-btn" href="{{ $category->route }}" target="_blank" rel="noopener">{{ AdminTheme::icon('external') }}{{ __('Open on site') }}</a>
                <x-admin.menu id="ad-category-more" :label="__('More actions')">
                    <a href="{{ route('admin.videos.index', ['category' => $category->id]) }}">{{ AdminTheme::icon('film') }}{{ __('Its videos') }}</a>
                    @if ($videos === 0)
                        <hr>
                        <form method="POST" action="{{ route('admin.categories.destroy', $category) }}"
                              data-ad-confirm="{{ __('Delete this category?') }}" data-ad-confirm-body="{{ __('It has no videos. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete category') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete category...') }}</button>
                        </form>
                    @endif
                </x-admin.menu>
            @endif
            <button type="submit" class="ad-btn is-primary" form="ad-category-form">{{ $category->exists ? __('Save changes') : __('Create category') }}</button>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="ad-record">
        <form id="ad-category-form" method="POST" action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}" @if ($category->exists) data-ad-form @endif class="ad-record-main">
            @csrf
            @if ($category->exists)
                @method('PUT')
            @endif

            <section class="ad-panel" aria-labelledby="ad-category-details">
                <div class="ad-panel-h"><h2 id="ad-category-details">{{ __('Details') }}</h2></div>
                <div class="ad-panel-b ad-record-fields">
                    <x-admin.field name="title" :label="__('Name')" :value="$category->title" />
                    <x-admin.field name="slug" :label="__('Address')" :value="$category->slug" :help="__('The last part of the category\'s address, e.g. :example. Leave it empty to make it from the name.', ['example' => '/categories/music'])" />
                    <x-admin.field name="icon" :label="__('Icon')" :help="__('A Font Awesome icon name, e.g. music, film or star.')">
                        <span class="ad-input-icon">
                            <span class="ad-sym" aria-hidden="true"><i class="fa-solid fa-{{ $category->exists ? $category->icon_name : 'folder' }}"></i></span>
                            <input class="ad-input" id="icon" name="icon" value="{{ old('icon', $category->icon) }}" maxlength="50" pattern="[A-Za-z0-9-]+" aria-describedby="icon-help" autocomplete="off">
                        </span>
                    </x-admin.field>
                </div>
            </section>

            <section class="ad-panel" aria-labelledby="ad-category-menu-h">
                <div class="ad-panel-h"><h2 id="ad-category-menu-h">{{ __('Menu') }}</h2></div>
                {{-- The store/update read the switch as on when it is sent, so it sends nothing when off. --}}
                <label class="ad-toggle-row" for="in_menu" data-ad-field="in_menu">
                    <span class="ad-toggle-text">
                        <b>{{ __('Show in the menu') }}</b>
                        <span class="ad-help" id="in_menu-help">{{ __('Visitors see it in the menu of every page. Change the order on the Categories page.') }}</span>
                    </span>
                    <span class="ad-switch">
                        <input type="checkbox" role="switch" id="in_menu" name="in_menu" value="1" @checked(session()->hasOldInput() ? old('in_menu') === '1' : (bool) $category->in_menu) aria-describedby="in_menu-help">
                        <i aria-hidden="true"></i>
                    </span>
                </label>
            </section>

            @if ($category->exists)
                <x-admin.savebar form="ad-category-form" />
            @else
                <div class="ad-record-actions">
                    <a class="ad-btn" href="{{ route('admin.categories.index') }}">{{ __('Cancel') }}</a>
                    <button type="submit" class="ad-btn is-primary">{{ __('Create category') }}</button>
                </div>
            @endif
        </form>

        @if ($category->exists)
            <aside class="ad-record-side" aria-label="{{ __('About this category') }}">
                <section class="ad-panel">
                    <dl class="ad-facts">
                        <div><dt>{{ __('Videos') }}</dt><dd>{{ number_format($videos) }}</dd></div>
                        <div><dt>{{ __('In the menu') }}</dt><dd>{{ $category->in_menu && $place !== null ? __('Place :place', ['place' => $place]) : __('No') }}</dd></div>
                        <div><dt>{{ __('Created') }}</dt><dd>{{ $category->created_at?->translatedFormat('j M Y') ?? '-' }}</dd></div>
                        <div><dt>ID</dt><dd>#{{ $category->id }}</dd></div>
                    </dl>
                </section>
            </aside>
        @endif
    </div>
@endsection
