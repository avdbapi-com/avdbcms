{{--
    Settings > Languages > one language (new, or its settings), in the redesigned admin (the old admin keeps
    languages/create and edit-meta): code, name, flag, text direction, order and whether visitors can pick it, saved
    with the save bar, Ctrl+S or the header's button. The translations themselves are on the editor page.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', $language->exists ? $language->label : __('New language'))

@section('content')
    @php
        $title = $language->exists ? $language->label : __('New language');
        $direction = (string) old('direction', $language->direction ?? 'ltr');
    @endphp

    <x-admin.page-header page="settings.languages" :title="$title"
        :description="$language->exists ? __('Language code :code', ['code' => $language->code]) : __('Add the language, then translate it or import its JSON file.')"
        :crumbs="[['label' => __('Settings'), 'url' => route('admin.settings.index')], ['label' => __('Languages'), 'url' => route('admin.languages.index')], ['label' => $title, 'url' => null]]"
        :tabs="[]">
        <x-slot:actions>
            @if ($language->exists)
                <a class="ad-btn" href="{{ route('admin.languages.edit', $language->code) }}">{{ AdminTheme::icon('pencil') }}{{ __('Edit translations') }}</a>
            @endif
            <button type="submit" class="ad-btn is-primary" form="ad-language-form">{{ $language->exists ? __('Save changes') : __('Add language') }}</button>
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-language-form" method="POST" action="{{ $language->exists ? route('admin.languages.update-meta', $language) : route('admin.languages.store') }}" @if ($language->exists) data-ad-form @endif class="ad-record-main">
        @csrf
        @if ($language->exists)
            @method('PUT')
        @endif

        <section class="ad-panel" aria-labelledby="ad-language-details">
            <div class="ad-panel-h"><h2 id="ad-language-details">{{ __('Language') }}</h2></div>
            <div class="ad-panel-b ad-record-fields is-3">
                @if ($language->exists)
                    <x-admin.field name="code" :label="__('Code')" :help="__('Part of the addresses (/:code/...). It cannot change.', ['code' => $language->code])">
                        <input class="ad-input" id="code" value="{{ $language->code }}" disabled aria-describedby="code-help">
                    </x-admin.field>
                @else
                    <x-admin.field name="code" :label="__('Code')" :help="__('2 to 5 small letters, e.g. vi or pt. Part of the addresses.')">
                        <input class="ad-input" id="code" name="code" value="{{ old('code') }}" required maxlength="5" pattern="[a-z]{2,5}" aria-describedby="code-help" autocomplete="off">
                    </x-admin.field>
                @endif
                <x-admin.field name="label" :label="__('Name')" :value="$language->label" :help="__('As visitors know it, e.g. Tiếng Việt.')" />
                <x-admin.field name="emoji" :label="__('Flag')" :value="$language->emoji" :help="__('An emoji flag, e.g. 🇻🇳')" />
                <x-admin.field name="direction" :label="__('Text direction')">
                    <select class="ad-select" id="direction" name="direction" required>
                        <option value="ltr" @selected($direction === 'ltr')>{{ __('Left to right') }}</option>
                        <option value="rtl" @selected($direction === 'rtl')>{{ __('Right to left (Arabic, Hebrew ...)') }}</option>
                    </select>
                </x-admin.field>
                @if ($language->exists)
                    <x-admin.field name="sort_order" type="number" :label="__('Order')" :value="$language->sort_order" :help="__('Lower numbers come first in the language menu.')" />
                @endif
            </div>
            <x-admin.toggle name="is_active" :label="__('Visitors can pick it')"
                :help="$language->is_default ? __('The default language is always on.') : __('It shows in the language menu of your site.')"
                :checked="$language->exists ? (bool) $language->is_active : true" :disabled="(bool) $language->is_default" />
        </section>

        @if ($language->exists)
            <x-admin.savebar form="ad-language-form" />
        @else
            <div class="ad-record-actions">
                <a class="ad-btn" href="{{ route('admin.languages.index') }}">{{ __('Cancel') }}</a>
                <button type="submit" class="ad-btn is-primary">{{ __('Add language') }}</button>
            </div>
        @endif
    </form>
@endsection
