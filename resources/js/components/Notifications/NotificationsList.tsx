import {Fragment} from 'preact';
import {useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState} from 'preact/hooks';
import Notification from "@/components/Notifications/Notification";
import {InfiniteData, QueryClient, QueryClientProvider, useInfiniteQuery, useMutation, useQueryClient} from "@tanstack/react-query";
import {
    BellNotification,
    BellPage,
    ClickAnswer,
    deleteAllNotifications,
    deleteSelectedNotifications,
    getNotifications,
    readAllNotifications,
    UnreadAnswer,
} from "@/api/avdbclip/notifications";
import {Dropdown, Offcanvas} from 'react-bootstrap';
import {useInView} from "react-intersection-observer";
import {formatRelativeTime} from "@/utils/format";
import {useTranslation} from "react-i18next";
import {siteFile} from "@/functions/sitePath";
import "./notifications.css";

type Props = {
    count : string,
    // The notifications page and the settings (Account settings > Notifications), localized by the header
    // (layouts/menus/headers/actions.blade.php: page-url, settings-url).
    pageUrl?: string,
    settingsUrl?: string,
}

type Data = InfiniteData<BellPage, string | null>;

const QUERY_KEY = ['notifications'];

// Pages of 24 kept in the panel. Scrolling on drops the page farthest away (and loads it again when it comes back), so
// the panel never holds more than MAX_PAGES x 24 rows, however many notifications the member has.
const MAX_PAGES = 6;

// The server counts unread notifications up to 100: from there the badge shows "99+".
const BADGE_CAP = 99;

// A list older than this is loaded again when the panel opens (new notifications arrive without a live connection).
const FRESH_MS = 20000;

// How long a refused change is explained in the panel (it can be closed sooner).
const PROBLEM_MS = 10000;

// Rows pushed live that are kept above the newest page until the list is loaded again.
const LIVE_MAX = 50;

export const badgeText = (count: number): string => count <= 0 ? '' : (count > BADGE_CAP ? `${BADGE_CAP}+` : String(count));

// --- what the member changed in this panel ------------------------------------------------------------------------
// Applied over the rows the server sends until the panel starts again from the newest: a page that was on its way when
// the change was made (scrolling loads the next page by itself), or a page loaded again after it was dropped, still
// carries the old state and would otherwise undo the change on screen.
type RowChange = {seq: number, read?: boolean, gone?: boolean};
// "Mark all as read" / "Delete all": every row created up to "before" (ms; until the server has answered: every row).
type Moment = {seq: number, before: number};
type Local = {
    seq: number,
    rows: Map<string, RowChange>,
    readAll: Moment | null,
    cleared: Moment | null,
    live: BellNotification[],
    pending: Set<number>,
};

const newLocal = (): Local => ({seq: 0, rows: new Map(), readAll: null, cleared: null, live: [], pending: new Set()});

// Milliseconds of a date of the API ("2026-10-25T08:12:33.000000Z": decimals past the third are cut for Safari).
const msOf = (value: unknown): number => {
    if (typeof value !== 'string' || value === '') return 0;
    const ms = Date.parse(value.replace(/(\.\d{3})\d+/, '$1'));
    return isNaN(ms) ? 0 : ms;
};

// A row shown read / unread unlike the server's copy: made once per row and state, so an unchanged row stays the same
// object (its component does not render again).
const flipped = new WeakMap<BellNotification, BellNotification>();
const withRead = (row: BellNotification, read: boolean): BellNotification => {
    if (row.is_read === read) return row;
    let copy = flipped.get(row);
    if (!copy) {
        copy = {...row, is_read: read};
        flipped.set(row, copy);
    }
    return copy;
};

// The row as the member left it (null: deleted). The latest change wins: a row marked unread after "Mark all as read"
// stays unread.
const applyLocal = (row: BellNotification, local: Local): BellNotification | null => {
    const change = local.rows.get(row.id);
    if (change?.gone) return null;
    const created = msOf(row.created_at);
    if (local.cleared && created <= local.cleared.before) return null;
    let read = row.is_read;
    let at = -1;
    if (local.readAll && created <= local.readAll.before) {
        read = true;
        at = local.readAll.seq;
    }
    if (change && change.read !== undefined && change.seq > at) {
        read = change.read;
    }
    return withRead(row, read);
};

// The text of a refused change: the server's own words when it sent some (a view-only demo account, a lost
// session), else a plain "Something went wrong".
const problemText = (error: unknown, fallback: string): string => {
    if (error instanceof Error && !(error instanceof TypeError) && !(error instanceof SyntaxError)
        && error.message && error.message !== 'Oups something went wrong !') {
        return error.message;
    }
    return fallback;
};

