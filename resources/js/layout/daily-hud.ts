// The Daily Aesthetic HUD's controls (perf plan step 25d): palette copy, personal glow, live custom
// accent, "Surprise Me" and the wallpaper card. resources/js/layout/core.ts imports this chunk on the
// first openDailyAestheticModal() call (or the first call of one of these names); until then each name
// is a stub that loads the chunk and forwards the call. With the badge on (DailyConfig present) the
// badge HUD's own glow and card replace the default ones and copyToClipboard() exists, as before.
import {byId, glowFactor, glowParticleAlpha, hexToRgb, layoutConfig, store, syncGlowGroup, type DailyCardConfig, type DailyConfig, type LayoutConfig} from './config';

declare global {
    interface Window {
        copyColorHex: (hex: string, name: string) => void;
        setPersonalGlow: (level: string) => void;
        setCustomAccent: (hex: string) => void;
        resetCustomAccent: () => void;
        avdSurpriseMe: () => void;
        generateAestheticCard: () => void;
        copyToClipboard?: (text: string) => void;
    }
}

const playClick = () => {
    if (window.AvdAudio) window.AvdAudio.playClick();
};
const playChime = () => {
    if (window.AvdAudio) window.AvdAudio.playChime();
};
const toast = (message: string) => {
    if (window.showCopyToast) window.showCopyToast(message);
};

// Theme collection v2: the layout also names the day's theme and colour style (translated) and gives the number of
// days of the daily design's year (DailyDesignCatalog::daysInYear); older layouts send neither.
type DailyDesign = DailyConfig & {theme?: string; flavor?: string; daysInYear?: number};
type DailyCard = DailyCardConfig & {daysInYear?: number};

// The daily rule numbers the days of the calendar year: 365, or 366 in a leap year. The layout's count (the daily
// design's own year, server time) wins; the browser's calendar year is the fallback.
const yearDays = (fromLayout?: number): number => {
    if (fromLayout && fromLayout > 0) return fromLayout;
    const year = new Date().getFullYear();
    return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0 ? 366 : 365;
};

// The day's design by name: "<theme> · <colour style>" in the visitor's language when the layout sends both.
const designName = (daily: DailyDesign): string => (daily.theme ? daily.theme + (daily.flavor ? ' · ' + daily.flavor : '') : daily.name);

// A colour of the active theme (its token), else the fallback.
const themeColor = (name: string, fallback: string): string => getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;

