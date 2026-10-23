{{--
    Settings > Languages in the redesigned admin (the old admin keeps languages/index.blade.php): each language with its
    state (default, on, off) and how much of the site is translated, a row menu filled from one template
    (modules/list-rows.ts: set as default, turn on / off with Undo, delete), "Add a language" and importing a JSON file.
--}}
@use('App\Support\AdminTheme')
@extends('layouts.admin')

@section('title', __('Languages'))

@section('content')
    @php ob_start(); @endphp
    @php
        $active = $languages->where('is_active', true)->count();
    @endphp

    <x-admin.page-header page="settings.languages"
        :description="trans_choice('1 language is on: visitors can pick it.|:count languages are on: visitors can pick them.', $active, ['count' => $active])">
        <x-slot:actions>
            <a class="ad-btn is-primary" href="{{ route('admin.languages.create') }}">{{ AdminTheme::icon('plus') }}{{ __('Add language') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    <form id="ad-language-default" method="POST" action="{{ route('admin.languages.index', [], false) }}" data-ad-row-action="change" data-ad-done="{{ __('Default language changed') }}"
          data-ad-confirm="{{ __('Make this the default language?') }}" data-ad-confirm-body="{{ __('Your site opens in it, at the addresses without a language code.') }}" data-ad-confirm-label="{{ __('Make default') }}" data-ad-confirm-tone="primary" hidden></form>
    <form id="ad-language-toggle" method="POST" action="{{ route('admin.languages.index', [], false) }}" data-ad-row-action="change" hidden></form>
    <form id="ad-language-delete" method="POST" action="{{ route('admin.languages.index', [], false) }}" data-ad-row-action="delete" data-ad-done="{{ __('Language removed') }}"
          data-ad-confirm="{{ __('Remove this language?') }}" data-ad-confirm-body="{{ __('Visitors can no longer pick it. Its translation file is kept, so you can add it again later.') }}" data-ad-confirm-label="{{ __('Remove language') }}" hidden>@method('DELETE')</form>
    <span hidden id="ad-l-act">{{ __('Actions for') }}</span>
    <template id="ad-language-menu">
        <a href="{{ route('admin.languages.edit', ['locale' => '__CODE__'], false) }}">{{ AdminTheme::icon('pencil') }}{{ __('Edit translations') }}</a>
        <a href="{{ route('admin.languages.edit-meta', ['language' => '__ID__'], false) }}">{{ AdminTheme::icon('sliders') }}{{ __('Language settings') }}</a>
        <a href="{{ route('admin.languages.export', ['locale' => '__CODE__'], false) }}" data-ad-if="file">{{ AdminTheme::icon('download') }}{{ __('Download the JSON file') }}</a>
        <hr>
        <button type="submit" form="ad-language-default" formaction="{{ route('admin.languages.set-default', ['language' => '__ID__'], false) }}" data-ad-when="active" data-ad-expect="default">{{ AdminTheme::icon('star') }}{{ __('Make default') }}</button>
        <button type="submit" form="ad-language-toggle" formaction="{{ route('admin.languages.toggle-active', ['language' => '__ID__'], false) }}" data-ad-when="active" data-ad-expect="inactive" data-ad-done="{{ __('Language turned off') }}" data-ad-undo-url="{{ route('admin.languages.toggle-active', ['language' => '__ID__'], false) }}">{{ AdminTheme::icon('eye-off') }}{{ __('Turn off') }}</button>
        <button type="submit" form="ad-language-toggle" formaction="{{ route('admin.languages.toggle-active', ['language' => '__ID__'], false) }}" data-ad-when="inactive" data-ad-expect="active" data-ad-done="{{ __('Language turned on') }}" data-ad-undo-url="{{ route('admin.languages.toggle-active', ['language' => '__ID__'], false) }}">{{ AdminTheme::icon('eye') }}{{ __('Turn on') }}</button>
        <button type="submit" form="ad-language-delete" formaction="{{ route('admin.languages.destroy', ['language' => '__ID__'], false) }}" class="is-danger" data-ad-when="!default">{{ AdminTheme::icon('trash') }}{{ __('Remove language...') }}</button>
    </template>

    <x-admin.table :label="__('Languages')" list="languages" table-class="is-list" row-menu="ad-language-menu">
        <x-slot:head>
            <th scope="col">{{ __('Language') }}</th>
            <th scope="col">{{ __('State') }}</th>
            <th scope="col">{{ __('Translated') }}</th>
            <th scope="col" class="is-actions"><span class="ad-vh">{{ __('Actions') }}</span></th>
        </x-slot:head>

        <x-slot:empty>
            <x-admin.empty icon="globe" :title="__('No languages yet')" :action="__('Add language')" :href="route('admin.languages.create')">{{ __('Add the languages your visitors can pick.') }}</x-admin.empty>
        </x-slot:empty>

        @foreach ($languages as $language)
            @php
                $stat = $coverage[$language->code] ?? ['percent' => 0, 'missing' => 0, 'file' => false];
                [$tone, $label, $state] = match (true) {
                    (bool) $language->is_default => ['ok', __('Default'), 'default'],
                    (bool) $language->is_active => ['ok', __('On'), 'active'],
                    default => ['off', __('Off'), 'inactive'],
                };
                $meterTone = $stat['percent'] >= 95 ? 'ok' : ($stat['percent'] >= 60 ? 'warn' : 'danger');
            @endphp
            <tr>
                <td class="c-main"><div class="ad-vid"><span class="ad-flag" aria-hidden="true">{{ $language->emoji }}</span><p><a id="ad-lang-{{ $language->id }}" href="{{ route('admin.languages.edit', $language->code, false) }}" @if ($language->direction === 'rtl') dir="rtl" @endif>{{ $language->label }}</a><small><code>{{ $language->code }}</code>@if ($language->direction === 'rtl') · {{ __('right to left') }}@endif</small></p></div></td>
                <td class="c-meta"><x-admin.status :type="$tone">{{ $label }}</x-admin.status><small class="ad-phone-only">{{ __(':percent% translated', ['percent' => $stat['percent']]) }}</small></td>
                <td class="ad-cover"><meter class="ad-meter is-{{ $meterTone }}" min="0" max="100" low="60" high="95" optimum="100" value="{{ $stat['percent'] }}" aria-labelledby="ad-lang-{{ $language->id }}"></meter><span>{{ $stat['percent'] }}%@if ($stat['missing'] > 0) · {{ trans_choice('1 missing|:count missing', $stat['missing'], ['count' => number_format($stat['missing'])]) }}@endif</span></td>
                <td class="is-actions"><button type="button" class="ad-iconbtn is-more" aria-haspopup="true" aria-labelledby="ad-l-act ad-lang-{{ $language->id }}" data-id="{{ $language->id }}" data-code="{{ $language->code }}" data-state="{{ $state }}"@if ($stat['file']) data-file="1"@endif></button></td>
            </tr>
        @endforeach
    </x-admin.table>

    <section class="ad-panel ad-console-block" aria-labelledby="ad-languages-import">
        <div class="ad-panel-h">
            <div class="ad-home-grow">
                <h2 id="ad-languages-import">{{ __('Import translations') }}</h2>
                <p class="ad-small ad-muted">{{ __('A JSON file of "English text": "translation" pairs replaces the translations of that language.') }}</p>
            </div>
        </div>
        <form method="POST" action="{{ route('admin.languages.import') }}" enctype="multipart/form-data" class="ad-panel-b ad-record-fields is-3"
              data-ad-confirm="{{ __('Replace the translations of this language?') }}" data-ad-confirm-body="{{ __('Its current translation file is replaced by the one you picked.') }}" data-ad-confirm-label="{{ __('Import') }}" data-ad-confirm-tone="primary">
            @csrf
            <x-admin.field name="locale" :label="__('Language')">
                <select class="ad-select" id="locale" name="locale" required>
                    <option value="">{{ __('Choose a language') }}</option>
                    @foreach ($languages as $language)
                        <option value="{{ $language->code }}">{{ $language->label }} ({{ $language->code }})</option>
                    @endforeach
                </select>
            </x-admin.field>
            <x-admin.field name="file" :label="__('JSON file')">
                <input class="ad-input" type="file" id="file" name="file" accept=".json,application/json" required>
            </x-admin.field>
            <div class="ad-record-actions"><button type="submit" class="ad-btn">{{ AdminTheme::icon('upload') }}{{ __('Import') }}</button></div>
        </form>
    </section>
    {!! preg_replace('/\n\s+/', "\n", (string) ob_get_clean()) !!}
@endsection
