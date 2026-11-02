{{-- Video status as dot + label (member design 5.8). Never a coloured pill: the label stays --avd-text, the dot
     carries the colour (status ink tokens pass 3:1 on every surface of the 348 token blocks). Revision 2: failed and
     banned show a warning triangle instead of a dot (the kit CSS draws it), so they differ by shape too.
     Processing (6.4, 9.3; the real Upload18 condition): a draft Upload18 still processes
     (\App\Support\Upload18\UploadClaims::isProcessing: not failed, still uploading, an Upload18 id, no embed code yet)
     reads "Processing on Upload18"; any other upload still being processed (is_uploading) reads "Processing". The rule
     itself is App\Support\Studio\StudioFormat::status() (also used by the cached dashboard lists).
     <x-mk.status :video="$video" />   or   <x-mk.status kind="public" :label="__('Public')" /> --}}
@props(['video' => null, 'kind' => null, 'label' => null])
@php
    if ($video !== null && $kind === null) {
        [$kind, $label] = \App\Support\Studio\StudioFormat::status($video);
    }
@endphp
<span {{ $attributes->class(['mk-status', 'mk-status--' . ($kind ?? 'private')]) }}>{{ $label }}</span>
