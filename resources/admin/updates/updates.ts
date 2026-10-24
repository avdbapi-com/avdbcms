// Tools > Updates (resources/views/admin/updates/index.blade.php): walks the one-click update of App\Support\Updater,
// one short request at a time so none comes near a host's time limit: start (the confirm dialog, password) -> step
// while the answer says "step" -> swap (answered by the maintenance hook without Laravel, with the swap token the step
// that prepared it handed over once) -> finish (the database step on the new code) -> the page again, with the result.
//
// Busy (409) waits retry_after (or 3 s) and asks again; a dropped connection is tried again after 2, 4, 8, 15 and 30 s,
// then the page says so and offers Continue; an ended session (419) reloads the page; a swap the hook refuses (its
// token is gone) reloads too, so the hook's own Finish / Undo page takes over. A page opened again goes on with its
// administrator's running update (boot.run). A run hides the result card of the run before. While work files of an
// earlier update wait in the trash (boot.tidy), the page asks for slices of clean-up of a few seconds each. Loaded by
// admin.ts only on pages with [data-ad-updates]; listeners are delegated, so the section the page swaps in after its
// first GitHub check (data-ad-defer) works the same.

import {$, $$, csrfToken} from '../lib/dom';
import './updates.scss';

type Next = 'step' | 'swap' | 'finish' | 'reload' | null;

/** Laravel's answer (UpdatesController) or the hook's (MaintenanceHook): the keys either may send. */
type Answer = {
    ok?: boolean;
    status?: string | null;
    step?: string;
    next?: Next;
    label?: string | null;
    message?: string | null;
    progress?: {done: number; total: number} | null;
    done?: number;
    total?: number;
    left?: number;
    wait?: number;
    retry_after?: number;
    busy?: boolean;
    retry?: boolean;
    token?: string;
    locked?: boolean;
};

type PlanView = {
    status: string;
    active: boolean;
    stalled: boolean;
    label: string;
    progress: {done: number; total: number} | null;
};

type Boot = {
    urls: {status: string; step: string; swap: string; finish: string; cancel: string};
    plan: {next: Next; mine: boolean; stalled: boolean; kind: 'update' | 'undo'; version: string; waiting: boolean} | null;
    run: boolean;
    /** Work files of an earlier update wait in the trash: ask for slices of clean-up (status?tidy=1). */
    tidy?: boolean;
    texts: Record<string, string>;
};

/** What status?tidy=1 says after its slice of clean-up (running: an update or undo runs, so nothing was deleted). */
type Tidy = {pending: boolean; deleted: number; busy: boolean; running?: boolean} | null;

type Reply = {status: number; data: Answer | null};

const root = $<HTMLElement>('[data-ad-updates]');
const bootEl = document.getElementById('ad-updates-boot');

