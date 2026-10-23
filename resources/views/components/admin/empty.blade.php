{{--
    Empty state: what this page will show and the one action that fills it.
    <x-admin.empty icon="film" title="No videos yet" action="Import your first videos" :href="route('admin.avdb-import.index')">Videos you import or members upload appear here.</x-admin.empty>
--}}
@props(['icon' => 'inbox', 'title' => '', 'action' => null, 'href' => null])
<div {{ $attributes->class(['ad-empty']) }}>
    <span class="ad-sym" aria-hidden="true">{{ \App\Support\AdminTheme::icon($icon, 'lg') }}</span>
    <h3>{{ $title }}</h3>
    @if (trim((string) $slot) !== '')
        <p>{{ $slot }}</p>
    @endif
    @if ($action && $href)
        <a class="ad-btn is-primary" href="{{ $href }}">{{ $action }}</a>
    @endif
</div>
