import { useRef } from 'preact/hooks';
import configuration from "@/config";
import {show as showToast} from "@/functions/toast";
import {ChangeEvent, InputHTMLAttributes} from "react";
import {useTranslation} from "react-i18next";

const MB = 1048576;

type PictureKind = 'avatar' | 'banner' | 'thumbnail';

type Props = {
    name: string,
    config: PictureKind,
} & InputHTMLAttributes<HTMLInputElement>

type CropData = {
    limits?: Partial<Record<PictureKind, number>>,
    msgType?: string,
    msgSize?: string,
    msgSmall?: string,
};

// The crop window (users/partials/crop.blade.php) carries the server's limits in KB and the messages in the page's
// language, so the browser and the server say the same thing (account-21).
const cropData = (): CropData => {
    const crop = document.getElementById('crop');

    if (!crop) {
        return {};
    }

    let limits: CropData['limits'] = {};

    try {
        limits = JSON.parse(crop.dataset.limits || '{}');
    } catch {
        limits = {};
    }

    return {limits, msgType: crop.dataset.msgType, msgSize: crop.dataset.msgSize, msgSmall: crop.dataset.msgSmall};
};

const fill = (text: string, values: Record<string, string | number>): string =>
    Object.keys(values).reduce((out, key) => out.split('{' + key + '}').join(String(values[key])), text);

// A size in MB as the page's language writes the number ("2.5" / "2,5"; Western digits as everywhere on the site).
const sizeLabel = (bytes: number): string => {
    const mb = bytes / MB;
    const value = mb >= 10 ? Math.round(mb) : Math.round(mb * 10) / 10;

    try {
        return new Intl.NumberFormat((document.documentElement.lang || 'en') + '-u-nu-latn', {maximumFractionDigits: 1, useGrouping: false}).format(value);
    } catch {
        return value.toString();
    }
};

// The picker that opened the file chooser of the next opening of the crop window (1.2.3 round 3, R3-014): the window's
// own opener is the hidden button of the picture field, and Bootstrap gives the focus back only to a visible one, so
// the focus went to <body> when the window closed.
let cropReturn: HTMLElement | null = null;

/*
 * The crop window's own behaviour (member design 6.9, 5.11). The window is the markup of
 * users/partials/crop.blade.php (#crop, img#cropped_image, #crop_button, data-crop-*); it is opened by the hidden opener
 * button of a picture field below (data-name, data-config) and answers with window events: "cropped-<name>" with the
 * cropped file (the account pages and Thumbnails.tsx listen to it) and "crop-cancelled-<name>". It is only needed once a
 * picture is picked, so it lives in this file, which every picture field loads and the browser keeps, instead of a
 * script printed in every page that has the window (member design 4.3).
 * - "Use this picture" waits until the crop tool has loaded (account-10: a picture chosen before cropperjs arrived opened
 *   a dead window); it never stays busy for ever: after 15 s the window says so.
 * - Cancel, the close button, Escape and the backdrop clear the chosen file (account-9: the uncropped file was saved with
 *   the next save of the form); a picture used before for the same field is put back.
 */
type CropBox = {
    aspectRatio?: number,
    cropArea?: number,
    resizeable?: boolean,
    rounded?: boolean,
    width?: number,
    height?: number,
};

type CropperInstance = {
    destroy(): void,
    getCanvasData(): {width: number},
    getImageData(): {naturalWidth: number},
    zoomTo(ratio: number): void,
    getCroppedCanvas(options: Record<string, unknown>): HTMLCanvasElement | null,
};

type CropperConstructor = new (image: HTMLImageElement, options: Record<string, unknown>) => CropperInstance;

type CropPage = {
    Cropper?: CropperConstructor,
    avdCropperReady?: PromiseLike<unknown>,
    bootstrap?: {Modal?: {getInstance(element: Element): {hide(): void} | null}},
};

type CropOpening = {
    name: string,
    box: CropBox,
    input: HTMLInputElement | null,
    used: boolean,
    base: number,
    back: HTMLElement | null,
};

const CROPPER_WAIT_MS = 15000;

const cropPage = (): CropPage => window as unknown as CropPage;

