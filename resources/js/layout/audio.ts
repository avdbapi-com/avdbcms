// UI sound synthesis (perf plan step 25c): window.AvdAudio (watch-page controls, the Daily HUD, the
// shortcuts) and window.avdPlaySound (html[data-avd-audio="on"] click sounds, the admin Theme Studio)
// on ONE AudioContext, where the layout used to create up to three. The context is created by the
// first sound (a user gesture, as before) and suspended about a second after the last one, so an
// idle page keeps no audio thread running; the next sound resumes it.
//
// Sounds are identical to before: with the Daily Aesthetic badge (DailyConfig present) the badge HUD's
// engine replaced the default one, so its recipes and its toggleSound() apply; it now also has
// playShutter(), which the watch page's frame capture calls (show.blade.php) and which used to throw
// there. The object carries the union of both engines' members.
import {byId, layoutConfig, store} from './config';

type AudioCtor = typeof AudioContext;

declare global {
    interface Window {
        webkitAudioContext?: AudioCtor;
        avdAudioCtx: AudioContext | null;
        avdPlaySound: (type?: string) => void;
        AvdAudio: AvdAudioApi;
    }
}

export type AvdAudioApi = {
    ctx: AudioContext | null;
    enabled: boolean;
    getContext(): AudioContext | null;
    isEnabled(): boolean;
    isSoundOn(): boolean;
    toggleSound(enable?: boolean): boolean | undefined;
    playChime(): void;
    playWhoosh(): void;
    playClick(): void;
    playShutter(): void;
};

// A recipe schedules its nodes and returns when its last node stops (seconds from now).
type Recipe = (ctx: AudioContext, now: number) => number;

const tone = (ctx: AudioContext, type: OscillatorType): [OscillatorNode, GainNode] => {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = type;
    osc.connect(gain);
    gain.connect(ctx.destination);
    return [osc, gain];
};

// The default engine's recipes.
const classic: Record<'chime' | 'whoosh' | 'click' | 'shutter', Recipe> = {
    chime(ctx, t) {
        [659.25, 830.61, 987.77].forEach((freq, idx) => {
            const [osc, gain] = tone(ctx, 'sine');
            osc.frequency.setValueAtTime(freq, t + idx * 0.08);
            gain.gain.setValueAtTime(0, t + idx * 0.08);
            gain.gain.linearRampToValueAtTime(0.18, t + idx * 0.08 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, t + idx * 0.08 + 0.55);
            osc.start(t + idx * 0.08);
            osc.stop(t + idx * 0.08 + 0.55);
        });
        return 0.71;
    },
    whoosh(ctx, t) {
        const [osc, gain] = tone(ctx, 'sine');
        osc.frequency.setValueAtTime(170, t);
        osc.frequency.exponentialRampToValueAtTime(45, t + 0.28);
        gain.gain.setValueAtTime(0.2, t);
        gain.gain.exponentialRampToValueAtTime(0.001, t + 0.28);
        osc.start(t);
        osc.stop(t + 0.28);
        return 0.28;
    },
    click(ctx, t) {
        const [osc, gain] = tone(ctx, 'triangle');
        osc.frequency.setValueAtTime(520, t);
        osc.frequency.exponentialRampToValueAtTime(140, t + 0.04);
        gain.gain.setValueAtTime(0.12, t);
        gain.gain.exponentialRampToValueAtTime(0.001, t + 0.04);
        osc.start(t);
        osc.stop(t + 0.04);
        return 0.04;
    },
    shutter(ctx, t) {
        const [osc1, gain1] = tone(ctx, 'triangle');
        osc1.frequency.setValueAtTime(850, t);
        osc1.frequency.exponentialRampToValueAtTime(150, t + 0.05);
        gain1.gain.setValueAtTime(0.25, t);
        gain1.gain.exponentialRampToValueAtTime(0.001, t + 0.05);
        osc1.start(t);
        osc1.stop(t + 0.05);

        const [osc2, gain2] = tone(ctx, 'sine');
        osc2.frequency.setValueAtTime(420, t + 0.06);
        osc2.frequency.exponentialRampToValueAtTime(70, t + 0.12);
        gain2.gain.setValueAtTime(0.2, t + 0.06);
        gain2.gain.exponentialRampToValueAtTime(0.001, t + 0.12);
        osc2.start(t + 0.06);
        osc2.stop(t + 0.12);
        return 0.12;
    },
};

