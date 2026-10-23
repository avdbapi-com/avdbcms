{{-- Neutral count or label; status colours only for status: <x-admin.badge type="ok|warn|danger|info|accent|outline">Public</x-admin.badge> --}}
@props(['type' => null])
<span {{ $attributes->class(['ad-badge', 'is-'.$type => $type !== null]) }}>{{ $slot }}</span>
