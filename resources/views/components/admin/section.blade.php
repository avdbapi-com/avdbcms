{{--
    Settings section: left column title + what the group is for (+ "Learn more"), right column a card of field rows.
    "More options (N)" folds advanced rows: put them in the "more" slot.
    <x-admin.section title="Email server" description="The details from your email provider." :link="['Setting up Gmail', $url]">
        <x-admin.field ...>...</x-admin.field>
        <x-slot:more>...advanced rows...</x-slot:more>
    </x-admin.section>
    Props: title, description, link ([label, url]), danger (bool), id.
--}}
@props(['title' => '', 'description' => null, 'link' => null, 'danger' => false, 'id' => null])
@php($sectionId = $id ?? 'sec-'.\Illuminate\Support\Str::slug($title))
<section {{ $attributes->class(['ad-sec', 'is-danger' => $danger]) }} aria-labelledby="{{ $sectionId }}-t">
    <div class="ad-sec-intro">
        <h2 id="{{ $sectionId }}-t">{{ $title }}</h2>
        @if ($description)
            <p>{{ $description }}</p>
        @endif
        @if (is_array($link) && count($link) === 2)
            <a class="ad-link" href="{{ $link[1] }}">{{ $link[0] }}</a>
        @endif
    </div>
    <div class="ad-sec-body">
        {{ $slot }}
        @isset($more)
            @if (trim((string) $more) !== '')
                <button type="button" class="ad-disclose" data-ad-nav-toggle aria-expanded="false" aria-controls="{{ $sectionId }}-more">
                    {{ \App\Support\AdminTheme::icon('chev-right', 'flip-rtl') }}{{ __('More options') }}
                </button>
                <div id="{{ $sectionId }}-more" class="ad-sec-more" hidden>{{ $more }}</div>
            @endif
        @endisset
    </div>
</section>
