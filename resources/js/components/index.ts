import register from 'preact-custom-element';
import {addedRootsBusEnabled, onAddedRoots} from '../dom/addedRoots';
import {requestAssetReload} from '../dom/navIntent';
import {i18nReady} from '../lang/config';

type Loader = () => Promise<Record<string, any>>;
type Registration = {
    tag: string;
    exportName: string;
    load: Loader;
    props?: string[];
};
type ReactRegistration = {
    tag: string;
    exportName: string;
    load: Loader;
    props: Record<string, string>;
};

const pending = new Set<string>();
// Bus tags whose chunk failed to load: looked up in the whole document again on the next batch,
// as the old observer retried them on its next mutation.
const retryTags = new Set<string>();

const hasElement = (tag: string): boolean => document.getElementsByTagName(tag).length > 0;

// A stale chunk reloads the page once, through ../dom/navIntent: never while the visitor is leaving
// (WebKit cancels the chunk loads of a page being left), and only after a short re-check.
const handleChunkError = (tag: string, error: unknown): void => {
    console.error(`[components] Failed to register <${tag}>`, error);
    const errStr = String(error);
    if (errStr.includes('dynamically imported module') || errStr.includes('Failed to fetch')) {
        requestAssetReload('avd_chunk_error_reload', '[components] Stale chunk detected. Refreshing to latest asset bundle...', error);
    }
};

// The header search's server-printed form (layouts/menus/headers/search-fallback.blade.php, watch-8) is replaced by the
// component when <search-bar> is defined: what a visitor typed there meanwhile, and the focus, go to the component
// (its `typed` and `refocus` props) instead of being lost.
const carryFallbackSearch = (): void => {
    document.querySelectorAll<HTMLElement>('search-bar').forEach((element) => {
        const input = element.querySelector<HTMLInputElement>('input[name="q"]');

        if (!input) {
            return;
        }

        if (input.value !== '') {
            element.setAttribute('typed', input.value);
        }

        if (document.activeElement === input) {
            element.setAttribute('refocus', '');
        }
    });
};

// The playlist editor's server-printed "Add videos" field (users/playlists/partials/editor.blade.php) goes the same way:
// the light DOM of <playlist-videos> is cleared when the element is defined, before the editor's first render
// (1.2.3 round 1, R1-107).
const carryPlaylistSearch = (): void => {
    document.querySelectorAll<HTMLElement>('playlist-videos').forEach((element) => {
        const input = element.querySelector<HTMLInputElement>('.mk-pl-pane--add input[type="search"]');

        if (!input) {
            return;
        }

        if (input.value !== '') {
            element.setAttribute('typed', input.value);
        }

        if (document.activeElement === input) {
            element.setAttribute('refocus', '');
        }
    });
};

// The same for the server-printed picture picker of the video form (users/videos/partials/form.blade.php): a picture
// picked there before <thumbnails-select> was defined goes to the picker (its `picked` prop: the radio's value, '' for
// "Current picture") instead of the active picture coming back.
const carryPickedThumbnail = (): void => {
    document.querySelectorAll<HTMLElement>('thumbnails-select').forEach((element) => {
        const picked = element.querySelector<HTMLInputElement>('input[type="radio"][name="thumbnail"]:checked');

        if (picked && !picked.defaultChecked) {
            element.setAttribute('picked', picked.value);
        }
    });
};

const beforeRegister: Record<string, () => void> = {
    'search-bar': carryFallbackSearch,
    'playlist-videos': carryPlaylistSearch,
    'thumbnails-select': carryPickedThumbnail,
};

const registerPreactElement = ({tag, exportName, load, props = []}: Registration): void => {
    if (customElements.get(tag) || pending.has(tag) || !hasElement(tag)) {
        return;
    }

    pending.add(tag);

    load()
        .then((module) => {
            const component = module[exportName] ?? module.default;

            if (component && !customElements.get(tag)) {
                beforeRegister[tag]?.();
                register(component, tag, props, {shadow: false});
            }
        })
        .catch((error) => handleChunkError(tag, error))
        .finally(() => pending.delete(tag));
};

const registerReactElement = (
    tag: string,
    exportName: string,
    load: Loader,
    props: Record<string, string>,
): void => {
    if (customElements.get(tag) || pending.has(tag) || !hasElement(tag)) {
        return;
    }

    pending.add(tag);

    Promise.all([import('@r2wc/react-to-web-component'), load()])
        .then(([r2wcModule, module]) => {
            const component = module[exportName] ?? module.default;

            if (component && !customElements.get(tag)) {
                customElements.define(tag, r2wcModule.default(component, {props}));
            }
        })
        .catch((error) => handleChunkError(tag, error))
        .finally(() => pending.delete(tag));
};

