{{--
    Button or link that looks like one. One primary per view; danger is outlined red (solid red only inside a
    confirm dialog: variant="danger-solid"). Labels say what happens ("Save changes", "Send test email").
    <x-admin.button variant="primary|secondary|ghost|danger|danger-solid" size="sm|md|lg" href="..." icon="plus" type="submit">Add category</x-admin.button>
--}}
@props(['variant' => 'secondary', 'size' => 'md', 'href' => null, 'icon' => null, 'type' => 'button', 'iconEnd' => null])
@php
    $classes = ['ad-btn', 'is-'.$variant => $variant !== 'secondary', 'sm' => $size === 'sm', 'lg' => $size === 'lg'];
@endphp
@if ($href !== null)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>@if($icon){{ \App\Support\AdminTheme::icon($icon) }}@endif{{ $slot }}@if($iconEnd){{ \App\Support\AdminTheme::icon($iconEnd, 'flip-rtl') }}@endif</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>@if($icon){{ \App\Support\AdminTheme::icon($icon) }}@endif{{ $slot }}@if($iconEnd){{ \App\Support\AdminTheme::icon($iconEnd, 'flip-rtl') }}@endif</button>
@endif
