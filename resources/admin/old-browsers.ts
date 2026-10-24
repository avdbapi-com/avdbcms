// The admin on old devices (1.2.2): the few newer browser features the admin's script relies on, filled in where the
// browser lacks them. admin.ts imports this module first, so it runs before any other module's code (shell.ts listens
// to the colour-scheme query as soon as it loads). The syntax itself is lowered at build time (vite.config.ts,
// adminOldBrowsers). Each fallback only applies where the feature is missing; current browsers skip all of them.

type ChangeListener = (event: MediaQueryListEvent) => void;

// MediaQueryList.addEventListener('change'): Safari 14 / Chrome 45 (older ones have addListener only). Without it
// the admin's script stopped at its first line on an old iPad.
if (typeof window.matchMedia === 'function') {
    const query = window.matchMedia('all') as MediaQueryList & {addListener?: (listener: ChangeListener) => void};
    const proto = Object.getPrototypeOf(query) as Record<string, unknown> | null;

    if (proto && typeof query.addEventListener !== 'function' && typeof query.addListener === 'function') {
        proto.addEventListener = function (this: MediaQueryList, type: string, listener: ChangeListener): void {
            if (type === 'change' && typeof listener === 'function') this.addListener(listener);
        };
        proto.removeEventListener = function (this: MediaQueryList, type: string, listener: ChangeListener): void {
            if (type === 'change' && typeof listener === 'function') this.removeListener(listener);
        };
    }
}

// The button that submitted a form (SubmitEvent.submitter): Safari 15.4 / Chrome 81. The confirm dialogs read it to
// ask before a delete button submits its form; without it they would not ask.
if (typeof window.SubmitEvent === 'undefined' && !('submitter' in Event.prototype)) {
    let lastSubmitter: HTMLElement | null = null;

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const button = target?.closest<HTMLButtonElement | HTMLInputElement>('button, input[type="submit"], input[type="image"]') ?? null;

        lastSubmitter = button && button.form && (button.type === 'submit' || button.type === 'image') ? button : null;
    }, true);

    // Read during the submit event only: a later submit (Enter in a field) must not report an old click.
    document.addEventListener('submit', () => {
        window.setTimeout(() => {
            lastSubmitter = null;
        }, 0);
    });

    Object.defineProperty(Event.prototype, 'submitter', {
        configurable: true,
        get(this: Event): HTMLElement | null | undefined {
            if (this.type !== 'submit') return undefined;

            const form = this.target;

            return lastSubmitter && (lastSubmitter as HTMLButtonElement).form === form ? lastSubmitter : null;
        },
    });
}

// form.requestSubmit(): Safari 16 / Chrome 76 / Firefox 75. "Save changes" in the save bar, the confirm dialogs and
// the list filters submit their forms with it: a click on a submit button does the same (validation, the submit
// event and its handlers) in every browser.
if (typeof HTMLFormElement !== 'undefined' && typeof HTMLFormElement.prototype.requestSubmit !== 'function') {
    HTMLFormElement.prototype.requestSubmit = function (this: HTMLFormElement, submitter?: HTMLElement | null): void {
        if (submitter && (submitter as HTMLButtonElement).form === this) {
            (submitter as HTMLButtonElement).click();

            return;
        }

        const button = document.createElement('button');
        button.type = 'submit';
        button.hidden = true;
        this.appendChild(button);
        button.click();
        this.removeChild(button);
    };
}

// element.replaceChildren(): Safari 14 / Chrome 86 (the preview monitor's reload button label).
if (typeof Element !== 'undefined' && typeof Element.prototype.replaceChildren !== 'function') {
    Element.prototype.replaceChildren = function (this: Element, ...nodes: (Node | string)[]): void {
        while (this.firstChild) this.removeChild(this.firstChild);
        this.append(...nodes);
    };
}

// new FormData(form, submitter): Safari 16.4 / Chrome 112 / Firefox 111. Older browsers ignore the button, so the row
// menus' actions that send the clicked button's name and value (Mark as read, Make administrator: ids[]; Delete
// file: path) reached the server without them and were refused. Where the second argument is ignored, the button's
// name and value are added, as a real submit with that button does. FormData itself stays the browser's own.
let formDataTakesSubmitter = false;