const readJson = <T,>(text: string | undefined, fallback: T): T => {
    if (!text) {
        return fallback;
    }

    try {
        return JSON.parse(text) as T;
    } catch {
        return fallback;
    }
};

// The crop tool is loaded by app.ts in the background: wait for it (window.avdCropperReady when the page has it, else a
// short poll), at most 15 seconds.
const waitForCropper = (): Promise<CropperConstructor> => {
    const loaded = (): CropperConstructor | null => cropPage().Cropper ?? null;
    const ready = loaded();
    const promised = cropPage().avdCropperReady;

    if (ready) {
        return Promise.resolve(ready);
    }

    if (promised && typeof promised.then === 'function') {
        return new Promise((resolve, reject) => {
            const timer = window.setTimeout(() => reject(new Error('cropper')), CROPPER_WAIT_MS);

            Promise.resolve(promised).then(() => {
                window.clearTimeout(timer);
                const constructor = loaded();

                if (constructor) {
                    resolve(constructor);
                } else {
                    reject(new Error('cropper'));
                }
            }, (error: unknown) => {
                window.clearTimeout(timer);
                reject(error);
            });
        });
    }

    return new Promise((resolve, reject) => {
        let waited = 0;
        const timer = window.setInterval(() => {
            const constructor = loaded();

            if (constructor) {
                window.clearInterval(timer);
                resolve(constructor);
            } else if ((waited += 100) >= CROPPER_WAIT_MS) {
                window.clearInterval(timer);
                reject(new Error('cropper'));
            }
        }, 100);
    });
};

