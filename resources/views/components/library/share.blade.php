{{-- Share a channel or a playlist (member design 6.10, 6.11, sheets-gallery "Share channel"; QA channel-9: there was no
     way to share either). A trigger with data-share-dialog="#<id>" opens the phone's own share sheet where the browser has
     one, else this dialog: the address with a copy button and the usual services.
     <x-library.share id="mkShareChannel" :url="$user->route" :title="$user->username" :heading="__('Share channel')" :label="__('Channel address')" /> --}}
@props(['id', 'url', 'title', 'heading', 'label' => null])
@php
    $shareUrl = (string) $url;
    $shareText = (string) $title;
    // the service links get their addresses from library/actions-script when the dialog opens (shareHref there)
    $shareLinks = [
        ['fa-brands fa-x-twitter', 'X', 'x'],
        ['fa-brands fa-facebook', 'Facebook', 'facebook'],
        ['fa-brands fa-whatsapp', 'WhatsApp', 'whatsapp'],
        ['fa-brands fa-telegram', 'Telegram', 'telegram'],
        ['fa-regular fa-envelope', __('E-mail'), 'email'],
    ];
@endphp
<div class="modal fade mk-share-dialog" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}Title" aria-hidden="true" data-share-url="{{ $shareUrl }}" data-share-title="{{ $shareText }}">
    <div class="modal-dialog modal-dialog-centered mk-dialog avd-mk">
        <div class="modal-content avd-mk">
            <div class="mk-sheet__head">
                <h2 class="mk-sheet__title" id="{{ $id }}Title">{{ $heading }}</h2>
                <button type="button" class="mk-icon-btn mk-icon-btn--muted" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" title="{{ __('Close') }}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mk-sheet__body">
                <div class="mk-copy">
                    <span class="mk-copy__label">{{ $label ?? __('Address') }}</span>
                    <span class="mk-copy__value">{{ $shareUrl }}</span>
                    <button type="button" class="mk-icon-btn mk-icon-btn--muted mk-icon-btn--sm" data-copy-text="{{ $shareUrl }}" aria-label="{{ __('Copy address') }}" title="{{ __('Copy address') }}"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
                </div>
                <p class="mk-help mk-copy__done" role="status" data-copy-done hidden>{{ __('Address copied') }}</p>
                <div class="mk-share-row">
@foreach($shareLinks as [$shareIcon, $shareName, $shareTo])<a class="mk-share" href="#" data-share-to="{{ $shareTo }}" target="_blank" rel="noopener noreferrer"><i class="{{ $shareIcon }}" aria-hidden="true"></i><span>{{ $shareName }}</span></a>@endforeach
                </div>
            </div>
        </div>
    </div>
</div>

@include('library.actions-script')
