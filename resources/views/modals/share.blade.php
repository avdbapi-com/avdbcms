@php
    $modalStyle = (string) config('site.modal_presentation_style', 'modal_glassmorphic_center');
@endphp
{{-- watch-7: the dialog is rendered the first time it opens (<template data-avd-lazy>, resources/js/layout/core.ts): its
     68 nodes are not in the watch page's document until a Share button is pressed. Its Alpine components stay registered
     below, outside the template, at alpine:init. --}}
<template data-avd-lazy="share">
{{-- R2A-022: the dialog is named by the title of the part it shows; the Embed part takes the focus when it opens (the
     Embed icon that had it is hidden: the focus fell to the page, where Escape no longer closed the dialog) and gives
     it back to the Embed icon on "Back". --}}
<div class="modal fade avd-modal-archetype" data-modal-style="{{ $modalStyle }}" tabindex="-1" id="share" aria-hidden="true" aria-labelledby="share-title" :aria-labelledby="embed ? 'share-embed-title' : 'share-title'" x-data="avdShareModal">
    <div @class([
        'modal-dialog',
        'modal-dialog-centered' => $modalStyle === 'modal_glassmorphic_center',
        'modal-fullscreen' => $modalStyle === 'modal_fullscreen_cinema',
        'avd-modal-slide-sheet' => $modalStyle === 'modal_slide_sheet',
        'avd-modal-brutalist' => $modalStyle === 'modal_brutalist_sharp',
    ]) :class="embed && 'modal-xl'">
        <div class="modal-content" >
            <div x-show="!embed" x-cloak>
                <div class="modal-header">
                    <h5 class="modal-title" id="share-title">{{ __('Share video') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{__('Close')}}"></button>
                </div>
                @php
                    $shareTitle = (string) $video->title;
                    $shareNetworks = [
                        ['name' => 'Facebook', 'icon' => 'fa-brands fa-facebook', 'colour' => '#3A5997', 'url' => 'https://www.facebook.com/share.php?u={url}'],
                        ['name' => 'WhatsApp', 'icon' => 'fa-brands fa-whatsapp', 'colour' => '#22D365', 'url' => 'https://api.whatsapp.com/send/?text={url}&type=custom_url&app_absent=0'],
                        ['name' => 'X', 'icon' => 'fa-brands fa-x-twitter', 'colour' => '#000000', 'url' => 'https://twitter.com/intent/tweet?text={title}&url={url}'],
                        ['name' => 'LinkedIn', 'icon' => 'fa-brands fa-linkedin', 'colour' => '#0277B5', 'url' => 'https://www.linkedin.com/sharing/share-offsite/?url={url}'],
                        ['name' => 'VK', 'icon' => 'fa-brands fa-vk', 'colour' => '#4680C2', 'url' => 'https://vk.com/share.php?url={url}'],
                        ['name' => 'Pinterest', 'icon' => 'fa-brands fa-pinterest', 'colour' => '#BC091C', 'url' => 'https://www.pinterest.com/pin/create/button/?url={url}&media={media}&description={title}'],
                    ];
                    $shareHref = fn (string $pattern): string => strtr($pattern, [
                        '{url}' => rawurlencode((string) $video->route),
                        '{title}' => rawurlencode($shareTitle),
                        '{media}' => rawurlencode((string) $video->thumbnail_url),
                    ]);
                @endphp
                {{-- watch-11: the links carry the start time when it is on; every part of a link is encoded (a title with &
                     or # broke the X link). --}}
                <div class="modal-body" x-data="startAt(@js((string) $video->route), @js($shareTitle))">
                    <img class="img-fluid rounded img-thumbnail mb-3" src="{{$video->thumbnail_url}}" alt="" width="640" height="360" loading="lazy" decoding="async">
                    <div class="d-flex flex-wrap" style="row-gap: 1rem !important;">
                        <button type="button" class="bg-transparent border-0 p-0 fa-stack fa-2x avd-share-embed" style="font-size: 1.6em" @click="openEmbed()" aria-label="{{ __('Embed') }}" title="{{ __('Embed') }}">
                            <i class="fa fa-circle fa-stack-2x" style="color: #888888;" aria-hidden="true"></i>
                            <i class="fa-solid fa-code fa-stack-1x fa-inverse" aria-hidden="true"></i>
                        </button>
                        <a href="mailto:?subject={{ rawurlencode($shareTitle) }}&body={{ rawurlencode((string) $video->route) }}" :href="'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(link)" class="fa-stack fa-2x" style="font-size: 1.6em" aria-label="{{ __('Email') }}" title="{{ __('Email') }}">
                            <i class="fa fa-circle fa-stack-2x" style="color: #888888;" aria-hidden="true"></i>
                            <i class="fa-regular fa-envelope fa-stack-1x fa-inverse" aria-hidden="true"></i>
                        </a>
                        @foreach($shareNetworks as $network)
                            <a target="_blank" rel="noopener" href="{{ $shareHref($network['url']) }}" :href="shareUrl(@js($network['url']))" class="fa-stack fa-2x" style="font-size: 1.6em" aria-label="{{ $network['name'] }}" title="{{ $network['name'] }}">
                                <i class="fa fa-circle fa-stack-2x" style="color: {{ $network['colour'] }};" aria-hidden="true"></i>
                                <i class="{{ $network['icon'] }} fa-stack-1x fa-inverse" aria-hidden="true"></i>
                            </a>
                        @endforeach
                    </div>
                    <hr>
                    <label for="share-link" class="form-label">{{ __('Video link') }}</label>
                    {{-- R1-057: the dialog is drawn after the page's start-up, so a Bootstrap tooltip never existed for these
                         buttons and a copy showed nothing. The button says "Copied" for a moment, a live region reads it
                         out, and the site's "Copied" message shows where the admin keeps it on (avdShareModal.copy). --}}
                    <div class="mb-3 input-group">
                        <input type="text" class="form-control" readonly id="share-link" x-model="link">
                        <button
                            class="btn btn-primary"
                            type="button"
                            @click="copy(link, 'link', 'share-link')"
                            title="{{__('Copy video link')}}"
                        >
                            <i class="fa-solid fa-check me-1" x-show="copied === 'link'" x-cloak aria-hidden="true"></i>
                            <span x-text="copied === 'link' ? @js(__('Copied')) : @js(__('Copy'))">{{ __('Copy') }}</span>
                        </button>
                    </div>
                    <p class="visually-hidden" role="status" x-text="said"></p>
                    <div class="col-12 col-sm-8">
                        <div class="input-group mb-3">
                            <div class="input-group-text d-flex align-items-center gap-2">
                                <input name="start_at" id="share-start-on" class="form-check-input mt-0" type="checkbox" x-model="enabled">
                                <label for="share-start-on" class="form-label mb-0">{{ __('Start at') }}</label>
                            </div>
                            <input type="text" id="share-start-at" class="form-control" inputmode="numeric" autocomplete="off" spellcheck="false"
                                   placeholder="0:00" aria-label="{{ __('Start at') }}" :disabled="!enabled" x-model="time"
                                   :class="{'is-invalid': enabled && !valid}" :aria-invalid="(enabled && !valid).toString()">
                        </div>
                    </div>
                </div>
            </div>
            <div x-show="embed" x-cloak>
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="bg-transparent avd-share-back" @click="closeEmbed()" aria-label="{{ __('Back') }}" title="{{ __('Back') }}">
                            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        </button>
                        <h5 class="modal-title" id="share-embed-title">{{ __('Embed') }}</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{__('Close')}}"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-12 col-xl-8">
                            <template x-if="modalOpen && embed">
                                <iframe src="{{route('video.embed', $video)}}" class="w-100" width="960" height="540" frameborder="0" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                            </template>
                        </div>
                        <div class="col-12 col-xl-4 d-flex flex-column gap-2">
                            <textarea id="embed_code" readonly rows="8" class="form-control" style="resize: none">{{ '<iframe width="560" height="315" src="'.route('video.embed', $video).'" title="'.config('app.name').' video player" frameborder="0" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>' }}</textarea>
                            <button
                                class="btn btn-primary btn-block d-flex align-items-center gap-2 justify-content-center"
                                type="button"
                                @click="copy($event.currentTarget.dataset.link, 'code', 'embed_code')"
                                data-link="{{ '<iframe width="560" height="315" src="'.route('video.embed', $video).'" title="'.config('app.name').' video player" frameborder="0" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>' }}"
                            >
                                <i class="fa-regular fa-copy" x-show="copied !== 'code'" aria-hidden="true"></i>
                                <i class="fa-solid fa-check" x-show="copied === 'code'" x-cloak aria-hidden="true"></i>
                                <span x-text="copied === 'code' ? @js(__('Code copied')) : @js(__('Copy code'))">{{ __('Copy code') }}</span>
                            </button>
                            <p class="visually-hidden" role="status" x-text="said"></p>
                            <div class="mt-auto">
                                <p class="text-muted text-sm mb-0">{{ __('By embedding :app_name videos on your site, you are agreeing to :app_name', ['app_name' => config('app.name')]) }}
                                    <a class="text-decoration-none" target="_blank" href="{{route('pages.terms')}}">{{ __('Terms of Service') }}</a>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
            </div>
        </div>
    </div>
</div>
</template>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('avdShareModal', () => ({
            embed: false,
            modalOpen: false,
            onModalShown: null,
            onModalHide: null,
            // R1-057: which copy just worked ('link' | 'code', for its button) and the words of the live region
            copied: '',
            said: '',
            copiedTimer: null,
            // R2A-022: the part that opens takes the focus (its back arrow; on "Back" the Embed icon again), so the
            // focus never falls out of the dialog and Escape keeps closing it ($el: the button that was pressed)
            openEmbed() {
                const dialog = this.$el.closest('.modal');
                this.embed = true;
                this.focusShown(dialog?.querySelector('.avd-share-back'));
            },
            closeEmbed() {
                const dialog = this.$el.closest('.modal');
                this.embed = false;
                this.focusShown(dialog?.querySelector('.avd-share-embed'));
            },
            // as soon as the part is drawn (Safari draws it later than Chrome, a busy phone later still: tried for up to
            // 1.5 s), and only while the focus is on nothing the member chose since (the page, the dialog itself or the
            // control that was just hidden)
            focusShown(target) {
                if (!target) return;
                const dialog = target.closest('.modal');
                const until = performance.now() + 1500;
                const free = () => {
                    const active = document.activeElement;
                    return !active || active === document.body || active === dialog || active.getClientRects().length === 0;
                };
                const attempt = () => {
                    if (target.getClientRects().length > 0) {
                        if (!free()) return;
                        target.focus();
                        if (document.activeElement === target) return;
                    }
                    if (performance.now() < until) requestAnimationFrame(attempt);
                };
                this.$nextTick(attempt);
            },
            copy(text, kind, fieldId) {
                const words = kind === 'code' ? @js(__('Code copied')) : @js(__('Link copied'));
                const done = () => {
                    this.copied = kind;
                    // a live region reads out what is added to it: empty first, so a second copy is read out again
                    this.said = '';
                    setTimeout(() => { this.said = words; }, 60);
                    clearTimeout(this.copiedTimer);
                    this.copiedTimer = setTimeout(() => { this.copied = ''; this.said = ''; }, 2500);
                    if (typeof window.showCopyToast === 'function') window.showCopyToast(words);
                };
                // no clipboard access (a site on http, an older browser, a refused permission): the text is selected and
                // copied the old way, and stays selected to copy by hand if even that is refused
                const byHand = () => {
                    const field = fieldId ? document.getElementById(fieldId) : null;
                    if (field) {
                        field.focus();
                        field.select();
                    }
                    // the old copy command, named in a string: the page's text never holds a program file's extension
                    // (the looks' render tests reject one anywhere in the page)
                    const legacyCopy = 'execCommand';
                    let ok = false;
                    try {
                        ok = document[legacyCopy]('copy');
                    } catch (e) {
                        ok = false;
                    }
                    if (ok) done();
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done, byHand);
                } else {
                    byHand();
                }
            },
            init() {
                this.onModalShown = event => {
                    if (event.target === this.$el) this.modalOpen = true;
                };
                this.onModalHide = event => {
                    if (event.target !== this.$el) return;
                    this.modalOpen = false;
                    this.embed = false;
                };
                this.$el.addEventListener('shown.bs.modal', this.onModalShown);
                this.$el.addEventListener('hide.bs.modal', this.onModalHide);
                this.$el.addEventListener('hidden.bs.modal', this.onModalHide);
            },
            destroy() {
                this.$el.removeEventListener('shown.bs.modal', this.onModalShown);
                this.$el.removeEventListener('hide.bs.modal', this.onModalHide);
                this.$el.removeEventListener('hidden.bs.modal', this.onModalHide);
            },
        }));

        // "Start at" in m:ss or h:mm:ss (or seconds), filled with the player's position each time the
        // dialog opens; the copied link and the network links get ?t=<seconds> while it is on.
        Alpine.data('startAt', (initial, title) => ({
            link: initial,
            title: title,
            enabled: false,
            time: '0:00',
            valid: true,
            init() {
                const dialog = this.$el.closest('.modal');
                if (dialog) {
                    dialog.addEventListener('show.bs.modal', () => {
                        const video = document.querySelector('#avdPlayerWrapper video');
                        const at = video && Number.isFinite(video.currentTime) ? Math.floor(video.currentTime) : 0;
                        this.time = this.format(at);
                        this.update();
                    });
                }
                this.$watch('time', () => this.update());
                this.$watch('enabled', () => this.update());
            },
            seconds(value) {
                const text = String(value || '').trim();
                if (!/^\d{1,6}(:\d{1,2}){0,2}$/.test(text)) {
                    return null;
                }
                const parts = text.split(':').map(Number);
                if (parts.slice(1).some((part) => part > 59)) {
                    return null;
                }
                return parts.reduce((total, part) => total * 60 + part, 0);
            },
            format(total) {
                const h = Math.floor(total / 3600);
                const m = Math.floor((total % 3600) / 60);
                const s = total % 60;
                const pad = (n) => String(n).padStart(2, '0');
                return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
            },
            update() {
                const at = this.seconds(this.time);
                this.valid = at !== null;
                this.link = this.enabled && at ? initial + (initial.includes('?') ? '&' : '?') + 't=' + at : initial;
            },
            shareUrl(pattern) {
                return pattern
                    .replace('{url}', encodeURIComponent(this.link))
                    .replace('{title}', encodeURIComponent(this.title))
                    .replace('{media}', encodeURIComponent(@js((string) $video->thumbnail_url)));
            },
        }));
    })
</script>
