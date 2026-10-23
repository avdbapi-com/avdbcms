@php
    $modalStyle = (string) config('site.modal_presentation_style', 'modal_glassmorphic_center');
@endphp
<div class="modal fade avd-modal-archetype" data-modal-style="{{ $modalStyle }}" tabindex="-1" id="share" aria-hidden="true" x-data="avdShareModal">
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
                    <h5 class="modal-title">{{ __('Share Video') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{__('Close')}}"></button>
                </div>
                <div class="modal-body" x-data="startAt('{{$video->route}}')">
                    <img class="img-fluid rounded img-thumbnail mb-3" src="{{$video->thumbnail_url}}" alt="{{$video->title}} Thumbnail">
                    <div class="d-flex flex-wrap" style="row-gap: 1rem !important;">
                        <a type="button" class="bg-transparent fa-stack fa-2x" style="font-size: 1.6em" @click="embed = true">
                            <i class="fa fa-circle fa-stack-2x" style="color: #888888;"></i>
                            <i class="fa-solid fa-code fa-stack-1x fa-inverse"></i>
                        </a>
                        <a href="mailto:?body={{$video->route}}" class="fa-stack fa-2x" style="font-size: 1.6em">
                            <i class="fa fa-circle fa-stack-2x" style="color: #888888;"></i>
                            <i class="fa-regular fa-envelope fa-stack-1x fa-inverse"></i>
                        </a>
                        <a target="_blank" href="https://www.facebook.com/share.php?u={{$video->route}}" class="fa-stack fa-2x" style="font-size: 1.6em">
                            <i class="fa fa-circle fa-stack-2x" style="color: #3A5997;"></i>
                            <i class="fa-brands fa-facebook fa-stack-1x fa-inverse"></i>
                        </a>
                        <a target="_blank" href="https://api.whatsapp.com/send/?text={{$video->route}}&type=custom_url&app_absent=0" class="fa-stack fa-2x" style="font-size: 1.6em">
                            <i class="fa fa-circle fa-stack-2x" style="color: #22D365;"></i>
                            <i class="fa-brands fa-whatsapp fa-stack-1x fa-inverse"></i>
                        </a>
                        <a target="_blank" href="https://twitter.com/share?text={{$video->title}}&url={{$video->route}}" class="fa-stack fa-2x" style="font-size: 1.6em">
                            <i class="fa fa-circle fa-stack-2x" style="color: #1EA1F1;"></i>
                            <i class="fa-brands fa-twitter fa-stack-1x fa-inverse"></i>
                        </a>
                        <a target="_blank" href="https://www.linkedin.com/sharing/share-offsite/?url={{$video->route}}" class="fa-stack fa-2x" style="font-size: 1.6em">
                            <i class="fa fa-circle fa-stack-2x" style="color: #0277B5;"></i>
                            <i class="fa-brands fa-linkedin fa-stack-1x fa-inverse"></i>
                        </a>
                        <a target="_blank" href="https://vk.com/share.php?url={{$video->route}}" class="fa-stack fa-2x" style="font-size: 1.6em">
                            <i class="fa fa-circle fa-stack-2x" style="color: #4680C2;"></i>
                            <i class="fa-brands fa-vk fa-stack-1x fa-inverse"></i>
                        </a>
                        <a target="_blank" href="https://www.pinterest.fr/pin/create/button/?description=test&url={{$video->route}}" class="fa-stack fa-2x" style="font-size: 1.6em">
                            <i class="fa fa-circle fa-stack-2x" style="color: #BC091C;"></i>
                            <i class="fa-brands fa-pinterest fa-stack-1x fa-inverse"></i>
                        </a>
                    </div>
                    <hr>
                    <label for="link" class="form-label">{{ __('Video Link') }}</label>
                    <div class="mb-3 input-group">
                        <input type="text" class="form-control" readonly id="link" x-model="link">
                        <button
                            class="btn btn-primary"
                            type="button"
                            @click="navigator.clipboard.writeText(link)"
                            title="{{__('Copy video link')}}"
                            data-bs-toggle="tooltip"
                            data-bs-trigger="click"
                            data-bs-title="{{ __('Link copied !') }}"
                        >
                            <span>{{ __('Copy') }}</span>
                        </button>
                    </div>
                    <div class="col-10 col-sm-7">
                        <div class="input-group mb-3 ">
                            <div class="input-group-text d-flex align-items-center gap-2">
                                <input name="start_at" class="form-check-input mt-0" type="checkbox" aria-label="{{__('Checkbox for following text input')}}" x-model="enabled">
                                <label for="link" class="form-label mb-0">{{ __('Start at :') }}</label>
                            </div>
                            <input type="time" id="time" class="form-control disabled" aria-label="{{__('Text input with checkbox')}}" value="00:01" :disabled="!enabled" x-model="time">
                        </div>
                    </div>
                </div>
            </div>
            <div x-show="embed" x-cloak>
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="bg-transparent" @click="embed = false">
                            <i class="fa-solid fa-arrow-left"></i>
                        </button>
                        <h5 class="modal-title">{{ __('Embed') }}</h5>
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
                                @click="navigator.clipboard.writeText($event.currentTarget.dataset.link)"
                                title="{{__('Copy video link')}}"
                                data-bs-toggle="tooltip"
                                data-bs-trigger="click"
                                data-bs-title="{{__('Code copied !')}}"
                                data-link="{{ '<iframe width="560" height="315" src="'.route('video.embed', $video).'" title="'.config('app.name').' video player" frameborder="0" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>' }}"
                            >
                                <i class="fa-regular fa-copy"></i>
                                <span>{{ __('Copy code') }}</span>
                            </button>
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

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('avdShareModal', () => ({
            embed: false,
            modalOpen: false,
            onModalShown: null,
            onModalHide: null,
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

        Alpine.data('startAt', (initial) => ({
            link : initial,
            enabled: false,
            time: '00:01',
            init() {
                this.$watch('time', value => {
                    if(value) {
                        this.link = initial + '?t=' + this.getSeconds(value)
                    } else {
                        this.link = initial
                    }
                });
                this.$watch('enabled', value => {
                   if(this.time) {
                       this.link = initial + (value ? '?t=' + this.getSeconds(this.time) : '')
                   }
                })
            },
            getSeconds(time) {
                const a = time.split(':')
                return (+a[0]) * 60 + (+a[1]);
            }
        }));
    })
</script>