// '#rrggbb' plus an alpha byte for the canvas glows; any other colour syntax glows transparent.
const withAlpha = (color: string, alpha: string): string => (/^#[0-9a-f]{6}$/i.test(color) ? color + alpha : 'transparent');

// Personal glow of the default HUD.
const setPersonalGlowDefault = (level: string): void => {
    document.querySelectorAll<HTMLElement>('.glow-btn').forEach((b) => {
        b.classList.toggle('active', b.dataset.glow === level);
    });
    const alphas: Record<string, string> = {subtle: '0.3', balanced: '0.65', vivid: '0.95'};
    const alpha = alphas[level] || '0.65';
    document.documentElement.style.setProperty('--avd-glow-alpha', alpha);
    const canvas = byId('avdSeasonalAtmosphere');
    if (canvas) canvas.style.opacity = alpha;
    store.set('avd_glow_level', level);
    playClick();
};

// Personal glow of the badge HUD.
const setPersonalGlowBadge = (daily: DailyConfig) => (mode: string): void => {
    document.documentElement.style.setProperty('--avd-brand-soft', 'rgba(' + daily.primaryRgb + ', ' + glowFactor(mode) + ')');
    if (window.avdParticles) {
        const particleAlpha = glowParticleAlpha(mode);
        window.avdParticles.forEach((p) => {
            p.baseAlpha = particleAlpha;
        });
    }
    syncGlowGroup(mode);
    store.set('avd_personal_glow', mode);
    playClick();
};

// Canvas wallpaper generator of the default HUD: the swatches are the active theme's colours.
const generateCardDefault = (card: DailyCard, texts: LayoutConfig['t'], siteColor: string) => (): void => {
    playChime();
    const canvas = document.createElement('canvas');
    canvas.width = 1200;
    canvas.height = 630;
    const ctx = canvas.getContext('2d') as CanvasRenderingContext2D;

    const pColor = themeColor('--avd-brand', siteColor);
    const sColor = themeColor('--avd-brand-2', pColor);
    const aColor = themeColor('--avd-accent', pColor);

    // Background gradient
    const grad = ctx.createLinearGradient(0, 0, 1200, 630);
    grad.addColorStop(0, '#0b0f19');
    grad.addColorStop(0.5, '#151b2d');
    grad.addColorStop(1, '#090c14');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, 1200, 630);

    // Atmospheric radiant orbs
    const orb1 = ctx.createRadialGradient(250, 200, 20, 250, 200, 400);
    orb1.addColorStop(0, withAlpha(pColor, '55'));
    orb1.addColorStop(1, 'transparent');
    ctx.fillStyle = orb1;
    ctx.fillRect(0, 0, 1200, 630);

    const orb2 = ctx.createRadialGradient(950, 450, 20, 950, 450, 450);
    orb2.addColorStop(0, withAlpha(aColor, '44'));
    orb2.addColorStop(1, 'transparent');
    ctx.fillStyle = orb2;
    ctx.fillRect(0, 0, 1200, 630);

    // Card Frame Border
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.12)';
    ctx.lineWidth = 3;
    ctx.strokeRect(30, 30, 1140, 570);

    // Brand Header
    ctx.fillStyle = pColor;
    ctx.font = 'bold 28px sans-serif';
    ctx.fillText('AVDBCMS  •  DAILY AESTHETIC IDENTITY', 80, 100);

    // Theme Name Title
    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 64px sans-serif';
    ctx.fillText(card.name, 80, 185);

    // Day Badge & Date
    ctx.fillStyle = 'rgba(255, 255, 255, 0.7)';
    ctx.font = '500 24px sans-serif';
    ctx.fillText('Day ' + card.day + ' of ' + yearDays(card.daysInYear) + '  •  ' + card.date, 80, 235);

    // Mood Lore Quote
    ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
    ctx.font = 'italic 26px sans-serif';
    ctx.fillText('"' + card.mood + '"', 80, 310);

    // 3 Color Swatch Cards
    const swatches = [
        {label: 'PRIMARY', color: pColor, x: 80},
        {label: 'SECONDARY', color: sColor, x: 440},
        {label: 'ACCENT', color: aColor, x: 800},
    ];

    swatches.forEach((s) => {
        ctx.fillStyle = 'rgba(255, 255, 255, 0.06)';
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.15)';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.roundRect(s.x, 380, 320, 170, 14);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = s.color;
        ctx.beginPath();
        ctx.roundRect(s.x + 20, 400, 280, 65, 10);
        ctx.fill();

        ctx.fillStyle = 'rgba(255, 255, 255, 0.6)';
        ctx.font = 'bold 16px sans-serif';
        ctx.fillText(s.label, s.x + 25, 495);

        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 22px monospace';
        ctx.fillText(s.color.toUpperCase(), s.x + 25, 525);
    });

    // Trigger Download
    const a = document.createElement('a');
    a.download = 'avdbcms-aesthetic-card-day-' + card.day + '.png';
    a.href = canvas.toDataURL('image/png');
    document.body.appendChild(a);
    a.click();
    a.remove();
    toast(texts.wallpaperDownloaded);
};

// Aesthetic wallpaper & palette card generator of the badge HUD (1200x630 social card).
const generateCardBadge = (daily: DailyDesign, texts: LayoutConfig['t']) => (): void => {
    const canvas = document.createElement('canvas');
    canvas.width = 1200;
    canvas.height = 630;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const font = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    const grad = ctx.createLinearGradient(0, 0, 1200, 630);
    grad.addColorStop(0, '#0a0d14');
    grad.addColorStop(0.5, '#0f172a');
    grad.addColorStop(1, '#020617');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, 1200, 630);

    const glowP = ctx.createRadialGradient(1050, 100, 10, 1050, 100, 480);
    glowP.addColorStop(0, withAlpha(daily.primary, '44'));
    glowP.addColorStop(1, 'transparent');
    ctx.fillStyle = glowP;
    ctx.fillRect(600, 0, 600, 630);

    const glowA = ctx.createRadialGradient(120, 530, 10, 120, 530, 420);
    glowA.addColorStop(0, withAlpha(daily.accent, '33'));
    glowA.addColorStop(1, 'transparent');
    ctx.fillStyle = glowA;
    ctx.fillRect(0, 200, 600, 430);

    ctx.strokeStyle = 'rgba(255, 255, 255, 0.12)';
    ctx.lineWidth = 2;
    ctx.strokeRect(36, 36, 1128, 558);

    ctx.fillStyle = '#94a3b8';
    ctx.font = '600 18px ' + font;
    ctx.fillText('AVDBCMS • 365 DAILY AESTHETIC IDENTITY', 72, 86);

    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 18px ' + font;
    ctx.textAlign = 'right';
    ctx.fillText('DAY ' + daily.day + ' / ' + yearDays(daily.daysInYear) + '  •  ' + daily.dateLabel, 1128, 86);
    ctx.textAlign = 'left';

    ctx.fillStyle = '#ffffff';
    ctx.font = 'bold 50px ' + font;
    ctx.fillText(designName(daily), 72, 170);

    ctx.fillStyle = 'rgba(255, 255, 255, 0.72)';
    ctx.font = 'italic 22px ' + font;
    ctx.fillText('“' + daily.seasonMood + '”', 72, 220);

    const swatches = [
        {label: 'PRIMARY', hex: daily.primary, x: 72},
        {label: 'SECONDARY', hex: daily.secondary, x: 432},
        {label: 'ACCENT', hex: daily.accent, x: 792},
    ];

    swatches.forEach((sw) => {
        const rx = sw.x, ry = 275, rw = 336, rh = 180, rad = 16;
        ctx.fillStyle = 'rgba(255, 255, 255, 0.04)';
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.12)';
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(rx, ry, rw, rh, rad); else ctx.rect(rx, ry, rw, rh);
        ctx.fill();
        ctx.stroke();

        ctx.fillStyle = sw.hex;
        ctx.beginPath();
        ctx.arc(rx + 55, ry + 90, 32, 0, Math.PI * 2);
        ctx.fill();
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2;
        ctx.stroke();

        ctx.fillStyle = '#94a3b8';
        ctx.font = 'bold 15px ' + font;
        ctx.fillText(sw.label, rx + 105, ry + 75);

        ctx.fillStyle = '#ffffff';
        ctx.font = 'bold 24px monospace';
        ctx.fillText(sw.hex, rx + 105, ry + 115);
    });

    ctx.fillStyle = 'rgba(255, 255, 255, 0.03)';
    ctx.fillRect(36, 520, 1128, 74);

    ctx.fillStyle = '#94a3b8';
    ctx.font = '16px ' + font;
    ctx.fillText('Layout: ' + daily.layout + '  |  Surface: ' + daily.surface + '  |  Typography: ' + daily.typography, 72, 564);

    ctx.fillStyle = daily.primary;
    ctx.font = 'bold 16px ' + font;
    ctx.textAlign = 'right';
    ctx.fillText(window.location.host, 1128, 564, 420);
    ctx.textAlign = 'left';

    const link = document.createElement('a');
    link.download = 'avdbcms-aesthetic-day-' + daily.day + '-' + daily.slug + '.png';
    link.href = canvas.toDataURL('image/png');
    link.click();

    playChime();
    toast(texts.cardDownloaded);
};

