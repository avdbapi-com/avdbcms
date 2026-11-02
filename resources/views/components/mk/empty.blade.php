{{-- Empty state (member design 5.10): icon tile, title, one or two lines, optional actions (slot).
     <x-mk.empty icon="clapperboard" :title="__('Upload your first video')" :text="__('...')">
         <a class="mk-btn mk-btn--primary" href="...">...</a>
     </x-mk.empty> --}}
@props(['icon' => 'circle-info', 'title', 'text' => null, 'compact' => false, 'heading' => 'h2'])
<div {{ $attributes->class(['mk-empty', 'mk-empty--compact' => $compact]) }}>
    <span class="mk-empty__icon" aria-hidden="true"><i class="fa-solid fa-{{ $icon }}"></i></span>
    <{{ $heading }} class="mk-empty__title">{{ $title }}</{{ $heading }}>
    @if($text)
        <p class="mk-empty__text">{{ $text }}</p>
    @endif
    @if($slot->isNotEmpty())
        <div class="mk-empty__actions">{{ $slot }}</div>
    @endif
</div>
