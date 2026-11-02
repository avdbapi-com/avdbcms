// @ts-nocheck
// The watch page's tools (watch-7): the mini player, ambilight, snapshot, speed,
// loop, sleep timer, equalizer, bookmarks, filters, embed code, mirror, fullscreen, zen, visualizer, notes and resume.
// Until 1.2.3 this was 84 KB of classic inline script at the end of every watch page, compiled during the parse with
// no code cache; videos/show.blade.php now prints its settings as JSON (#avd-watch-tools) and loads this module with a
// <script type="module">, which runs once the document has been parsed, before DOMContentLoaded. The code is the inline
// script's, written for the browser as it was (ES5 with a few later forms, hence no type checking here), except:
// - its Blade values come from the settings: __r('<English>') is the translation as plain text, for textContent, a
//   title, canvas text and the toasts (window.showCopyToast writes its message as text: core.ts, 1.2.3 round 2);
//   __t('<English>') the same HTML-escaped as {{ __('...') }} printed it, only for markup built here; the video's
//   title, uuid and embed address; the sleep timer's lengths in words (sleepLengths); the mini-player switch is
//   window.avdPipAllowed, which the view still declares inline;
// - DOMContentLoaded work runs at once when the module runs after the parse (avdWhenParsed);
// - the Sleep timer, Notes, Embed and Video details dialogs are rendered the first time they open or when the page is
//   idle, the phone tool sheet the first time it opens (window.avdLazy, core.ts); the sheet's and the details dialog's
//   badges are brought up to date then (avdSyncRenderedPanel, on core.ts's avd:lazy-rendered).
// It runs once per page (window.__avdWatchTools), whether the page's module script or core.ts's fallback loads it.