// The first row on screen and where it is: kept in place when rows are added or dropped above it.
const firstVisibleRow = (box: HTMLElement): {id: string, top: number} | null => {
    const top = box.getBoundingClientRect().top;
    const rows = box.querySelectorAll<HTMLElement>('[data-avd-notification]');
    for (let i = 0; i < rows.length; i++) {
        const rect = rows[i].getBoundingClientRect();
        if (rect.bottom > top + 1) {
            return {id: rows[i].getAttribute('data-avd-notification') || '', top: rect.top};
        }
    }
    return null;
};

const shakeBell = () => {
    const bell = document.getElementById('bell');
    bell?.classList.add('fa-shake');
    window.setTimeout(() => bell?.classList.remove('fa-shake'), 1000);
};

// --- groups and times (member design 6.14): Today / Yesterday / This week (2 to 6 days ago) / Earlier -------------
// The server sends each row's group and time in words (the site's time zone and date format, as the notifications page
// shows them: BellResource::time). Only a row pushed live, which has neither, is placed here, by the device's clock.
type Group = 'today' | 'yesterday' | 'week' | 'earlier';

const GROUPS: ReadonlyArray<Group> = ['today', 'yesterday', 'week', 'earlier'];

const isGroup = (value: unknown): value is Group => typeof value === 'string' && (GROUPS as ReadonlyArray<string>).includes(value);

const pageLanguage = (): string => window.LANG || document.documentElement.lang || 'en';

const dayStart = (ms: number): number => {
    const d = new Date(ms);
    d.setHours(0, 0, 0, 0);
    return d.getTime();
};

const groupOf = (createdMs: number, todayStart: number): Group => {
    if (createdMs <= 0 || createdMs >= todayStart) return 'today';
    const days = Math.round((todayStart - dayStart(createdMs)) / 86400000);
    if (days <= 1) return 'yesterday';
    if (days <= 6) return 'week';
    return 'earlier';
};

const format = (ms: number, options: Intl.DateTimeFormatOptions): string => {
    try {
        return new Intl.DateTimeFormat(pageLanguage(), options).format(new Date(ms));
    } catch {
        return new Date(ms).toLocaleString();
    }
};

// --- the keyboard (1.2.3 round 3, R3-016) --------------------------------------------------------------------------
// The "More options" menu is a menu button: Enter, Space or Down opens it on its first item (Up: on its last), Up and
// Down move between the items (they stop at the ends, as the notifications page's row menu), Home and End go to the
// first and last, Escape closes the menu only (the sheet stays) and gives the focus back to the button. The keys are
// taken on the window in the capture phase while the sheet is open: Bootstrap's own keyboard handler (a capture listener
// on the document for every .dropdown-menu) would otherwise take them first and fail on this menu, which has no
// Bootstrap button, and the page's own shortcuts (the watch page's volume keys) never see them. The closed menu's button
// opens the menu itself on those keys and the focus moves from an effect of the menu's state: react-bootstrap's own
// "opened by a key" memory is reset by any render between the key and the button's click (a list loaded again, a live
// notification, the state change of a control used just before), and the menu then opened with the focus left on its
// button (WebKit, Enter right after the selection's Cancel).
const MENU_ITEMS = '.dropdown-item:not(.disabled):not(:disabled)';
const OPEN_KEYS: ReadonlyArray<string> = ['Enter', ' ', 'ArrowDown', 'ArrowUp'];
// how long the mark of the key that opened the menu holds (its menu opens on the next render; a Space's keyup comes
// when the key is released)
const KEY_MARK_MS = 5000;

// The sheet is react-bootstrap's: Bootstrap's own Offcanvas (the main bundle's data API) shows every ".offcanvas.show"
// element again when the window's load event comes, so a sheet opened before every picture of the page had loaded got a
// second, unmanaged Offcanvas whose focus trap took the focus from the menu or the confirmation (the arrow keys then did
// nothing and Escape closed the whole sheet), with a backdrop and a page scroll lock nothing removed. Its show is
// refused for this sheet (Bootstrap's "show.bs.offcanvas" event can be cancelled); every other side panel keeps it.
const BELL_SHEET = 'data-avd-bell-sheet';
const refuseBootstrapShow = (event: Event) => {
    if (event.target instanceof Element && event.target.hasAttribute(BELL_SHEET)) {
        event.preventDefault();
    }
};

// A control the focus can go back to: still on the page, enabled and drawn.
const canFocus = (element: HTMLElement | null | undefined): element is HTMLElement =>
    !!element && element.isConnected && !(element as HTMLButtonElement).disabled && element.getClientRects().length > 0;

