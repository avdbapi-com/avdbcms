// Bootstrap's ES module sources (imported one component at a time by modules/legacy.ts).
declare module 'bootstrap/js/src/*.js' {
    const Component: {
        new (element: Element | string, config?: Record<string, unknown>): {show?: () => void; hide?: () => void; dispose: () => void};
        getInstance(element: Element | string): unknown;
        getOrCreateInstance(element: Element | string, config?: Record<string, unknown>): unknown;
    };
    export default Component;
}

// Sortable's core build (modules/legacy.ts mounts the same plugins as the default build).
declare module 'sortablejs/modular/sortable.core.esm.js' {
    type Plugin = object;
    const Sortable: {mount: (...plugins: Plugin[]) => void};
    export const AutoScroll: new () => Plugin;
    export const OnSpill: Plugin[];
    export default Sortable;
}

// Alpine's self-starting build (sets window.Alpine).
declare module 'alpinejs/dist/cdn.min.js';
