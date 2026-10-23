import {useTranslation} from "react-i18next";
import {VideoType} from "@/types";
import {memo} from "preact/compat";
import ImageLoaded from "@/components/Images/ImageLoaded";
import {formatCompactNumber, formatRelativeTime} from "@/utils/format";

type VideoAction = {
    label: string,
    icon: string,
    action: () => void
}

type Props = {
    video : VideoType
    actions? : VideoAction[]
    priority?: boolean
    // One of the feed's first cards (VideosList FIRST_PAGE_CARDS): rendered from its first frame (feed-skeleton.scss).
    firstPage?: boolean
}

const CARD_LAYOUTS = new Set([
    'card_standard',
    'card_hover_expand',
    'card_glass_overlay',
    'card_horizontal_strip',
    'card_bento_modern',
]);

function getCardLayout(): string {
    if (typeof document === 'undefined') {
        return 'card_standard';
    }

    const configured = document.documentElement.dataset.avdCardLayout ?? 'card_standard';

    return CARD_LAYOUTS.has(configured) ? configured : 'card_standard';
}

// Title script for the title-script-* class (covering font, no uppercase for scripts without case). Mirrors
// App\Support\TitleScript::detect: a non-Latin script wins with at least a quarter of the letters, letters of
// other scripts make it 'other', Vietnamese letters make it 'vietnamese'; keep the two in step. Letters only, by
// Script_Extensions as PHP's PCRE2 (10.40+) matches \p{Arabic}: the long vowel mark counts, shared punctuation not.
const TITLE_SCRIPTS: [string, RegExp][] = [
    ['arabic', /(?=\p{L})\p{Script_Extensions=Arabic}/gu],
    ['devanagari', /(?=\p{L})\p{Script_Extensions=Devanagari}/gu],
    ['thai', /(?=\p{L})\p{Script_Extensions=Thai}/gu],
    ['cjk', /(?=\p{L})[\p{Script_Extensions=Han}\p{Script_Extensions=Hiragana}\p{Script_Extensions=Katakana}\p{Script_Extensions=Hangul}]/gu],
    ['cyrillic', /(?=\p{L})\p{Script_Extensions=Cyrillic}/gu],
];
const VIETNAMESE = /[Ạ-ỹƠơƯư]/u;
const VIETNAMESE_WEAK = /[Đđ].*[ĂăÂâÊêÔô]|[ĂăÂâÊêÔô].*[Đđ]/u;
const countMatches = (text: string, pattern: RegExp): number => (text.match(pattern) || []).length;

export function titleScript(title: string | null | undefined): string {
    const text = (title || '').trim();
    const letters = countMatches(text, /\p{L}/gu);

    if (letters === 0) {
        return 'latin';
    }

    let best = '';
    let bestCount = 0;
    for (const [script, pattern] of TITLE_SCRIPTS) {
        const count = countMatches(text, pattern);
        if (count > bestCount) {
            best = script;
            bestCount = count;
        }
    }

    if (best && bestCount * 4 >= letters) {
        return best;
    }

    if ((letters - countMatches(text, /(?=\p{L})\p{Script_Extensions=Latin}/gu) - bestCount) * 2 > letters) {
        return 'other';
    }

    return VIETNAMESE.test(text) || VIETNAMESE_WEAK.test(text) ? 'vietnamese' : 'latin';
}

// The theme's card frame (html[data-avd-card-frame]). Only the window frame adds markup: its titlebar.
function getCardFrame(): string {
    return typeof document === 'undefined' ? '' : (document.documentElement.dataset.avdCardFrame ?? '');
}

function cardLayoutClass(layout: string): string {
    return {
        card_hover_expand: 'avd-card-hover-expand',
        card_glass_overlay: 'avd-card-glass-overlay',
        card_horizontal_strip: 'avd-card-horizontal',
        card_bento_modern: 'avd-card-bento',
    }[layout] ?? '';
}

export const Video = memo(({video, actions, priority = false, firstPage = false} : Props) => {
    const { t } = useTranslation();
    const layout = getCardLayout();
    const layoutClass = cardLayoutClass(layout);
    const windowFrame = getCardFrame() === 'window';

    return (
        <article className={`col avd-card-col avd-feed-card--${layout}`} data-card-layout={layout} data-avd-card="" data-avd-first-page={firstPage ? '' : undefined}>
            <div className={`avd-video-card ${layoutClass}`} data-card-layout={layout}>
                {windowFrame && <span className="avd-card-titlebar">{video.user?.username ?? ''}</span>}
                <a href={video.route} className="avd-thumb" aria-label={video.title}>
                    <ImageLoaded
                        source={video.thumbnail}
                        title={video.title}
                        loading={priority ? 'eager' : 'lazy'}
                        decoding="async"
                    />
                    {video.formated_duration && <span className="avd-duration">{video.formated_duration}</span>}
                    {video.is_private && (
                        <span className="avd-status-chip position-absolute top-0 start-0 m-2">
                            <i className="fa-solid fa-lock"></i>
                            {t('Private')}
                        </span>
                    )}
                </a>
                {(actions && actions.length > 0) && (
                    <div className="avd-card-menu dropdown">
                        <button className="btn dropdown-toggle without-caret" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="true" aria-label={t('Actions')}>
                            <i className="fa-solid fa-ellipsis-vertical"></i>
                        </button>
                        <ul className="dropdown-menu dropdown-menu-end">
                            {actions.map((action, index) => (
                                <li key={index}>
                                    <button className="dropdown-item d-flex align-items-center gap-3" onClick={action.action}>
                                        <i className={"fa-solid fa-" + action.icon}></i>
                                        {t(action.label)}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
                <div className={layout === 'card_glass_overlay' ? 'avd-card-body avd-glass-content' : 'avd-card-body'}>
                    {windowFrame ? null : video.user ? (
                        <a href={video.user.route} className="avd-card-avatar-link" title={video.user.username}>
                            <img className="avd-channel-avatar" src={video.user.avatar} alt={video.user.username + ' avatar'} loading="lazy" decoding="async" width={38} height={38} />
                        </a>
                    ) : (
                        <div className="avd-channel-avatar bg-secondary rounded-circle d-flex align-items-center justify-content-center">
                            <i className="fa-solid fa-user-slash text-muted" aria-hidden="true"></i>
                        </div>
                    )}
                    <div className="min-w-0">
                        <a href={video.route} className={`avd-video-title title-script-${titleScript(video.title || video.short_title)}`} title={video.title}>{video.title || video.short_title}</a>
                        {video.user ? (
                            <a href={video.user.route} className="avd-channel-name" title={video.user.username}>{video.user.username}</a>
                        ) : (
                            <span className="avd-channel-name text-muted">{t('Deleted user')}</span>
                        )}
                        <span className="avd-video-meta">
                            {t('Views', { count: video.views, formatted: formatCompactNumber(video.views) })}
                            <span aria-hidden="true"> &middot; </span>
                            {formatRelativeTime(video.published_at)}
                        </span>
                    </div>
                </div>
            </div>
        </article>
    )
})