const Main = ({count, pageUrl, settingsUrl} : Props) => {

    const [open, setOpen] = useState<boolean>(false);
    const [unread, setUnread] = useState<number>(Math.max(parseInt(count, 10) || 0, 0));
    const [selected, setSelected] = useState<Set<string>>(() => new Set());
    const [selectMode, setSelectMode] = useState<boolean>(false);
    const [confirming, setConfirming] = useState<'all' | 'selected' | null>(null);
    const [box, setBox] = useState<HTMLDivElement | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    // R3-016: the menu's state (it is the sheet's: Escape closes it alone), its button and items, the confirmation's
    // Cancel, and the control the confirmation goes back to (the menu's button, or the selection's Delete)
    const [menuOpen, setMenuOpen] = useState<boolean>(false);
    const toggleRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const confirmRef = useRef<HTMLDivElement>(null);
    const cancelRef = useRef<HTMLButtonElement>(null);
    const selectAllRef = useRef<HTMLInputElement>(null);
    const alertRef = useRef<HTMLDivElement>(null);
    const opener = useRef<HTMLElement | null>(null);
    const focusSelectAll = useRef<boolean>(false);
    // the menu opened from its button by a key: the item that takes the focus, and the Space whose keyup would click
    // the button again (and close the menu); each with the moment of its key, so a mark left by a key whose menu never
    // opened, or whose keyup never came, does not act on a later opening by the mouse or a later Space
    const focusOnOpen = useRef<{where: 'first' | 'last', at: number} | null>(null);
    const spaceOpened = useRef<number>(0);
    const lastFetch = useRef<number>(0);
    const local = useRef<Local>(newLocal());
    const [version, setVersion] = useState<number>(0);
    const bump = useCallback(() => setVersion((value) => value + 1), []);

    const { t } = useTranslation();

    const queryClient = useQueryClient();

    const {
        data,
        isLoading,
        isError,
        refetch,
        hasNextPage,
        hasPreviousPage,
        isFetchingNextPage,
        isFetchingPreviousPage,
        fetchNextPage,
        fetchPreviousPage,
    } = useInfiniteQuery({
        queryKey: QUERY_KEY,
        queryFn: async ({pageParam}): Promise<BellPage> => {
            const page = await getNotifications(pageParam);
            lastFetch.current = Date.now();
            if (typeof page?.unread === 'number') {
                setUnread(page.unread);
            }
            return page;
        },
        initialPageParam: null as string | null,
        getNextPageParam: (last: BellPage) => last?.meta?.next_cursor ?? undefined,
        getPreviousPageParam: (first: BellPage) => first?.meta?.prev_cursor ?? undefined,
        maxPages: MAX_PAGES,
        enabled: open,
        staleTime: Infinity,
    });

    // The rows on screen: the server's pages, the rows pushed live above the newest page, the member's changes over them.
    const items = useMemo<BellNotification[]>(() => {
        const mine = local.current;
        const pages = data?.pages ?? [];
        let rows: BellNotification[] = pages.flatMap(page => page.data);
        if (mine.live.length > 0 && pages.length > 0 && !pages[0].meta?.prev_cursor) {
            const known = new Set(rows.map(row => row.id));
            const fresh = mine.live.filter(row => !known.has(row.id));
            if (fresh.length > 0) {
                rows = [...fresh, ...rows];
            }
        }
        if (mine.rows.size === 0 && !mine.readAll && !mine.cleared) {
            return rows;
        }
        const shown: BellNotification[] = [];
        for (const row of rows) {
            const next = applyLocal(row, mine);
            if (next) {
                shown.push(next);
            }
        }
        return shown;
    }, [data, version]);

    // --- keep the reading place while pages come and go above it --------------------------------------------------
    const firstId = items.length > 0 ? items[0].id : null;
    const anchor = useRef<{id: string, top: number} | null>(null);
    const seenFirst = useRef<string | null>(null);
    const hadNewerAbove = useRef<boolean>(false);
    if (seenFirst.current !== firstId) {
        // this render changes the top of the list: the DOM still shows the rows before the change
        if (box && seenFirst.current !== null && (box.scrollTop > 0 || hadNewerAbove.current)) {
            anchor.current = firstVisibleRow(box);
        }
        seenFirst.current = firstId;
    }
    hadNewerAbove.current = !!hasPreviousPage;

    useLayoutEffect(() => {
        const mark = anchor.current;
        anchor.current = null;
        if (!mark || !box) return;
        const row = box.querySelector<HTMLElement>(`[data-avd-notification="${mark.id}"]`);
        if (row) {
            box.scrollTop += row.getBoundingClientRect().top - mark.top;
        }
    }, [firstId, box]);

    // --- loading more: older rows at the bottom, newer rows at the top (after older ones were dropped) -------------
    const {ref: olderRef, inView: olderInView} = useInView({root: box, rootMargin: '0px 0px 900px 0px'});
    const {ref: newerRef, inView: newerInView} = useInView({root: box, rootMargin: '900px 0px 0px 0px'});
    const busy = isFetchingNextPage || isFetchingPreviousPage || isError;

    useEffect(() => {
        // both ends in view (a short window): the older end only, so the two never take turns
        if (open && olderInView && hasNextPage && !busy) {
            fetchNextPage();
        }
    }, [open, olderInView, hasNextPage, busy]);

    useEffect(() => {
        if (open && newerInView && hasPreviousPage && !busy && !(olderInView && hasNextPage)) {
            fetchPreviousPage();
        }
    }, [open, newerInView, hasPreviousPage, busy, olderInView, hasNextPage]);

    // --- the member's changes ----------------------------------------------------------------------------------------
    // Shown at once and kept over every page the server sends (see Local); sent; when the server refuses (a view-only
    // demo account, a lost connection) the change is taken back, the stored rows are loaded again and the panel says
    // why, inside the panel, where nothing covers its buttons.
    const change = <A, R>(apply: (arg: A, seq: number) => void | Promise<void>, call: (arg: A) => Promise<R>, confirm?: (answer: R, seq: number) => void) =>
        async (arg: A): Promise<R> => {
            const mine = local.current;
            mine.seq += 1;
            const seq = mine.seq;
            mine.pending.add(seq);
            setProblem(null);
            try {
                const applied = apply(arg, seq);
                bump();
                await applied;
                const answer = await call(arg);
                confirm?.(answer, seq);
                return answer;
            } catch (e) {
                mine.rows.forEach((row, id) => {
                    if (row.seq === seq) mine.rows.delete(id);
                });
                if (mine.readAll?.seq === seq) mine.readAll = null;
                if (mine.cleared?.seq === seq) mine.cleared = null;
                queryClient.resetQueries({queryKey: QUERY_KEY});
                throw e;
            } finally {
                mine.pending.delete(seq);
                bump();
            }
        };

    // The panel starts again from the newest: what the server has confirmed is in what it sends now, the changes still
    // on their way are kept.
    const restart = () => {
        const mine = local.current;
        mine.rows.forEach((row, id) => {
            if (!mine.pending.has(row.seq)) mine.rows.delete(id);
        });
        if (mine.readAll && !mine.pending.has(mine.readAll.seq)) mine.readAll = null;
        if (mine.cleared && !mine.pending.has(mine.cleared.seq)) mine.cleared = null;
        mine.live = [];
        bump();
        queryClient.resetQueries({queryKey: QUERY_KEY});
    };

    const refused = (e: unknown) => setProblem(problemText(e, t('Something went wrong')));
    const showUnread = (answer: UnreadAnswer | null | undefined) => {
        if (typeof answer?.unread === 'number') {
            setUnread(answer.unread);
        }
    };

    // the moment the server took (UTC): rows that came after the click show as they are
    const keepMoment = (key: 'readAll' | 'cleared') => (answer: ClickAnswer, seq: number) => {
        const mine = local.current;
        const before = msOf(answer?.before);
        const moment = mine[key];
        if (moment && moment.seq === seq && before > 0) {
            mine[key] = {seq, before};
        }
        showUnread(answer);
    };

    const {mutate: readAll, isPending: isReadAllPending} = useMutation<ClickAnswer, Error, void>({
        mutationFn: change(
            (_: void, seq: number) => {
                local.current.readAll = {seq, before: Infinity};
                setUnread(0);
            },
            () => readAllNotifications(),
            keepMoment('readAll'),
        ),
        onError: refused,
    });

    const {mutate: deleteAll, isPending: isDeleteAllPending} = useMutation<ClickAnswer, Error, void>({
        mutationFn: change(
            async (_: void, seq: number) => {
                local.current.cleared = {seq, before: Infinity};
                setUnread(0);
                setSelected(new Set<string>());
                setSelectMode(false);
                // a page on its way would add the old rows and more pages would load: it is stopped, and the list is
                // one empty page (the empty state, nothing more to load)
                await queryClient.cancelQueries({queryKey: QUERY_KEY});
                queryClient.setQueryData<Data>(QUERY_KEY, (old) => old && old.pages.length > 0 ? {
                    pages: [{...old.pages[0], data: [], meta: {...old.pages[0].meta, next_cursor: null, prev_cursor: null}}],
                    pageParams: [null],
                } : old);
            },
            () => deleteAllNotifications(),
            keepMoment('cleared'),
        ),
        onError: refused,
    });

    const {mutate: removeSelected} = useMutation<UnreadAnswer & {deleted: number}, Error, string[]>({
        mutationFn: change(
            (ids: string[], seq: number) => {
                ids.forEach((id) => local.current.rows.set(id, {seq, gone: true}));
                setSelected(new Set<string>());
            },
            (ids: string[]) => deleteSelectedNotifications(ids),
            showUnread,
        ),
        onError: refused,
    });

    // The refusal note goes (its Close, or after PROBLEM_MS): a focus that was on its Close goes to the menu's button
    // instead of the sheet (R3-016's look-around: a control that disappears hands its focus on).
    const dismissProblem = () => {
        if (alertRef.current?.contains(document.activeElement)) {
            toggleRef.current?.focus();
        }
        setProblem(null);
    };

    useEffect(() => {
        if (!problem) return;
        const timer = window.setTimeout(dismissProblem, PROBLEM_MS);
        return () => window.clearTimeout(timer);
    }, [problem]);

    // --- live notifications (only on sites with a live broadcaster) ------------------------------------------------
    useEffect( () => {
        if (!window.PRIVATE_CHANNEL) {
            return;
        }
        window.PRIVATE_CHANNEL.notification((notification: BellNotification) => {
            // kept apart from the pages (a page on its way would drop it), shown above the newest page
            const mine = local.current;
            mine.live = [{...notification, is_read: false}, ...mine.live.filter((row) => row.id !== notification.id)].slice(0, LIVE_MAX);
            bump();
            setUnread((value) => value + 1);
            shakeBell();
            new Audio(siteFile('/sounds/notification.mp3')).play().catch(() => undefined);
        });
    }, []);

    // --- the panel ------------------------------------------------------------------------------------------------
    const openPanel = () => {
        if (data && Date.now() - lastFetch.current > FRESH_MS) {
            restart();
        }
        setOpen(true);
    };

    const closePanel = () => {
        setOpen(false);
        setMenuOpen(false);
        setSelectMode(false);
        setSelected(new Set<string>());
        setConfirming(null);
        setProblem(null);
        opener.current = null;
    };

    const backToNewest = () => {
        restart();
        if (box) {
            box.scrollTop = 0;
        }
    };

    const isAllSelected = items.length > 0 && items.every(n => selected.has(n.id));

    const toggleSelectAll = () => {
        setSelected(isAllSelected ? new Set<string>() : new Set<string>(items.map(n => n.id)));
    };

    const toggleSelected = useCallback((id: string) => {
        setSelected(prev => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }, []);

    // R3-016: every control that goes away while it has the focus hands it on: leaving select mode (its Cancel) and
    // "Mark all as read" (gone once nothing is unread) to the menu's button, which stays in the sheet's head.
    const leaveSelect = () => {
        toggleRef.current?.focus();
        setSelectMode(false);
        setSelected(new Set<string>());
        setConfirming(null);
        opener.current = null;
    };

    const markAllRead = () => {
        toggleRef.current?.focus();
        readAll();
    };

    // "Select" in the menu: the select row's first control takes the focus (after the menu gave it back to its button)
    const startSelect = () => {
        focusSelectAll.current = true;
        setSelectMode(true);
        setConfirming(null);
    };

    useEffect(() => {
        if (selectMode && focusSelectAll.current) {
            focusSelectAll.current = false;
            selectAllRef.current?.focus();
        }
    }, [selectMode]);

    // The confirmation ("Delete all" from the menu, "Delete" of the selection) is an alert dialog inside the sheet: it
    // takes the focus on its Cancel (the safe choice) as it opens, after the menu has given the focus back to its
    // button, so a screen reader announces it and the keyboard does not have to pass Close to reach it. Cancel and
    // Escape give the focus back to the control that opened it; after deleting, the menu's button (Delete all), else the
    // selection's first control (the selection's Delete is disabled once its rows are gone).
    const askToDelete = (kind: 'all' | 'selected', from: HTMLElement | null) => {
        opener.current = from;
        setConfirming(kind);
    };

    useEffect(() => {
        if (confirming) {
            cancelRef.current?.focus();
        }
    }, [confirming]);

    const backFromConfirm = (fallback?: HTMLElement | null) => {
        const to = opener.current;
        opener.current = null;
        (canFocus(to) ? to : (canFocus(fallback) ? fallback : toggleRef.current))?.focus();
    };

    const cancelDelete = () => {
        backFromConfirm();
        setConfirming(null);
    };

    const confirmDelete = () => {
        if (confirming === 'all') {
            backFromConfirm();
            deleteAll();
        } else if (confirming === 'selected' && selected.size > 0) {
            opener.current = null;
            (selectAllRef.current ?? toggleRef.current)?.focus();
            removeSelected(Array.from(selected).slice(0, 200));
        } else {
            backFromConfirm();
        }
        setConfirming(null);
    };

    // The menu opened from its button by a key: its first item (Down, Enter, Space) or its last (Up) takes the focus.
    // (Only the effect of an opening reads the mark: Preact runs an earlier render's pending effects first, and the
    // effect of a closing that ran then must not take the mark of the key that opens the menu again.)
    useEffect(() => {
        if (!menuOpen) {
            return;
        }
        const mark = focusOnOpen.current;
        focusOnOpen.current = null;
        if (!mark || Date.now() - mark.at > KEY_MARK_MS) {
            return;
        }
        const items = Array.from(menuRef.current?.querySelectorAll<HTMLElement>(MENU_ITEMS) ?? []);
        (mark.where === 'last' ? items[items.length - 1] : items[0])?.focus();
    }, [menuOpen]);

    // Bootstrap's own Offcanvas never shows this sheet a second time (see BELL_SHEET).
    useEffect(() => {
        document.addEventListener('show.bs.offcanvas', refuseBootstrapShow, true);

        return () => document.removeEventListener('show.bs.offcanvas', refuseBootstrapShow, true);
    }, []);

    // The menu's keys, its closed button's keys and the confirmation's Escape (see MENU_ITEMS), on the window while the
    // sheet is open.
    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKey = (event: KeyboardEvent) => {
            const target = event.target instanceof Node ? event.target : null;
            const confirm = confirmRef.current;
            if (confirming && event.key === 'Escape' && target && confirm?.contains(target)) {
                event.preventDefault();
                event.stopPropagation();
                cancelDelete();
                return;
            }
            const menu = menuRef.current;
            const toggle = toggleRef.current;
            if (!menuOpen) {
                if (toggle && target && toggle.contains(target) && OPEN_KEYS.includes(event.key)
                    && !event.altKey && !event.ctrlKey && !event.metaKey) {
                    event.preventDefault();
                    event.stopPropagation();
                    if (!event.repeat) {
                        focusOnOpen.current = {where: event.key === 'ArrowUp' ? 'last' : 'first', at: Date.now()};
                        spaceOpened.current = event.key === ' ' ? Date.now() : 0;
                        setMenuOpen(true);
                    }
                }
                return;
            }
            if (!menu || !toggle || !target || (!menu.contains(target) && !toggle.contains(target))) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                toggle.focus();
                setMenuOpen(false);
                return;
            }
            const items = Array.from(menu.querySelectorAll<HTMLElement>(MENU_ITEMS));
            const at = items.indexOf(document.activeElement as HTMLElement);
            let next: HTMLElement | undefined;
            if (event.key === 'ArrowDown') {
                next = items[at < 0 ? 0 : Math.min(at + 1, items.length - 1)];
            } else if (event.key === 'ArrowUp') {
                next = items[at < 0 ? items.length - 1 : Math.max(at - 1, 0)];
            } else if ((event.key === 'Home' || event.key === 'End') && menu.contains(target)) {
                next = event.key === 'Home' ? items[0] : items[items.length - 1];
            } else {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            next?.focus();
        };
        // the keyup of the Space that opened the menu would "click" the button (or the item the focus moved to)
        const onKeyUp = (event: KeyboardEvent) => {
            if (event.key !== ' ' || !spaceOpened.current) {
                return;
            }
            const recent = Date.now() - spaceOpened.current <= KEY_MARK_MS;
            spaceOpened.current = 0;
            if (recent) {
                event.preventDefault();
                event.stopPropagation();
            }
        };
        window.addEventListener('keydown', onKey, true);
        window.addEventListener('keyup', onKeyUp, true);

        return () => {
            window.removeEventListener('keydown', onKey, true);
            window.removeEventListener('keyup', onKeyUp, true);
        };
    }, [open, menuOpen, confirming]);

    const badge = badgeText(unread);

    // The rows with the group labels, and each row's time as it reads: "12 minutes ago" today, "Yesterday, 21:06",
    // "Friday, 23 Oct" this week, the site's date format before: the server's words (the same as on the notifications
    // page); a row pushed live gets its words from the device's clock.
    const rows = useMemo(() => {
        const now = Date.now();
        const todayStart = dayStart(now);
        const titles: Record<Group, string> = {today: t('Today'), yesterday: t('Yesterday'), week: t('This week'), earlier: t('Earlier')};
        const out: Array<{group?: {key: Group, title: string}, row: BellNotification, when: string}> = [];
        let last: Group | null = null;
        for (const row of items) {
            let group: Group;
            let when: string;
            if (isGroup(row.group) && typeof row.when === 'string' && row.when !== '') {
                group = row.group;
                when = row.when;
            } else {
                const ms = msOf(row.created_at);
                group = groupOf(ms, todayStart);
                if (group === 'today' || ms <= 0) {
                    when = formatRelativeTime(row.created_at);
                } else if (group === 'yesterday') {
                    when = t('Yesterday, {time}', {time: format(ms, {hour: '2-digit', minute: '2-digit'})});
                } else if (group === 'week') {
                    when = format(ms, {weekday: 'long', day: 'numeric', month: 'short'});
                } else {
                    when = format(ms, new Date(ms).getFullYear() === new Date(now).getFullYear() ? {day: 'numeric', month: 'short'} : {day: 'numeric', month: 'short', year: 'numeric'});
                }
            }
            out.push({group: group !== last ? {key: group, title: titles[group]} : undefined, row, when});
            last = group;
        }
        return out;
    }, [items, t]);

    return (
        <>
            <button
                id="notifications_button"
                type="button"
                className="avd-notification-button avd-icon-button"
                aria-label={badge !== '' ? `${t('Notifications')} (${badge})` : t('Notifications')}
                aria-expanded={open}
                aria-haspopup="dialog"
                onClick={openPanel}
            >
                <i id="bell" className="fa-solid fa-bell" aria-hidden="true"></i>
                {
                    badge !== '' &&
                    <span className="avd-notification-badge has-count" aria-hidden="true">{badge}</span>
                }
            </button>
            {/* The kit's side panel: 420px on desktop (--mk-panel-w, one width for every side panel), full screen on
                phones (_notify.scss). Not the old .avd-notifications-panel: header.scss sizes that one 380px with
                !important, which kept a strip of the page beside the sheet on phones. */}
            <Offcanvas show={open} onHide={closePanel} placement="end" className="mk-drawer mk-notify avd-mk" aria-labelledby="avd-bell-title" data-avd-bell-sheet="">
                <div className="mk-sheet__head mk-notify__head">
                    <h2 className="mk-sheet__title" id="avd-bell-title">
                        <span className="mk-notify__title-text">{t('Notifications')}</span>
                        {badge !== '' && <span className="mk-count mk-count--brand" aria-label={t('{unread} new', {unread: badge})}>{badge}</span>}
                    </h2>
                    {
                        unread > 0 && !selectMode &&
                        /* An icon button at every width, like its neighbours (... and Close): in the 420px panel the
                           labelled button left the title "B..." in German and nothing in Russian. Its
                           name stays in aria-label and title. */
                        <button type="button" className="mk-icon-btn mk-icon-btn--muted mk-notify__markall" onClick={markAllRead}
                                disabled={isReadAllPending} aria-busy={isReadAllPending ? 'true' : undefined}
                                aria-label={t('Mark all as read')} title={t('Mark all as read')}>
                            <i className="fa-solid fa-check-double" aria-hidden="true"></i>
                        </button>
                    }
                    <Dropdown align="end" className="mk-notify__options" show={menuOpen} onToggle={(next: boolean) => setMenuOpen(next)}>
                        <Dropdown.Toggle ref={toggleRef} as="button" type="button" bsPrefix="mk-icon-btn" className="mk-icon-btn--muted" aria-label={t('More options')} title={t('More options')}>
                            <i className="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                        </Dropdown.Toggle>
                        <Dropdown.Menu ref={menuRef} className="mk-menu" role="menu">
                            {items.length > 0 && !selectMode && (
                                <Dropdown.Item as="button" bsPrefix="mk-menu__item" className="dropdown-item" role="menuitem" onClick={startSelect}>
                                    <i className="fa-regular fa-square-check" aria-hidden="true"></i>{t('Select')}
                                </Dropdown.Item>
                            )}
                            {settingsUrl && (
                                <Dropdown.Item href={settingsUrl} bsPrefix="mk-menu__item" className="dropdown-item" role="menuitem">
                                    <i className="fa-solid fa-gear" aria-hidden="true"></i>{t('Notification settings')}
                                </Dropdown.Item>
                            )}
                            {items.length > 0 && (
                                <>
                                    <Dropdown.Divider/>
                                    <Dropdown.Item as="button" bsPrefix="mk-menu__item" className="dropdown-item mk-menu__item--danger" role="menuitem" onClick={() => askToDelete('all', toggleRef.current)}>
                                        <i className="fa-regular fa-trash-can" aria-hidden="true"></i>{t('Delete all')}
                                    </Dropdown.Item>
                                </>
                            )}
                        </Dropdown.Menu>
                    </Dropdown>
                    <button type="button" className="mk-icon-btn mk-icon-btn--muted" aria-label={t('Close')} title={t('Close')} onClick={closePanel}>
                        <i className="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>

                {problem && (
                    <div ref={alertRef} className="mk-alert mk-alert--danger mk-notify__alert" role="alert" data-avd-bell-alert>
                        <i className="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                        <div className="mk-alert__body"><span>{problem}</span></div>
                        <button type="button" className="mk-icon-btn mk-icon-btn--muted mk-icon-btn--sm" aria-label={t('Close')} title={t('Close')} onClick={dismissProblem}>
                            <i className="fa-solid fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                )}

                {confirming && (
                    <div ref={confirmRef} className="mk-notify__confirm" role="alertdialog" aria-labelledby="avd-bell-confirm">
                        <p id="avd-bell-confirm">
                            {confirming === 'all'
                                ? t('Delete all your notifications? This cannot be undone.')
                                : t('Delete the selected notifications? This cannot be undone.')}
                        </p>
                        <div className="mk-notify__confirm-actions">
                            <button ref={cancelRef} type="button" className="mk-btn mk-btn--secondary mk-btn--sm" onClick={cancelDelete}>{t('Cancel')}</button>
                            <button type="button" className="mk-btn mk-btn--danger mk-btn--sm" onClick={confirmDelete} disabled={isDeleteAllPending}>
                                <i className="fa-regular fa-trash-can" aria-hidden="true"></i>
                                <span>{confirming === 'all' ? t('Delete all') : t('Delete')}</span>
                            </button>
                        </div>
                    </div>
                )}

                {selectMode && (
                    <div className="mk-notify__tools">
                        <label className="mk-notify__all">
                            <input ref={selectAllRef} type="checkbox" className="mk-check" checked={isAllSelected} onChange={toggleSelectAll} aria-label={t('Select all')}/>
                            <span>{selected.size > 0 ? t('{count} selected', {count: selected.size}) : t('Select all')}</span>
                        </label>
                        <div className="mk-notify__tools-actions">
                            <button type="button" className="mk-btn mk-btn--danger-quiet mk-btn--sm" disabled={selected.size === 0} onClick={(event) => askToDelete('selected', event.currentTarget as HTMLElement)}>
                                <i className="fa-regular fa-trash-can" aria-hidden="true"></i><span>{t('Delete')}</span>
                            </button>
                            <button type="button" className="mk-btn mk-btn--quiet mk-btn--sm" onClick={leaveSelect}><span>{t('Cancel')}</span></button>
                        </div>
                    </div>
                )}

                <div className="mk-sheet__body mk-notify__body avd-bell-scroll" ref={setBox} data-avd-scroll>
                    {
                        isLoading &&
                        <div className="mk-notify__loading" aria-busy="true" aria-label={t('Loading...')}>
                            {[0, 1, 2, 3].map((n) => (
                                <div className="mk-notif mk-notif--skel" key={n} aria-hidden="true">
                                    <span className="mk-skel mk-notif__skel-icon"></span>
                                    <span className="mk-notif__skel-text"><span className="mk-skel mk-skel--line"></span><span className="mk-skel mk-skel--line"></span></span>
                                </div>
                            ))}
                        </div>
                    }
                    {
                        isError &&
                        <div className="mk-empty mk-empty--compact" role="alert">
                            <span className="mk-empty__icon" aria-hidden="true"><i className="fa-solid fa-triangle-exclamation"></i></span>
                            <p className="mk-empty__text">{t('The notifications could not be loaded.')}</p>
                            <div className="mk-empty__actions">
                                <button type="button" className="mk-btn mk-btn--secondary mk-btn--sm" onClick={() => refetch()}>
                                    <i className="fa-solid fa-rotate-right" aria-hidden="true"></i><span>{t('Try again')}</span>
                                </button>
                            </div>
                        </div>
                    }
                    {
                        hasPreviousPage &&
                        <button type="button" className="mk-notify__newer" ref={newerRef} data-avd-newer-above onClick={backToNewest}>
                            {isFetchingPreviousPage ? t('Loading...') : t('Newer notifications')}
                        </button>
                    }
                    {
                        data && (
                            items.length > 0 ?
                                <ul className="mk-notify-list avd-bell-list">
                                    {rows.map(({group, row, when}) => (
                                        <Fragment key={row.id}>
                                            {group && <li className="mk-notify__group">{group.title}</li>}
                                            <Notification
                                                notification={row}
                                                when={when}
                                                selectMode={selectMode}
                                                isSelected={selected.has(row.id)}
                                                onToggleSelect={toggleSelected}
                                            />
                                        </Fragment>
                                    ))}
                                </ul>
                                :
                                !hasPreviousPage && !hasNextPage &&
                                <div className="mk-empty mk-empty--compact">
                                    <span className="mk-empty__icon" aria-hidden="true"><i className="fa-regular fa-bell"></i></span>
                                    <h3 className="mk-empty__title">{t('No notifications yet')}</h3>
                                    <p className="mk-empty__text">{t('New subscribers, comments on your videos and new videos from channels you follow show here.')}</p>
                                </div>
                        )
                    }
                    {
                        isFetchingNextPage &&
                        <div className="mk-notify__more" role="status">
                            <span className="mk-spinner" aria-hidden="true"></span>
                            <span className="mk-sr">{t('Loading...')}</span>
                        </div>
                    }
                    {hasNextPage && <div className="avd-bell-sentinel" ref={olderRef} aria-hidden="true"></div>}
                    {
                        data && !hasNextPage && items.length > 0 &&
                        <p className="mk-endline" data-avd-end><i className="fa-solid fa-check" aria-hidden="true"></i>{t('You are all caught up')}</p>
                    }
                </div>

                {(settingsUrl || pageUrl) && (
                    <div className="mk-sheet__foot mk-notify__foot">
                        {settingsUrl && <a className="mk-btn mk-btn--quiet mk-btn--sm" href={settingsUrl}><i className="fa-solid fa-gear" aria-hidden="true"></i><span>{t('Settings')}</span></a>}
                        {pageUrl && <a className="mk-btn mk-btn--secondary mk-btn--sm" href={pageUrl}><span>{t('See all')}</span></a>}
                    </div>
                )}
            </Offcanvas>
        </>
    )
}

export function NotificationsList(props : Props) {

    const [queryClient] = useState(() => new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            }
        }
    }));

    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props}/>
        </QueryClientProvider>
    )
}
