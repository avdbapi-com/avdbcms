{{--
    An Appearance page (Logo, colours & fonts ... Custom code): the settings of config/admin_settings.php for this
    page on the left, the live preview of the real site with the publish bar on the right. Changes are this admin's
    draft until Publish (AppearanceController, resources/admin/appearance/appearance.ts).
--}}
@extends('layouts.admin')
@section('title', __($page['label'] ?? 'Appearance'))
@section('admin_page', $pageId)
@section('admin_width', 'wide')

@push('head')
    @vite('resources/admin/appearance/appearance.ts')
@endpush

@section('content')
    <x-admin.page-header :page="$pageId">
        <x-slot:actions>
            <a class="ad-btn" href="{{ route('admin.appearance.history') }}" data-ad-history-open>{{ \App\Support\AdminTheme::icon('clock') }}{{ __('History') }}</a>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- The settings rows (admin/settings/partials/field.blade.php) and their switch / segmented / radio shapes. --}}
    @include('admin.settings.partials.styles')

    <div class="ad-split ad-appearance" data-ad-appearance data-page="{{ $pageId }}">
        <form id="ad-appearance-form" class="ad-settings-form ad-appearance-form" method="POST" action="{{ $urls['publish'] }}" autocomplete="off" novalidate data-ad-appearance-form>
            @csrf

            @if ($pageId === 'appearance.effects')
                @include('admin.appearance.partials.effects')
            @else
                @foreach ($form['cards'] as $card)
                    @include('admin.appearance.partials.card', ['card' => $card])
                @endforeach
            @endif

            @if ($form['advancedCards'] !== [])
                @php($advancedCount = array_sum(array_map(fn (array $c): int => count($c['fields']) + count($c['more']), $form['advancedCards'])))
                <details class="ad-adv" data-remember="{{ $pageId }}.advanced" @if ($pageId === 'appearance.other_pages' || $pageId === 'appearance.channels' || $form['cards'] === []) open @endif>
                    <summary>{{ __('Advanced') }} <span class="ad-badge">{{ $advancedCount }}</span></summary>
                    @foreach ($form['advancedCards'] as $card)
                        @include('admin.appearance.partials.card', ['card' => $card])
                    @endforeach
                </details>
            @endif

            {{-- Without JavaScript Publish posts every field shown here (a switch absent from the post is off). Code
                 boxes are left out: their value only arrives with JavaScript. --}}
            @php($manifest = [])
            @foreach (array_merge($form['cards'], $form['advancedCards']) as $card)
                @foreach (array_merge($card['fields'], $card['more']) as $f)
                    @unless (in_array($f['control'], ['code', 'code_editor', 'gallery'], true))
                        @php($manifest[$f['group']][] = $f['key'])
                    @endunless
                @endforeach
            @endforeach
            @foreach ($manifest as $group => $keys)
                <input type="hidden" name="fields[{{ $group }}]" value="{{ implode(',', $keys) }}">
            @endforeach
        </form>

        <aside class="ad-appearance-side" aria-label="{{ __('Preview') }}">
            @if ($pageId === 'appearance.effects')
                @include('admin.appearance.partials.speed')
            @endif
            <x-admin.monitor :pages="$monitorPages" :shot="$shot" shot-page="/">
                <x-slot:publish>@include('admin.appearance.partials.publish-bar')</x-slot:publish>
            </x-admin.monitor>
        </aside>
    </div>

    @if ($form['hiddenExpert'] > 0)
        <p class="ad-small ad-faint ad-appearance-note">{{ __('Settings for developers on this page: :count. They appear in Developer mode (account menu).', ['count' => $form['hiddenExpert']]) }}</p>
    @endif

    @include('admin.appearance.partials.history')
    @include('admin.appearance.partials.state')
@endsection
