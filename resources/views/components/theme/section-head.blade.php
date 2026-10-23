{{-- Section heading in the theme's voice (html[data-avd-section-style]: icon tile, kicker line, double rule,
     ornament, number...). Mockup .sec order: kicker, icon, [rule], h2, [rule], link: the rules are only in the
     markup for the section styles that draw them (rule, rule_kicker, ornament, chrome, slash, seal; ornament also
     before the title), as in the theme mockups. The icon and the kicker are in the markup when given; the section
     style decides whether they show. Like the mockup .sec the head packs to the start (the link pushes itself to the
     end); only the ornament style centres it. --}}
@props([
    'title',
    'icon' => null,
    'kicker' => null,
    'moreUrl' => null,
    'moreLabel' => null,
    'tag' => 'h2',
    'titleId' => null,
])
@php
    $sectionStyle = \App\Support\ThemeStructure::get('section_style');
@endphp
<div {{ $attributes->class(['avd-discovery-head avd-section-head', 'justify-content-start' => $sectionStyle !== 'ornament']) }}>
    @if(filled($kicker))
        <span class="avd-page-kicker">{{ $kicker }}</span>
    @endif
    @if($icon)
        <span class="avd-sec-ico" aria-hidden="true"><i class="fa-solid fa-{{ $icon }}"></i></span>
    @endif
    @if($sectionStyle === 'ornament')
        <span class="avd-sec-rule" aria-hidden="true"></span>
    @endif
    <{{ $tag }} @if($titleId) id="{{ $titleId }}" @endif>{{ $title }}@if($sectionStyle === 'prompt')<span class="avd-cursor ms-2" aria-hidden="true"></span>@endif</{{ $tag }}>
    @if(in_array($sectionStyle, ['rule', 'rule_kicker', 'ornament', 'chrome', 'slash', 'seal'], true))
        <span class="avd-sec-rule" aria-hidden="true"></span>
    @endif
    @if($moreUrl)
        <a class="avd-discovery-link" href="{{ $moreUrl }}">{{ $moreLabel ?? __('View All') }}</a>
    @endif
    {{ $slot }}
</div>
