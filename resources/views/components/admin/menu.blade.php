{{--
    A small menu behind a button: the page header's "More" and each table row's actions (kebab). Destructive
    items go last, in red, and should confirm (data-ad-confirm).
    <x-admin.menu id="row-12" :label="__('Actions for :name', ['name' => $video->title])">
        <a href="...">{{ __('Edit details') }}</a>
        <hr>
        <form method="POST" action="..." data-ad-confirm="{{ __('Delete this video?') }}">@csrf @method('DELETE')<button class="is-danger">{{ __('Delete video') }}</button></form>
    </x-admin.menu>
    Props: id (unique), label (accessible name), icon (default "more"), text (visible button text instead of an icon).
--}}
@props(['id', 'label' => null, 'icon' => 'more', 'text' => null])
<div {{ $attributes->class(['ad-popwrap']) }}>
    @if ($text)
        <button type="button" class="ad-btn" data-ad-menu aria-controls="{{ $id }}" aria-expanded="false" aria-haspopup="true">{{ $text }}{{ \App\Support\AdminTheme::icon('chev-down', 'sm') }}</button>
    @else
        <button type="button" class="ad-iconbtn" data-ad-menu aria-controls="{{ $id }}" aria-expanded="false" aria-haspopup="true" aria-label="{{ $label ?? __('More actions') }}">{{ \App\Support\AdminTheme::icon($icon) }}</button>
    @endif
    <div class="ad-menu" id="{{ $id }}" hidden>{{ $slot }}</div>
</div>