// The Daily Aesthetic badge engine's recipes (it had no shutter: the classic one is used).
const badge: Record<'chime' | 'whoosh' | 'click', Recipe> = {
    chime(ctx, now) {
        [659.25, 830.61, 987.77].forEach((freq, idx) => {
            const [osc, gain] = tone(ctx, 'sine');
            osc.frequency.setValueAtTime(freq, now + idx * 0.07);
            gain.gain.setValueAtTime(0.001, now + idx * 0.07);
            gain.gain.exponentialRampToValueAtTime(0.12, now + idx * 0.07 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + idx * 0.07 + 0.42);
            osc.start(now + idx * 0.07);
            osc.stop(now + idx * 0.07 + 0.45);
        });
        return 0.59;
    },
    whoosh(ctx, now) {
        const [osc, gain] = tone(ctx, 'triangle');
        osc.frequency.setValueAtTime(170, now);
        osc.frequency.exponentialRampToValueAtTime(45, now + 0.32);
        gain.gain.setValueAtTime(0.001, now);
        gain.gain.exponentialRampToValueAtTime(0.12, now + 0.06);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.35);
        osc.start(now);
        osc.stop(now + 0.38);
        return 0.38;
    },
    click(ctx, now) {
        const [osc, gain] = tone(ctx, 'sine');
        osc.frequency.setValueAtTime(880, now);
        gain.gain.setValueAtTime(0.06, now);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.05);
        osc.start(now);
        osc.stop(now + 0.06);
        return 0.06;
    },
};

// window.avdPlaySound's recipes.
const ui: Record<'chime' | 'thud' | 'tick', Recipe> = {
    chime(ctx, now) {
        // Ascending 4-note chime: C5 (523Hz), E5 (659Hz), G5 (784Hz), C6 (1046Hz)
        [523.25, 659.25, 783.99, 1046.50].forEach((freq, idx) => {
            const [osc, gain] = tone(ctx, 'sine');
            osc.frequency.setValueAtTime(freq, now + idx * 0.055);
            gain.gain.setValueAtTime(0, now + idx * 0.055);
            gain.gain.linearRampToValueAtTime(0.08, now + idx * 0.055 + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + idx * 0.055 + 0.18);
            osc.start(now + idx * 0.055);
            osc.stop(now + idx * 0.055 + 0.19);
        });
        return 0.355;
    },
    thud(ctx, now) {
        // Deep gentle 160Hz damped pulse
        const [osc, gain] = tone(ctx, 'triangle');
        osc.frequency.setValueAtTime(160, now);
        osc.frequency.exponentialRampToValueAtTime(60, now + 0.08);
        gain.gain.setValueAtTime(0.12, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.08);
        osc.start(now);
        osc.stop(now + 0.09);
        return 0.09;
    },
    tick(ctx, now) {
        // Crisp 800Hz subtle micro-tick
        const [osc, gain] = tone(ctx, 'sine');
        osc.frequency.setValueAtTime(800, now);
        osc.frequency.exponentialRampToValueAtTime(400, now + 0.035);
        gain.gain.setValueAtTime(0.09, now);
        gain.gain.exponentialRampToValueAtTime(0.001, now + 0.035);
        osc.start(now);
        osc.stop(now + 0.04);
        return 0.04;
    },
};

const SUSPEND_AFTER_MS = 1000;

let context: AudioContext | null = null;
let suspendTimer = 0;
// AvdAudio.getContext() handed the context out: other code may play through it, so it is never
// suspended automatically from then on.
let handedOut = false;

const quiet = (promise: unknown): void => {
    if (promise && typeof (promise as Promise<void>).catch === 'function') {
        (promise as Promise<void>).catch(() => undefined);
    }
};