const preactRegistrations: Registration[] = [
    {tag: 'subscribe-button', exportName: 'Subscribe', load: () => import('./Actions/Subscribe')},
    {tag: 'comments-area', exportName: 'CommentsList', load: () => import('./Comments/CommentsList')},
    {tag: 'videos-area', exportName: 'VideosList', load: () => import('./Videos/VideosList')},
    {tag: 'interactions-area', exportName: 'InteractionList', load: () => import('./Interactions/InteractionsList')},
    {tag: 'search-bar', exportName: 'SearchBar', load: () => import('./Search/SearchBar')},
    {tag: 'user-replies', exportName: 'UserReplies', load: () => import('./Comments/UserReplies')},
    {tag: 'user-videos', exportName: 'UserVideos', load: () => import('./Videos/UserVideos')},
    {tag: 'playlist-videos', exportName: 'Playlist', load: () => import('./Playlists/Playlist')},
    {tag: 'site-notifications', exportName: 'NotificationsList', load: () => import('./Notifications/NotificationsList')},
    {tag: 'image-upload', exportName: 'default', load: () => import('./Images/ImageUpload'), props: ['name', 'config']},
    {
        tag: 'video-upload',
        exportName: 'VideoUpload',
        load: () => import('./Videos/VideoUpload'),
        props: ['configendpoint', 'completeendpoint', 'maxsize', 'maxfilename', 'allowedformats'],
    },
    {tag: 'image-loaded', exportName: 'default', load: () => import('./Images/ImageLoaded'), props: ['hover']},
    {tag: 'save-video', exportName: 'Save', load: () => import('./Playlists/Save')},
    {tag: 'search-model', exportName: 'SearchModel', load: () => import('./Search/SearchModel')},
    {tag: 'thumbnails-select', exportName: 'Thumbnails', load: () => import('./Videos/Thumbnails'), props: ['video', 'initial', 'allowupload']},
    {tag: 'report-button', exportName: 'Report', load: () => import('./Actions/Report')},
    {tag: 'theme-switch', exportName: 'ThemeSwitcher', load: () => import('./Switchs/ThemeSwitcher')},
    {tag: 'dynamic-input', exportName: 'DynamicInput', load: () => import('./Commons/DynamicInput')},
    {tag: 'user-history', exportName: 'HistoryList', load: () => import('./History/HistoryList')},
];

const interactionButtons: ReactRegistration = {
    tag: 'interaction-buttons',
    exportName: 'Interaction',
    load: () => import('./Interactions/Interaction'),
    props: {
        model: 'string',
        target: 'number',
        likes: 'number',
        dislikes: 'number',
        liked: 'boolean',
        disliked: 'boolean',
        showCount: 'boolean',
    },
};

const scanAndRegister = (): void => {
    preactRegistrations.forEach(registerPreactElement);

    registerReactElement(interactionButtons.tag, interactionButtons.exportName, interactionButtons.load, interactionButtons.props);

    registerReactElement('video-player', 'Player', () => import('./Videos/Player'), {
        video_uuid: 'string',
    });
};

// ---------------------------------------------------------------------------------------------
// Public pages: the shared added-nodes bus instead of ~20 document-wide tag
// walks per mutation batch for the whole life of the page.
//
// - Hands-off: the upload flow (<video-upload>) and the Upload18 player (<video-player>) keep the
//   original immediate path unchanged: the same register calls, a document check on every
//   mutation batch, in the MutationObserver callback. The observer disconnects once both are
//   defined (both calls are no-ops from then on).
// - Every other tag: one document lookup at boot, then only the subtrees the bus reports, once
//   per frame. A lookup is one querySelector per round over the still-undefined tags (each
//   match drops its tag), so k present tags cost k + 1 subtree walks. The subscription ends once
//   every tag is defined.
// - These components first render after the page language is active
//   (i18nReady: at once for English, after the locale chunk otherwise, at most LOCALE_WAIT_MS),
//   instead of rendering English and re-rendering. The server skeletons hold their space meanwhile.
// Admin pages and the embed shell keep the original observer and scan, unchanged (below).
// ---------------------------------------------------------------------------------------------
const VIDEO_UPLOAD_TAG = 'video-upload';
const VIDEO_PLAYER_TAG = 'video-player';
const LOCALE_WAIT_MS = 1500;

const videoUploadRegistration = preactRegistrations.find((registration) => registration.tag === VIDEO_UPLOAD_TAG);

