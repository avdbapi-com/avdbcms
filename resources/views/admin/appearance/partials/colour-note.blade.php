{{--
    How the owner's own main colour shows on buttons (Themes > colour style, Logo, colours & fonts > Colours): the
    colour engine picks a nearby shade when white or dark text on it would be hard to read, unless "Keep text on my
    colours readable" (brand_contrast_autofix) is off. The page script swaps this block after each change.
    $note: AppearanceController::colourNote() or null; $onThemes: the Themes page adds what a colour style does.
--}}
<div data-ad-colour-note>
@if ($note && $note['primary'])
{{-- Colour codes are isolated, so a right-to-left sentence keeps "#ff0055" in order (not "ff0055#"). --}}
@php($colour = "\u{2066}".$note['primary']."\u{2069}")
@php($shown = "\u{2066}".$note['shown']."\u{2069}")
<p class="ad-field-meta ad-colour-note">{{ \App\Support\AdminTheme::icon('info', 'sm') }}<span>
@if (! $note['autofix'])
{{ __('Your own colour :colour is used exactly as it is, also where text on it is hard to read.', ['colour' => $colour]) }}
@elseif ($note['shown'])
{{ $note['label'] === 'white'
    ? __('Your own colour :colour shows as :shown on buttons so white text stays readable (4.5:1).', ['colour' => $colour, 'shown' => $shown])
    : __('Your own colour :colour shows as :shown on buttons so dark text stays readable (4.5:1).', ['colour' => $colour, 'shown' => $shown]) }}
@else
{{ __('Your own colour :colour is used as it is: text on it is readable.', ['colour' => $colour]) }}
@endif
@if ($onThemes ?? false)
{{ __('Picking a colour style replaces your own colours.') }}
@endif
</span></p>
@endif
</div>
