{{-- The Upload18 draft completion poll (moved here VERBATIM from users/videos/create.blade.php, included where it
     was): the 10 s create-status check, its stop on is_failed, the ready notice and the failed notice, whose <template>
     sits here too because the script finds it by its id (document.getElementById('upload18-failed-notice')). Part of
     the Upload18 upload flow: hands-off (this whole file is guarded). Include it unchanged. --}}
@push('scripts')
    @if ($video->is_draft && ! $video->embed_code)
        {{-- Shown by the completion check below when the upload failed while this page was open (Upload18 never finished
             it, or its video is not the member's file): why, and the way to the studio list, where it shows Failed. --}}
        <template id="upload18-failed-notice">
            <div class="alert alert-danger shadow-lg position-fixed d-flex align-items-center gap-3" role="alert" data-upload18-failed-notice
                 style="right:1rem;bottom:1rem;z-index:1080;max-width:min(440px,calc(100vw - 2rem));">
                <i class="fa-solid fa-circle-exclamation flex-shrink-0" aria-hidden="true"></i>
                <div class="flex-grow-1" style="min-width:0" data-upload18-failed-message>{{ __('The processing of your video failed.') }}</div>
                <a class="btn btn-sm btn-light text-nowrap flex-shrink-0" href="{{ locale_url(route('user.videos.index')) }}">{{ __('Manage videos') }}</a>
            </div>
        </template>
    @endif
@endpush
@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            const planned_value = document.getElementById('planned_value').textContent;
            Alpine.data('planned', (initial) => ({
                value: initial,
                date: initial ? '{{old('scheduled_at')}}' : '',
                update(e) {
                    this.value = e.target.options[e.target.selectedIndex].index == planned_value;
                }
            }));
        })

        document.addEventListener("DOMContentLoaded", function(event) {
            if (window.PRIVATE_CHANNEL) {
                window.PRIVATE_CHANNEL.listen('.video.uploaded', (data) => {
                    window.dispatchEvent(new CustomEvent('uploaded'));
                    setTimeout(() => {
                        window.dispatchEvent(new CustomEvent('terminate', {detail: data}));
                    }, 4000)
                });
            }

            const VIDEO_ID = {{ $video->id }};
            const CREATE_STATUS_ENDPOINT = @json(route('videos.create-status', ['video' => $video->id], false));
            const GENERATE_SERVER_THUMBNAILS_ENDPOINT = @json(route('videos.generate-server-thumbnails', ['video' => $video->id], false));
            const CSRF_TOKEN = '{{ csrf_token() }}';
            const THUMBNAIL_COUNT = {{ max(1, min(5, (int) config('site.upload18_thumbnail_count', 3))) }};
            const IS_UPLOAD18 = @json((bool) $video->is_upload18);
            const SHOULD_POLL_UPLOAD_COMPLETION = @json((bool) ($video->is_draft && !$video->embed_code));
            const SKIP_KEY = 'tp_skip_' + VIDEO_ID;
            const SKIP_VALUE = 'manual:server-fallback-v1';
            const DONE_KEY = 'tp_done_' + VIDEO_ID;
            const DONE_TTL_MS = 10 * 60 * 1000;
            const STREAM_READY_UNTIL_KEY = 'tp_stream_ready_until_' + VIDEO_ID;
            const STREAM_READY_DEADLINE_TTL_MS = 30 * 60 * 1000;
            const STREAM_READY_DELAY_SECONDS = 30;
            {{-- 1.2.3 (R1-006, owner-approved 2026-10-27): the page was printed with this draft's pictures; the completion
                 check reloads it only when the state CHANGED since then (the Upload18 embed arrived, or the first ready
                 picture appeared), and never twice for the same state (the state that caused a reload is remembered for
                 this draft in this tab). Before, a draft with a ready picture but no embed reloaded itself on every
                 check, for as long as it was open. --}}
            const READY_AT_RENDER = {{ $video->thumbnails->whereIn('status', [\App\Enums\ThumbnailStatus::GENERATED, \App\Enums\ThumbnailStatus::UPLOADED])->count() > 0 ? 'true' : 'false' }};
            const RELOAD_KEY = 'tp_reload_' + VIDEO_ID;
            // This draft's own form (the layout may have a search form before it).
            const form = document.querySelector('form[data-video-draft-form]') || document.querySelector('form');
            let formDirty = false;

            if (form) {
                form.addEventListener('input', function () {
                    formDirty = true;
                }, {capture: true});
                form.addEventListener('change', function () {
                    formDirty = true;
                }, {capture: true});
            }

            function showReadyRefreshNotice() {
                if (document.getElementById('video-ready-refresh-notice')) return;

                const notice = document.createElement('div');
                notice.id = 'video-ready-refresh-notice';
                notice.className = 'alert alert-info shadow-lg position-fixed d-flex align-items-center gap-3';
                notice.style.cssText = 'right:1rem;bottom:1rem;z-index:1080;max-width:min(420px,calc(100vw - 2rem));';
                notice.innerHTML =
                    '<div class="flex-grow-1">{{ __("Video processing is complete.") }}</div>' +
                    '<button type="button" class="btn btn-sm btn-primary">{{ __("Refresh") }}</button>';
                notice.querySelector('button')?.addEventListener('click', function () {
                    window.location.reload();
                });
                document.body.appendChild(notice);
            }

            // The upload failed while this page was open: say why (the check's message, else the general one), point to the
            // studio list where it shows Failed, and stop. No reload: a failed draft cannot be edited (its page is refused).
            function showUploadFailed(message) {
                if (document.querySelector('body > [data-upload18-failed-notice]')) return;

                const template = document.getElementById('upload18-failed-notice');
                const notice = template ? template.content.firstElementChild.cloneNode(true) : null;
                if (!notice) return;

                const text = notice.querySelector('[data-upload18-failed-message]');
                if (text && typeof message === 'string' && message !== '') text.textContent = message;
                document.body.appendChild(notice);

                // The preview box said "Processing ..." and "You will receive a notification ...": now it says why not.
                document.querySelectorAll('[data-video-processing-box]').forEach(function (box) {
                    const line = document.createElement('div');
                    line.className = 'fw-bold text-danger';
                    line.setAttribute('data-upload18-failed-box', '');
                    line.textContent = text ? text.textContent : '';
                    box.replaceChildren(line);
                });
                if (form) {
                    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                        button.disabled = true;
                    });
                }
            }

            function pollUploadCompletion() {
                if (!SHOULD_POLL_UPLOAD_COMPLETION) return;

                const poll = async () => {
                    try {
                        const response = await fetch(CREATE_STATUS_ENDPOINT, {
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            credentials: 'same-origin',
                        });

                        if (!response.ok) {
                            // 403: the upload failed meanwhile (the scheduler checked it); 404: the draft is gone.
                            if (response.status === 403 || response.status === 404) {
                                clearInterval(timer);
                                if (response.status === 403) showUploadFailed('');
                            }
                            return;
                        }

                        const data = await response.json();

                        // This check failed it (Upload18 never finished it, or its video is not the member's file).
                        if (data.is_failed) {
                            clearInterval(timer);
                            showUploadFailed(data.message || '');
                            return;
                        }

                        const ready = data.ready_thumbnail_count > 0;
                        if (data.has_embed_code || (ready && !READY_AT_RENDER)) {
                            const reached = (data.has_embed_code ? 'embed' : 'no-embed') + '|' + (ready ? 'ready' : 'no-picture');
                            let reloadedFor = null;
                            try { reloadedFor = sessionStorage.getItem(RELOAD_KEY); } catch (e) {}
                            {{-- this state already reloaded the page once: keep checking (the embed may still come), never
                                 reload for the same state again --}}
                            if (reloadedFor === reached) return;
                            clearInterval(timer);
                            if (formDirty) {
                                showReadyRefreshNotice();
                            } else {
                                try { sessionStorage.setItem(RELOAD_KEY, reached); } catch (e) {}
                                window.location.reload();
                            }
                        }
                    } catch (e) {
                    }
                };

                const timer = setInterval(poll, 10000);
                setTimeout(poll, 3000);
            }

            pollUploadCompletion();

            const widget = document.getElementById('thumb-processing');
            if (!widget) return;

            const doneAt = parseInt(localStorage.getItem(DONE_KEY) || '0', 10);
            const recentlyDone = Number.isFinite(doneAt) && doneAt > 0 && Date.now() - doneAt < DONE_TTL_MS;
            if (doneAt && !recentlyDone) {
                localStorage.removeItem(DONE_KEY);
            }

            const skipValue = localStorage.getItem(SKIP_KEY);
            if (skipValue && skipValue !== SKIP_VALUE) {
                localStorage.removeItem(SKIP_KEY);
            }

            if (!IS_UPLOAD18 || skipValue === SKIP_VALUE || recentlyDone) {
                widget.remove();
                return;
            }

            const countdownEl = document.getElementById('tp-number');
            const progressEl = document.getElementById('tp-progress');
            const statusEl = document.getElementById('tp-status');
            const circumference = 2 * Math.PI * 65;
            progressEl.style.strokeDasharray = circumference;
            progressEl.style.strokeDashoffset = circumference;

            function setStep(stepId, state) {
                const el = document.getElementById(stepId);
                if (!el) return;
                const icon = el.querySelector('span:first-child');
                el.classList.remove('done', 'active', 'error');
                el.classList.add(state);
                if (state === 'active') {
                    icon.innerHTML = '<span class="tp-spin"><i class="fa-solid fa-gear"></i></span>';
                } else if (state === 'done') {
                    icon.innerHTML = '<span class="check"><i class="fa-solid fa-check"></i></span>';
                } else if (state === 'error') {
                    icon.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-xmark"></i></span>';
                }
            }

            function setProgress(pct) {
                const offset = circumference * (1 - pct / 100);
                progressEl.style.strokeDashoffset = offset;
                countdownEl.textContent = Math.round(pct) + '%';
            }

            async function waitForUpload18StorageReady() {
                const startProgress = 35;
                const endProgress = 45;
                const now = Date.now();
                let readyAt = parseInt(localStorage.getItem(STREAM_READY_UNTIL_KEY) || '0', 10);

                if (!Number.isFinite(readyAt) || readyAt <= 0 || now - readyAt > STREAM_READY_DEADLINE_TTL_MS) {
                    readyAt = now + STREAM_READY_DELAY_SECONDS * 1000;
                    localStorage.setItem(STREAM_READY_UNTIL_KEY, String(readyAt));
                }

                while (true) {
                    const remainingMs = readyAt - Date.now();
                    if (remainingMs <= 0) break;

                    const remaining = Math.ceil(remainingMs / 1000);
                    const elapsed = Math.max(0, STREAM_READY_DELAY_SECONDS - remainingMs / 1000);
                    const progress = startProgress + (Math.min(elapsed, STREAM_READY_DELAY_SECONDS) / STREAM_READY_DELAY_SECONDS) * (endProgress - startProgress);
                    setProgress(progress);
                    countdownEl.textContent = remaining + 's';
                    statusEl.innerHTML = '<i class="fa-solid fa-clock me-2"></i>{{ __("Finalizing stream files...") }} ' + remaining + 's';
                    await new Promise(r => setTimeout(r, Math.min(1000, remainingMs)));
                }

                setProgress(endProgress);
            }

            async function generateServerThumbnails() {
                const response = await fetch(GENERATE_SERVER_THUMBNAILS_ENDPOINT, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({count: THUMBNAIL_COUNT}),
                });

                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.message || data.error || 'Server thumbnail generation failed (HTTP ' + response.status + ')');
                }

                return data;
            }

            function skipOverlay() {
                localStorage.setItem(SKIP_KEY, SKIP_VALUE);
                localStorage.removeItem(STREAM_READY_UNTIL_KEY);
                widget.classList.add('fade-out');
                setTimeout(() => { try { widget.remove(); } catch(e){} }, 800);
            }

            function finishOverlay() {
                localStorage.removeItem(SKIP_KEY);
                localStorage.removeItem(STREAM_READY_UNTIL_KEY);
                localStorage.setItem(DONE_KEY, String(Date.now()));
                widget.classList.add('fade-out');
                setTimeout(() => { try { widget.remove(); } catch(e){} }, 800);
            }

            const skipAlwaysBtn = document.getElementById('tp-skip-always');
            if (skipAlwaysBtn) {
                skipAlwaysBtn.addEventListener('click', function() {
                    skipOverlay();
                });
            }

            widget.classList.add('is-visible');
            runThumbnailGeneration();

            async function runThumbnailGeneration() {
                try {
                    setStep('step-process', 'active');
                    statusEl.innerHTML = '<i class="fa-solid fa-cloud-arrow-up me-2"></i>{{ __("Waiting for video to be ready...") }}';
                    setProgress(5);

                    await waitForUpload18StorageReady();

                    setStep('step-process', 'done');
                    setStep('step-stream', 'done');
                    setStep('step-capture', 'active');
                    setProgress(72);
                    countdownEl.textContent = '72%';
                    statusEl.innerHTML = '<i class="fa-solid fa-camera me-2"></i>{{ __("Generating thumbnails on server...") }}';

                    const data = await generateServerThumbnails();

                    setStep('step-capture', 'done');
                    setProgress(100);
                    statusEl.innerHTML = '<i class="fa-solid fa-check-circle text-success me-2"></i>' + data.count + ' {{ __("thumbnails generated!") }}';
                    window.dispatchEvent(new CustomEvent('thumbnails-generated', {
                        detail: data.data,
                    }));
                    setTimeout(() => finishOverlay(), 1800);
                } catch (err) {
                    console.error('Server thumbnail generation error:', err);
                    setStep('step-process', 'error');
                    setStep('step-stream', 'error');
                    setStep('step-capture', 'error');
                    setProgress(0);
                    countdownEl.textContent = '!';
                    statusEl.innerHTML = '<div class="text-warning" style="font-size:0.85rem"><i class="fa-solid fa-triangle-exclamation me-2"></i>' + err.message + '</div>' +
                        '<div class="mt-3 d-flex gap-2 justify-content-center">' +
                            '<button type="button" class="btn btn-sm btn-outline-info" id="tp-retry-btn"><i class="fa-solid fa-rotate-right me-1"></i>{{ __("Retry") }}</button>' +
                            '<button type="button" class="btn btn-sm btn-outline-secondary" id="tp-skip-btn"><i class="fa-solid fa-forward me-1"></i>{{ __("Skip") }}</button>' +
                        '</div>' +
                        '<div class="mt-2"><small class="text-muted">{{ __("You can upload a thumbnail manually below.") }}</small></div>';

                    const autoDismiss = setTimeout(() => skipOverlay(), 3000);

                    const retryBtn = document.getElementById('tp-retry-btn');
                    if (retryBtn) {
                        retryBtn.addEventListener('click', function() {
                            clearTimeout(autoDismiss);
                            localStorage.removeItem(SKIP_KEY);
                            localStorage.removeItem(DONE_KEY);
                            runThumbnailGeneration();
                        }, { once: true });
                    }

                    const skipBtn = document.getElementById('tp-skip-btn');
                    if (skipBtn) {
                        skipBtn.addEventListener('click', function() {
                            clearTimeout(autoDismiss);
                            skipOverlay();
                        }, { once: true });
                    }
                }
            }
        })
    </script>
@endpush