/** Wires the page's crop window once (a page without one, or a window already wired, is left as it is). */
export const wireCropDialog = (): void => {
    const dialog = document.getElementById('crop');

    if (!dialog || dialog.dataset.cropReady === '1') {
        return;
    }

    const image = document.getElementById('cropped_image');
    const useButton = document.getElementById('crop_button');
    const zoom = dialog.querySelector<HTMLInputElement>('[data-crop-zoom]');
    const busy = dialog.querySelector<HTMLElement>('[data-crop-busy]');
    const loading = dialog.querySelector<HTMLElement>('[data-crop-loading]');
    const failure = dialog.querySelector<HTMLElement>('[data-crop-error]');
    const frame = dialog.querySelector<HTMLElement>('[data-crop-frame]');
    const title = document.getElementById('cropTitle');
    const help = dialog.querySelector<HTMLElement>('[data-crop-help]');

    if (!(image instanceof HTMLImageElement) || !(useButton instanceof HTMLButtonElement) || !zoom || !busy || !loading || !failure || !frame) {
        return;
    }

    dialog.dataset.cropReady = '1';

    const texts = readJson<Record<string, string>>(dialog.dataset.help, {});
    const titles = readJson<Record<string, string>>(dialog.dataset.titles, {});

    // One state per opening: which field, which rules, whether the picture was used.
    let opening: CropOpening | null = null;
    let cropper: CropperInstance | null = null;
    // The picture last used for each field (name => File): a later pick that is cancelled puts it back.
    let kept: Record<string, File> = {};

    window.addEventListener('mk-form-reset', () => {
        kept = {};
    });

    const setBusy = (on: boolean): void => {
        useButton.disabled = on;
        useButton.setAttribute('aria-busy', on ? 'true' : 'false');
        busy.hidden = !on;
        loading.hidden = !on;
        zoom.disabled = on;
    };

    const destroy = (): void => {
        cropper?.destroy();
        cropper = null;
    };

    dialog.addEventListener('show.bs.modal', (event) => {
        const opener = (event as Event & {relatedTarget?: EventTarget | null}).relatedTarget;
        const source = opener instanceof HTMLElement ? opener : null;
        const box = readJson<CropBox>(source?.dataset.config, {});
        const name = source?.dataset.name || '';
        const ratio = box.aspectRatio || 0;
        const kind = ratio === 1 ? 'avatar' : (Math.abs(ratio - 3.2) < 0.01 ? 'banner' : 'thumbnail');

        opening = {
            name,
            box,
            input: name ? document.querySelector<HTMLInputElement>('input[name="' + CSS.escape(name) + '"]:not([readonly])') : null,
            used: false,
            base: 1,
            back: cropReturn,
        };
        cropReturn = null;

        if (title) {
            title.textContent = titles[kind] || title.textContent;
        }

        if (help) {
            help.textContent = texts[kind] || help.textContent;
        }

        frame.classList.toggle('mk-crop--round', box.rounded === true);
        frame.classList.toggle('mk-crop--banner', kind === 'banner');
        failure.hidden = true;
        zoom.value = '1';
        setBusy(true);
    });

    dialog.addEventListener('shown.bs.modal', () => {
        const current = opening;

        if (!current) {
            return;
        }

        destroy();

        waitForCropper().then((Cropper) => {
            if (opening !== current) {
                return;
            }

            cropper = new Cropper(image, {
                aspectRatio: current.box.aspectRatio || NaN,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: current.box.cropArea || 1,
                cropBoxResizable: current.box.resizeable !== false,
                cropBoxMovable: true,
                toggleDragModeOnDblclick: false,
                guides: false,
                center: false,
                background: false,
                responsive: true,
                ready: () => {
                    if (opening !== current || !cropper) {
                        return;
                    }

                    const canvas = cropper.getCanvasData();
                    const data = cropper.getImageData();
                    current.base = data.naturalWidth ? canvas.width / data.naturalWidth : 1;
                    setBusy(false);
                },
                zoom: (event: CustomEvent<{ratio: number}>) => {
                    if (opening === current && current.base > 0) {
                        zoom.value = String(Math.min(3, Math.max(1, event.detail.ratio / current.base)));
                    }
                },
            });
        }).catch(() => {
            if (opening !== current) {
                return;
            }

            busy.hidden = true;
            loading.hidden = true;
            useButton.setAttribute('aria-busy', 'false');
            failure.hidden = false;
        });
    });

    zoom.addEventListener('input', () => {
        if (cropper && opening) {
            cropper.zoomTo(opening.base * Number(zoom.value));
        }
    });

    useButton.addEventListener('click', () => {
        const current = opening;
        const instance = cropper;

        if (!current || !instance || useButton.disabled) {
            return;
        }

        setBusy(true);
        loading.hidden = true;

        const finish = (): void => {
            setBusy(false);
            cropPage().bootstrap?.Modal?.getInstance(dialog)?.hide();
        };

        const canvas = instance.getCroppedCanvas({
            width: current.box.width,
            height: current.box.height,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high',
        });
        const input = current.input;

        if (!canvas || !input) {
            finish();
            return;
        }

        canvas.toBlob((blob) => {
            try {
                if (!blob) {
                    return;
                }

                const file = new File([blob], current.name + '.jpg', {type: 'image/jpeg', lastModified: Date.now()});

                if (typeof DataTransfer === 'function') {
                    const container = new DataTransfer();
                    container.items.add(file);
                    input.files = container.files;
                    current.used = true;
                    kept[current.name] = file;
                    window.dispatchEvent(new CustomEvent('cropped-' + current.name, {detail: file}));
                } else if (input.files && input.files[0]) {
                    // Older Safari cannot set a file list: the chosen picture is kept as it is.
                    current.used = true;
                    window.dispatchEvent(new CustomEvent('cropped-' + current.name, {detail: input.files[0]}));
                }
            } finally {
                finish();
            }
        }, 'image/jpeg', 0.92);
    });

    dialog.addEventListener('hidden.bs.modal', () => {
        const current = opening;

        destroy();
        setBusy(true);
        failure.hidden = true;

        // Cancel, close, Escape or the backdrop: the picture is not used and the next save does not send it; a picture
        // used before for the same field stays chosen (its preview is still shown).
        if (current && !current.used && current.input) {
            current.input.value = '';

            if (kept[current.name] && typeof DataTransfer === 'function') {
                const restore = new DataTransfer();
                restore.items.add(kept[current.name]);
                current.input.files = restore.files;
            }

            window.dispatchEvent(new CustomEvent('crop-cancelled-' + current.name));
        }

        // Every way out (Use this picture, Cancel, the close button, Escape, the backdrop): the keyboard focus goes back
        // to the picker that opened the file chooser ("Change picture", "Change banner", the thumbnail tile), never to
        // <body> (R3-014); a picker a page drew again takes it by its id (the field's name).
        const back = current?.back && current.back.isConnected ? current.back : (current?.name ? document.getElementById(current.name) : null);
        const active = document.activeElement;

        if (back && (!active || active === document.body || dialog.contains(active))) {
            back.focus();
        }

        opening = null;
    });
};

