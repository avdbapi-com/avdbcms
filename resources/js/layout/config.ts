// Data and small helpers for the layout modules (perf plan step 25): resources/js/layout/core.ts,
// audio.ts, daily-hud.ts and decor.ts, which replace the static inline scripts at the end of
// resources/views/components/layout.blade.php.
//
// The layout emits their translations and settings once, as
// <script type="application/json" id="avd-i18n">, instead of Blade tokens inside the scripts. Every
// string there is plain text (the __() value itself, perf plan 4c): it goes into textContent, title
// attributes and canvas text as it is, and through escapeHtml() into any markup (avdToast). Pages of
// other layouts (the Upload18 embed shell, the maintenance page) have no such element: layoutConfig()
// is null there and the modules stay idle.

export type LayoutTexts = {
    lightsOut: string;
    lightsRestored: string;
    audioOn: string;
    audioOff: string;
    audioEnabled: string;
    audioMuted: string;
    copied: string;
    copiedColon: string;
    wallpaperDownloaded: string;
    cardDownloaded: string;
    wlPromptTitle: string;
    wlPromptMessage: string;
    wlQuickSaveMessage: string;
    wlSavedTitle: string;
    wlTitle: string;
    wlSaved: string;
    wlRemoved: string;
    wlFailed: string;
};

export type LayoutUser = {
    isAuthenticated: boolean;
    id: number | null;
    loginUrl: string;
    registerUrl: string;
    watchLaterIds: number[];
};

// The wallpaper card the HUD script always defined (window.generateAestheticCard without the badge).
export type DailyCardConfig = {
    name: string;
    day: string;
    date: string;
    mood: string;
};

// The Daily Aesthetic badge and HUD (daily design active and its badge on). Its presence selects the
// HUD script that ran with the badge: its sounds, glow, card and particles.
export type DailyConfig = {
    primaryRgb: string;
    primary: string;
    secondary: string;
    accent: string;
    day: string;
    dateLabel: string;
    name: string;
    seasonMood: string;
    layout: string;
    surface: string;
    typography: string;
    slug: string;
};

export type LayoutConfig = {
    t: LayoutTexts;
    theaterMemory: boolean;
    copyToast: boolean;
    primaryColor: string;
    themePreset: string;
    user: LayoutUser;
    dailyCard: DailyCardConfig;
    daily: DailyConfig | null;
};

let cached: LayoutConfig | null | undefined;

export const layoutConfig = (): LayoutConfig | null => {
    if (cached !== undefined) {
        return cached;
    }

    cached = null;
    const element = document.getElementById('avd-i18n');

    if (element) {
        try {
            cached = JSON.parse(element.textContent || '') as LayoutConfig;
        } catch {
            cached = null;
        }
    }

    return cached;
};

// Storage may throw (storage-blocked WebViews, some private modes): a failed access never aborts a
// feature.
export const store = {
    get(key: string): string | null {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key: string, value: string): void {
        try {
            window.localStorage.setItem(key, value);
        } catch {
            // ignored
        }
    },
    del(key: string): void {
        try {
            window.localStorage.removeItem(key);
        } catch {
            // ignored
        }
    },
};

export const byId = <T extends HTMLElement = HTMLElement>(id: string): T | null => document.getElementById(id) as T | null;

const HTML_ESCAPES: Record<string, string> = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'};

// Plain text (a translation, a server message, a URL) for a place that takes markup: avdToast() builds
// its toast with innerHTML, so its callers here pass their text through this.
export const escapeHtml = (text: unknown): string => String(text).replace(/[&<>"']/g, (char) => HTML_ESCAPES[char]);

// '#rgb' or '#rrggbb' -> 'r, g, b'.
export const hexToRgb = (hex: string): string => {
    let c = hex.replace('#', '');
    if (c.length === 3) c = c[0] + c[0] + c[1] + c[1] + c[2] + c[2];
    const num = parseInt(c, 16);
    return [(num >> 16) & 255, (num >> 8) & 255, num & 255].join(', ');
};

// Personal glow levels of the badge HUD: the --avd-brand-soft alpha and the particle alpha.
export const glowFactor = (mode: string): string => (mode === 'vivid' ? '0.5' : (mode === 'subtle' ? '0.12' : '0.28'));
export const glowParticleAlpha = (mode: string): number => (mode === 'vivid' ? 0.95 : (mode === 'subtle' ? 0.35 : 0.65));

// The badge HUD's glow buttons (.glow-btn[data-glow], one active), and those of its former second modal
// (#avdGlowModeGroup), if a page has them. A value other than subtle or vivid is the balanced glow, as in
// glowFactor().
export const syncGlowGroup = (mode: string): void => {
    const level = mode === 'subtle' || mode === 'vivid' ? mode : 'balanced';
    document.querySelectorAll<HTMLElement>('.glow-btn[data-glow]').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.glow === level);
    });

    const group = byId('avdGlowModeGroup');

    if (group) {
        group.querySelectorAll('button').forEach((btn) => {
            const active = (btn.textContent || '').toLowerCase().trim() === mode;
            btn.classList.toggle('active', active);
            btn.classList.toggle('text-white', active);
            btn.classList.toggle('text-white-50', !active);
        });
    }
};

// Errors in one feature never stop the others (nor the module bundle that imports them): they are
// rethrown on their own task, so they still reach the console.
export const safely = (feature: () => void): void => {
    try {
        feature();
    } catch (error) {
        window.setTimeout(() => {
            throw error;
        }, 0);
    }
};
