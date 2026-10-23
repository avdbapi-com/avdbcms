{{-- Status = dot + word: <x-admin.status type="ok|warn|danger|info|off">Public</x-admin.status> --}}
@props(['type' => null])
<span {{ $attributes->class(['ad-state', 'is-'.$type => $type !== null]) }}>{{ $slot }}</span>