const ensureContext = (): AudioContext | null => {
    if (!context) {
        const Ctor = window.AudioContext || window.webkitAudioContext;

        if (Ctor) {
            context = new Ctor();
            window.avdAudioCtx = context;
        }
    }

    if (context && context.state === 'suspended') {
        quiet(context.resume());
    }

    return context;
};

const suspendLater = (seconds: number): void => {
    window.clearTimeout(suspendTimer);

    if (handedOut) {
        return;
    }

    suspendTimer = window.setTimeout(() => {
        suspendTimer = 0;

        if (!handedOut && context && context.state === 'running' && typeof context.suspend === 'function') {
            quiet(context.suspend());
        }
    }, Math.ceil(seconds * 1000) + SUSPEND_AFTER_MS);
};

const play = (recipe: Recipe): void => {
    try {
        const ctx = ensureContext();

        if (!ctx) {
            return;
        }

        suspendLater(recipe(ctx, ctx.currentTime));
    } catch {
        // Blocked by the browser's autoplay policy, or no audio device.
    }
};

export const installAudio = (): void => {
    const config = layoutConfig();

    if (!config) {
        return;
    }

    const texts = config.t;
    const daily = config.daily !== null;
    let enabled = store.get('avd_sound_fx') !== '0';

    const playChime = () => {
        if (enabled) play(daily ? badge.chime : classic.chime);
    };

    // The Daily HUD's Audio FX button names the current state, with either engine and from the start
    // (the server always renders 'ON'; the badge engine never updated it).
    const syncSoundText = (): void => {
        const text = byId('avdSoundToggleText');
        const label = enabled ? texts.audioOn : texts.audioOff;
        if (text && text.textContent !== label) text.textContent = label;
    };

    const api: AvdAudioApi = {
        get ctx() {
            return context;
        },
        set ctx(value: AudioContext | null) {
            context = value;
        },
        get enabled() {
            return enabled;
        },
        set enabled(value: boolean) {
            enabled = value;
        },
        getContext() {
            handedOut = true;
            window.clearTimeout(suspendTimer);
            return ensureContext();
        },
        isEnabled: () => enabled,
        isSoundOn: () => enabled,
        toggleSound(enable?: boolean) {
            if (!daily) {
                enabled = !enabled;
                store.set('avd_sound_fx', enabled ? '1' : '0');
                syncSoundText();
                if (enabled) playChime();
                return undefined;
            }

            enabled = enable !== undefined ? enable : !enabled;
            store.set('avd_sound_fx', enabled ? '1' : '0');
            syncSoundText();
            document.querySelectorAll<HTMLInputElement>('.avd-sound-toggle-input').forEach((toggle) => {
                toggle.checked = enabled;
            });
            if (enabled) playChime();
            if (window.showCopyToast) window.showCopyToast(enabled ? texts.audioEnabled : texts.audioMuted);
            return enabled;
        },
        playChime,
        playWhoosh() {
            if (enabled) play(daily ? badge.whoosh : classic.whoosh);
        },
        playClick() {
            if (enabled) play(daily ? badge.click : classic.click);
        },
        playShutter() {
            if (enabled) play(classic.shutter);
        },
    };

    window.AvdAudio = api;
    window.avdAudioCtx = null;
    syncSoundText();

    // Web Audio API micro-haptics (html[data-avd-audio="on"]); 'force' and 'preview' play regardless.
    window.avdPlaySound = (type?: string) => {
        const audioEnabled = document.documentElement.getAttribute('data-avd-audio') === 'on';
        if (!audioEnabled && type !== 'force' && type !== 'preview') return;

        if (type === 'chime' || type === 'preset_applied' || type === 'success' || type === 'preview') {
            play(ui.chime);
        } else if (type === 'thud' || type === 'toggle_off') {
            play(ui.thud);
        } else {
            play(ui.tick);
        }
    };

    document.addEventListener('click', (event) => {
        if (document.documentElement.getAttribute('data-avd-audio') !== 'on') return;
        const target = (event.target as Element | null)?.closest?.('button, .btn, .nav-link, .avd-preset-card, .form-check-input, a.dropdown-item');
        if (target) {
            window.avdPlaySound('click');
        }
    }, {passive: true});
};