const scanHandsOff = (): void => {
    if (videoUploadRegistration) {
        registerPreactElement(videoUploadRegistration);
    }

    registerReactElement('video-player', 'Player', () => import('./Videos/Player'), {
        video_uuid: 'string',
    });
};

// Same as registerPreactElement / registerReactElement without their document check: the
// caller found the tag in the document or in an added subtree.
const definePreactElement = ({tag, exportName, load, props = []}: Registration): void => {
    if (customElements.get(tag) || pending.has(tag)) {
        return;
    }

    pending.add(tag);

    load()
        .then((module) => {
            const component = module[exportName] ?? module.default;

            if (component && !customElements.get(tag)) {
                beforeRegister[tag]?.();
                register(component, tag, props, {shadow: false});
            }
        })
        .catch((error) => {
            retryTags.add(tag);
            handleChunkError(tag, error);
        })
        .finally(() => pending.delete(tag));
};

const defineReactElement = ({tag, exportName, load, props}: ReactRegistration): void => {
    if (customElements.get(tag) || pending.has(tag)) {
        return;
    }

    pending.add(tag);

    Promise.all([import('@r2wc/react-to-web-component'), load()])
        .then(([r2wcModule, module]) => {
            const component = module[exportName] ?? module.default;

            if (component && !customElements.get(tag)) {
                customElements.define(tag, r2wcModule.default(component, {props}));
            }
        })
        .catch((error) => {
            retryTags.add(tag);
            handleChunkError(tag, error);
        })
        .finally(() => pending.delete(tag));
};

const busDefiners = new Map<string, () => void>();

preactRegistrations.forEach((registration) => {
    if (registration.tag !== VIDEO_UPLOAD_TAG) {
        busDefiners.set(registration.tag, () => definePreactElement(registration));
    }
});
busDefiners.set(interactionButtons.tag, () => defineReactElement(interactionButtons));

const undefinedBusTags = (): string[] => {
    const tags: string[] = [];

    busDefiners.forEach((_define, tag) => {
        if (!customElements.get(tag)) {
            tags.push(tag);
        }
    });

    return tags;
};

// Moves every tag of `wanted` that occurs in `root` (the root itself or a descendant) to `found`.
const collectTags = (root: ParentNode, wanted: Set<string>, found: Set<string>): void => {
    if (root instanceof Element && wanted.has(root.localName)) {
        found.add(root.localName);
        wanted.delete(root.localName);
    }

    while (wanted.size > 0) {
        const match = root.querySelector(Array.from(wanted).join(','));

        if (!match) {
            return;
        }

        found.add(match.localName);
        wanted.delete(match.localName);
    }
};

const defineFound = (found: Set<string>): void => {
    found.forEach((tag) => busDefiners.get(tag)?.());
};

let stopBus: (() => void) | null = null;

const onRoots = (roots: readonly Element[]): void => {
    if (retryTags.size > 0) {
        const retry = new Set(Array.from(retryTags).filter((tag) => !customElements.get(tag) && !pending.has(tag)));
        const found = new Set<string>();

        retryTags.clear();
        collectTags(document, retry, found);
        defineFound(found);
    }

    const undefinedTags = undefinedBusTags();

    if (undefinedTags.length === 0) {
        stopBus?.();
        stopBus = null;
        return;
    }

    const wanted = new Set(undefinedTags.filter((tag) => !pending.has(tag)));
    const found = new Set<string>();

    for (const root of roots) {
        if (wanted.size === 0) {
            break;
        }

        collectTags(root, wanted, found);
    }

    defineFound(found);
};

const startBusRegistration = (): void => {
    const found = new Set<string>();

    collectTags(document, new Set(undefinedBusTags()), found);
    defineFound(found);
    stopBus = onAddedRoots(onRoots);
};

const whenLocaleReady = (callback: () => void): void => {
    let started = false;
    const start = () => {
        if (!started) {
            started = true;
            callback();
        }
    };

    i18nReady.then(start, start);
    window.setTimeout(start, LOCALE_WAIT_MS);
};

if (addedRootsBusEnabled()) {
    scanHandsOff();

    const handsOffObserver = new MutationObserver(() => {
        scanHandsOff();

        if (customElements.get(VIDEO_UPLOAD_TAG) && customElements.get(VIDEO_PLAYER_TAG)) {
            handsOffObserver.disconnect();
        }
    });

    handsOffObserver.observe(document.body, {
        childList: true,
        subtree: true,
    });

    whenLocaleReady(startBusRegistration);
} else {
    scanAndRegister();

    if (document.body) {
        const observer = new MutationObserver(() => scanAndRegister());

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });
    }
}
