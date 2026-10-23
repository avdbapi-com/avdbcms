// Seasonal ambient particles of the Daily Aesthetic badge (perf plan step 25c): the layout's canvas
// engine, moved out of the inline HUD script into a chunk that only the rich tier downloads (lower
// tiers never ran it: their canvas is hidden by resources/js/layout/core.ts without this chunk).
//
// Each colour's glow is drawn once into a small sprite per core size, with the same shadowBlur 8 as
// before, so a frame is one drawImage per particle: no per-particle shadow blur, save or restore. It
// draws at ~30 fps with time-scaled steps (the old 60 fps speed on any refresh rate) and pauses while
// the page is hidden or a touch scroll runs ('avd:scroll-activity'). A tier downgrade ('avd:tier')
// stops it and hides the canvas.
import {glowParticleAlpha, store, type DailyConfig} from './config';

type Particle = {
    x: number;
    y: number;
    radius: number;
    color: string;
    sprite: HTMLCanvasElement;
    vx: number;
    vy: number;
    alpha: number;
    baseAlpha: number;
    phase: number;
};

declare global {
    interface Window {
        avdParticles?: Particle[];
        toggleSeasonalAtmosphere?: (on: boolean) => void;
    }
}

const rich = (): boolean => !!(window.AVD_TIER && window.AVD_TIER.at('rich'));

// Balanced / lite: no particle layer; the HUD toggle still records the choice.
export const hideAtmosphere = (canvas: HTMLCanvasElement): void => {
    canvas.style.display = 'none';
    window.toggleSeasonalAtmosphere = (on: boolean) => {
        store.set('avd_atmosphere_enabled', on ? '1' : '0');
    };
};

export const startAtmosphere = (canvas: HTMLCanvasElement, daily: DailyConfig, initiallyEnabled: boolean): void => {
    const ctx = rich() && canvas.getContext ? canvas.getContext('2d') : null;

    if (!ctx) {
        hideAtmosphere(canvas);
        return;
    }

    let enabled = initiallyEnabled;
    const particles: Particle[] = [];
    window.avdParticles = particles;
    const numParticles = 35;
    let animId: number | null = null;
    let last = 0;
    let scrolling = false;
    let stopped = false;
    const colors = [daily.primary, daily.accent, '#ffffff'];
    const SPRITE = 32;
    const HALF = SPRITE / 2;
    const sprites: Record<string, HTMLCanvasElement> = {};
    // The saved personal glow level (the HUD restored it onto the particles at load).
    const savedGlow = store.get('avd_personal_glow');
    const baseAlpha = savedGlow ? glowParticleAlpha(savedGlow) : 0.65;

    // Core radius 0.8-3 px rounds to a 1, 2 or 3 px sprite; the 8 px glow dominates.
    const sprite = (color: string, radius: number): HTMLCanvasElement => {
        const core = Math.max(1, Math.min(3, Math.round(radius)));
        const key = color + '|' + core;
        if (!sprites[key]) {
            const c = document.createElement('canvas');
            c.width = SPRITE;
            c.height = SPRITE;
            const g = c.getContext('2d');
            if (g) {
                g.fillStyle = color;
                g.shadowBlur = 8;
                g.shadowColor = color;
                g.beginPath();
                g.arc(HALF, HALF, core, 0, Math.PI * 2);
                g.fill();
            }
            sprites[key] = c;
        }
        return sprites[key];
    };

    // The backing store follows the viewport, but height-only changes under 160 px (mobile toolbars
    // showing and hiding) keep it, instead of clearing and reallocating the canvas on every one of them.
    let boxW = 0;
    let boxH = 0;
    const fit = (width: number, height: number): void => {
        width = Math.round(width);
        height = Math.round(height);
        if (!width || !height) return;
        if (width === boxW && Math.abs(height - boxH) < 160) return;
        boxW = width;
        boxH = height;
        canvas.width = width;
        canvas.height = height;
    };
    fit(window.innerWidth, window.innerHeight);
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver((entries) => {
            const box = entries[entries.length - 1].contentRect;
            fit(box.width, box.height);
        }).observe(canvas);
    } else {
        window.addEventListener('resize', () => fit(window.innerWidth, window.innerHeight), {passive: true});
    }

    for (let i = 0; i < numParticles; i++) {
        const radius = Math.random() * 2.2 + 0.8;
        const color = colors[Math.floor(Math.random() * colors.length)];
        particles.push({
            x: Math.random() * canvas.width,
            y: Math.random() * canvas.height,
            radius,
            color,
            sprite: sprite(color, radius),
            vx: (Math.random() - 0.5) * 0.4,
            vy: -Math.random() * 0.45 - 0.15,
            alpha: Math.random() * 0.5 + 0.2,
            baseAlpha,
            phase: Math.random() * Math.PI * 2,
        });
    }

    const wanted = (): boolean => enabled && !stopped && !scrolling && !document.hidden;

    const draw = (now: number): void => {
        animId = null;
        if (!wanted()) return;
        animId = requestAnimationFrame(draw);
        // ~30 fps: a step is skipped until 30 ms have passed, and the motion is scaled by the time
        // since the last drawn frame (capped after pauses).
        if (last && now - last < 30) return;
        const k = last ? Math.min(4, (now - last) / 16.667) : 1;
        last = now;
        const w = canvas.width;
        const h = canvas.height;
        ctx.clearRect(0, 0, w, h);
        for (let i = 0; i < particles.length; i++) {
            const p = particles[i];
            p.phase += 0.02 * k;
            p.x += (p.vx + Math.sin(p.phase) * 0.2) * k;
            p.y += p.vy * k;

            if (p.y < -10) {
                p.y = h + 10;
                p.x = Math.random() * w;
            }
            if (p.x < -10) p.x = w + 10;
            if (p.x > w + 10) p.x = -10;

            ctx.globalAlpha = p.alpha * (p.baseAlpha || 0.65);
            ctx.drawImage(p.sprite, p.x - HALF, p.y - HALF);
        }
        ctx.globalAlpha = 1;
    };

    const sync = (): void => {
        if (wanted()) {
            if (animId === null) {
                last = 0;
                animId = requestAnimationFrame(draw);
            }
        } else if (animId !== null) {
            cancelAnimationFrame(animId);
            animId = null;
        }
    };

    document.addEventListener('visibilitychange', sync);
    document.addEventListener('avd:scroll-activity', (event) => {
        const detail = (event as CustomEvent<{active?: boolean}>).detail;
        scrolling = !!(detail && detail.active === true);
        sync();
    });
    document.addEventListener('avd:tier', () => {
        if (rich()) return;
        stopped = true;
        sync();
        canvas.style.display = 'none';
    });

    window.toggleSeasonalAtmosphere = (on: boolean) => {
        enabled = !!on;
        canvas.style.display = enabled && !stopped ? 'block' : 'none';
        sync();
        store.set('avd_atmosphere_enabled', enabled ? '1' : '0');
    };

    if (!enabled) canvas.style.display = 'none';
    sync();
};