export const installHud = (): void => {
    const config = layoutConfig();

    if (!config) {
        return;
    }

    const texts = config.t;
    const daily = config.daily as DailyDesign | null;
    const card = config.dailyCard as DailyCard;

    window.copyColorHex = (hex: string, name: string) => {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(hex);
        }
        toast(texts.copied + ' ' + name + ' ' + hex);
        playClick();
    };

    window.setPersonalGlow = daily ? setPersonalGlowBadge(daily) : setPersonalGlowDefault;

    window.setCustomAccent = (hex: string) => {
        if (!hex) return;
        document.documentElement.style.setProperty('--avd-brand', hex);
        document.documentElement.style.setProperty('--avd-brand-rgb', hexToRgb(hex));
        const nameEl = byId('avdCustomColorName');
        if (nameEl) nameEl.textContent = 'Custom Tone: ' + hex.toUpperCase();
        const picker = byId<HTMLInputElement>('avdCustomColorPicker');
        if (picker && picker.value !== hex) picker.value = hex;
        store.set('avd_custom_accent', hex);
        playClick();
    };

    window.resetCustomAccent = () => {
        const defaultColor = config.primaryColor;
        const picker = byId<HTMLInputElement>('avdCustomColorPicker');
        if (picker) picker.value = defaultColor;
        document.documentElement.style.setProperty('--avd-brand', defaultColor);
        document.documentElement.style.setProperty('--avd-brand-rgb', hexToRgb(defaultColor));
        const nameEl = byId('avdCustomColorName');
        if (nameEl) nameEl.textContent = config.themePreset;
        store.del('avd_custom_accent');
        playChime();
    };

    // Another day of this year through the rule's preview parameter (?daily_design=N); a preview_day left in
    // the address would win over it, so it goes.
    window.avdSurpriseMe = () => {
        playChime();
        const days = yearDays(daily ? daily.daysInYear : card.daysInYear);
        const shown = Number(daily ? daily.day : card.day);
        let randDay = Math.floor(Math.random() * days) + 1;
        if (randDay === shown) randDay = (randDay % days) + 1;
        toast('🎲 Loading Aesthetic Day ' + randDay + '...');
        setTimeout(() => {
            const url = new URL(window.location.href);
            url.searchParams.delete('preview_day');
            url.searchParams.set('daily_design', String(randDay));
            window.location.href = url.toString();
        }, 300);
    };

    window.generateAestheticCard = daily ? generateCardBadge(daily, texts) : generateCardDefault(card, texts, config.primaryColor);

    if (daily) {
        window.copyToClipboard = (text: string) => {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(() => {
                    toast(texts.copiedColon + ' ' + text);
                    playClick();
                });
            }
        };
    }
};
