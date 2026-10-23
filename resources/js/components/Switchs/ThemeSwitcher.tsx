import {useEffect, useState} from "preact/hooks";
import {THEME_KEY} from "./keys";
import {useTranslation} from "react-i18next";

type Theme = 'light' | 'dark';

type Props = {
    id: string
}

const COLORS: Record<Theme, string> = {
    'light' : '#FFFFFF',
    'dark': '#05080f'
}

const writeStoredTheme = (key: string, value: Theme, enabled: boolean): void => {
    if (!enabled) {
        return;
    }

    try {
        localStorage.setItem(key, value);
    } catch {
    }
};

export function ThemeSwitcher({id} : Props) {
    const {t} = useTranslation();
    const themeMemory = window.AVD_SETTINGS?.themeMemory !== false;
    const serverTheme = (document.documentElement.getAttribute('data-bs-theme') as Theme) || 'light';
    // The early layout script resolves server choice and matching user memory
    // before paint. Hydration must not replace that decision with stale storage.
    const [theme, setTheme] = useState<Theme>(serverTheme);

    const selectTheme = (value: Theme) => {
        const query = new URLSearchParams(window.location.search);
        const preview = ['theme', 'theme_preset', 'theme_mode'].some(key => query.has(key))
            || ['light', 'dark'].includes(query.get('mode') ?? '');
        if (themeMemory && !preview) {
            writeStoredTheme(THEME_KEY, value, true);
            try {
                const root = document.documentElement;
                localStorage.setItem('avd_manual_theme_context', `${root.dataset.avdThemePreset}:${root.dataset.avdThemeDefault}`);
            } catch {}
        }
        setTheme(value);
    };

    useEffect(() => {
        window.dispatchEvent(new CustomEvent('theme-changed', {
            detail: {
                theme: theme,
                id: id
            }
        }));
        document.documentElement.setAttribute('data-bs-theme', theme)
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', COLORS[theme])
        document.querySelector('meta[name="msapplication-navbutton-color"]')?.setAttribute('content', COLORS[theme])
        document.querySelector('meta[name="apple-mobile-web-app-status-bar-style"]')?.setAttribute('content', COLORS[theme])
    }, [theme, serverTheme, id, themeMemory])

    useEffect(() => {
        const event =  (e: Event) => {
            const detail = (e as CustomEvent<{theme: Theme, id: string}>).detail;

            if (detail.id !== id) {
                setTheme(detail.theme)
            }
        }

        window.addEventListener("theme-changed", event)
        return () => window.removeEventListener("theme-changed", event)
    }, [id])

    return (
        <div class="input-switch d-inline-block position-relative cursor-pointer">
                <input
                    type="checkbox"
                    id={'_' + id}
                    class="theme-switcher"
                    aria-label={t('Change theme')}
                    onChange={(e : any) => selectTheme(e.currentTarget.checked ? 'dark' : 'light')}
                    checked={theme === 'dark'}
                />
                <label for={'_' + id}>
                    <span class="switch"></span>
                    <svg class="icon icon-on" fill="currentColor" width="800px" height="800px" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M21.64,13a1,1,0,0,0-1.05-.14,8.05,8.05,0,0,1-3.37.73A8.15,8.15,0,0,1,9.08,5.49a8.59,8.59,0,0,1,.25-2A1,1,0,0,0,8,2.36,10.14,10.14,0,1,0,22,14.05,1,1,0,0,0,21.64,13Zm-9.5,6.69A8.14,8.14,0,0,1,7.08,5.22v.27A10.15,10.15,0,0,0,17.22,15.63a9.79,9.79,0,0,0,2.1-.22A8.11,8.11,0,0,1,12.14,19.73Z"/>
                    </svg>
                    <svg class="icon icon-off" fill="#000000" width="800px" height="800px" viewBox="0 0 24 24" id="sun" data-name="Line Color" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12,3V4M5.64,5.64l.7.7M3,12H4m1.64,6.36.7-.7M12,21V20m6.36-1.64-.7-.7M21,12H20M18.36,5.64l-.7.7" style="fill: none; stroke: rgb(44, 169, 188); stroke-linecap: round; stroke-linejoin: round; stroke-width: 2;"></path>
                        <circle cx="12" cy="12" r="4" style="fill: none; stroke: rgb(0, 0, 0); stroke-linecap: round; stroke-linejoin: round; stroke-width: 2;"></circle>
                    </svg>
                </label>
        </div>
    )
}
