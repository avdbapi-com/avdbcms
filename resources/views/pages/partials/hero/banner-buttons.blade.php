{{-- Call to action of the banner decorations with a picture: the first trending video, then the trending page
     (the categories when trending is switched off). Wider screens only: on phones the banner stays the copy alone,
     so the first videos keep their place on the first screen. --}}
<div class="avd-hbtns d-none d-md-flex">
    <a class="btn btn-primary" href="{{ $bannerLead->route }}"><i class="fa-solid fa-play" aria-hidden="true"></i> {{ __('Watch Now') }}</a>
    <a class="btn btn-outline-secondary avd-btn2" href="{{ $bannerBrowse }}">{{ __('Browse') }}</a>
</div>
