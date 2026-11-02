import {useCallback, useEffect, useId, useMemo, useRef, useState} from 'preact/hooks';
import {createPortal} from 'preact/compat';
import type {ComponentChildren} from 'preact';
import {useTranslation} from 'react-i18next';
import {Dropdown} from 'react-bootstrap';
import {clsx} from 'clsx';
import {useErrorMutation} from '@/hooks/useErrorMutation';
import {ChannelBell as Level, getChannelBell, setChannelBell} from '@/api/avdbclip/notifications';

/**
 * The bell menu of a followed channel (member design 6.13, 6.14; mockup public-watch-member): the "Subscribed"
 * button opens a menu "New videos from <channel>" with All / Only highlights / None (a tick on the chosen one), then
 * "Unsubscribe..." after a divider. The choice is the member's bell for that channel (subscriptions.notify, the route
 * /api/notifications/channels/{channel}) and wins over the global switch "New videos from channels I follow"; "Only
 * highlights" = new videos that are not Shorts.
 *
 * Subscribe.tsx renders it for a subscribed member: <ChannelBellMenu user={id} name={channel}
 * onUnsubscribe={() => setAsking(true)} toggleClassName="mk-btn mk-btn--secondary ..." label={t('Subscribed')} />.
 * The level is read when the menu opens the first time (no request per button on pages with many channels) unless the
 * page passes it (level="all|highlights|none"). It needs a QueryClientProvider above it (Subscribe has one).
 *
 * Where the menu is drawn (R1-003): not inside the button's row. A row that is its own stacking context (the watch
 * page's channel panel: position + isolation + layout containment) kept the open menu under the panels that follow it,
 * so "All" and "Only highlights" could not be clicked. The menu is drawn at the end of the page instead (or at the end
 * of the dialog or side panel the button sits in, so their focus trap keeps working), placed by Popper with the fixed
 * strategy (it follows the button while the page scrolls), with the kit's variables (avd-mk) and hidden until Popper
 * has placed it. role="menu" + items that are .dropdown-item give it the arrow keys; Tab leaves the menu where the
 * button is (the menu is not next to the button in the page any more).
 *
 * 1.2.3 round 2:
 * - R2A-016: the level is loaded before the menu opens (when the member presses the button, or stays on it with the
 *   keyboard), and All / Only highlights / None are never disabled while it loads: the first keyboard opening of a page
 *   focuses "All" like every later one (the disabled levels sent it to "Unsubscribe..."). A level picked before the
 *   stored one arrived is sent as it is and wins over the late answer.
 * - R2A-028: the menu is drawn on the window's layer, above the sticky header. It never goes over the header: it does
 *   not open upwards into it (Popper's flip keeps the header's height free), it closes when its button goes under the
 *   header while the page scrolls (RC1's menu went under the header with its button), and a button the header covers
 *   when the menu opens (the keyboard reached it there: the browser does not scroll a covered button into view, or
 *   its own smooth scroll is still bringing the button out) is first moved just below the header, at once, so the menu
 *   opens below a visible button. A button that the browser is still scrolling into view keeps its menu open.
 */

const LEVELS: Level[] = ['all', 'highlights', 'none'];

const EVENT = 'avd:channel-bell';

export const isLevel = (value: unknown): value is Level => typeof value === 'string' && (LEVELS as string[]).includes(value);

/** The bell of one channel: its level (null until known), a loader, a setter and a sync for other buttons. */
export function useChannelBell(user: number | string, initial?: Level | null) {
    const [level, setLevel] = useState<Level | null>(isLevel(initial) ? initial : null);
    const [unavailable, setUnavailable] = useState<boolean>(false);
    const loading = useRef<boolean>(false);

    const load = useCallback(async () => {
        if (level !== null || loading.current || unavailable) {
            return;
        }
        loading.current = true;
        try {
            const answer = await getChannelBell(String(user));
            // a level the member picked while this answer was on its way (sent, shown with its tick) stays
            setLevel((current) => current ?? (isLevel(answer?.level) ? answer.level : 'all'));
        } catch (error) {
            // not followed (404) or the site's update not finished (409): the menu keeps Unsubscribe only. Any other
            // failure (no connection, too many tries, a server error) leaves the levels without a tick; the next
            // opening asks again.
            const status = (error as {status?: number} | null)?.status;
            if (status === 404 || status === 409) {
                setUnavailable(true);
            }
        } finally {
            loading.current = false;
        }
    }, [user, level, unavailable]);

    const {isPending, mutate} = useErrorMutation({
        mutationKey: ['channel-bell', String(user)],
        mutationFn: (wanted: Level) => setChannelBell(String(user), wanted),
        onMutate: (wanted: Level) => {
            const before = level;
            setLevel(wanted);

            return {before};
        },
        onSuccess: (answer: {level: Level}) => {
            if (isLevel(answer?.level)) {
                setLevel(answer.level);
                document.dispatchEvent(new CustomEvent(EVENT, {detail: {user: String(user), level: answer.level}}));
            }
        },
        onSettled: (_answer, error, _wanted, context) => {
            // on a failure the toast of useErrorMutation tells why, and the tick goes back to the stored choice
            if (error) {
                setLevel((context as {before: Level | null} | undefined)?.before ?? null);
            }
        },
    });

    return {
        level,
        unavailable,
        load,
        choose: (wanted: Level) => mutate(wanted),
        sync: (known: Level) => setLevel(known),
        isPending,
    };
}

