{{--
    The crop dialog of a new picture (member design 6.9, 5.11): the profile picture (a round preview: account-52), the
    channel banner (fixed at 16:5, saved at 2048 x 640: account-8) and, on the video pages, the thumbnail. A kit dialog
    that follows the look's "Pop-up window style" (a bottom sheet on phones).
    - "Use this picture" waits until the crop tool has loaded (account-10: a picture chosen before cropperjs arrived
      opened a dead dialog); it never stays busy for ever: after 15 s the dialog says so.
    - Cancel, the close button, Escape and the backdrop clear the chosen file (account-9: the uncropped file was saved
      with the next save of the form).
    - The size limits and the messages of <image-upload> come from here (data-limits in KB, data-msg-*), so the browser
      says what the server will say (account-21).
    The crop tool, the img#cropped_image, the hidden opener button (data-name, data-config) and the "cropped-<name>"
    event keep their names: <image-upload> (ImageUpload.tsx) and the video pages' Thumbnails.tsx use them.
    The window's behaviour is in ImageUpload.tsx (wireCropDialog), which every picture field loads: the page carries no
    script for it (member design 4.3, the whole-document budget), and nothing can open it before that file is loaded.
--}}
@php
    $cropLimits = [
        'avatar' => (int) config('site.max_avatar_size', 2048),
        'banner' => (int) config('site.max_banner_size', 5120),
        'thumbnail' => (int) config('site.max_thumbnail_size', 2048),
    ];
    // the size as the page's language writes the number ("2.5" / "2,5"), as the file sizes of the studio (R3-045)
    $cropMb = fn (int $kb): string => \App\Support\NumberFormat::decimal($kb / 1024, 1);
    $cropHelp = [
        'avatar' => __('Drag to move the picture. The round part is what people see. JPG, PNG or WebP, up to :size MB.', ['size' => $cropMb($cropLimits['avatar'])]),
        'banner' => __('Drag to move the picture. Your banner is shown at 16:5. JPG, PNG or WebP, up to :size MB.', ['size' => $cropMb($cropLimits['banner'])]),
        'thumbnail' => __('Drag to move the picture. Thumbnails are shown at 16:9. JPG, PNG or WebP, up to :size MB.', ['size' => $cropMb($cropLimits['thumbnail'])]),
    ];
    $cropTitles = [
        'avatar' => __('Change picture'),
        'banner' => __('Change banner'),
        'thumbnail' => __('Change thumbnail'),
    ];
    // the pictures this page uses (Account settings: the profile picture or the banner); every kind by default
    $cropKinds = array_values(array_intersect(array_keys($cropTitles), (array) ($cropKinds ?? array_keys($cropTitles))));
    $cropKinds = $cropKinds !== [] ? $cropKinds : array_keys($cropTitles);
    $cropHelp = array_intersect_key($cropHelp, array_flip($cropKinds));
    $cropTitles = array_intersect_key($cropTitles, array_flip($cropKinds));
    $cropFirst = $cropKinds[0];
@endphp
<div class="modal fade" tabindex="-1" id="crop" aria-labelledby="cropTitle" aria-hidden="true"
     data-limits="{{ json_encode($cropLimits) }}"
     data-msg-type="{{ __('This file type cannot be used ({type}). Use {types}.') }}"
     data-msg-size="{{ __('This picture is too large ({size} MB). Use one of {max} MB or less.') }}"
     data-msg-small="{{ __('This picture is too small ({width} x {height}). Use one of at least {minWidth} x {minHeight} pixels.') }}"
     @if(count($cropKinds) > 1) data-help="{{ json_encode($cropHelp) }}" data-titles="{{ json_encode($cropTitles) }}" @endif>
    <div class="modal-dialog modal-dialog-centered mk-dialog mk-confirm mk-crop-dialog">
        <div class="modal-content avd-mk">
            <div class="mk-sheet__grip" aria-hidden="true"></div>
            <div class="mk-sheet__head">
                <h2 class="mk-sheet__title" id="cropTitle">{{ $cropTitles[$cropFirst] }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" title="{{ __('Close') }}" aria-label="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mk-sheet__body">
                <div class="mk-crop" data-crop-frame>
                    <img id="cropped_image" src="{{ asset('images/placeholders/no-image.svg') }}" alt="" width="640" height="400">
                    <span class="mk-crop__loading" data-crop-loading><span class="mk-spinner" aria-hidden="true"></span><span class="mk-sr">{{ __('Loading the picture tool') }}</span></span>
                </div>
                <div class="mk-crop__zoom">
                    <i class="fa-solid fa-minus" aria-hidden="true"></i>
                    <input type="range" class="mk-range" min="1" max="3" step="0.05" value="1" aria-label="{{ __('Zoom') }}" data-crop-zoom disabled>
                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                </div>
                <p class="mk-help" data-crop-help>{{ $cropHelp[$cropFirst] }}</p>
                <div class="mk-alert mk-alert--danger" role="alert" data-crop-error hidden>
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                    <div class="mk-alert__body"><span>{{ __('The picture tool did not load. Check your connection, then try again.') }}</span></div>
                    <span></span>
                </div>
            </div>
            <div class="mk-sheet__foot">
                <button type="button" class="mk-btn mk-btn--secondary" data-bs-dismiss="modal"><span>{{ __('Cancel') }}</span></button>
                <button id="crop_button" type="button" class="mk-btn mk-btn--primary" disabled aria-busy="true">
                    <span class="mk-spinner" aria-hidden="true" data-crop-busy></span>
                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                    <span>{{ __('Use this picture') }}</span>
                </button>
            </div>
        </div>
    </div>
</div>

