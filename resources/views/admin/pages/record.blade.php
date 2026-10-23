{{--
    Content > Info pages > one page (new or existing), in the redesigned admin (the old admin keeps pages/form): title,
    address, text, search engine fields and where it shows, saved with the save bar, Ctrl+S or the header's button;
    Delete in the header's More menu with its confirmation.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', $page ? $page->title : __('New page'))

@section('content')
    @php
        $title = $page ? $page->title : __('New page');
        $switch = fn (string $name) => session()->hasOldInput() ? old($name) === '1' : (bool) ($page->{$name} ?? false);
    @endphp

    <x-admin.page-header page="content.pages" :title="$title"
        :description="$page ? ($page->is_published ? __('Published') : __('Draft')) : __('Pages such as About us, Terms or Contact.')"
        :crumbs="[['label' => __('Content'), 'url' => route('admin.videos.index')], ['label' => __('Info pages'), 'url' => route('admin.pages.index')], ['label' => \Illuminate\Support\Str::limit($title, 40), 'url' => null]]"
        :tabs="[]">
        <x-slot:actions>
            @if ($page)
                <x-admin.menu id="ad-page-more" :label="__('More actions')">
                    <form method="POST" action="{{ route('admin.pages.destroy', $page->id) }}"
                          data-ad-confirm="{{ __('Delete this page?') }}" data-ad-confirm-body="{{ __('Its text is deleted too. This cannot be undone.') }}" data-ad-confirm-label="{{ __('Delete page') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="is-danger">{{ AdminTheme::icon('trash') }}{{ __('Delete page...') }}</button>
                    </form>
                </x-admin.menu>
            @endif
            <button type="submit" class="ad-btn is-primary" form="ad-page-form">{{ $page ? __('Save changes') : __('Create page') }}</button>
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-page-form" method="POST" action="{{ $page ? route('admin.pages.update', $page->id) : route('admin.pages.store') }}" @if ($page) data-ad-form @endif class="ad-record-main">
        @csrf
        @if ($page)
            @method('PUT')
        @endif

        <section class="ad-panel" aria-labelledby="ad-page-details">
            <div class="ad-panel-h"><h2 id="ad-page-details">{{ __('Page') }}</h2></div>
            <div class="ad-panel-b ad-record-fields">
                <x-admin.field name="title" :label="__('Title')" :value="$page->title ?? null" />
                <x-admin.field name="slug" :label="__('Address')" :help="__('The last part of the address: letters, numbers and dashes, e.g. about-us.')">
                    <span class="ad-input-icon"><code aria-hidden="true">/page/</code><input class="ad-input" id="slug" name="slug" value="{{ old('slug', $page->slug ?? '') }}" required maxlength="200" aria-describedby="slug-help"></span>
                </x-admin.field>
                <x-admin.field name="content" :label="__('Text')" :help="__('HTML is allowed.')">
                    <textarea class="ad-textarea ad-code" id="content" name="content" rows="14" aria-describedby="content-help">{{ old('content', $page->content ?? '') }}</textarea>
                </x-admin.field>
            </div>
        </section>

        <section class="ad-panel" aria-labelledby="ad-page-seo">
            <div class="ad-panel-h"><h2 id="ad-page-seo">{{ __('In search engines') }}</h2></div>
            <div class="ad-panel-b ad-record-fields">
                <x-admin.field name="meta_title" :label="__('Title in search results')" :value="$page->meta_title ?? null" :help="__('Leave it empty to use the page title.')" />
                <x-admin.field name="meta_description" :label="__('Description in search results')" :value="$page->meta_description ?? null" :help="__('One or two sentences, up to 500 characters.')" />
            </div>
        </section>

        <section class="ad-panel" aria-labelledby="ad-page-place">
            <div class="ad-panel-h"><h2 id="ad-page-place">{{ __('Where it shows') }}</h2></div>
            {{-- The store/update read a switch as on when it is sent, so these send nothing when off. --}}
            @foreach (['is_published' => __('Published'), 'show_in_header' => __('Link in the header'), 'show_in_footer' => __('Link in the footer')] as $name => $label)
                <label class="ad-toggle-row" for="{{ $name }}" data-ad-field="{{ $name }}">
                    <span class="ad-toggle-text"><b>{{ $label }}</b></span>
                    <span class="ad-switch">
                        <input type="checkbox" role="switch" id="{{ $name }}" name="{{ $name }}" value="1" @checked($switch($name))>
                        <i aria-hidden="true"></i>
                    </span>
                </label>
            @endforeach
            <div class="ad-panel-b ad-record-fields is-3">
                <x-admin.field name="sort_order" type="number" :label="__('Order')" :value="$page->sort_order ?? 0" :help="__('Lower numbers come first.')" />
            </div>
        </section>

        @if ($page)
            <x-admin.savebar form="ad-page-form" />
        @else
            <div class="ad-record-actions">
                <a class="ad-btn" href="{{ route('admin.pages.index') }}">{{ __('Cancel') }}</a>
                <button type="submit" class="ad-btn is-primary">{{ __('Create page') }}</button>
            </div>
        @endif
    </form>
@endsection