try {
    // Where the submitter is supported, a button that is not in the form throws (NotFoundError).
    void new FormData(document.createElement('form'), document.createElement('button'));
} catch (error) {
    formDataTakesSubmitter = true;
}

if (!formDataTakesSubmitter) {
    const NativeFormData = window.FormData;
    const FormDataWithSubmitter = function (form?: HTMLFormElement, submitter?: HTMLElement | null): FormData {
        const data = form === undefined ? new NativeFormData() : new NativeFormData(form);
        const button = submitter as HTMLButtonElement | HTMLInputElement | null | undefined;

        if (form && button && button.form === form && button.name && !button.disabled) {
            data.append(button.name, button.value);
        }

        return data;
    };

    // `new FormData(...)` returns the native object, so instanceof FormData and every method stay as they were.
    FormDataWithSubmitter.prototype = NativeFormData.prototype;
    (window as unknown as {FormData: unknown}).FormData = FormDataWithSubmitter;
}

// <dialog> (show(), showModal(), close(), open, returnValue, the close and cancel events): Safari 15.4 / Firefox 98.
// Before them <dialog> is an unknown element: the search (Ctrl+K), Appearance > History and the Tools > Updates
// dialogs called showModal() and stopped, and the Updates page's click handler stopped at `instanceof
// HTMLDialogElement`. The admin's CSS already places these dialogs (.ad-dialog, .ad-palette: fixed, z-index 61) and
// hides them without [open]. A modal one gets a backdrop under it (a click there reaches the dialog, like a click on
// ::backdrop, so the scripts' "click outside closes" works) and Escape cancels the top one, as with a real <dialog>.
type DialogLike = HTMLElement & {onclose?: ((event: Event) => void) | null, oncancel?: ((event: Event) => void) | null};

const dialogProbe = document.createElement('dialog') as DialogLike & {showModal?: unknown};