type MenuProps = {
    user: number | string,
    name?: string,
    level?: string | null,
    label: ComponentChildren,
    /**
     * The button's two faces ("Subscribe" and "Subscribed"), drawn invisibly under the visible one in the look's local
     * fallback font: the button is as wide in both states (R1-125) and keeps its width when the look's web font arrives
     * (R2A-027). Subscribe passes its two .mk-subscribe__face with aria-hidden.
     */
    sizer?: ComponentChildren,
    toggleClassName?: string,
    disabled?: boolean,
    onUnsubscribe: () => void,
    align?: 'start' | 'end',
};

// The bottom edge of the site's header where it stays on the screen (sticky or fixed), in window pixels; 0 when the
// header scrolls away with the page or is hidden (R2A-028).
export const headerEdge = (): number => {
    if (typeof document === 'undefined') {
        return 0;
    }
    const header = document.querySelector<HTMLElement>('[data-avd-header]');
    if (header === null) {
        return 0;
    }
    const position = window.getComputedStyle(header).position;
    if (position !== 'sticky' && position !== 'fixed') {
        return 0;
    }
    const box = header.getBoundingClientRect();

    return box.height > 0 && box.bottom > 0 ? box.bottom : 0;
};

// R2A-028: the room left between the header's edge and a button moved out from under it (its focus ring stays visible).
const REVEAL_ROOM = 8;

// R2A-028: a button the sticky header covers is moved just below the header before its menu opens. The page moves at
// once: the site's html { scroll-behavior: smooth } (or an owner's own CSS, even with !important) is set aside for this
// one call by an inline !important declaration, put back as it was right after (an animated move would draw the menu
// over the header on its way), and the instant move also ends a smooth scroll the browser was running to bring the
// focused button into view (checked in Chromium, WebKit and Firefox). Returns the gap between the button's top and the
// header's edge afterwards (0 when no header stays on the screen).
const revealBelowHeader = (toggle: HTMLElement): number => {
    const edge = headerEdge();
    if (edge <= 0) {
        return 0;
    }
    const top = toggle.getBoundingClientRect().top;
    if (top < edge) {
        const style = document.documentElement.style;
        const value = style.getPropertyValue('scroll-behavior');
        const priority = style.getPropertyPriority('scroll-behavior');
        style.setProperty('scroll-behavior', 'auto', 'important');
        window.scrollBy(0, top - edge - REVEAL_ROOM);
        if (value === '') {
            style.removeProperty('scroll-behavior');
        } else {
            style.setProperty('scroll-behavior', value, priority);
        }
    }

    return toggle.getBoundingClientRect().top - headerEdge();
};

// Fixed: the menu is placed against the window, so no ancestor of the page (a contained or transformed panel) can
// shift or clip it; Popper moves it with the button while the page scrolls. Not adaptive: Popper places it from the
// window's left and top only (its right-anchored styles measured the window without the scroll bar, so on desktop
// the menu's end edge stood 10 px off the button's). The flip keeps the header's height free (R2A-028): the menu opens
// upwards only where it fits between the header and its button (measured when the menu opens).
const popperConfig = (headerBottom: number) => ({
    strategy: 'fixed' as const,
    modifiers: [
        {name: 'computeStyles', options: {adaptive: false}},
        {name: 'flip', options: {padding: {top: headerBottom, right: 0, bottom: 0, left: 0}}},
    ],
});

// The dialog or side panel the button sits in (their focus trap must keep the menu's focus), else the page.
const menuHost = (toggle: HTMLElement | null): HTMLElement | null => {
    if (typeof document === 'undefined') {
        return null;
    }

    return toggle?.closest<HTMLElement>('.modal, .offcanvas') ?? document.body;
};

// R2A-016: how long the keyboard focus stays on the button before its level is asked for (a member who only tabs past
// a list of channels sends no request; one who stops on it has the level by the time the menu opens).
const FOCUS_INTENT_MS = 250;

// Popper places the menu with physical sides; react-bootstrap reads right-to-left only from its own ThemeProvider,
// which the site does not use. In a right-to-left page the logical end of the button is its left edge.
const physicalAlign = (align: 'start' | 'end', toggle: HTMLElement | null): 'start' | 'end' => {
    const rtl = toggle !== null && typeof window !== 'undefined' && window.getComputedStyle(toggle).direction === 'rtl';

    return rtl ? (align === 'end' ? 'start' : 'end') : align;
};