(function () {
    var settingsEl = document.getElementById('avd-watch-tools');
    if (!settingsEl || window.__avdWatchTools) return;
    window.__avdWatchTools = true;

    var C = {};
    try {
        C = JSON.parse(settingsEl.textContent || '{}') || {};
    } catch (e) {
        C = {};
    }
    var TEXTS = C.t || {};
    // The plain translation of an English text (the key itself when the settings lack it).
    var __r = function (key) {
        return Object.prototype.hasOwnProperty.call(TEXTS, key) ? String(TEXTS[key]) : key;
    };
    // Text escaped for markup as Blade's e() does (htmlspecialchars, ENT_QUOTES).
    var avdEsc = function (text) {
        return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    };
    // The translation as {{ __() }} printed it into the old inline script: HTML-escaped.
    var __t = function (key) {
        return avdEsc(__r(key));
    };
    // 1.2.3 round 3 (R3-048): a tool's name and its value in the reader's punctuation, from the settings' pattern
    // ("Sleep timer: 15 minutes", French "Minuteur de mise en veille : 15 minutes", Chinese "睡眠定时器：15 分钟"), never
    // a hard-coded ': ' between them
    var avdPair = function (label, value) {
        return __r(':label: :value').replace(/:label|:value/g, function (key) {
            return key === ':label' ? String(label) : String(value);
        });
    };
    // A number with the reader's decimal mark, in Western digits as the site's cards print them ("1.25" / "1,25"; the
    // same formats as App\Support\LocalNumber and resources/js/utils/format.ts)
    var avdLang = String(window.LANG || document.documentElement.lang || 'en').toLowerCase().split(/[-_@]/)[0] || 'en';
    var avdNumber = function (value) {
        try {
            return new Intl.NumberFormat(avdLang, {maximumFractionDigits: 2, numberingSystem: 'latn'}).format(value);
        } catch (e) {
            return String(value);
        }
    };
    // A speed or a zoom: "1.25x" ("1,25x" in French)
    var avdTimes = function (value) {
        return avdNumber(value) + 'x';
    };
    // A translated sentence with a time in it, where the language puts it (__r('Resumed playback from :time'))
    var avdAt = function (sentence, time) {
        return String(sentence).replace(':time', time);
    };
    // DOMContentLoaded work: at once when the document has been parsed already (this module runs after the parse).
    var avdWhenParsed = function (callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    };
    // A dialog rendered the first time it opens (<template data-avd-lazy>, core.ts); getElementById otherwise.
    var avdLazy = function (id) {
        return typeof window.avdLazy === 'function' ? window.avdLazy(id) : document.getElementById(id);
    };

        // CSS mini-player state. The provider component mounts asynchronously,
        // so this state is bound to the media node after every DOM update.
        var avdPipAllowed = window.avdPipAllowed === true;
        var avdPipShell = null;
        var avdPipWrapper = null;
        var avdPipVideo = null;
        var avdPipFrame = null;
        var avdPipReady = false;
        var avdPipPlaying = false;
        var avdPipManualClosed = false;
        var avdPipManualActive = false;
        var avdPipOriginalMinHeight = '';
        var avdPipTick = 0;
        var avdPipObserver = null;
        var avdPipMutationObserver = null;
        var avdPipMediaSignature = '';
        // Where the player's box is, as its IntersectionObserver last saw it (on screen, fully above the screen, its
        // height). The check below reads these instead of the layout: it ran on every scroll frame and its
        // getBoundingClientRect() forced the page's whole style and layout in the middle of the frame (110-208 ms per
        // scroll gesture on a weak laptop, the watch page's longest frames). Null until the first observation, or
        // without IntersectionObserver: then the box is measured as before.
        var avdPipBox = null;

        function avdPipObserved(entries) {
            var entry = entries[entries.length - 1];
            if (entry) {
                var top = entry.rootBounds ? entry.rootBounds.top : 0;
                avdPipBox = {
                    inView: entry.isIntersecting,
                    outAbove: !entry.isIntersecting && entry.boundingClientRect.bottom <= top,
                    height: entry.boundingClientRect.height,
                };
            }
            avdPipRequestUpdate();
        }

        function avdPipWhere() {
            if (avdPipBox) return avdPipBox;
            var rect = avdPipWrapper.getBoundingClientRect();
            return {inView: rect.top < window.innerHeight && rect.bottom > 0, outAbove: rect.bottom <= 0, height: rect.height};
        }

        function avdPipResolveElements() {
            if (!avdPipAllowed) return false;
            avdPipShell = document.getElementById('avdMainPlayerShell');
            avdPipWrapper = document.getElementById('avdPlayerWrapper');
            return !!(avdPipShell && avdPipWrapper);
        }

        function avdPipClearPlaceholder() {
            if (!avdPipWrapper || avdPipWrapper.dataset.pipPlaceholder !== 'true') return;
            avdPipWrapper.style.minHeight = avdPipOriginalMinHeight;
            delete avdPipWrapper.dataset.pipPlaceholder;
        }

        function avdPipSetActive(active, manual) {
            if (!avdPipResolveElements()) return false;
            if (active) {
                if (!manual && (window.innerWidth < 992 || avdPipManualClosed || !avdPipReady || !avdPipPlaying || !avdPipWhere().outAbove)) {
                    return false;
                }
                if (!avdPipShell.classList.contains('avd-pip-active')) {
                    // Measured when the mini-player opens (once, before the writes below): the box may have changed
                    // size since the observer last reported it.
                    var shellHeight = avdPipWrapper.getBoundingClientRect().height;
                    if (shellHeight > 0) {
                        avdPipOriginalMinHeight = avdPipWrapper.style.minHeight;
                        avdPipWrapper.style.minHeight = shellHeight + 'px';
                        avdPipWrapper.dataset.pipPlaceholder = 'true';
                    }
                    avdPipShell.classList.add('avd-pip-active');
                }
                return true;
            }
            avdPipShell.classList.remove('avd-pip-active');
            avdPipClearPlaceholder();
            return false;
        }

        function avdPipEvaluate() {
            avdPipTick = 0;
            if (!avdPipResolveElements()) return;

            var playerStage = avdPipShell.querySelector('.avd-player-stage');
            if (playerStage && playerStage.dataset.playerState === 'ad') {
                if (avdPipShell.classList.contains('avd-pip-active')) avdPipSetActive(false, false);
                return;
            }

            // Do not read layout on every fast scroll while the async player
            // is still mounting. PiP becomes eligible after media readiness.
            if (!avdPipReady && !avdPipManualActive) return;

            var where = avdPipWhere();
            var inView = where.inView;
            var outAbove = where.outAbove;
            // A manual mini-player choice must survive scrolling on every
            // breakpoint.  Previously the first scroll past the player reset
            // this flag and the mobile-only guard below immediately closed the
            // floating player, even though the UI still reported it as active.
            if (avdPipManualActive) {
                avdPipSetActive(true, true);
                return;
            }
            if (inView) {
                avdPipManualClosed = false;
                if (avdPipShell.classList.contains('avd-pip-active')) avdPipSetActive(false, false);
                return;
            }
            if (window.innerWidth < 992 || !outAbove || !avdPipReady || !avdPipPlaying || avdPipManualClosed) {
                if (avdPipShell.classList.contains('avd-pip-active') && (window.innerWidth < 992 || !avdPipPlaying)) {
                    avdPipSetActive(false, false);
                }
                return;
            }
            avdPipSetActive(true, false);
        }

        function avdPipRequestUpdate() {
            if (!avdPipTick) avdPipTick = window.requestAnimationFrame(avdPipEvaluate);
        }

        function avdPipMarkReady() {
            avdPipReady = true;
            if (avdPipShell) avdPipShell.dataset.playerReady = 'true';
            avdPipRequestUpdate();
        }

        function avdPipMarkPlaying() {
            avdPipPlaying = true;
            avdPipRequestUpdate();
        }

        function avdPipMarkPaused() {
            avdPipPlaying = false;
            avdPipRequestUpdate();
        }

        function avdPipFrameSource() {
            var source = avdPipFrame && avdPipFrame.getAttribute('src');
            if (!source || !source.trim()) return null;
            try {
                var url = new URL(source, window.location.href);
                return /^(https?:)$/.test(url.protocol) ? url : null;
            } catch (sourceError) { return null; }
        }

        function avdPipBindMedia(records) {
            if (!avdPipResolveElements()) return;
            // Preroll media is a separate layer. Its removal must not reset the
            // retained provider frame or claim that the content is playing.
            var mediaLayer = avdPipShell.querySelector('.avd-player-frame .avd-player-media-layer');
            var nextVideo = mediaLayer && mediaLayer.querySelector('video');
            var nextFrame = mediaLayer && mediaLayer.querySelector('iframe');
            var nextSource = nextVideo && nextVideo.querySelector('source');
            var nextSignature = nextVideo
                ? 'video:' + (nextVideo.getAttribute('src') || nextVideo.querySelector('source')?.getAttribute('src') || '')
                : nextFrame
                    ? 'frame:' + (nextFrame.getAttribute('src') || '')
                    : '';
            var sourceChanged = (records || []).some(function(record) {
                return record.type === 'attributes'
                    && record.attributeName === 'src'
                    && (record.target === nextVideo || record.target === nextFrame || record.target === nextSource);
            });
            if (nextVideo !== avdPipVideo || nextFrame !== avdPipFrame || sourceChanged || nextSignature !== avdPipMediaSignature) {
                avdPipVideo = nextVideo;
                avdPipFrame = nextFrame;
                avdPipMediaSignature = nextSignature;
                avdPipReady = false;
                avdPipPlaying = false;
                avdPipManualActive = false;
                avdPipShell.dataset.playerReady = 'false';
                avdPipSetActive(false, false);
                avdSetPlaybackActive(false);
                if (avdPipVideo && !sourceChanged) {
                    avdPipReady = avdPipVideo.readyState >= 2;
                    avdPipPlaying = !avdPipVideo.paused && !avdPipVideo.ended;
                    avdPipShell.dataset.playerReady = avdPipReady ? 'true' : 'false';
                }
                avdPipRequestUpdate();
            }
        }

        function avdPipInit() {
            if (!avdPipAllowed) return;
            if (!avdPipResolveElements()) return;
            avdPipBindMedia();
            if ('IntersectionObserver' in window) {
                avdPipObserver = new IntersectionObserver(avdPipObserved, { threshold: [0, 0.1, 1] });
                avdPipObserver.observe(avdPipWrapper);
            }
            if ('MutationObserver' in window) {
                avdPipMutationObserver = new MutationObserver(avdPipBindMedia);
                var observedPlayerFrame = avdPipShell.querySelector('.avd-player-frame');
                if (observedPlayerFrame) {
                    avdPipMutationObserver.observe(observedPlayerFrame, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });
                }
            }
            // Capture media events once on the stable shell, including nodes
            // mounted or replaced later. Detached provider nodes are ignored.
            avdPipShell.addEventListener('load', function(event) {
                avdPipBindMedia();
                if (event.target === avdPipFrame && avdPipFrameSource()) avdPipMarkReady();
            }, true);
            ['loadeddata', 'canplay', 'play', 'playing', 'timeupdate', 'pause', 'ended', 'emptied', 'error'].forEach(function(name) {
                avdPipShell.addEventListener(name, function(event) {
                    avdPipBindMedia();
                    if (event.target !== avdPipVideo || !avdPipVideo) return;
                    avdPipReady = name !== 'emptied' && !avdPipVideo.error && (avdPipReady || avdPipVideo.readyState >= 2);
                    avdPipPlaying = !avdPipVideo.paused && !avdPipVideo.ended && !avdPipVideo.error;
                    avdPipShell.dataset.playerReady = avdPipReady ? 'true' : 'false';
                    avdSetPlaybackActive(avdPipPlaying);
                    avdPipRequestUpdate();
                }, true);
            });
            // The observer reports every time the box enters or leaves the screen, so scrolling runs no script here;
            // without it (old engines) the box is measured once per scroll frame, as before.
            if (!avdPipObserver) {
                window.addEventListener('scroll', avdPipRequestUpdate, { passive: true });
            }
            window.addEventListener('resize', avdPipRequestUpdate, { passive: true });
        }

        // Ambilight Backlight Engine
        var avdAmbientGlowAllowed = C.ambientGlow === true;
        var avdAmbientGlowDisabledLabel = __r('Disabled');
        window.avdToggleAmbilight = function(force) {
            var glow = document.getElementById('avdAmbilightGlow');
            var btn = document.getElementById('avdAmbilightToggle');
            if (!glow) return;
            if (!avdAmbientGlowAllowed) {
                glow.classList.remove('active');
                if (btn) {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-pressed', 'false');
                }
                var disabledTelemetry = document.getElementById('avdTelemAmbilight');
                if (disabledTelemetry) disabledTelemetry.textContent = avdAmbientGlowDisabledLabel;
                return;
            }
            var isActive = (force !== undefined) ? force : !glow.classList.contains('active');
            glow.classList.toggle('active', isActive);
            if (btn) {
                btn.classList.toggle('active', isActive);
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            }
            var telemAmbi = document.getElementById('avdTelemAmbilight');
            if (telemAmbi) telemAmbi.textContent = isActive ? __r('Active') : __r('Disabled');
            if (window.AvdAudio) window.AvdAudio.playClick();
            if (window.showCopyToast) {
                window.showCopyToast('✨ ' + avdPair(__r('Glow around the player'), isActive ? __r('On') : __r('Off')));
            }
            try {
                localStorage.setItem('avd_ambilight_enabled', isActive ? '1' : '0');
            } catch(e) {}
        };

        // Cinema Snapshot Frame Capture Tool
        window.avdCaptureFrame = function() {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ ' + __r('Snapshots work only with the built-in player'));
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }

            var flash = document.getElementById('avdCameraFlash');
            if (flash) {
                flash.style.opacity = '0.9';
                setTimeout(function(){ flash.style.opacity = '0'; }, 200);
            }
            if (window.AvdAudio) window.AvdAudio.playShutter();

            var canvas = document.createElement('canvas');
            var w = (vid && vid.videoWidth) ? vid.videoWidth : 1280;
            var h = (vid && vid.videoHeight) ? vid.videoHeight : 720;
            canvas.width = w;
            canvas.height = h;
            var ctx = canvas.getContext('2d');

            var captured = false;
            if (vid && vid.readyState >= 2) {
                try {
                    ctx.drawImage(vid, 0, 0, w, h);
                    captured = true;
                } catch(e) {}
            }

            if (!captured) {
                var grad = ctx.createLinearGradient(0, 0, w, h);
                grad.addColorStop(0, '#0a0d14');
                grad.addColorStop(1, '#1b162b');
                ctx.fillStyle = grad;
                ctx.fillRect(0, 0, w, h);

                ctx.fillStyle = '#ff3d61';
                ctx.font = 'bold ' + Math.round(h * 0.08) + 'px sans-serif';
                ctx.textAlign = 'center';
                ctx.fillText('AVDBCMS', w/2, h/2 - 20);
                ctx.fillStyle = '#ffffff';
                ctx.font = Math.round(h * 0.04) + 'px sans-serif';
                ctx.fillText(C.title, w/2, h/2 + 40);
            }

            var badgeH = Math.round(h * 0.12);
            var pad = Math.round(w * 0.03);
            var curTime = (vid && vid.currentTime) ? vid.currentTime : 0;
            // R3X-03: the stamp says the picture's real size ("1920×1080") when it holds a frame of the video, and no size
            // otherwise (it said "4K" for every video)
            var stampSize = captured && vid.videoWidth && vid.videoHeight ? ' ' + vid.videoWidth + '×' + vid.videoHeight : '';
            var mins = Math.floor(curTime / 60);
            var secs = Math.floor(curTime % 60);
            var hrs = Math.floor(mins / 60);
            mins = mins % 60;
            var timeStr = (hrs > 0 ? String(hrs).padStart(2, '0') + ':' : '') + String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');

            var barGrad = ctx.createLinearGradient(0, h - badgeH - pad, 0, h);
            barGrad.addColorStop(0, 'rgba(0,0,0,0)');
            barGrad.addColorStop(1, 'rgba(0,0,0,0.85)');
            ctx.fillStyle = barGrad;
            ctx.fillRect(0, h - badgeH - pad, w, badgeH + pad);

            var pillX = pad;
            var pillY = h - badgeH;
            var pillW = Math.round(w * 0.45);
            var pillH = Math.round(badgeH * 0.7);
            ctx.fillStyle = 'rgba(15, 20, 32, 0.85)';
            ctx.strokeStyle = 'rgba(255, 61, 97, 0.6)';
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.roundRect(pillX, pillY, pillW, pillH, 8);
            ctx.fill();
            ctx.stroke();

            ctx.fillStyle = '#ff3d61';
            ctx.font = 'bold ' + Math.round(pillH * 0.38) + 'px sans-serif';
            ctx.textAlign = 'left';
            ctx.fillText('AVDBCMS' + stampSize, pillX + 16, pillY + Math.round(pillH * 0.42));

            ctx.fillStyle = '#ffffff';
            ctx.font = '600 ' + Math.round(pillH * 0.32) + 'px sans-serif';
            var shortTitle = C.title;
            if (shortTitle.length > 28) shortTitle = shortTitle.substring(0, 28) + '...';
            ctx.fillText(shortTitle + '  [' + timeStr + ']', pillX + 16, pillY + Math.round(pillH * 0.82));

            var a = document.createElement('a');
            a.download = 'avdbcms-snapshot-' + Date.now() + '.png';
            a.href = canvas.toDataURL('image/png');
            document.body.appendChild(a);
            a.click();
            a.remove();

            if (window.showCopyToast) {
                window.showCopyToast('📸 ' + __r('Snapshot saved') + ' [' + timeStr + ']');
            }
        };

        // Direct Playback Rate Setter with Toast & Badge update
        window.avdSetPlaybackRate = function(rate, btn) {
            var vid = document.querySelector('video');
            if (vid) {
                vid.playbackRate = rate;
                if (window.showCopyToast) window.showCopyToast('⏩ ' + avdPair(__r('Playback speed'), avdTimes(rate)));
            } else {
                if (window.showCopyToast) window.showCopyToast('ℹ️ ' + __r('Use the gear icon inside the player to change the speed'));
            }

            var speedBadge = document.getElementById('avdMenuCurrentSpeed');
            if (speedBadge) speedBadge.textContent = avdTimes(rate);

            document.querySelectorAll('.avd-speed-chip').forEach(function(b) {
                var r = parseFloat(b.textContent);
                if (Math.abs(r - rate) < 0.05) {
                    b.classList.add('active', 'btn-primary');
                    b.classList.remove('btn-outline-secondary');
                } else {
                    b.classList.remove('active', 'btn-primary');
                    b.classList.add('btn-outline-secondary');
                }
            });

            var hud = document.getElementById('avdSpeedHud');
            var txt = document.getElementById('avdSpeedHudText');
            if (hud && txt) {
                txt.textContent = avdTimes(rate);
                hud.style.opacity = '1';
                hud.style.transform = 'translate(-50%, -50%) scale(1)';
                clearTimeout(window._avdSpeedTimeout);
                window._avdSpeedTimeout = setTimeout(function(){
                    hud.style.opacity = '0';
                    hud.style.transform = 'translate(-50%, -50%) scale(0.9)';
                }, 1200);
            }
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Playback Speed Controller with HUD Toast (Shortcut: [ and ])
        window.avdChangeSpeed = function(delta) {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ ' + __r('Use the gear icon inside the player to change the speed'));
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }
            var speeds = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0];
            var cur = (vid && vid.playbackRate) ? vid.playbackRate : 1.0;
            var idx = speeds.indexOf(cur);
            if (idx === -1) idx = 2;
            var nextIdx = Math.max(0, Math.min(speeds.length - 1, idx + delta));
            var newRate = speeds[nextIdx];
            window.avdSetPlaybackRate(newRate);
        };

        // Picture-in-Picture Engine
        window.avdTogglePip = function(force) {
            if (!avdPipResolveElements()) return;
            var isPip = (force !== undefined) ? !!force : !avdPipShell.classList.contains('avd-pip-active');
            var playerStage = avdPipShell.querySelector('.avd-player-stage');
            if (isPip && playerStage && playerStage.dataset.playerState === 'ad') return;
            if (isPip && !avdPipReady) {
                // Manual PiP before the media surface is ready would force a
                // fixed-position flash over the loading placeholder.
                avdPipRequestUpdate();
                return;
            }
            avdPipManualClosed = !isPip;
            avdPipManualActive = isPip;
            avdPipSetActive(isPip, true);
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) {
                window.showCopyToast(avdPair(__r('Mini player'), isPip ? __r('On') + ' (P)' : __r('Off')));
            }
        };

        // Keyboard Shortcuts (Cinema Experience: C=Filter, M=Mirror, F=Fullscreen, Shift+F=Zen, I=Telemetry, W=Aspect, Z=Sleep)
        // Capture phase: this handler must run before the layout's shortcut handler (core.ts), which skips the keys it
        // ran (e.defaultPrevented); a listener stopped by an earlier capture listener (cancelBubble) stays out of it.
        document.addEventListener('keydown', function(e) {
            if (e.cancelBubble) return;
            var tag = e.target && e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (e.target && e.target.isContentEditable)) return;
            if ((e.key === 'p' || e.key === 'P') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                window.avdTogglePip && window.avdTogglePip();
            } else if ((e.key === 'r' || e.key === 'R') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleLoop && window.avdToggleLoop();
            } else if (e.key === '.' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdStepFrame && window.avdStepFrame(1/30);
            } else if (e.key === ',' && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdStepFrame && window.avdStepFrame(-1/30);
            } else if ((e.key === 'z' || e.key === 'Z') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleSleepTimerModal && window.avdToggleSleepTimerModal();
            } else if ((e.key === 'b' || e.key === 'B') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdCycleAudioBoost && window.avdCycleAudioBoost();
            } else if ((e.key === 'c' || e.key === 'C') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdCycleVideoFilter && window.avdCycleVideoFilter();
            } else if ((e.key === 'm' || e.key === 'M') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleMirror && window.avdToggleMirror();
            } else if ((e.key === 'f' || e.key === 'F') && e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleZenMode && window.avdToggleZenMode();
            } else if ((e.key === 'f' || e.key === 'F') && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleFullscreen && window.avdToggleFullscreen();
            } else if ((e.key === 'i' || e.key === 'I') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleTelemetryModal && window.avdToggleTelemetryModal();
            } else if ((e.key === 'o' || e.key === 'O') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdCycleRotation && window.avdCycleRotation();
            } else if ((e.key === 'a' || e.key === 'A') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleAmbilight && window.avdToggleAmbilight();
            } else if ((e.key === 't' || e.key === 'T') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                var tBtn = document.querySelector('[data-theater-toggle]');
                if (tBtn) tBtn.click();
            } else if ((e.key === 'l' || e.key === 'L') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleLightsOut && window.avdToggleLightsOut();
            } else if ((e.key === 'n' || e.key === 'N') && e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdSetVideoFilter && window.avdSetVideoFilter('night');
            } else if (e.key === 'Escape') {
                var mobileToolsSheet = document.getElementById('avdMobileToolsSheet');
                if (mobileToolsSheet && (mobileToolsSheet.classList.contains('show') || mobileToolsSheet.classList.contains('avd-mobile-sheet-fallback-open'))) {
                    e.preventDefault();
                    window.avdCloseMobileTools && window.avdCloseMobileTools();
                    return;
                }
                if (document.body.classList.contains('avd-zen-mode')) {
                    window.avdToggleZenMode();
                }
            } else if ((e.key === 'w' || e.key === 'W') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdCycleAspectRatio && window.avdCycleAspectRatio();
            } else if ((e.key === 'v' || e.key === 'V') && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleVisualizer && window.avdToggleVisualizer();
            } else if ((e.key === 'n' || e.key === 'N') && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                e.preventDefault();
                window.avdToggleNotes && window.avdToggleNotes();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                window.avdAdjustVolume && window.avdAdjustVolume(0.05);
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                window.avdAdjustVolume && window.avdAdjustVolume(-0.05);
            } else if (e.key === 'ArrowRight' && !e.shiftKey) {
                e.preventDefault();
                window.avdRelativeSeek && window.avdRelativeSeek(5);
            } else if (e.key === 'ArrowLeft' && !e.shiftKey) {
                e.preventDefault();
                window.avdRelativeSeek && window.avdRelativeSeek(-5);
            }
        }, true);

        // Infinite Video Loop Controller
        window.avdToggleLoop = function() {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ ' + __r('Looping works only with the built-in player'));
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }
            vid.loop = !vid.loop;
            var item = document.getElementById('avdMenuLoopItem');
            if (item) item.classList.toggle('active', vid.loop);
            if (window.AvdAudio) window.AvdAudio.playClick();
            if (window.showCopyToast) {
                window.showCopyToast('🔁 ' + avdPair(__r('Loop the video'), vid.loop ? __r('On') : __r('Off')));
            }
        };

        // Frame-by-frame Precision Stepping
        window.avdStepFrame = function(stepSeconds) {
            var vid = document.querySelector('video');
            if (!vid) return;
            if (!vid.paused) vid.pause();
            var dt = (typeof stepSeconds === 'number') ? stepSeconds : (1 / 30);
            vid.currentTime = Math.max(0, Math.min(vid.duration || 999999, vid.currentTime + dt));
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Cinema Sleep Timer Engine
        window._avdSleepInterval = null;
        window._avdSleepTarget = 0;
        window._avdSleepFadeStarted = false;

        window.avdToggleSleepTimerModal = function() {
            var modalEl = avdLazy('avdSleepTimerModal');
            if (!modalEl || typeof bootstrap === 'undefined') return;
            var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // "15 minutes" in the reader's language (the dialog's own words, from the settings); the number alone otherwise
        var avdSleepLength = function (mins) {
            var lengths = C.sleepLengths || {};
            return Object.prototype.hasOwnProperty.call(lengths, String(mins)) ? String(lengths[String(mins)]) : String(mins);
        };

        window.avdSetSleepTimer = function(mins) {
            var modalEl = document.getElementById('avdSleepTimerModal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }

            clearInterval(window._avdSleepInterval);
            var label = document.getElementById('avdSleepTimerLabel');
            var btn = document.getElementById('avdSleepTimerBtn');
            var vid = document.querySelector('video');

            if (mins === 0) {
                if (label) label.textContent = __r('Sleep');
                if (btn) btn.classList.remove('active', 'text-warning');
                if (window.showCopyToast) window.showCopyToast('🌙 ' + avdPair(__r('Sleep timer'), __r('Off')));
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }

            if (mins === -1) {
                if (label) label.textContent = __r('Sleep: End');
                if (btn) btn.classList.add('active', 'text-warning');
                if (window.showCopyToast) window.showCopyToast('🌙 ' + avdPair(__r('Sleep timer'), __r('At the end of the video')));
                if (vid) {
                    vid.onended = function() {
                        vid.pause();
                        if (window.showCopyToast) window.showCopyToast('🌙 ' + __r('Paused by the sleep timer'));
                        if (window.AvdAudio) window.AvdAudio.playChime();
                        if (label) label.textContent = __r('Sleep');
                        if (btn) btn.classList.remove('active', 'text-warning');
                    };
                }
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }

            window._avdSleepTarget = Date.now() + mins * 60 * 1000;
            if (btn) btn.classList.add('active', 'text-warning');
            if (window.showCopyToast) window.showCopyToast('🌙 ' + avdPair(__r('Sleep timer'), avdSleepLength(mins)));
            if (window.AvdAudio) window.AvdAudio.playChime();

            function updateSleep() {
                var diff = Math.max(0, Math.floor((window._avdSleepTarget - Date.now()) / 1000));
                var m = Math.floor(diff / 60);
                var s = diff % 60;
                if (label) label.textContent = m + ':' + (s < 10 ? '0' : '') + s;

                var vid = document.querySelector('video');
                if (diff <= 15 && diff > 0 && vid && !window._avdSleepFadeStarted) {
                    window._avdSleepFadeStarted = true;
                }

                if (diff <= 0) {
                    clearInterval(window._avdSleepInterval);
                    if (vid) {
                        vid.pause();
                    } else {
                        var ifr = document.querySelector('.avd-player-frame iframe');
                        if (ifr) {
                            ifr.setAttribute('data-saved-src', ifr.src);
                            ifr.src = 'about:blank';
                        }
                    }
                    var overlay = document.getElementById('avdSleepFinishedOverlay');
                    if (overlay) overlay.classList.remove('d-none');

                    if (label) label.textContent = __r('Sleep');
                    if (btn) btn.classList.remove('active', 'text-warning');
                    if (window.showCopyToast) window.showCopyToast('🌙 ' + __r('Paused by the sleep timer'));
                    if (window.AvdAudio) window.AvdAudio.playChime();
                }
            }

            updateSleep();
            window._avdSleepInterval = setInterval(updateSleep, 1000);
        };

        // Resume Stream After Sleep Timer
        window.avdResumeFromSleep = function() {
            var overlay = document.getElementById('avdSleepFinishedOverlay');
            if (overlay) overlay.classList.add('d-none');
            var vid = document.querySelector('video');
            if (vid) {
                vid.play().catch(function(){});
            } else {
                var ifr = document.querySelector('.avd-player-frame iframe');
                if (ifr) {
                    var savedSrc = ifr.getAttribute('data-saved-src');
                    if (savedSrc && savedSrc !== 'about:blank') {
                        ifr.src = savedSrc;
                    } else {
                        window.avdReloadPlayer();
                    }
                }
            }
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('▶️ ' + __r('Video resumed'));
        };

        // Spatial Audio Equalizer Simulator
        var avdAudioModes = [
            { id: 'standard', name: __r('Normal sound'), tag: 'Audio' },
            { id: 'bass', name: __r('Bass boost') + ' 🔊', tag: 'Bass 🔊' },
            { id: 'vocal', name: __r('Clear voices') + ' 🎙️', tag: 'Vocal 🎙️' },
            { id: 'night', name: __r('Night listening') + ' 🌙', tag: 'Night 🌙' }
        ];
        var avdAudioModeIdx = 0;

        window.avdCycleAudioBoost = function() {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ ' + __r('Sound modes work only with the built-in player'));
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }
            avdAudioModeIdx = (avdAudioModeIdx + 1) % avdAudioModes.length;
            var mode = avdAudioModes[avdAudioModeIdx];
            var label = document.getElementById('avdMenuAudioLabel');
            if (label) label.textContent = mode.name;
            if (window.showCopyToast) window.showCopyToast('🎧 ' + mode.name);
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Timestamp Quick-Bookmark Engine
        var avdVideoUuid = String(C.uuid || '');
        var avdBookmarkStorageKey = 'avd_bkm_' + avdVideoUuid;
        window.avdBookmarks = [];

        try {
            var rawBkm = localStorage.getItem(avdBookmarkStorageKey);
            if (rawBkm) window.avdBookmarks = JSON.parse(rawBkm);
        } catch(e) {}

        function avdFormatTime(secs) {
            var s = Math.floor(secs);
            var m = Math.floor(s / 60);
            var sec = s % 60;
            return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
        }

        window.renderBookmarks = function() {
            var shelf = document.getElementById('avdBookmarkShelf');
            var list = document.getElementById('avdBookmarkList');
            if (!shelf || !list) return;

            if (!window.avdBookmarks || window.avdBookmarks.length === 0) {
                shelf.classList.add('d-none');
                shelf.classList.remove('d-flex');
                list.innerHTML = '';
                return;
            }

            shelf.classList.remove('d-none');
            shelf.classList.add('d-flex');
            list.innerHTML = '';

            window.avdBookmarks.forEach(function(bkm, idx) {
                var pill = document.createElement('div');
                pill.className = 'badge rounded-pill bg-dark border border-secondary text-white px-2.5 py-1.5 cursor-pointer d-inline-flex align-items-center gap-1.5 shadow-sm';
                pill.style.cssText = 'font-size: 0.76rem; cursor: pointer; transition: transform 0.15s ease, background 0.15s ease;';
                pill.innerHTML = '<i class="fa-solid fa-play text-warning" style="font-size:0.65rem;"></i> ' +
                                 '<span>' + bkm.timeStr + '</span>' +
                                 '<i class="fa-solid fa-xmark text-white-50 ms-1" style="cursor:pointer;" title="' + __t('Remove') + '"></i>';

                pill.addEventListener('click', function(e) {
                    if (e.target.classList.contains('fa-xmark')) {
                        e.stopPropagation();
                        window.avdDeleteBookmark(idx);
                    } else {
                        window.avdSeekTo(bkm.seconds);
                    }
                });
                list.appendChild(pill);
            });
        };

        window.avdAddBookmark = function() {
            var vid = document.querySelector('video');
            if (!vid) {
                if (window.showCopyToast) window.showCopyToast('ℹ️ ' + __r('Bookmarks work only with the built-in player'));
                if (window.AvdAudio) window.AvdAudio.playClick();
                return;
            }
            var curTime = (vid && vid.currentTime) ? vid.currentTime : 0;
            var timeStr = avdFormatTime(curTime);

            window.avdBookmarks.push({
                seconds: Math.floor(curTime),
                timeStr: timeStr,
                timestamp: Date.now()
            });

            // Keep max 10 bookmarks
            if (window.avdBookmarks.length > 10) window.avdBookmarks.shift();

            try {
                localStorage.setItem(avdBookmarkStorageKey, JSON.stringify(window.avdBookmarks));
            } catch(e) {}

            window.renderBookmarks();
            if (window.AvdAudio) window.AvdAudio.playChime();
            if (window.showCopyToast) window.showCopyToast('📌 ' + avdAt(__r('Bookmark saved at :time'), timeStr));
        };

        window.avdSeekTo = function(secs) {
            var vid = document.querySelector('video');
            if (vid) {
                vid.currentTime = secs;
                if (vid.paused) vid.play();
            }
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('⏩ ' + avdAt(__r('Jumped to :time'), avdFormatTime(secs)));
        };

        window.avdDeleteBookmark = function(idx) {
            window.avdBookmarks.splice(idx, 1);
            try {
                localStorage.setItem(avdBookmarkStorageKey, JSON.stringify(window.avdBookmarks));
            } catch(e) {}
            window.renderBookmarks();
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Render any existing bookmarks on load
        window.renderBookmarks();

        // Cinema Zoom & Aspect Ratio Stretcher Controller (Shortcut: W). label: the dock button's; hud: the HUD's, the
        // toast's and the Video details dialog's - the ratio, or the plain translated word (1.2.3 round 2: they were
        // English in every language)
        var avdAspectModes = [
            { id: 'fit', label: '16:9', hud: '16:9', fit: 'contain', transform: 'scale(1)' },
            { id: 'cinemascope', label: '21:9', hud: '21:9', fit: 'cover', transform: 'scale(1, 0.77)' },
            { id: 'fill', label: __r('Fill'), hud: __r('Fill'), fit: 'cover', transform: 'scale(1.15)' },
            { id: 'zoom', label: avdTimes(1.25), hud: avdTimes(1.25), fit: 'cover', transform: 'scale(1.25)' }
        ];
        var avdCurrentAspectIndex = 0;
        var avdAspectHudTimer = null;

        window.avdCycleAspectRatio = function() {
            avdCurrentAspectIndex = (avdCurrentAspectIndex + 1) % avdAspectModes.length;
            var mode = avdAspectModes[avdCurrentAspectIndex];
            var vid = document.querySelector('video');
            var ifr = document.querySelector('.avd-player-frame iframe');
            var target = vid || ifr;
            if (target) {
                target.style.transition = 'transform 0.35s cubic-bezier(0.16,1,0.3,1), object-fit 0.35s ease';
                if (target.tagName === 'VIDEO') {
                    target.style.objectFit = mode.fit;
                }
                target.style.transform = mode.transform;
                target.style.transformOrigin = 'center center';
            }

            var btnLabel = document.getElementById('avdAspectLabel');
            if (btnLabel) btnLabel.textContent = mode.label;
            var telemAspect = document.getElementById('avdTelemAspect');
            if (telemAspect) telemAspect.textContent = mode.hud;

            var hud = document.getElementById('avdAspectHud');
            var hudText = document.getElementById('avdAspectHudText');
            if (hud && hudText) {
                hudText.textContent = mode.hud;
                hud.style.opacity = '1';
                hud.style.transform = 'translate(-50%, -50%) scale(1)';
                if (avdAspectHudTimer) clearTimeout(avdAspectHudTimer);
                avdAspectHudTimer = setTimeout(function() {
                    hud.style.opacity = '0';
                    hud.style.transform = 'translate(-50%, -50%) scale(0.9)';
                }, 1300);
            }

            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('📐 ' + avdPair(__r('Aspect ratio'), mode.hud));
        };

        // Player Stream Reload Engine (Crucial for Upload18 iframes)
        window.avdReloadPlayer = function() {
            var ifr = document.querySelector('.avd-player-frame iframe');
            if (ifr) {
                var src = ifr.src;
                ifr.src = '';
                setTimeout(function() { ifr.src = src; }, 80);
                if (window.showCopyToast) window.showCopyToast('🔄 ' + __r('Video reloaded'));
                if (window.AvdAudio) window.AvdAudio.playWhoosh();
                return;
            }
            var vid = document.querySelector('video');
            if (vid) {
                vid.load();
                vid.play().catch(function(){});
                if (window.showCopyToast) window.showCopyToast('🔄 ' + __r('Video reloaded'));
                if (window.AvdAudio) window.AvdAudio.playWhoosh();
            }
        };

        // Quick Share Video URL: the page's own share, which the layout's avdQuickShare() runs when it gets no URL (the
        // dock button); with a URL (a related card) it shares that card (core.ts initShare).
        window.avdPageShare = function() {
            var url = window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    if (window.showCopyToast) window.showCopyToast('🔗 ' + __r('Video link copied to clipboard!'));
                }).catch(function() {
                    prompt(__r('Copy link'), url);
                });
            } else {
                prompt(__r('Copy link'), url);
            }
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Cinema Color Grading Engine (100% works on Upload18 iframe & HTML5!)
        var avdFilters = ['normal', 'vivid', 'cinema', 'cyberpunk', 'oled', 'noir', 'night', 'anime'];
        // the filters' names in the reader's language, the words of the phone tool sheet and the dock's chips (R1-146)
        var avdFilterNames = {
            'normal': __r('Normal'),
            'vivid': __r('Vivid'),
            'cinema': __r('Warm film'),
            'cyberpunk': __r('Cyberpunk'),
            'oled': __r('Deep black'),
            'noir': __r('Black and white'),
            'night': __r('Night'),
            'anime': __r('Anime')
        };
        var avdConfiguredFilter = {
            'none': 'normal',
            'hdr_vibrant': 'vivid',
            'warm_35mm': 'cinema',
            'cyber_glow': 'cyberpunk',
            'cool_scifi': 'night',
            'monochrome_noir': 'noir'
        }[document.documentElement.getAttribute('data-avd-cinema-filter') || 'none'] || 'normal';
        var avdCurrentFilterIdx = Math.max(0, avdFilters.indexOf(avdConfiguredFilter));

        // Mobile Bottom Sheet Trigger (For iPhone, Android, and mobile viewports).
        // Bootstrap is the primary implementation, with a small scoped fallback
        // so a delayed/missing bundle cannot strand the mobile controls.
        window.avdCloseMobileTools = function() {
            var sheetEl = document.getElementById('avdMobileToolsSheet');
            if (!sheetEl) return;

            if (window.bootstrap && bootstrap.Offcanvas) {
                var instance = bootstrap.Offcanvas.getInstance(sheetEl);
                if (instance) {
                    instance.hide();
                    return;
                }
            }

            sheetEl.classList.remove('show', 'avd-mobile-sheet-fallback-open');
            sheetEl.removeAttribute('aria-modal');
            sheetEl.removeAttribute('role');
            sheetEl.style.visibility = '';
            sheetEl.style.transform = '';
            document.body.classList.remove('avd-mobile-tools-open');
            var moreButton = document.getElementById('avdMoreToolsBtn');
            if (moreButton) moreButton.setAttribute('aria-expanded', 'false');
            var backdrop = document.getElementById('avdMobileToolsBackdrop');
            if (backdrop) backdrop.remove();
        };

        window.avdOpenMobileTools = function() {
            var sheetEl = avdLazy('avdMobileToolsSheet');
            if (!sheetEl) return;

            if (window.bootstrap && bootstrap.Offcanvas) {
                var sheet = bootstrap.Offcanvas.getInstance(sheetEl) || new bootstrap.Offcanvas(sheetEl);
                document.body.classList.add('avd-mobile-tools-open');
                sheet.show();
            } else {
                sheetEl.classList.add('show', 'avd-mobile-sheet-fallback-open');
                sheetEl.setAttribute('aria-modal', 'true');
                sheetEl.setAttribute('role', 'dialog');
                sheetEl.style.visibility = 'visible';
                sheetEl.style.transform = 'translateY(0)';
                document.body.classList.add('avd-mobile-tools-open');
                var moreButton = document.getElementById('avdMoreToolsBtn');
                if (moreButton) moreButton.setAttribute('aria-expanded', 'true');

                var backdrop = document.getElementById('avdMobileToolsBackdrop');
                if (!backdrop) {
                    backdrop = document.createElement('div');
                    backdrop.id = 'avdMobileToolsBackdrop';
                    backdrop.className = 'avd-mobile-sheet-fallback-backdrop';
                    backdrop.addEventListener('click', window.avdCloseMobileTools);
                    document.body.appendChild(backdrop);
                }

                var closeButton = sheetEl.querySelector('.btn-close');
                if (closeButton) window.setTimeout(function() { closeButton.focus(); }, 0);
            }

            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        function avdBindMobileSheet(sheetEl) {
            if (sheetEl.dataset.avdSheetBound === '1') return;
            sheetEl.dataset.avdSheetBound = '1';
            sheetEl.addEventListener('hidden.bs.offcanvas', function() {
                document.body.classList.remove('avd-mobile-tools-open');
            });
        }

        // Prevent the desktop dropup's delegated Bootstrap handler from also
        // running after the mobile trigger has opened the sheet.
        (function bindMobileCinemaControls() {
            var moreButton = document.getElementById('avdMoreToolsBtn');
            var sheetEl = document.getElementById('avdMobileToolsSheet');
            if (moreButton) {
                moreButton.addEventListener('click', function(event) {
                    if (window.innerWidth < 768) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.avdOpenMobileTools();
                    }
                }, true);
                // watch-7: the sheet renders on first use (core.ts leaves it out of the idle pass). The touch on the button
                // (or the pointer over it) starts that, so its layout is done in the frame before the click opens it.
                var avdWarmMobileSheet = function() {
                    if (window.innerWidth < 768) avdLazy('avdMobileToolsSheet');
                };
                moreButton.addEventListener('pointerdown', avdWarmMobileSheet, {passive: true});
                moreButton.addEventListener('pointerenter', avdWarmMobileSheet, {passive: true});
            }
            if (sheetEl) {
                avdBindMobileSheet(sheetEl);
            }
        }());

        // Keep the desktop menu inside the visual viewport when the trigger is
        // near an edge. The mobile path is always the bottom sheet above.
        function avdClampCinemaDropdown(menu) {
            if (!menu || window.innerWidth < 768 || !menu.classList.contains('show')) return;
            menu.style.translate = '0 0';
            var rect = menu.getBoundingClientRect();
            var gutter = 8;
            var dx = rect.left < gutter ? gutter - rect.left : (rect.right > window.innerWidth - gutter ? (window.innerWidth - gutter) - rect.right : 0);
            var dy = rect.top < gutter ? gutter - rect.top : (rect.bottom > window.innerHeight - gutter ? (window.innerHeight - gutter) - rect.bottom : 0);
            if (dx || dy) menu.style.translate = dx + 'px ' + dy + 'px';
        }

        document.addEventListener('shown.bs.dropdown', function(event) {
            var menu = event.target && event.target.parentElement ? event.target.parentElement.querySelector('.avd-cinema-dropdown-menu') : null;
            window.setTimeout(function() { avdClampCinemaDropdown(menu); }, 0);
        });
        window.addEventListener('resize', function() {
            var openMenu = document.querySelector('.avd-cinema-dropdown-menu.show');
            avdClampCinemaDropdown(openMenu);
        }, { passive: true });

        window.avdSetVideoFilter = function(preset, btn) {
            var frame = document.querySelector('.avd-player-frame');
            if (!frame) return;
            avdFilters.forEach(function(f) {
                frame.classList.remove('avd-filter-' + f);
            });
            if (preset !== 'normal') {
                frame.classList.add('avd-filter-' + preset);
            }
            avdCurrentFilterIdx = avdFilters.indexOf(preset);
            if (avdCurrentFilterIdx === -1) avdCurrentFilterIdx = 0;

            // Update badge & chips in menu & mobile sheet
            var badge = document.getElementById('avdCurrentFilterBadge');
            if (badge) badge.textContent = avdFilterNames[preset] || preset;
            var mobBadge = document.getElementById('avdMobileFilterBadge');
            if (mobBadge) mobBadge.textContent = avdFilterNames[preset] || preset;
            var dockLabel = document.getElementById('avdFilterLabel');
            if (dockLabel) dockLabel.textContent = preset === 'normal' ? __r('Filter') : (avdFilterNames[preset] || preset);
            var dockBtn = document.getElementById('avdFilterBtn');
            if (dockBtn) {
                dockBtn.classList.toggle('active', preset !== 'normal');
                dockBtn.title = avdPair(__r('Color filter'), avdFilterNames[preset] || preset) + ' (C)';
            }

            // Telemetry modal update
            var telemFilter = document.getElementById('avdTelemFilter');
            if (telemFilter) telemFilter.textContent = avdFilterNames[preset] || preset;

            document.querySelectorAll('.avd-filter-chip, .avd-mob-filter-chip').forEach(function(chip) {
                chip.classList.remove('active', 'btn-primary');
                chip.classList.add('btn-outline-secondary');
            });
            document.querySelectorAll('.avd-filter-chip[onclick*="' + preset + '"], .avd-mob-filter-chip[onclick*="' + preset + '"]').forEach(function(chip) {
                chip.classList.add('active', 'btn-primary');
                chip.classList.remove('btn-outline-secondary');
            });

            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('🎨 ' + avdPair(__r('Color filter'), avdFilterNames[preset] || preset));
        };

        // Mirror the persisted appearance filter in both control surfaces on
        // first paint; clicking a chip continues to use the interactive cycle.
        (function() {
            var preset = avdConfiguredFilter;
            var label = avdFilterNames[preset] || preset;
            ['avdCurrentFilterBadge', 'avdMobileFilterBadge'].forEach(function(id) {
                var badge = document.getElementById(id);
                if (badge) badge.textContent = label;
            });
            var telemetry = document.getElementById('avdTelemFilter');
            if (telemetry) telemetry.textContent = label;
            document.querySelectorAll('.avd-filter-chip, .avd-mob-filter-chip').forEach(function(chip) {
                var active = chip.getAttribute('onclick') && chip.getAttribute('onclick').indexOf("'" + preset + "'") !== -1;
                chip.classList.toggle('active', active);
                chip.classList.toggle('btn-primary', active);
                chip.classList.toggle('btn-outline-secondary', !active);
            });
        }());

        window.avdCycleVideoFilter = function() {
            avdCurrentFilterIdx = (avdCurrentFilterIdx + 1) % avdFilters.length;
            var nextPreset = avdFilters[avdCurrentFilterIdx];
            var targetBtn = document.querySelector('.avd-filter-chip[onclick*="' + nextPreset + '"]');
            window.avdSetVideoFilter(nextPreset, targetBtn);
        };

        // Frame Rotation Engine (Works 100% on Upload18 iframe & HTML5!)
        var avdRotations = [0, 90, 180, 270];
        var avdCurrentRotation = 0;
        window.avdRotateVideo = function(deg, btn) {
            var frame = document.querySelector('.avd-player-frame');
            if (!frame) return;
            avdRotations.forEach(function(r) {
                if (r !== 0) frame.classList.remove('avd-rotate-' + r);
            });
            if (deg !== 0) {
                frame.classList.add('avd-rotate-' + deg);
            }
            avdCurrentRotation = deg;
            var badge = document.getElementById('avdCurrentRotationBadge');
            if (badge) badge.textContent = deg + '°';
            var mobRotateBadge = document.getElementById('avdMobileRotateBadge');
            if (mobRotateBadge) mobRotateBadge.textContent = deg + '°';

            document.querySelectorAll('.avd-rotate-chip, .avd-mob-rotate-chip').forEach(function(c) {
                c.classList.remove('active', 'btn-primary');
                c.classList.add('btn-outline-secondary');
            });
            document.querySelectorAll('.avd-rotate-chip[onclick*="' + deg + '"], .avd-mob-rotate-chip[onclick*="' + deg + '"]').forEach(function(c) {
                c.classList.add('active', 'btn-primary');
                c.classList.remove('btn-outline-secondary');
            });

            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) window.showCopyToast('🔄 ' + avdPair(__r('Rotate the video'), deg + '°'));
        };

        window.avdCycleRotation = function() {
            var nextIdx = (avdRotations.indexOf(avdCurrentRotation) + 1) % avdRotations.length;
            var nextDeg = avdRotations[nextIdx];
            var targetBtn = document.querySelector('.avd-rotate-chip[onclick*="' + nextDeg + '"]');
            window.avdRotateVideo(nextDeg, targetBtn);
        };

        // Screen Brightness Engine (Works 100% on Upload18 iframe & HTML5!)
        window.avdSetBrightness = function(level, btn) {
            var frame = document.querySelector('.avd-player-frame');
            if (!frame) return;
            frame.classList.remove('avd-bright-boost', 'avd-bright-dim');
            if (level === 'boost') frame.classList.add('avd-bright-boost');
            if (level === 'dim') frame.classList.add('avd-bright-dim');

            var badge = document.getElementById('avdCurrentBrightBadge');
            if (badge) badge.textContent = level === 'boost' ? '+15%' : (level === 'dim' ? '-15%' : __r('Normal'));
            var mobBrightBadge = document.getElementById('avdMobileBrightBadge');
            if (mobBrightBadge) mobBrightBadge.textContent = level === 'boost' ? '+15%' : (level === 'dim' ? '-15%' : __r('Normal'));

            document.querySelectorAll('.avd-bright-chip, .avd-mob-bright-chip').forEach(function(c) {
                c.classList.remove('active', 'btn-primary');
                c.classList.add('btn-outline-secondary');
            });
            document.querySelectorAll('.avd-bright-chip[onclick*="' + level + '"], .avd-mob-bright-chip[onclick*="' + level + '"]').forEach(function(c) {
                c.classList.add('active', 'btn-primary');
                c.classList.remove('btn-outline-secondary');
            });

            if (window.AvdAudio) window.AvdAudio.playClick();
            // R3-049: the signed value is isolated left to right, so Arabic reads "+15%" (not "15%+")
            if (window.showCopyToast) window.showCopyToast('☀️ ' + avdPair(__r('Brightness'), level === 'boost' ? '\u2066+15%\u2069' : (level === 'dim' ? '\u2066-15%\u2069' : __r('Normal'))));
        };

        // Ambilight Multi-Glow Engine (Works 100% on Upload18 iframe & HTML5!)
        var avdAmbiModes = ['theme', 'pulse', 'neon', 'warm'];
        window.avdSetAmbiMode = function(mode, btn) {
            if (!avdAmbientGlowAllowed) return;
            var glow = document.getElementById('avdAmbilightGlow');
            if (!glow) return;
            glow.classList.remove('glow-pulse', 'glow-neon', 'glow-warm');
            if (mode === 'pulse') glow.classList.add('glow-pulse');
            if (mode === 'neon') glow.classList.add('glow-neon');
            if (mode === 'warm') glow.classList.add('glow-warm');

            var names = {'theme': __r('Theme'), 'pulse': __r('Pulse'), 'neon': __r('Neon'), 'warm': __r('Warm')};
            var badge = document.getElementById('avdCurrentAmbiBadge');
            if (badge) badge.textContent = names[mode] || mode;
            var mobAmbiBadge = document.getElementById('avdMobileAmbiBadge');
            if (mobAmbiBadge) mobAmbiBadge.textContent = names[mode] || mode;

            document.querySelectorAll('.avd-ambi-chip, .avd-mob-ambi-chip').forEach(function(c) {
                c.classList.remove('active', 'btn-primary');
                c.classList.add('btn-outline-secondary');
            });
            document.querySelectorAll('.avd-ambi-chip[onclick*="' + mode + '"], .avd-mob-ambi-chip[onclick*="' + mode + '"]').forEach(function(c) {
                c.classList.add('active', 'btn-primary');
                c.classList.remove('btn-outline-secondary');
            });

            window.avdToggleAmbilight(true);
            if (window.AvdAudio) window.AvdAudio.playChime();
            if (window.showCopyToast) window.showCopyToast('✨ ' + avdPair(__r('Glow around the player'), names[mode] || mode));
        };

        // Embed Code Generator (Opens modal & pre-populates iframe code)
        window.avdOpenEmbedModal = function() {
            var modalEl = avdLazy('avdEmbedModal');
            if (!modalEl || typeof bootstrap === 'undefined') return;
            var ta = document.getElementById('avdEmbedCodeTextarea');
            if (ta) {
                var embedUrl = String(C.embedUrl || '');
                ta.value = '<iframe src="' + embedUrl + '" width="100%" height="480" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
            }
            var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        window.avdCopyEmbedCode = function() {
            var ta = document.getElementById('avdEmbedCodeTextarea');
            if (!ta) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ta.value).then(function() {
                    if (window.showCopyToast) window.showCopyToast('📋 ' + __r('Embed code copied to clipboard!'));
                    if (window.AvdAudio) window.AvdAudio.playChime();
                });
            } else {
                ta.select();
                document.execCommand('copy');
                if (window.showCopyToast) window.showCopyToast('📋 ' + __r('Embed code copied!'));
            }
        };

        // Sticky Mini Watch Bar. It shows once the page has scrolled past
        // max(450px, player bottom - 50px): scrollY > 450 and the player's
        // bottom edge above 50px from the viewport top. Two IntersectionObservers
        // track both edges, so scrolling runs no script here and the class is only
        // written on a transition. The 450px sentinel spans the document top, so it
        // is in view at scrollY 0 on any viewport height and every crossing of 450
        // flips it, jumps included (scroll restoration, End key, scrollbar drag);
        // an edge-adjacent target still intersects, so it reproduces scrollY > 450
        // exactly. overflow-anchor:none keeps it from becoming the scroll anchor (it
        // never moves with the content). Zen mode hides the bar through CSS
        // (body.avd-zen-mode #avdStickyWatchBar).
        var avdStickyBar = document.getElementById('avdStickyWatchBar');
        // 1.2.3 round 3: the bar leaves the player's stacking layer (the shell's isolation, the wrapper's z-index 1),
        // where the site header covered it and the footer slid over it at the end of a page; next to the header its
        // own z-index (1039, video.scss watch-20) keeps it above the page and under every window's backdrop.
        var avdSiteHeader = avdStickyBar && document.querySelector('.header');
        if (avdSiteHeader && avdSiteHeader.parentNode) avdSiteHeader.parentNode.insertBefore(avdStickyBar, avdSiteHeader.nextSibling);
        var avdStickyPlayerWrapper = document.getElementById('avdPlayerWrapper');
        var avdStickyVisible = false;
        var avdStickyPast450 = false;
        var avdStickyPlayerAbove = !avdStickyPlayerWrapper;
        function avdSetStickyWatchBar(shouldShow) {
            if (shouldShow === avdStickyVisible) return;
            avdStickyVisible = shouldShow;
            avdStickyBar.classList.toggle('d-none', !shouldShow);
        }
        if (avdStickyBar && typeof window.IntersectionObserver === 'function') {
            var avdStickySentinel = document.createElement('div');
            avdStickySentinel.setAttribute('aria-hidden', 'true');
            avdStickySentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:450px;pointer-events:none;overflow-anchor:none';
            document.body.insertBefore(avdStickySentinel, document.body.firstChild);
            new IntersectionObserver(function(entries) {
                var entry = entries[entries.length - 1];
                avdStickyPast450 = !entry.isIntersecting;
                avdSetStickyWatchBar(avdStickyPast450 && avdStickyPlayerAbove);
            }).observe(avdStickySentinel);
            if (avdStickyPlayerWrapper) {
                new IntersectionObserver(function(entries) {
                    var entry = entries[entries.length - 1];
                    avdStickyPlayerAbove = !entry.isIntersecting && entry.boundingClientRect.bottom < 50;
                    avdSetStickyWatchBar(avdStickyPast450 && avdStickyPlayerAbove);
                }, { rootMargin: '-50px 0px 0px 0px' }).observe(avdStickyPlayerWrapper);
            }
        } else if (avdStickyBar) {
            // Without IntersectionObserver: one rAF-coalesced check per scroll, with
            // the threshold measured at start and on resize (engines without
            // IntersectionObserver have no ResizeObserver either).
            var avdStickyFrame = 0;
            var avdStickyThreshold = 450;
            var avdStickyNeedsMeasure = true;
            var avdUpdateStickyWatchBar = function() {
                avdStickyFrame = 0;
                if (avdStickyNeedsMeasure) {
                    if (avdStickyPlayerWrapper) {
                        avdStickyThreshold = Math.max(450, window.scrollY + avdStickyPlayerWrapper.getBoundingClientRect().bottom - 50);
                    }
                    avdStickyNeedsMeasure = false;
                }
                avdSetStickyWatchBar(window.scrollY > avdStickyThreshold);
            };
            var avdRequestStickyWatchBarUpdate = function(measure) {
                if (measure) avdStickyNeedsMeasure = true;
                if (avdStickyFrame) return;
                avdStickyFrame = window.requestAnimationFrame(avdUpdateStickyWatchBar);
            };
            window.addEventListener('scroll', function() { avdRequestStickyWatchBarUpdate(false); }, { passive: true });
            window.addEventListener('resize', function() { avdRequestStickyWatchBarUpdate(true); }, { passive: true });
            avdRequestStickyWatchBarUpdate(true);
        }

        // Mirror Flip View (100% works on Upload18 iframe & HTML5)
        window.avdToggleMirror = function() {
            var frame = document.querySelector('.avd-player-frame');
            var btn = document.getElementById('avdMirrorBtn');
            var telemMirror = document.getElementById('avdTelemMirror');
            if (!frame) return;
            var isMirrored = frame.classList.toggle('avd-mirrored');
            if (btn) btn.classList.toggle('active', isMirrored);
            if (telemMirror) {
                telemMirror.textContent = isMirrored ? __r('On') : __r('Off');
                telemMirror.className = 'badge ' + (isMirrored ? 'bg-warning text-dark' : 'bg-secondary-subtle text-secondary');
            }
            if (window.AvdAudio) window.AvdAudio.playClick();
            if (window.showCopyToast) window.showCopyToast('🪞 ' + avdPair(__r('Mirror the video'), isMirrored ? __r('On') : __r('Off')));
        };

        // True Fullscreen Engine with iOS Safari pseudo-fullscreen fallback
        window.avdToggleFullscreen = function() {
            var shell = document.getElementById('avdMainPlayerShell') || document.querySelector('.avd-player-frame');
            if (!shell) return;

            // Check if native video element has webkitEnterFullscreen (iOS Safari native video)
            var videoEl = document.querySelector('.avd-player-frame video, #player video');
            if (videoEl && videoEl.webkitEnterFullscreen && !document.fullscreenElement) {
                try {
                    videoEl.webkitEnterFullscreen();
                    return;
                } catch(e){}
            }

            if (!document.fullscreenElement && !document.webkitFullscreenElement && !shell.classList.contains('avd-ios-fullscreen')) {
                var enterFallback = function() {
                    shell.classList.add('avd-ios-fullscreen');
                    document.body.classList.add('avd-fullscreen-active');
                };

                if (shell.requestFullscreen) {
                    shell.requestFullscreen().catch(enterFallback);
                } else if (shell.webkitRequestFullscreen) {
                    try {
                        shell.webkitRequestFullscreen();
                    } catch(e) {
                        enterFallback();
                    }
                } else if (shell.msRequestFullscreen) {
                    shell.msRequestFullscreen();
                } else {
                    enterFallback();
                }
                if (window.AvdAudio) window.AvdAudio.playWhoosh();
                if (window.showCopyToast) window.showCopyToast('⛶ ' + avdPair(__r('Full screen'), __r('On')));
            } else {
                if (shell.classList.contains('avd-ios-fullscreen')) {
                    shell.classList.remove('avd-ios-fullscreen');
                    document.body.classList.remove('avd-fullscreen-active');
                } else if (document.exitFullscreen) {
                    document.exitFullscreen().catch(function(){});
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
                if (window.AvdAudio) window.AvdAudio.playClick();
                if (window.showCopyToast) window.showCopyToast('⛶ ' + avdPair(__r('Full screen'), __r('Off')));
            }
        };

        // Zen Focus Immersion Engine (Shortcut: Shift+F or ESC)
        window.avdToggleZenMode = function() {
            var isZen = document.body.classList.toggle('avd-zen-mode');
            var btn = document.getElementById('avdZenBtn');
            if (btn) btn.classList.toggle('active', isZen);
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
            if (window.showCopyToast) {
                window.showCopyToast('🧘 ' + avdPair(__r('Focus mode'), isZen ? __r('On') + ' (Shift+F, Esc)' : __r('Off')));
            }
        };

        // Video details modal (Shortcut: I)
        window.avdToggleTelemetryModal = function() {
            var modalEl = avdLazy('avdTelemetryModal');
            if (!modalEl || typeof bootstrap === 'undefined') return;
            var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Smart Runtime Detection of Player Engine (Iframe vs Native HTML5)
        function avdUpdatePlayerCapabilities() {
            var vid = document.querySelector('video');
            var ifr = document.querySelector('.avd-player-frame iframe') || document.querySelector('iframe');
            var isIframe = !vid && !!ifr;
            var badge = document.getElementById('avdPlayerTypeBadge');
            var iframeNotice = document.getElementById('avdIframeHelperNotice');
            var html5Tools = document.getElementById('avdHtml5OnlyTools');

            // R1-146 (owner approved 2026-10-27): the player's kind in plain, translated words (was "Upload18 Stream",
            // "HTML5 Native", "Player Ready" in English on every page)
            if (badge) {
                badge.textContent = isIframe ? __r('Upload18 player') : (vid ? __r('Built-in player') : __r('Player ready'));
                badge.className = 'badge rounded-pill ' + (isIframe ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-success-subtle text-success border border-success-subtle');
            }
            var mobBadge = document.getElementById('avdMobilePlayerTypeBadge');
            if (mobBadge) {
                mobBadge.textContent = isIframe ? __r('Upload18 player') : (vid ? __r('Built-in player') : __r('Player ready'));
                mobBadge.className = 'badge rounded-pill ' + (isIframe ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-success-subtle text-success border border-success-subtle');
            }

            if (iframeNotice) {
                if (isIframe) {
                    iframeNotice.classList.remove('d-none');
                } else {
                    iframeNotice.classList.add('d-none');
                }
            }

            if (html5Tools) {
                if (vid) {
                    html5Tools.classList.remove('d-none');
                } else {
                    html5Tools.classList.add('d-none');
                }
            }

        }

        avdWhenParsed(function() {
            setTimeout(avdUpdatePlayerCapabilities, 1200);
            setTimeout(avdUpdatePlayerCapabilities, 3500);
            // The player may put its <video> or Upload18 frame in later than that (a slow page or device): the Tools
            // menu checks again each time it opens, before it is drawn, as the phone tools sheet does when it is drawn.
            var avdToolsMenuToggle = document.getElementById('avdMoreToolsBtn');
            if (avdToolsMenuToggle) avdToolsMenuToggle.addEventListener('show.bs.dropdown', avdUpdatePlayerCapabilities);
        });

        // Audio Spectrum Visualizer Controller (Shortcut: V)
        var avdVisualizerActive = false;
        var avdVisualizerAnimId = null;
        var avdVisualizerBars = 48;

        window.avdToggleVisualizer = function() {
            avdVisualizerActive = !avdVisualizerActive;
            var canvas = document.getElementById('avdVisualizerCanvas');
            var btn = document.getElementById('avdVisualizerBtn');
            if (!canvas) return;

            if (btn) {
                btn.classList.toggle('active', avdVisualizerActive);
                btn.classList.toggle('text-info', avdVisualizerActive);
            }

            if (avdVisualizerActive) {
                canvas.style.opacity = '0.85';
                if (window.AvdAudio) window.AvdAudio.playChime();
                if (window.showCopyToast) window.showCopyToast('📊 ' + avdPair(__r('Sound bars'), __r('On')));
                avdStartVisualizerAnimation();
            } else {
                canvas.style.opacity = '0';
                if (avdVisualizerAnimId) {
                    cancelAnimationFrame(avdVisualizerAnimId);
                    avdVisualizerAnimId = null;
                }
                var ctx = canvas.getContext('2d');
                if (ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
                if (window.AvdAudio) window.AvdAudio.playClick();
                if (window.showCopyToast) window.showCopyToast('📊 ' + avdPair(__r('Sound bars'), __r('Off')));
            }
        };

        function avdStartVisualizerAnimation() {
            var canvas = document.getElementById('avdVisualizerCanvas');
            if (!canvas) return;
            var ctx = canvas.getContext('2d');
            var phase = 0;

            function render() {
                if (!avdVisualizerActive) return;
                var vid = document.querySelector('video');
                var isPlaying = vid && !vid.paused && vid.currentTime > 0;

                if (canvas.width !== canvas.clientWidth) canvas.width = canvas.clientWidth || 800;
                if (canvas.height !== 50) canvas.height = 50;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                var barWidth = canvas.width / avdVisualizerBars;
                phase += isPlaying ? 0.08 : 0.02;

                var grad = ctx.createLinearGradient(0, canvas.height, 0, 0);
                grad.addColorStop(0, 'rgba(56, 189, 248, 0.2)');
                grad.addColorStop(0.5, 'rgba(168, 85, 247, 0.7)');
                grad.addColorStop(1, 'rgba(236, 72, 153, 0.95)');

                for (var i = 0; i < avdVisualizerBars; i++) {
                    var factor = Math.sin(i * 0.25 + phase) * 0.5 + 0.5;
                    var harmonic = Math.cos(i * 0.4 - phase * 1.5) * 0.3;
                    var rawHeight = (factor + harmonic) * (canvas.height - 8);
                    if (!isPlaying) rawHeight = Math.max(3, rawHeight * 0.25);
                    var barH = Math.max(2, rawHeight);

                    var x = i * barWidth;
                    var y = canvas.height - barH;

                    ctx.fillStyle = grad;
                    ctx.fillRect(x + 1, y, Math.max(1, barWidth - 2), barH);

                    // Peak glowing cap dot
                    ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
                    ctx.fillRect(x + 1, Math.max(0, y - 2), Math.max(1, barWidth - 2), 2);
                }

                avdVisualizerAnimId = requestAnimationFrame(render);
            }

            if (avdVisualizerAnimId) cancelAnimationFrame(avdVisualizerAnimId);
            render();
        }

        // Video Timestamp Notes & Scratchpad Controller (Shortcut: N)
        var avdNotesStorageKey = 'avd_notes_' + avdVideoUuid;

        window.avdToggleNotes = function() {
            var modalEl = avdLazy('avdNotesModal');
            if (!modalEl) return;
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (!modal) modal = new bootstrap.Modal(modalEl);

            var isShown = modalEl.classList.contains('show');
            if (isShown) {
                modal.hide();
            } else {
                var ta = document.getElementById('avdNotesTextarea');
                if (ta) {
                    try {
                        ta.value = localStorage.getItem(avdNotesStorageKey) || '';
                    } catch(e) {}
                }
                modal.show();
                if (window.AvdAudio) window.AvdAudio.playClick();
                setTimeout(function() { if (ta) ta.focus(); }, 350);
            }
        };

        window.avdInsertNoteTimestamp = function() {
            var vid = document.querySelector('video');
            var curTime = (vid && vid.currentTime) ? vid.currentTime : 0;
            var timeStr = avdFormatTime(curTime);
            var ta = document.getElementById('avdNotesTextarea');
            if (!ta) return;

            var insertText = (ta.value.length > 0 && !ta.value.endsWith('\n') ? '\n' : '') + '⏱️ [' + timeStr + '] ';
            var startPos = ta.selectionStart !== undefined ? ta.selectionStart : ta.value.length;
            var endPos = ta.selectionEnd !== undefined ? ta.selectionEnd : ta.value.length;

            ta.value = ta.value.substring(0, startPos) + insertText + ta.value.substring(endPos);
            ta.selectionStart = ta.selectionEnd = startPos + insertText.length;
            ta.focus();
            window.avdSaveNotes();
            if (window.AvdAudio) window.AvdAudio.playChime();
        };

        window.avdSaveNotes = function() {
            var ta = document.getElementById('avdNotesTextarea');
            if (!ta) return;
            try {
                localStorage.setItem(avdNotesStorageKey, ta.value);
            } catch(e) {}
        };

        window.avdCopyNotes = function() {
            var ta = document.getElementById('avdNotesTextarea');
            if (!ta || !ta.value.trim()) {
                if (window.showCopyToast) window.showCopyToast('⚠️ ' + __r('No notes to copy yet'));
                return;
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ta.value).then(function() {
                    if (window.showCopyToast) window.showCopyToast('📋 ' + __r('Notes copied to clipboard!'));
                    if (window.AvdAudio) window.AvdAudio.playChime();
                });
            } else {
                ta.select();
                document.execCommand('copy');
                if (window.showCopyToast) window.showCopyToast('📋 ' + __r('Notes copied!'));
                if (window.AvdAudio) window.AvdAudio.playChime();
            }
        };

        window.avdDownloadNotes = function() {
            var ta = document.getElementById('avdNotesTextarea');
            var text = ta ? ta.value : '';
            if (!text.trim()) {
                if (window.showCopyToast) window.showCopyToast('⚠️ ' + __r('No notes to export yet'));
                return;
            }
            var blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'video_' + avdVideoUuid.substring(0, 8) + '_notes.txt';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            if (window.showCopyToast) window.showCopyToast('💾 ' + __r('Notes exported as .txt!'));
            if (window.AvdAudio) window.AvdAudio.playChime();
        };

        function avdGetVideo() {
            var v = document.querySelector('video');
            if (v) return v;
            var ifrs = document.querySelectorAll('iframe');
            for (var i = 0; i < ifrs.length; i++) {
                try {
                    var iv = ifrs[i].contentDocument?.querySelector('video') || ifrs[i].contentWindow?.document?.querySelector('video');
                    if (iv) return iv;
                } catch(e) {}
            }
            return null;
        }

        // Floating Volume HUD & Arrow Key Controller
        var avdVolumeHudTimer = null;
        window._avdVirtualVolume = 1.0;
        window.avdAdjustVolume = function(delta) {
            var vid = avdGetVideo();
            var curVol = vid ? vid.volume : (window._avdVirtualVolume !== undefined ? window._avdVirtualVolume : 1.0);
            if (vid && vid.muted) vid.muted = false;
            var newVol = Math.max(0, Math.min(1, Math.round((curVol + delta) * 100) / 100));
            window._avdVirtualVolume = newVol;
            if (vid) vid.volume = newVol;

            var hud = document.getElementById('avdVolumeHud');
            var text = document.getElementById('avdVolumeText');
            var bar = document.getElementById('avdVolumeBar');
            var icon = document.getElementById('avdVolumeIcon');
            var pct = Math.round(newVol * 100);

            if (text) text.textContent = pct + '%';
            if (bar) bar.style.width = pct + '%';
            if (icon) {
                icon.className = pct === 0 ? 'fa-solid fa-volume-xmark text-danger' :
                                 (pct < 50 ? 'fa-solid fa-volume-low text-info' : 'fa-solid fa-volume-high text-info');
            }

            if (hud) {
                hud.style.opacity = '1';
                hud.style.transform = 'translate(-50%, -50%) scale(1)';
                if (avdVolumeHudTimer) clearTimeout(avdVolumeHudTimer);
                avdVolumeHudTimer = setTimeout(function() {
                    hud.style.opacity = '0';
                    hud.style.transform = 'translate(-50%, -50%) scale(0.9)';
                }, 1100);
            }
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Floating Seek HUD Controller
        var avdSeekHudTimer = null;
        window._avdVirtualTime = 0;
        window.avdRelativeSeek = function(deltaSecs) {
            var vid = avdGetVideo();
            var curTime = vid ? vid.currentTime : (window._avdVirtualTime || 0);
            var maxTime = (vid && vid.duration) ? vid.duration : 999999;
            var targetTime = Math.max(0, Math.min(maxTime, curTime + deltaSecs));
            window._avdVirtualTime = targetTime;
            if (vid) vid.currentTime = targetTime;

            var hud = document.getElementById('avdSeekHud');
            var text = document.getElementById('avdSeekText');
            var icon = document.getElementById('avdSeekIcon');

            if (text) {
                var sign = deltaSecs > 0 ? '+' : '';
                text.textContent = sign + deltaSecs + 's (' + avdFormatTime(targetTime) + ')';
            }
            if (icon) {
                icon.innerHTML = deltaSecs > 0 ? '<i class="fa-solid fa-forward text-warning"></i>' : '<i class="fa-solid fa-backward text-warning"></i>';
            }

            if (hud) {
                hud.style.opacity = '1';
                hud.style.transform = 'translate(-50%, -50%) scale(1)';
                if (avdSeekHudTimer) clearTimeout(avdSeekHudTimer);
                avdSeekHudTimer = setTimeout(function() {
                    hud.style.opacity = '0';
                    hud.style.transform = 'translate(-50%, -50%) scale(0.9)';
                }, 1100);
            }
            if (window.AvdAudio) window.AvdAudio.playWhoosh();
        };

        // Smart Playback Resume Engine
        var avdResumeStorageKey = 'avd_resume_' + avdVideoUuid;
        var avdPendingResumeTime = 0;

        try {
            var savedPos = parseInt(localStorage.getItem(avdResumeStorageKey), 10);
            if (!isNaN(savedPos) && savedPos > 10) {
                avdPendingResumeTime = savedPos;
                var banner = document.getElementById('avdResumeBanner');
                var label = document.getElementById('avdResumeTimeLabel');
                if (banner && label) {
                    label.textContent = avdFormatTime(savedPos);
                    banner.classList.remove('d-none');
                    banner.classList.add('d-flex');
                    setTimeout(function() {
                        if (banner && !banner.classList.contains('d-none')) {
                            banner.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                            banner.style.opacity = '0';
                            banner.style.transform = 'translateY(-16px)';
                            setTimeout(function() { banner.classList.add('d-none'); }, 400);
                        }
                    }, 12000);
                }
            }
        } catch(e) {}

        window.avdAcceptResume = function() {
            var banner = document.getElementById('avdResumeBanner');
            if (banner) banner.classList.add('d-none');
            var vid = document.querySelector('video');
            if (vid && avdPendingResumeTime > 0) {
                vid.currentTime = avdPendingResumeTime;
                if (vid.paused) vid.play();
                if (window.AvdAudio) window.AvdAudio.playWhoosh();
                if (window.showCopyToast) window.showCopyToast('⏩ ' + avdAt(__r('Resumed playback from :time'), avdFormatTime(avdPendingResumeTime)));
            }
        };

        window.avdDismissResume = function() {
            var banner = document.getElementById('avdResumeBanner');
            if (banner) banner.classList.add('d-none');
            if (window.AvdAudio) window.AvdAudio.playClick();
        };

        // Track playback position periodically for resume
        setInterval(function() {
            var vid = document.querySelector('video');
            if (!vid || vid.paused || !vid.currentTime || !vid.duration) return;
            if (vid.currentTime > 10 && vid.currentTime < vid.duration - 15) {
                try {
                    localStorage.setItem(avdResumeStorageKey, Math.floor(vid.currentTime));
                } catch(e) {}
            } else if (vid.currentTime >= vid.duration - 10) {
                try {
                    localStorage.removeItem(avdResumeStorageKey);
                } catch(e) {}
            }
        }, 3000);

        // Automatic PiP on scroll past the stable player slot. The provider's
        // <video-player> mounts asynchronously, so bind it after each update.
        avdPipInit();

        // Active Playback Performance Engine: pause decorative animations when media plays
        function avdSetPlaybackActive(active) {
            document.body.classList.toggle('avd-playback-active', !!active);
        }

        window.addEventListener('message', function(e) {
            if (!e.data || !avdPipFrame || e.source !== avdPipFrame.contentWindow) return;
            avdPipBindMedia();
            // A frame keeps its WindowProxy while navigating. Ignore queued
            // messages from its old document until the current source loads.
            if (!avdPipReady) return;
            try {
                var frameSource = avdPipFrameSource();
                if (!frameSource) return;
                var frameOrigin = frameSource.origin;
                var approvedProviderOrigins = [
                    'https://upload18.org', 'https://upload18.net', 'https://upload18.com',
                    'https://upload18.cc', 'https://helvid.com', 'https://321watch.workers.dev'
                ];
                if (e.origin !== frameOrigin && approvedProviderOrigins.indexOf(e.origin) === -1) return;
            } catch (originError) { return; }
            try {
                var data = typeof e.data === 'string' ? JSON.parse(e.data) : e.data;
                var evt = (data.event || data.type || (data.name ? data.name : '')).toString().toLowerCase();
                if (/^(play|playing|timeupdate)$/.test(evt)) {
                    avdPipMarkPlaying();
                    avdSetPlaybackActive(true);
                } else if (/^(pause|complete|ended|finish|stop)$/.test(evt)) {
                    avdPipMarkPaused();
                    avdSetPlaybackActive(false);
                }
            } catch(err) {}
        });

    

        // watch-7: the phone tool sheet and the video details dialog render on first use (<template data-avd-lazy>,
        // core.ts). A panel rendered after the player's state changed is brought up to date once, as the tools would
        // have left it had it been there.
        function avdMarkChips(selector, matches) {
            document.querySelectorAll(selector).forEach(function(chip) {
                var on = matches(chip.getAttribute('onclick') || '');
                chip.classList.toggle('active', on);
                chip.classList.toggle('btn-primary', on);
                chip.classList.toggle('btn-outline-secondary', !on);
            });
        }

        function avdSyncRenderedPanel(id) {
            var frame = document.querySelector('.avd-player-frame');
            var glow = document.getElementById('avdAmbilightGlow');
            var preset = avdFilters[avdCurrentFilterIdx] || 'normal';
            var filterName = avdFilterNames[preset] || preset;
            if (id === 'avdMobileToolsSheet') {
                var sheet = document.getElementById('avdMobileToolsSheet');
                if (!sheet) return;
                avdBindMobileSheet(sheet);
                var badge = document.getElementById('avdMobileFilterBadge');
                if (badge) badge.textContent = filterName;
                avdMarkChips('.avd-mob-filter-chip', function(onclick) { return onclick.indexOf("'" + preset + "'") !== -1; });
                if (avdCurrentRotation !== 0) {
                    var rotate = document.getElementById('avdMobileRotateBadge');
                    if (rotate) rotate.textContent = avdCurrentRotation + '°';
                    avdMarkChips('.avd-mob-rotate-chip', function(onclick) { return onclick.indexOf(String(avdCurrentRotation)) !== -1; });
                }
                var level = frame && frame.classList.contains('avd-bright-boost') ? 'boost' : (frame && frame.classList.contains('avd-bright-dim') ? 'dim' : 'normal');
                if (level !== 'normal') {
                    var bright = document.getElementById('avdMobileBrightBadge');
                    if (bright) bright.textContent = level === 'boost' ? '+15%' : '-15%';
                    avdMarkChips('.avd-mob-bright-chip', function(onclick) { return onclick.indexOf(level) !== -1; });
                }
                var mode = glow && glow.classList.contains('glow-pulse') ? 'pulse' : (glow && glow.classList.contains('glow-neon') ? 'neon' : (glow && glow.classList.contains('glow-warm') ? 'warm' : 'theme'));
                if (mode !== 'theme') {
                    var ambi = document.getElementById('avdMobileAmbiBadge');
                    if (ambi) ambi.textContent = {'pulse': __r('Pulse'), 'neon': __r('Neon'), 'warm': __r('Warm')}[mode];
                    avdMarkChips('.avd-mob-ambi-chip', function(onclick) { return onclick.indexOf(mode) !== -1; });
                }
                avdUpdatePlayerCapabilities();
            } else if (id === 'avdTelemetryModal') {
                var telemFilter = document.getElementById('avdTelemFilter');
                if (telemFilter) telemFilter.textContent = filterName;
                if (avdCurrentAspectIndex !== 0) {
                    var telemAspect = document.getElementById('avdTelemAspect');
                    if (telemAspect) telemAspect.textContent = avdAspectModes[avdCurrentAspectIndex].hud;
                }
                if (frame && frame.classList.contains('avd-mirrored')) {
                    var telemMirror = document.getElementById('avdTelemMirror');
                    if (telemMirror) {
                        telemMirror.textContent = __r('On');
                        telemMirror.className = 'badge bg-warning text-dark';
                    }
                }
                // the server printed the glow's state at load; it changes only by the A key or a glow mode
                var telemAmbi = document.getElementById('avdTelemAmbilight');
                if (telemAmbi && glow && (!avdAmbientGlowAllowed || !glow.classList.contains('active'))) {
                    telemAmbi.textContent = avdAmbientGlowDisabledLabel;
                }
            }
        }

        document.addEventListener('avd:lazy-rendered', function(event) {
            var id = event && event.detail ? event.detail.id : '';
            if (id === 'avdMobileToolsSheet' || id === 'avdTelemetryModal') avdSyncRenderedPanel(id);
        });
        // Rendered before this module ran (its fallback path loads it late): up to date now.
        ['avdMobileToolsSheet', 'avdTelemetryModal'].forEach(function(id) {
            if (document.getElementById(id) && !document.querySelector('template[data-avd-lazy="' + id + '"]')) avdSyncRenderedPanel(id);
        });

    // The view's stubs (videos/show.blade.php) queued the calls the page's onclick attributes made before this module
    // ran; every name is the real function now: replay them, then take later pushes directly.
    var queued = Array.isArray(window.__avdWatchQ) ? window.__avdWatchQ : [];
    window.__avdWatchQ = {
        push: function (entry) {
            var fn = entry && window[entry[0]];
            if (typeof fn === 'function' && !fn.avdQueued) {
                try {
                    fn.apply(window, entry[1] || []);
                } catch (error) {
                    console.error(error);
                }
            }
            return 0;
        },
    };
    queued.forEach(function (entry) {
        window.__avdWatchQ.push(entry);
    });
})();

// A module (the page loads it with <script type="module">; core.ts imports it as the fallback).
export {};