if (root && bootEl) {
    const boot = JSON.parse(bootEl.textContent || '{}') as Boot;
    const text = (key: string, fallback = ''): string => boot.texts?.[key] ?? fallback;

    /** One request may take the server's 20 s of work plus a slow disk; Cloudflare gives up at 125 s. */
    const TIMEOUT_MS = 120_000;
    const BACKOFF = [2, 4, 8, 15, 30];
    const BEFORE_SWAP = ['checking', 'downloading', 'verifying', 'unpacking', 'backing-up', 'preparing'];
    const FINAL = ['done', 'failed', 'undone', 'cancelled'];

    let running = false;
    let stopping = false;
    let reloading = false;
    let watching = false;
    let token = '';
    let logAt = 0;

    // ---------- the progress panel

    const panel = (): HTMLElement | null => $('[data-ad-updates-progress]', root);
    const part = <T extends HTMLElement = HTMLElement>(name: string): T | null => $<T>(`[data-ad-updates-${name}]`, panel() ?? root);

    const showPanel = (): void => {
        const box = panel();

        if (box && box.hidden) {
            box.hidden = false;
            box.scrollIntoView({block: 'nearest'});
        }
    };

    const setTitle = (kind: 'update' | 'undo', version: string): void => {
        const title = part('title');
        const keep = part('keep');

        if (title && version) title.textContent = text(kind === 'undo' ? 'undoing' : 'installing').replace(':version', version);
        // An undo ends with "Your files are back to ...": the hint under the bar says so.
        if (keep) keep.textContent = text(kind === 'undo' ? 'keep_undo' : 'keep_update', keep.textContent ?? '');
    };

    /** The result of the run before (a card under the progress panel) is not this run's: it goes when a run starts. */
    const hideResult = (): void => {
        $$<HTMLElement>('[data-ad-updates-result]', root).forEach((card) => {
            card.hidden = true;
            $$<HTMLDetailsElement>('details[data-ad-updates-log]', card).forEach((details) => (details.open = false));
        });
    };

    const setLabel = (value: string | null | undefined): void => {
        const label = part('label');

        if (label && value) label.textContent = value;
    };

    const setBar = (done: number | undefined, total: number | undefined): void => {
        const bar = part<HTMLProgressElement>('bar');

        if (bar && typeof done === 'number' && typeof total === 'number' && total > 0) {
            bar.value = Math.max(0, Math.min(100, Math.floor((done * 100) / total)));
        }
    };

    const setError = (message: string | null): void => {
        const error = part('error');

        if (!error) return;
        error.textContent = message ?? '';
        error.hidden = !message;
    };

    /** Continue (what the page asks next) and Cancel (only before files are replaced). */
    const setButtons = (next: Next, cancellable: boolean): void => {
        const box = panel();
        const go = box ? $<HTMLFormElement>('form[data-ad-updates-continue]', box) : null;
        const cancel = box ? $<HTMLFormElement>('form[data-ad-updates-cancel]', box) : null;

        if (go) {
            go.hidden = next === null;

            if (next === 'reload') {
                go.dataset.adUpdatesContinue = 'reload';
            } else if (next) {
                go.dataset.adUpdatesContinue = next;
                go.action = boot.urls[next];
            }
        }

        if (cancel) cancel.hidden = !cancellable;
    };

    /** What an answer says now: Laravel's label and progress, or the hook's message and counts. */
    const show = (data: Answer): void => {
        setLabel(data.step ? data.message : (data.label ?? data.message));

        if (data.progress) {
            setBar(data.progress.done, data.progress.total);
        } else if (typeof data.done === 'number') {
            setBar(data.done, data.total);
        }
    };

    // ---------- requests

    const send = async (url: string, fields: Record<string, string>, form = false): Promise<Reply> => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), TIMEOUT_MS);

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': form ? 'application/x-www-form-urlencoded' : 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: form ? new URLSearchParams(fields).toString() : JSON.stringify(fields),
            });
            let data: Answer | null = null;

            try {
                data = (await response.json()) as Answer;
            } catch {
                data = null;
            }

            return {status: response.status, data};
        } finally {
            window.clearTimeout(timer);
        }
    };

    const statusOf = async (log = false): Promise<{plan: PlanView | null; log: string[] | null} | null> => {
        try {
            const url = new URL(boot.urls.status, window.location.href);

            if (log) url.searchParams.set('log', '1');

            const response = await fetch(url.href, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});

            return response.ok ? ((await response.json()) as {plan: PlanView | null; log: string[] | null}) : null;
        } catch {
            return null;
        }
    };

    /** Wait, or less when the administrator cancels meanwhile. */
    const pause = async (seconds: number): Promise<void> => {
        const until = Date.now() + Math.max(0, seconds) * 1000;

        while (Date.now() < until && !stopping) {
            await new Promise((resolve) => window.setTimeout(resolve, Math.min(250, until - Date.now())));
        }
    };

    const reload = (): void => {
        reloading = true;
        window.location.reload();
    };

    /** "Show details": the newest lines of the step log, while one is open (at most every 4 s during a run). */
    const refreshLog = async (force = false): Promise<void> => {
        const open = $$<HTMLDetailsElement>('details[data-ad-updates-log]', root).filter((details) => details.open);

        if (open.length === 0 || (!force && Date.now() - logAt < 4000)) return;

        logAt = Date.now();
        const answer = await statusOf(true);

        if (answer?.log) {
            open.forEach((details) => {
                const pre = $('[data-ad-updates-log-lines]', details);

                if (pre) {
                    pre.textContent = answer.log!.join('\n');
                    pre.scrollTop = pre.scrollHeight;
                }
            });
        }
    };

    // ---------- the walk

    const resumeFor = (status: string | null | undefined): Next => {
        if (!status) return null;
        if (BEFORE_SWAP.includes(status)) return 'step';
        if (status === 'swapping' || status === 'rolling-back') return 'swap';
        if (status === 'swapped' || status === 'migrating') return 'finish';

        return null;
    };

    /** Stopped with a reason: the message, and Continue (and Cancel before the swap) to go on from there. */
    const halt = (message: string | null | undefined, next: Next, status?: string | null): void => {
        setError(message || text('lost'));
        setButtons(next ?? 'reload', status ? BEFORE_SWAP.includes(status) : false);
    };

    const run = async (first: Next): Promise<void> => {
        if (running || first === null) return;

        running = true;
        stopping = false;
        hideResult();
        showPanel();
        setError(null);
        setButtons(null, false);

        let next: Next = first;
        let failures = 0;
        let refusals = 0;
        let busy = 0;
        let lastStatus: string | null = null;

        try {
            while (next !== null && !stopping) {
                if (next === 'reload') {
                    reload();

                    return;
                }

                const action: Exclude<Next, null | 'reload'> = next;
                let reply: Reply;

                try {
                    reply = await send(boot.urls[action], action === 'swap' ? {avdbcms_update: 'swap', token, _token: csrfToken()} : {}, action === 'swap');
                } catch {
                    // No answer (connection dropped, timed out): again after a pause, then the administrator decides.
                    if (failures >= BACKOFF.length) {
                        halt(text('lost'), action, lastStatus);

                        return;
                    }

                    setLabel(text('retrying').replace(':seconds', String(BACKOFF[failures])));
                    await pause(BACKOFF[failures++]);
                    continue;
                }

                const {status, data} = reply;

                if (status === 419) {
                    reload();

                    return;
                }

                if (status === 409 || data?.busy) {
                    // Another step holds the update lock (another tab, a slow request): its end frees it. After about
                    // ten minutes the administrator decides.
                    if (++busy > 200) {
                        halt(data?.message, action, lastStatus);

                        return;
                    }

                    if (data?.message) setLabel(data.message);
                    await pause(data?.retry_after ?? 3);
                    continue;
                }

                busy = 0;

                if (action === 'swap' && status === 503) {
                    // The hook did not take this request (its token is gone): its own page takes over after a reload.
                    if (++refusals >= 3) {
                        reload();

                        return;
                    }

                    await pause(2);
                    continue;
                }

                if (data === null || status >= 500) {
                    if (failures >= BACKOFF.length) {
                        halt(data?.message ?? text('lost'), action, lastStatus);

                        return;
                    }

                    setLabel(text('retrying').replace(':seconds', String(BACKOFF[failures])));
                    await pause(BACKOFF[failures++]);
                    continue;
                }

                failures = 0;
                lastStatus = data.status ?? lastStatus;

                if (typeof data.token === 'string' && /^[0-9a-f]{64}$/.test(data.token)) token = data.token;

                show(data);
                void refreshLog();

                if (status >= 400) {
                    halt(data.message, resumeFor(data.status ?? lastStatus), data.status ?? lastStatus);

                    return;
                }

                if (data.next === 'reload' || (data.status && FINAL.includes(data.status)) || data.step === 'failed') {
                    reload();

                    return;
                }

                if (!data.next) {
                    // The hook could not even put the files back: its message says what to do; Continue reloads.
                    if (data.step === 'stuck') {
                        halt(data.message, 'reload');

                        return;
                    }

                    halt(data.message, resumeFor(data.status ?? lastStatus), data.status ?? lastStatus);

                    return;
                }

                if (data.next === 'finish' && typeof data.wait === 'number' && data.wait > 0) {
                    await pause(data.wait);
                } else if (data.next === action && typeof data.retry_after === 'number' && data.retry_after > 0) {
                    await pause(data.retry_after);
                }

                next = data.next;
            }
        } finally {
            running = false;
        }
    };

    // ---------- the dialogs: Update to X.Y.Z, Undo this update

    const submitDialog = async (form: HTMLFormElement): Promise<void> => {
        const button = $<HTMLButtonElement>('button[type="submit"]', form);
        const error = $('[data-ad-updates-form-error]', form);
        const password = $<HTMLInputElement>('input[name="password"]', form);
        const skip = $<HTMLInputElement>('input[name="skip_backup"]', form);
        const kind = form.dataset.adUpdatesForm === 'undo' ? 'undo' : 'update';
        const fail = (message: string | null | undefined): void => {
            if (error) {
                error.textContent = message || text('lost');
                error.hidden = false;
            }
        };

        if (!button || button.disabled || running) return;

        button.disabled = true;
        if (error) error.hidden = true;

        let reply: Reply;

        try {
            reply = await send(form.action, {password: password?.value ?? '', ...(skip?.checked ? {skip_backup: '1'} : {})});
        } catch {
            button.disabled = false;
            fail(text('lost'));

            return;
        }

        if (reply.status === 419) {
            reload();

            return;
        }

        const data = reply.data;

        if (data === null || reply.status >= 500 || reply.status === 409 || data.ok === false) {
            // Wrong password, too many tries, or a reason this update cannot start now: said in the dialog.
            button.disabled = data?.locked === true;
            fail(data?.message);

            if (password) {
                password.value = '';
                password.focus();
            }

            return;
        }

        if (password) password.value = '';
        form.closest('dialog')?.close();
        $$<HTMLButtonElement>('[data-ad-updates-open]', root).forEach((opener) => (opener.disabled = true));

        if (typeof data.token === 'string' && /^[0-9a-f]{64}$/.test(data.token)) token = data.token;

        setTitle(kind, form.dataset.adUpdatesVersion ?? '');
        hideResult();
        showPanel();
        show(data);
        setLabel(data.label ?? text('starting'));
        button.disabled = false;

        if (data.next === 'reload' || !data.next) {
            reload();

            return;
        }

        void run(data.next);
    };

    // ---------- another administrator's update: watch it

    const watch = async (): Promise<void> => {
        if (watching) return;

        watching = true;
        showPanel();

        while (!reloading) {
            const answer = await statusOf();
            const plan = answer?.plan ?? null;

            if (answer && (!plan || !plan.active || plan.stalled)) {
                reload();

                return;
            }

            if (plan) {
                setLabel(plan.label);
                if (plan.progress) setBar(plan.progress.done, plan.progress.total);
            }

            void refreshLog();
            await new Promise((resolve) => window.setTimeout(resolve, 4000));
        }
    };

    // ---------- clean-up: work files of an earlier update, deleted a few seconds per request while the page is open

    /**
     * Each status?tidy=1 deletes for at most a few seconds on the server. Stops when nothing is left, when a slice
     * deleted nothing (a file that cannot be deleted; the scheduler tries again later), after 60 slices, or as soon as
     * an update or undo starts (here, or in another tab: the server then deletes nothing and says so, because that
     * run's own steps empty the trash) or the page is watched or left. Silent: nothing on the page depends on it.
     */
    const tidy = async (): Promise<void> => {
        for (let slices = 0; slices < 60; slices++) {
            if (running || watching || reloading) return;

            let answer: Tidy = null;

            try {
                const url = new URL(boot.urls.status, window.location.href);
                url.searchParams.set('tidy', '1');
                const response = await fetch(url.href, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});

                answer = response.ok ? (((await response.json()) as {tidy?: Tidy}).tidy ?? null) : null;
            } catch {
                return;
            }

            if (!answer || answer.running || !answer.pending || (answer.deleted === 0 && !answer.busy)) return;

            // Another clean-up holds the trash (the scheduler): ask again a little later.
            await new Promise((resolve) => window.setTimeout(resolve, answer!.busy ? 3000 : 250));
        }
    };

    // ---------- Cancel: after the request in flight, then the page again

    const cancel = async (): Promise<void> => {
        stopping = true;

        while (running) {
            await new Promise((resolve) => window.setTimeout(resolve, 200));
        }

        stopping = false;

        for (let tries = 0; tries < 5; tries++) {
            let reply: Reply;

            try {
                reply = await send(boot.urls.cancel, {});
            } catch {
                halt(text('lost'), resumeFor(null));

                return;
            }

            if (reply.status === 419) break;

            if (reply.status === 409 || reply.data?.busy) {
                await pause(reply.data?.retry_after ?? 3);
                continue;
            }

            if (reply.data?.ok === false) {
                showPanel();
                halt(reply.data.message, resumeFor(reply.data.status), reply.data.status);

                return;
            }

            break;
        }

        reload();
    };

    // ---------- events

    document.addEventListener('click', (event) => {
        const target = event.target as Element | null;

        if (!target) return;

        const opener = target.closest<HTMLButtonElement>('[data-ad-updates-open]');

        if (opener && root.contains(opener)) {
            const dialog = document.getElementById(opener.dataset.adUpdatesOpen ?? '') as HTMLDialogElement | null;

            if (dialog && typeof dialog.showModal === 'function' && !opener.disabled) {
                $$('[data-ad-updates-form-error]', dialog).forEach((error) => (error.hidden = true));
                dialog.showModal();
                $<HTMLInputElement>('input[name="password"]', dialog)?.focus();
            }

            return;
        }

        if (target.closest('[data-ad-updates-close]')) {
            target.closest('dialog')?.close();

            return;
        }

        // A click on the backdrop closes the dialog (the form fills the dialog itself).
        if (target instanceof HTMLDialogElement && target.classList.contains('ad-upd-dialog')) {
            target.close();

            return;
        }

        if (target.closest('[data-ad-updates-watch]')) {
            void watch();
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;

        // A confirm dialog (data-ad-confirm, modules/confirm.ts) holds the first submit back and sends it again.
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented || !root.contains(form)) return;

        // Continue / Cancel of a stopped update's callout: from now on the progress panel speaks for it.
        const callout = form.closest<HTMLElement>('.ad-callout');

        if (form.dataset.adUpdatesForm) {
            event.preventDefault();
            void submitDialog(form);
        } else if (form.hasAttribute('data-ad-updates-continue')) {
            event.preventDefault();
            const next = form.dataset.adUpdatesContinue as Next;

            if (next === 'step' || next === 'swap' || next === 'finish') {
                if (callout && !running) callout.hidden = true;
                setTitle(boot.plan?.kind ?? 'update', boot.plan?.version ?? '');
                void run(next);
            } else {
                reload();
            }
        } else if (form.hasAttribute('data-ad-updates-cancel')) {
            event.preventDefault();
            if (callout) callout.hidden = true;
            void cancel();
        }
    });

    document.addEventListener('toggle', (event) => {
        const details = event.target;

        if (details instanceof HTMLDetailsElement && details.matches('[data-ad-updates-log]') && details.open) {
            void refreshLog(true);
        }
    }, true);

    // Leaving while files are being downloaded or replaced pauses the update: the browser asks first.
    window.addEventListener('beforeunload', (event) => {
        if (running && !reloading) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // Finish / Undo of a maintenance page that had stood down lands here with ?update_choice= (the callout says what it
    // means once): a reload of the page is not that click again.
    const here = new URL(window.location.href);

    if (here.searchParams.has('update_choice')) {
        here.searchParams.delete('update_choice');
        window.history.replaceState(window.history.state, '', here.pathname + here.search + here.hash);
    }

    if (boot.run && boot.plan?.next) {
        void run(boot.plan.next);
    } else if (boot.tidy) {
        void tidy();
    }
}
