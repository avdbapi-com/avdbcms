@php
    $acceptedVideoTypes = collect(explode(',', (string) config('site.upload_allowed_formats', 'mp4,webm,avi,mov,mkv')))
        ->map(fn ($type) => trim($type))
        ->filter();
    $uploadMaxSize = (int) config('plans.'.Auth::user()->plan.'.max_file_size');
@endphp

<div class="modal fade avd-upload-modal-shell" tabindex="-1" id="video_create" aria-hidden="true" aria-labelledby="video-create-title">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable avd-upload-dialog">
        <div class="modal-content avd-upload-modal">
            <div class="modal-header avd-upload-header">
                <div class="d-flex align-items-center gap-3 min-w-0">
                    <span class="avd-upload-header-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span>
                    <div class="min-w-0">
                        <h5 class="modal-title mb-1" id="video-create-title">{{ __('Upload new video') }}</h5>
                        <p class="mb-0 text-muted text-sm">{{ __('Your video will be private until you publish them') }}</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{__('Close')}}"></button>
            </div>
            <div class="modal-body p-0">
                <div class="avd-upload-body">
                    <video-upload configendpoint="{{route('videos.upload18.config', [], false)}}" completeendpoint="{{route('videos.upload18.complete', [], false)}}" maxsize="{{$uploadMaxSize}}" maxfilename="{{config('validation.video.title.max', 100)}}" allowedformats="{{ $acceptedVideoTypes->implode(',') }}"></video-upload>

                    <div class="avd-upload-meta-grid">
                        <section class="avd-upload-meta-card avd-upload-formats">
                            <span class="avd-upload-meta-label">{{ __('Accepted formats')}}</span>
                            <strong class="avd-upload-format-list">
                                @foreach($acceptedVideoTypes as $type)
                                    <span>{{ $type }}</span>
                                @endforeach
                            </strong>
                        </section>

                        <section class="upload-limit-panel avd-upload-meta-card">
                            <span class="avd-upload-meta-label">{{ __('Your upload limits') }}</span>
                            <div class="avd-upload-limit-items">
                                @if(!Auth::user()->is_premium)
                                    <span @class(['is-danger' => $available_uploads <= 0])>
                                        <small>{{ __('Available uploads') }}</small>
                                        <strong>{{ ($available_uploads > 0) ? $available_uploads : 0  }}</strong>
                                    </span>
                                @else
                                    <span>
                                        <small>{{ __('Available uploads') }}</small>
                                        <strong>{{ __('Unlimited') }}</strong>
                                    </span>
                                @endif
                                    <span>
                                        <small>{{ __('Max file size') }}</small>
                                        <strong>@size($uploadMaxSize)</strong>
                                    </span>
                                @if(!Auth::user()->is_premium)
                                    <span>
                                        <small>{{ __('Available space')}}</small>
                                        <strong>@size(($available_space > 0) ? $available_space : 0)</strong>
                                    </span>
                                @else
                                    <span>
                                        <small>{{ __('Available space')}}</small>
                                        <strong>{{ __('Unlimited')}}</strong>
                                    </span>
                                @endif
                            </div>
                            @if(!Auth::user()->is_premium)
                                <a href="{{route('pages.premium')}}" class="alert-link avd-upload-upgrade">
                                    <i class="fa-solid fa-crown"></i>
                                    <span>{{ __('Upgrade to premium') }}</span>
                                    <span>{{ __('for unlimited uploads') }}</span>
                                </a>
                            @endif
                        </section>
                    </div>
                </div>
            </div>
            <div class="modal-footer avd-upload-footer d-block">
                <div class="text-muted text-sm text-center">
                    <p class="mb-1">
                        @php $termsText = __('Terms of Service'); @endphp
                        {!! __('By submitting your videos to :app_name, you acknowledge that you agree to our <a href=":terms_url" class="text-decoration-none">:terms_text</a>', ['app_name' => config('app.name'), 'terms_url' => url('/terms'), 'terms_text' => $termsText]) !!}
                    </p>
                    <p>
                        {{ __('Please be sure not to violate others copyright or privacy rights') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