if (typeof dialogProbe.showModal !== 'function') {
    const dialogProto = Object.getPrototypeOf(dialogProbe) as object;
    const nativeHandler = {close: 'onclose' in dialogProbe, cancel: 'oncancel' in dialogProbe};
    const modals: DialogLike[] = [];
    const backdrops = new WeakMap<DialogLike, HTMLElement>();
    const focusBefore = new WeakMap<DialogLike, HTMLElement>();
    const returnValues = new WeakMap<DialogLike, string>();

    // Sends `type` to the dialog; true when no handler cancelled it. Where the browser has no onclose / oncancel
    // property, the handler a script assigned (confirm.ts: dialog.onclose) is called here.
    const fire = (dialog: DialogLike, type: 'close' | 'cancel'): boolean => {
        const event = new Event(type, {cancelable: type === 'cancel'});
        const notCancelled = dialog.dispatchEvent(event);
        const handler = dialog[type === 'close' ? 'onclose' : 'oncancel'];

        if (!nativeHandler[type] && typeof handler === 'function') handler.call(dialog, event);

        return notCancelled && !event.defaultPrevented;
    };

    const dropModal = (dialog: DialogLike): void => {
        const index = modals.indexOf(dialog);

        if (index >= 0) modals.splice(index, 1);

        backdrops.get(dialog)?.remove();
        backdrops.delete(dialog);
    };

    const closeDialog = (dialog: DialogLike, value?: string): void => {
        if (!dialog.hasAttribute('open')) return;

        dialog.removeAttribute('open');
        dropModal(dialog);

        if (value !== undefined) returnValues.set(dialog, String(value));

        // The focus goes back where it was before the dialog opened, unless the user moved it elsewhere.
        const before = focusBefore.get(dialog);
        const active = document.activeElement;
        focusBefore.delete(dialog);

        if (before && document.body.contains(before) && (!active || active === document.body || dialog.contains(active))) {
            before.focus();
        }

        fire(dialog, 'close');
    };

    Object.defineProperties(dialogProto, {
        open: {
            configurable: true,
            get(this: DialogLike): boolean {
                return this.hasAttribute('open');
            },
            set(this: DialogLike, value: boolean): void {
                if (value) {
                    this.setAttribute('open', '');
                } else {
                    this.removeAttribute('open');
                    dropModal(this);
                }
            },
        },
        returnValue: {
            configurable: true,
            get(this: DialogLike): string {
                return returnValues.get(this) ?? '';
            },
            set(this: DialogLike, value: string): void {
                returnValues.set(this, String(value));
            },
        },
        show: {
            configurable: true,
            writable: true,
            value(this: DialogLike): void {
                if (!this.hasAttribute('open')) this.setAttribute('open', '');
            },
        },
        showModal: {
            configurable: true,
            writable: true,
            value(this: DialogLike): void {
                if (this.hasAttribute('open')) return;

                const active = document.activeElement;
                if (active instanceof HTMLElement && active !== document.body) focusBefore.set(this, active);

                this.setAttribute('open', '');

                const backdrop = document.createElement('div');
                backdrop.setAttribute('aria-hidden', 'true');
                backdrop.setAttribute('data-ad-dialog-backdrop', '');
                const style = backdrop.style;
                style.position = 'fixed';
                style.top = '0';
                style.right = '0';
                style.bottom = '0';
                style.left = '0';
                style.zIndex = '60';
                style.background = 'var(--overlay, rgba(15, 23, 42, .55))';
                backdrop.addEventListener('click', () => this.click());
                this.parentNode?.insertBefore(backdrop, this.nextSibling);
                backdrops.set(this, backdrop);
                modals.push(this);

                this.querySelector<HTMLElement>('[autofocus]')?.focus();
            },
        },
        close: {
            configurable: true,
            writable: true,
            value(this: DialogLike, value?: string): void {
                closeDialog(this, value);
            },
        },
    });

    // Escape: the top modal dialog gets "cancel", then closes unless a handler said no. A script that handles Escape
    // itself (the search box) prevents the default and closes its dialog on its own.
    document.addEventListener('keydown', (event) => {
        const top = modals[modals.length - 1];

        if (!top || event.defaultPrevented || (event.key !== 'Escape' && event.key !== 'Esc')) return;

        event.preventDefault();

        if (fire(top, 'cancel')) closeDialog(top);
    });

    // `x instanceof HTMLDialogElement` (the Updates page) needs the name to exist: true for <dialog> elements.
    if (typeof (window as unknown as {HTMLDialogElement?: unknown}).HTMLDialogElement !== 'function') {
        const DialogShim = function HTMLDialogElement(): void {
            throw new TypeError('Illegal constructor');
        };

        Object.defineProperty(DialogShim, Symbol.hasInstance, {
            value: (value: unknown): boolean => value instanceof HTMLElement && value.tagName === 'DIALOG',
        });
        DialogShim.prototype = dialogProto;
        Object.defineProperty(window, 'HTMLDialogElement', {configurable: true, writable: true, value: DialogShim});
    }
}

// :has() (Safari 15.4 / Chrome 105 / Firefox 121): the radio cards, pickers, swatches and the Appearance choices hide
// their input and show the picked one with :has(input:checked), a rule these browsers drop: nothing looked picked.
// Here the picked one gets the class is-picked (styled in components/_ui.scss), kept in step on every change.
let selectorHas = true;

try {
    document.querySelector(':has(*)');
} catch (error) {
    selectorHas = false;
}

if (!selectorHas) {
    const PICKERS = '.ad-rc, .ad-pick, .ad-swatch, .ad-level-opt, .ad-seg label';
    const markPicked = (): void => {
        document.querySelectorAll<HTMLElement>(PICKERS).forEach((box) => {
            const input = box.querySelector<HTMLInputElement>('input[type="radio"], input[type="checkbox"]');

            if (input) box.classList.toggle('is-picked', input.checked);
        });
    };

    // A picked radio unpicks the others of its group without an event of their own: every change marks them all.
    document.addEventListener('change', markPicked);
    document.addEventListener('reset', () => window.setTimeout(markPicked, 0));
    document.addEventListener('click', () => window.setTimeout(markPicked, 0));

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', markPicked);
    } else {
        markPicked();
    }
}

export {};
