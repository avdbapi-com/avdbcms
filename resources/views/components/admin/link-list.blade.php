{{--
    Link list editor for "Label | URL | icon" textareas (side menu, footer columns): one row per link with add,
    remove and reorder (drag the grip, or focus it and press the up/down arrow keys); it still saves the same text
    format (the hidden textarea keeps the name). Behaviour: modules/controls.ts.
    <x-admin.link-list name="footer_links" :value="$text" label="Footer links" help="Shown at the bottom of every page." />
--}}
@props(['name', 'value' => '', 'label' => '', 'help' => null, 'id' => null])
@php
    $listId = $id ?? $name;
    $rows = collect(preg_split('/\R/', (string) old($name, $value)) ?: [])
        ->map(fn (string $line) => array_pad(array_map('trim', explode('|', $line)), 3, ''))
        ->filter(fn (array $parts) => $parts[0] !== '' || $parts[1] !== '')
        ->values();
@endphp
<x-admin.field :name="$name" :label="$label" :help="$help" :id="$listId" {{ $attributes }}>
    <div class="ad-links" data-ad-links>
        @foreach ($rows->isEmpty() ? collect([['', '', '']]) : $rows as [$text, $url, $icon])
            <div class="ad-link-row" data-ad-link-row>
                <button type="button" class="ad-grip" data-ad-link-grip aria-label="{{ __('Move this link (drag, or use the up and down arrow keys)') }}">{{ \App\Support\AdminTheme::icon('grip') }}</button>
                <input class="ad-input" value="{{ $text }}" placeholder="{{ __('Label') }}" aria-label="{{ __('Label') }}" data-ad-link-part="0" data-ad-ignore>
                <input class="ad-input" value="{{ $url }}" placeholder="https://" aria-label="{{ __('Link') }}" data-ad-link-part="1" data-ad-ignore>
                <input class="ad-input" value="{{ $icon }}" placeholder="{{ __('Icon (optional)') }}" aria-label="{{ __('Icon') }}" data-ad-link-part="2" data-ad-ignore>
                <button type="button" class="ad-iconbtn" data-ad-link-remove aria-label="{{ __('Remove this link') }}">{{ \App\Support\AdminTheme::icon('trash') }}</button>
            </div>
        @endforeach
        <div><button type="button" class="ad-btn sm" data-ad-link-add>{{ \App\Support\AdminTheme::icon('plus') }}{{ __('Add a link') }}</button></div>
        <textarea class="ad-vh" id="{{ $listId }}" name="{{ $name }}" tabindex="-1" aria-hidden="true">{{ old($name, $value) }}</textarea>
    </div>
</x-admin.field>