const ICONS: Record<Level, string> = {
    all: 'fa-solid fa-bell',
    highlights: 'fa-regular fa-bell',
    none: 'fa-regular fa-bell-slash',
};

// The focus came from the keyboard (an older browser without :focus-visible counts every focus as such).
const keyboardFocus = (element: HTMLElement): boolean => {
    try {
        return element.matches(':focus-visible');
    } catch {
        return true;
    }
};

export function ChannelBellMenu({user, name, level: initial, label, sizer, toggleClassName, disabled = false, onUnsubscribe, align = 'start'}: MenuProps) {
    const {t} = useTranslation();
    const {level, unavailable, load, choose, sync, isPending} = useChannelBell(user, isLevel(initial) ? initial : null);
    const channel = name && name.trim() !== '' ? name : t('this channel');
    const toggleRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const noteId = useId();
    // where the menu is drawn and on which side it lines up, known once the button is on the page (before any open)
    const [host, setHost] = useState<HTMLElement | null>(null);
    const [side, setSide] = useState<'start' | 'end'>(align);
    // open or not (R2A-028 closes it when its button goes under the header) and the header's edge at the opening
    const [show, setShow] = useState<boolean>(false);
    const [headerBottom, setHeaderBottom] = useState<number>(0);
    const config = useMemo(() => popperConfig(headerBottom), [headerBottom]);
    const intent = useRef<number>(0);
    // R2A-028: the gap between the button's top and the header's edge when the menu opened (after the reveal)
    const openGap = useRef<number>(0);

    useEffect(() => {
        setHost(menuHost(toggleRef.current));
        setSide(physicalAlign(align, toggleRef.current));
    }, [align]);

    // R2A-016: the level is asked for before the menu opens: when the button is pressed (pointer), or when the keyboard
    // focus stays on it a moment (not while tabbing past a list of channels: each request counts against the site's
    // API limit). The opening itself asks too (onToggle) if it is still unknown.
    const loadSoon = () => {
        window.clearTimeout(intent.current);
        const toggle = toggleRef.current;
        if (level !== null || unavailable || toggle === null || !keyboardFocus(toggle)) {
            return;
        }
        intent.current = window.setTimeout(() => { void load(); }, FOCUS_INTENT_MS);
    };
    const loadNow = () => {
        window.clearTimeout(intent.current);
        void load();
    };
    useEffect(() => () => window.clearTimeout(intent.current), []);

    const close = useCallback(() => {
        // the focus goes back to the button without scrolling the page to it (react-bootstrap would focus it with a
        // scroll when the menu closes with the focus inside)
        const toggle = toggleRef.current;
        const menu = menuRef.current;
        if (toggle !== null && menu !== null && menu.contains(document.activeElement)) {
            toggle.focus({preventScroll: true});
        }
        setShow(false);
    }, []);

    // R2A-028: while the menu is open on the page (not in a dialog or side panel, which sit above the header), its
    // button going under the sticky header closes it: the menu is never drawn over the header. Scrolls of the page and
    // of any panel around the button are followed (capture), once per frame. Only a button that goes further under the
    // header than it was at the opening counts: the opening has moved a covered button below the header, and a scroll
    // that brings the button out from under the header (the browser's own smooth scroll to a focused button) keeps the
    // menu open, so no key press is lost.
    useEffect(() => {
        if (!show || host === null || host !== document.body) {
            return undefined;
        }
        let frame = 0;
        const check = () => {
            frame = 0;
            const toggle = toggleRef.current;
            const edge = headerEdge();
            if (toggle === null || edge <= 0) {
                return;
            }
            const limit = openGap.current < 0 ? openGap.current - 1 : 0;
            if (toggle.getBoundingClientRect().top - edge < limit) {
                close();
            }
        };
        const onMove = () => {
            if (frame === 0) {
                frame = window.requestAnimationFrame(check);
            }
        };
        window.addEventListener('scroll', onMove, {passive: true, capture: true});
        window.addEventListener('resize', onMove, {passive: true});

        return () => {
            window.removeEventListener('scroll', onMove, {capture: true} as EventListenerOptions);
            window.removeEventListener('resize', onMove);
            if (frame !== 0) {
                window.cancelAnimationFrame(frame);
            }
        };
    }, [show, host, close]);

    // the levels go away when the channel's bell cannot be read (not followed any more, the site's update not
    // finished): the focus that was on one of them goes to Unsubscribe..., never to the page
    useEffect(() => {
        const menu = menuRef.current;
        if (!unavailable || !show || menu === null) {
            return;
        }
        const active = document.activeElement;
        if (active === null || active === document.body || !document.contains(active)) {
            menu.querySelector<HTMLElement>('.dropdown-item')?.focus({preventScroll: true});
        }
    }, [unavailable, show]);

    const onToggle = (open: boolean) => {
        if (open) {
            // R2A-028: on the page, a button the header covers comes out from under it first; the header's edge (the
            // flip's padding) and the button's gap to it are measured after that move
            const toggle = toggleRef.current;
            const onPage = host === document.body;
            openGap.current = onPage && toggle !== null ? revealBelowHeader(toggle) : 0;
            setHeaderBottom(onPage ? headerEdge() : 0);
            loadNow();
        }
        setShow(open);
    };

    // Tab inside the menu goes on from the button's place in the page (Shift+Tab back to the button itself), as when
    // the menu followed the button; the menu then closes (react-bootstrap closes it once the focus has left it).
    const leaveWithTab = (event: {key: string, shiftKey: boolean, preventDefault: () => void}) => {
        const toggle = toggleRef.current;
        if (event.key !== 'Tab' || toggle === null) {
            return;
        }
        if (event.shiftKey) {
            event.preventDefault();
        }
        toggle.focus({preventScroll: true});
    };

    // another button of the same channel on the page (the watch page has two) follows a change made there
    useEffect(() => {
        const follow = (event: Event) => {
            const detail = (event as CustomEvent<{user: string, level: Level}>).detail;
            if (detail && String(detail.user) === String(user) && isLevel(detail.level)) {
                sync(detail.level);
            }
        };
        document.addEventListener(EVENT, follow);

        return () => document.removeEventListener(EVENT, follow);
    }, [user]);

    const pick = (wanted: Level) => {
        if (wanted !== level && !isPending) {
            choose(wanted);
        }
    };

    const items: {value: Level, text: string}[] = [
        {value: 'all', text: t('All')},
        {value: 'highlights', text: t('Only highlights')},
        {value: 'none', text: t('None')},
    ];

    // bsPrefix: the menu is not a Bootstrap .dropdown-menu. Bootstrap's own keyboard handler (a capture listener on
    // the document for every .dropdown-menu) took the arrow keys and Escape of a menu at the end of the page and
    // opened the site's language menu instead; the menu's own rules are in member/_notify.scss.
    // R2A-016: the levels are never disabled (a disabled first item sent the first keyboard opening to "Unsubscribe...");
    // until the stored level is known none is ticked, and a level picked meanwhile is sent as it is.
    const menu = (
        <Dropdown.Menu
            ref={menuRef}
            bsPrefix="mk-bell-menu__menu"
            className="mk-menu avd-mk"
            role="menu"
            aria-label={t('Notifications from {name}', {name: channel})}
            popperConfig={config}
            onKeyDown={leaveWithTab}
        >
            {!unavailable && (
                <>
                    <div role="group" aria-labelledby={noteId}>
                        <span className="mk-menu__note" id={noteId}>{t('New videos from {name}', {name: channel})}</span>
                        {items.map(({value, text}) => (
                            <Dropdown.Item
                                key={value}
                                as="button"
                                type="button"
                                bsPrefix="mk-menu__item"
                                className="dropdown-item"
                                role="menuitemradio"
                                aria-checked={level === value ? 'true' : 'false'}
                                onClick={() => pick(value)}
                            >
                                <i className={ICONS[value]} aria-hidden="true"></i>
                                {text}
                                {level === value && <i className="fa-solid fa-check mk-menu__tick" aria-hidden="true"></i>}
                            </Dropdown.Item>
                        ))}
                    </div>
                    <Dropdown.Divider/>
                </>
            )}
            <Dropdown.Item as="button" type="button" bsPrefix="mk-menu__item" className="dropdown-item" role="menuitem" onClick={onUnsubscribe}>
                <i className="fa-solid fa-user-minus" aria-hidden="true"></i>
                {t('Unsubscribe')}…
            </Dropdown.Item>
        </Dropdown.Menu>
    );

    return (
        <Dropdown align={side} show={show} onToggle={onToggle} className="mk-bell-menu">
            <Dropdown.Toggle
                ref={toggleRef}
                as="button"
                type="button"
                bsPrefix="mk-bell-menu__toggle"
                className={clsx(toggleClassName)}
                disabled={disabled}
                onPointerDown={loadNow}
                onFocus={loadSoon}
                onBlur={() => window.clearTimeout(intent.current)}
            >
                <span className="mk-subscribe__face">
                    <i className={clsx(ICONS[level ?? 'all'], 'fa-fw')} aria-hidden="true"></i>
                    {label}
                    <i className="fa-solid fa-chevron-down mk-bell-menu__chevron" aria-hidden="true"></i>
                </span>
                {sizer}
            </Dropdown.Toggle>
            {host !== null ? createPortal(menu, host) : null}
        </Dropdown>
    );
}