// The first picture field of a page loads this file: the window is ready before anything can be picked.
wireCropDialog();

export default function ImageUpload ({name, config, ...attributes} : Props) {

    const input = useRef<HTMLInputElement>(null);
    const button = useRef<HTMLButtonElement>(null);

    const { t } = useTranslation();

    const fileConfig = configuration[config];

    // Attributes of the <image-upload> element that are not for the file input itself.
    const inputAttributes: Record<string, unknown> = {...attributes};
    ['class', 'className', 'msg-type', 'msg-size', 'msg-small'].forEach((key) => delete inputAttributes[key]);

    const change = async (event: ChangeEvent<HTMLInputElement>) => {

        const target = event.currentTarget;
        const file = target.files?.[0];

        if(!file) {
            return;
        }

        const data = cropData();
        const maxKb = Number(data.limits?.[config] ?? 0) > 0 ? Number(data.limits?.[config]) : fileConfig.maxSize * 1024;
        const maxBytes = maxKb * 1024;
        const allowed = 'JPG, PNG, WebP';
        // The messages are the crop window's own (its data-msg-* texts, the server's words); a page without the window
        // says the same through its translations (R3X-02: the old type message printed the name of its value).
        // Every value goes in as text: the translations' {placeholders} print it as it is ("1920 x 1080", never "1,920").
        const typeValues = {type: file.type || '?', types: allowed};

        if(!configuration.accepted_format.includes(file.type)) {
            target.value = '';
            showToast(data.msgType
                ? fill(data.msgType, typeValues)
                : t('This file type cannot be used ({type}). Use {types}.', typeValues));
            return;
        }

        if(file.size > maxBytes) {
            target.value = '';
            const sizeValues = {size: sizeLabel(file.size), max: sizeLabel(maxBytes)};
            showToast(data.msgSize
                ? fill(data.msgSize, sizeValues)
                : t('This picture is too large ({size} MB). Use one of {max} MB or less.', sizeValues));
            return;
        }

        const image = new Image();
        const url = URL.createObjectURL(file);

        image.onload = function() {

            if (image.width < fileConfig.minWidth || image.height < fileConfig.minHeight) {
                target.value = '';
                URL.revokeObjectURL(url);
                const smallValues = {width: String(image.width), height: String(image.height), minWidth: String(fileConfig.minWidth), minHeight: String(fileConfig.minHeight)};
                showToast(data.msgSmall
                    ? fill(data.msgSmall, smallValues)
                    : t('This picture is too small ({width} x {height}). Use one of at least {minWidth} x {minHeight} pixels.', smallValues));
            } else {
                // a window printed after this file loaded is wired now, before it opens; the focus comes back to this
                // picker when it closes (R3-014)
                wireCropDialog();
                document.getElementById('cropped_image')?.setAttribute('src', url);
                cropReturn = target;
                button.current?.click();

                // A picker that only opens the window (readonly: the page keeps the picture in a field of its own, as
                // Video details does) never keeps the uncropped file: the form would send it next to the cropped one
                // (account-9), and picking the same file again would not open the window.
                if (target.hasAttribute('readonly')) {
                    target.value = '';
                }
            }
        };

        image.onerror = function() {
            target.value = '';
            URL.revokeObjectURL(url);
            showToast(data.msgType
                ? fill(data.msgType, typeValues)
                : t('This file type cannot be used ({type}). Use {types}.', typeValues));
        };

        image.src = url;
    }

    return (
        <>
            <input
                ref={input}
                type="file"
                accept={configuration.accepted_format.join(',')}
                name={name}
                id={name}
                className={'opacity-0 w-100 h-100 cursor-pointer'}
                onChange={change}
                {...inputAttributes}
            />
            <button
                ref={button}
                className="d-none"
                type="button"
                tabIndex={-1}
                aria-hidden="true"
                data-bs-toggle="modal"
                data-bs-target="#crop"
                data-name={name}
                data-config={JSON.stringify(fileConfig)}
            >
            </button>
        </>
    )

}
