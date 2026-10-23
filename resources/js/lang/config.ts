import i18n from 'i18next';
import ICU from 'i18next-icu';
import { initReactI18next } from 'react-i18next';

// Always eager-load the base English locale.
import en from './en';

const lang = window.LANG || 'en';

// Lazy-load translation for non-English locales.
// Each case produces a separate chunk (Rollup static analysis).
// Only the matching chunk is ever downloaded by the browser.
async function loadLocale(locale: string): Promise<Record<string, string>> {
    switch (locale) {
        case 'ar': {
            const m = await import('./ar');
            return m.default;
        }
        case 'de': {
            const m = await import('./de');
            return m.default;
        }
        case 'es': {
            const m = await import('./es');
            return m.default;
        }
        case 'fr': {
            const m = await import('./fr');
            return m.default;
        }
        case 'hi': {
            const m = await import('./hi');
            return m.default;
        }
        case 'id': {
            const m = await import('./id');
            return m.default;
        }
        case 'ja': {
            const m = await import('./ja');
            return m.default;
        }
        case 'ko': {
            const m = await import('./ko');
            return m.default;
        }
        case 'ms': {
            const m = await import('./ms');
            return m.default;
        }
        case 'pt': {
            const m = await import('./pt');
            return m.default;
        }
        case 'ru': {
            const m = await import('./ru');
            return m.default;
        }
        case 'th': {
            const m = await import('./th');
            return m.default;
        }
        case 'tl': {
            const m = await import('./tl');
            return m.default;
        }
        case 'tr': {
            const m = await import('./tr');
            return m.default;
        }
        case 'vi': {
            const m = await import('./vi');
            return m.default;
        }
        case 'zh': {
            const m = await import('./zh');
            return m.default;
        }
        default:
            return en as Record<string, string>;
    }
}

// Initialise i18next synchronously with English.
i18n
    .use(initReactI18next)
    .use(ICU)
    .init({
        resources: {
            en: { translation: en },
        },
        fallbackLng: 'en',
        lng: 'en',
        interpolation: {
            escapeValue: false,
        },
    });

// Swap in the real locale asynchronously. i18nReady settles once the page language is active: at
// once for English, after the locale chunk otherwise (the layout <head> modulepreloads it), and
// also on failure (English stays). It never rejects. components/index.ts registers the custom
// elements after it, so they render once in the right language instead of English first.
export const i18nReady: Promise<void> = lang === 'en'
    ? Promise.resolve()
    : loadLocale(lang).then((translations) => {
        i18n.addResourceBundle(lang, 'translation', translations, true, true);

        return i18n.changeLanguage(lang);
    }).then(() => undefined, (err) => {
        console.warn(`[i18n] Failed to load locale "${lang}":`, err);
    });

export default i18n;
