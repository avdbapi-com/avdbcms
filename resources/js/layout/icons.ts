// The Font Awesome safety net (perf-7, 1.2.3).
//
// The main CSS carries only the icons the site's code names (resources/sass/_fontawesome.scss, generated: all 1,895
// icon rules were 58 KB of render-blocking CSS on every page), and the layout prints the rules of the icons owners
// choose (a category's icon, a menu link's icon: App\Support\IconSubset::extraCss()). An icon that still finds no rule
// (a name in some page's data that nobody listed) would be an empty space. So once the page is idle, and again when
// content is added to it, the icon elements are asked for Font Awesome's --fa value; the first one without it loads
// the full icon set (resources/sass/fa-full.scss) once, and the checks stop. window.__avdIconsMissing lists what was
// missing, window.__avdIconsChecked counts the finished passes (browser checks read both).
//
// Cost (watch-7: the first version asked every icon element in one go, a 100-140 ms task on a slow phone): each set
// of icon names is asked once (two elements with the same names get the same rule: the name classes set --fa), and the
// asking runs in idle slices that stop when the browser's idle period runs low.
import {optionalLoad} from '../dom/navIntent';
import {watchAddedElements} from '../dom/addedRoots';

declare global {
    interface Window {
        __avdIconsMissing?: string[];
        __avdIconsChecked?: number;
    }
}

const ICON_ELEMENTS = 'i[class*="fa-"], span[class*="fa-"]';
// A Font Awesome style class: the element draws an icon from the --fa its icon class sets.
const STYLE_CLASS = /(?:^|\s)(?:fa|fas|far|fab|fa-solid|fa-regular|fa-brands)(?:\s|$)/;
// Classes that are not icon names (sizes, animations, stacking, lists, rotation).
const UTILITY = /^fa-(?:solid|regular|brands|classic|sharp|fw|spin|pulse|spin-pulse|spin-reverse|lg|sm|xs|xl|2xs|2xl|\d+x|beat|fade|beat-fade|bounce|flip|shake|border|pull-(?:left|right|start|end)|li|ul|stack|stack-1x|stack-2x|inverse|rotate-\d+|rotate-by|flip-(?:horizontal|vertical|both)|sr-only|sr-only-focusable|width-auto|layers|swap-opacity)$/;
// Missing names reported before the full set is loaded (one is enough to load it).
const REPORT_LIMIT = 8;
// A slice always asks a few elements, even when the idle period is over (a requestIdleCallback that ran on its timeout).
const SLICE_MINIMUM = 6;
// ... and stops when less than this is left of the idle period (ms).
const SLICE_RESERVE_MS = 3;

type Deadline = {timeRemaining: () => number};

let loading = false;
let stop: (() => void) | null = null;
let scheduled = false;
const queue: Element[] = [];
// Sets of icon names already seen with their rule.
const known = new Set<string>();

const iconNames = (element: Element): string[] => {
    const className = typeof element.className === 'string' ? element.className : '';

    if (!STYLE_CLASS.test(className)) {
        return [];
    }

    return className.split(/\s+/).filter((token) => token.startsWith('fa-') && !UTILITY.test(token));
};

const idle = (task: (deadline: Deadline) => void, timeout: number): void => {
    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback((deadline) => task(deadline), {timeout});
    } else {
        window.setTimeout(() => {
            const end = performance.now() + 8;
            task({timeRemaining: () => Math.max(0, end - performance.now())});
        }, 200);
    }
};

const loadFullSet = (missing: string[]): void => {
    loading = true;
    window.__avdIconsMissing = missing;
    queue.length = 0;
    stop?.();
    stop = null;
    void optionalLoad(import('../../sass/fa-full.scss')).catch(() => {
        loading = false;
    });
};

// One slice: the queued elements, while the idle period lasts.
const runSlice = (deadline: Deadline): void => {
    scheduled = false;

    if (loading) {
        return;
    }

    const missing: string[] = [];
    let asked = 0;

    while (queue.length && (asked < SLICE_MINIMUM || deadline.timeRemaining() > SLICE_RESERVE_MS)) {
        const element = queue.shift() as Element;

        if (!element.isConnected) {
            continue;
        }

        const names = iconNames(element);
        const key = names.join(' ');

        if (!names.length || known.has(key)) {
            continue;
        }

        asked += 1;

        if (window.getComputedStyle(element).getPropertyValue('--fa').trim() === '') {
            if (!missing.includes(key)) {
                missing.push(key);
            }

            if (missing.length >= REPORT_LIMIT) {
                break;
            }
        } else {
            known.add(key);
        }
    }

    if (missing.length) {
        loadFullSet(missing);

        return;
    }

    if (queue.length) {
        schedule();
    } else {
        window.__avdIconsChecked = (window.__avdIconsChecked || 0) + 1;
    }
};

function schedule(): void {
    if (scheduled || loading || !queue.length) {
        return;
    }

    scheduled = true;
    idle(runSlice, 3000);
}

// The icon elements under the roots join the queue (listing them is cheap; asking their style is what is sliced).
const check = (roots: readonly ParentNode[]): void => {
    if (loading) {
        return;
    }

    for (const root of roots) {
        if (root instanceof Element && root.matches(ICON_ELEMENTS)) {
            queue.push(root);
        }

        queue.push(...Array.from(root.querySelectorAll(ICON_ELEMENTS)));
    }

    if (queue.length) {
        schedule();
    } else {
        window.__avdIconsChecked = (window.__avdIconsChecked || 0) + 1;
    }
};

const afterLoad = (task: () => void): void => {
    if (document.readyState === 'complete') {
        task();
    } else {
        window.addEventListener('load', task, {once: true});
    }
};

export const installIconSafetyNet = (): void => {
    if (!document.body || document.querySelector('link[data-avd-icons="all"]')) {
        return;
    }

    window.__avdIconsMissing = [];
    window.__avdIconsChecked = 0;
    afterLoad(() => {
        check([document]);

        if (loading) {
            return;
        }

        let pending: Element[] = [];
        let timer = 0;
        stop = watchAddedElements(document.body, (roots) => {
            pending.push(...roots);

            if (!timer) {
                timer = window.setTimeout(() => {
                    timer = 0;
                    const batch = pending.filter((root) => root.isConnected);
                    pending = [];
                    check(batch);
                }, 500);
            }
        });
    });
};
