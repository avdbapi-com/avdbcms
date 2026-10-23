// One shared added-nodes bus (perf plan step 23). It replaces the subtree MutationObservers that
// each re-scanned every DOM insertion on their own: the component registry (about 20
// document-wide tag walks per mutation batch), the TomSelect select scan, the reveal, widget and
// auto-run scanners of the motion chunk, and the layout's inline card pass (watch-later badges,
// tilt, spotlight).
//
// - One MutationObserver on document.body ({childList, subtree}). Its callback only collects the
//   added element nodes, so a mutation burst costs no queries at all.
// - Once per animation frame (with a 100 ms timer as a backstop where frames do not run: hidden
//   tabs, prerendering, a long task) the batch is pruned to its topmost connected roots and handed
//   to every subscriber, which then queries only inside those roots.
// - Inline (non-module) scripts receive the same batch as the document CustomEvent
//   'avd:added-roots' {detail: {roots}}.
// - Nodes the effect engines inject and remove again (ripples, bursts, the card glare, spotlight
//   and hotspot layers) are dropped at collection time: no subscriber cares about them.
//
// The bus runs on the public pages only. Admin pages and the Upload18 embed shell
// (body.avd-embed-shell, hands-off) keep their original observers, like every other new initializer.

export type AddedRootsListener = (roots: readonly Element[]) => void;

export const ADDED_ROOTS_EVENT = 'avd:added-roots';

const IGNORED_SELECTOR = '.avd-click-ripple, .avd-ripple-wave, .avd-anime-ripple, .avd-click-burst, .avd-card-glare, .avd-card-spotlight, .avd-card-hotspot';

const FLUSH_BACKSTOP_MS = 100;

const listeners = new Set<AddedRootsListener>();
let observer: MutationObserver | null = null;
let pending: Element[] = [];
let frame = 0;
let timer = 0;

export const addedRootsBusEnabled = (): boolean => {
    const body = document.body;

    return Boolean(body)
        && typeof MutationObserver === 'function'
        && !body.classList.contains('avd-admin')
        && !body.classList.contains('avd-embed-shell');
};

// The batch's topmost connected roots: a node inside another added node of the same batch is
// covered by its ancestor's subtree, and a node removed again before the flush needs nothing.
const topmostRoots = (nodes: Element[]): Element[] => {
    const connected = new Set<Element>();

    nodes.forEach((node) => {
        if (node.isConnected) {
            connected.add(node);
        }
    });

    if (connected.size < 2) {
        return Array.from(connected);
    }

    const roots: Element[] = [];

    connected.forEach((node) => {
        for (let parent = node.parentElement; parent; parent = parent.parentElement) {
            if (connected.has(parent)) {
                return;
            }
        }

        roots.push(node);
    });

    return roots;
};

const flush = (): void => {
    if (frame) {
        window.cancelAnimationFrame(frame);
        frame = 0;
    }

    if (timer) {
        window.clearTimeout(timer);
        timer = 0;
    }

    const roots = Object.freeze(topmostRoots(pending));
    pending = [];

    if (!roots.length) {
        return;
    }

    listeners.forEach((listener) => {
        try {
            listener(roots);
        } catch (error) {
            // One failing subscriber must not starve the others.
            console.error(error);
        }
    });

    try {
        document.dispatchEvent(new CustomEvent(ADDED_ROOTS_EVENT, {detail: {roots}}));
    } catch (error) {
        console.error(error);
    }
};

const schedule = (): void => {
    if (frame || timer) {
        return;
    }

    frame = window.requestAnimationFrame(flush);
    timer = window.setTimeout(flush, FLUSH_BACKSTOP_MS);
};

const collect = (records: MutationRecord[]): void => {
    for (let i = 0; i < records.length; i += 1) {
        const added = records[i].addedNodes;

        for (let j = 0; j < added.length; j += 1) {
            const node = added[j];

            if (node.nodeType === Node.ELEMENT_NODE && !(node as Element).matches(IGNORED_SELECTOR)) {
                pending.push(node as Element);
            }
        }
    }

    if (pending.length) {
        schedule();
    }
};

// Starts the observer once (idempotent). It stays connected for the page's lifetime, because the
// layout's inline consumers listen for the event even when no module subscriber is left.
export const startAddedRoots = (): boolean => {
    if (observer) {
        return true;
    }

    if (!addedRootsBusEnabled()) {
        return false;
    }

    observer = new MutationObserver(collect);
    observer.observe(document.body, {childList: true, subtree: true});
    // Read by the layout's inline consumer at DOMContentLoaded: without it (the app bundle failed
    // to load, or an older build) that consumer falls back to its own observer.
    (window as Window & {__avdAddedRootsBus?: boolean}).__avdAddedRootsBus = true;

    return true;
};

// Subscribe to the batches of added roots. Returns the unsubscribe function.
export const onAddedRoots = (listener: AddedRootsListener): (() => void) => {
    listeners.add(listener);
    startAddedRoots();

    return () => {
        listeners.delete(listener);
    };
};

// Added elements under `scope`. Bus pages: the batch's roots that lie inside the scope, once per
// frame. Admin pages and the embed shell: a MutationObserver on the scope, as before (every added
// element of each mutation batch). The listener receives a non-empty list. Returns a disposer.
export const watchAddedElements = (scope: Element, listener: AddedRootsListener): (() => void) => {
    if (addedRootsBusEnabled()) {
        return onAddedRoots((roots) => {
            const inside = scope === document.body ? roots : roots.filter((root) => scope.contains(root));

            if (inside.length) {
                listener(inside);
            }
        });
    }

    if (typeof MutationObserver !== 'function') {
        return () => undefined;
    }

    const legacyObserver = new MutationObserver((records) => {
        const added: Element[] = [];

        records.forEach((record) => {
            record.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    added.push(node);
                }
            });
        });

        if (added.length) {
            listener(added);
        }
    });

    legacyObserver.observe(scope, {childList: true, subtree: true});

    return () => legacyObserver.disconnect();
};
