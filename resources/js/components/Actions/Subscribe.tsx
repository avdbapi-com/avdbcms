import type {ComponentChildren, ComponentType} from 'preact';
import {useEffect, useState} from 'preact/hooks';
import {useTranslation} from "react-i18next";
import {Modal} from "react-bootstrap";
import {QueryClient, QueryClientProvider, useMutation} from '@tanstack/react-query'
import {jsonFetch} from "@/functions/api";
import {API_URL} from "@/api/avdbclip/config";
import {clsx} from "clsx";

/**
 * The Subscribe button of every page (channel, watch page, Manage subscriptions, Discover: member design 5.4, 6.10,
 * 6.13; QA channel-17, watch-35): the kit's mk-btn with one fixed width, so the button never changes size.
 *
 * - Not subscribed: the look's primary "Subscribe".
 * - Subscribed: a secondary "Subscribed". With the channel bell (its ChannelBellMenu component and the
 *   /api/notifications/channels/{id} route, which come together) it opens the bell menu (All / Only
 *   highlights / None, then Unsubscribe...); without it, it asks to unsubscribe at once. Unsubscribing always asks once
 *   in a dialog ("Unsubscribe from <name>?"), so one click never ends a subscription by mistake.
 * - The request names the wanted state (state=on|off): a second tab or a double tap can no longer flip it back.
 * - After a change, every element of the page with data-subscriber-count="<channel id>" shows the server-made text of
 *   the new state (data-count-on / data-count-off), so the channel's subscriber count follows at once.
 * - A refusal (a view-only demo account, 6.18) or a failed request closes the dialog and shows the server's words in
 *   the site's toast (window.avdToast), never the old red Bootstrap toast behind the dialog.
 *
 * Attributes: user (the channel's id), subscribed="1|0" (else the 1.2.2 is-subscribe attribute: present = not
 * subscribed), name (the channel's name, for the dialog), size="sm", bell="0" (no bell menu).
 */
type Props = {
    isSubscribe?: boolean | string,
    subscribed?: string,
    user: number | string,
    name?: string,
    size?: string | null,
    bell?: string,
    class?: string,
    className?: string,
}

type SubscribeAnswer = {
    subscribed: boolean,
    subscribers: number | null,
} | null;

type BellMenuProps = {
    user: number | string,
    name?: string,
    label: ComponentChildren,
    sizer?: ComponentChildren,
    toggleClassName?: string,
    disabled?: boolean,
    onUnsubscribe: () => void,
    align?: 'start' | 'end',
};

/**
 * R2A-027: the invisible faces that size the button are drawn in the look's metric-matched fallback face (a local font,
 * R1-068 / R1R-03), so the button keeps its width when the look's web font arrives. --mk-sizer is the look's body
 * font list without its web font (the fallback face and the local fonts after it: a browser that cannot open the local
 * bold face falls back to a local font, never to the web font); library/subscribe.blade.php sets it in the page's head
 * before the first paint (the server-printed buttons), this sets it for buttons drawn only here (the watch page).
 * Nothing is set where the look's font has no fallback face: its web font sizes the buttons, as before.
 */
export const ensureFaceSizer = (): void => {
    if (typeof document === 'undefined') {
        return;
    }
    try {
        const root = document.documentElement;
        if (root.style.getPropertyValue('--mk-sizer') !== '') {
            return;
        }
        const stack = getComputedStyle(root).getPropertyValue('--avd-font-body').split(',');
        const family = (entry: string): string => entry.trim().replace(/^["']|["']$/g, '');
        if (stack.length > 1 && family(stack[1]) === family(stack[0]) + ' Fallback') {
            root.style.setProperty('--mk-sizer', stack.slice(1).join(','));
        }
    } catch {
        // no computed styles (a detached document): the web font sizes the buttons
    }
};

ensureFaceSizer();

// The bell menu, when it is part of the build (import.meta.glob gives {} without the file, so this button works
// with or without it).
const bellModules = import.meta.glob<{ChannelBellMenu?: ComponentType<BellMenuProps>}>('../Notifications/ChannelBell.tsx', {eager: true});
const ChannelBellMenu: ComponentType<BellMenuProps> | null = Object.values(bellModules)[0]?.ChannelBellMenu ?? null;

const initialState = (subscribed: string | undefined, isSubscribe: boolean | string | undefined): boolean => {
    if (subscribed === '1' || subscribed === 'true') {
        return true;
    }

    if (subscribed === '0' || subscribed === 'false') {
        return false;
    }

    // 1.2.2 markup: the attribute is-subscribe is PRESENT when the member is not subscribed (its value is then '').
    return isSubscribe === undefined || isSubscribe === true;
};

// the member pages print data-subscriber-count + data-count-on|off; the watch page (watch-17) prints
// data-avd-subscriber-count + data-avd-count-on|off, which its layout script follows only through a button's
// aria-pressed (the bell menu's "Subscribed" has none), so this button writes both kinds itself
const showCount = (user: number | string, subscribed: boolean): void => {
    const id = String(user);
    document.querySelectorAll<HTMLElement>(`[data-subscriber-count="${id}"], [data-avd-subscriber-count="${id}"]`).forEach((element) => {
        const text = element.dataset[subscribed ? 'countOn' : 'countOff'] ?? element.dataset[subscribed ? 'avdCountOn' : 'avdCountOff'];

        if (typeof text === 'string' && text !== '') {
            element.textContent = text;
        }
    });
};

const escapeHtml = (text: string): string => text.replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c] as string));

function Main({isSubscribe, subscribed: subscribedAttr, user, name, size = null, bell, class: elementClass = '', className = ''}: Props) {

    const {t} = useTranslation();

    const [subscribed, setSubscribed] = useState<boolean>(initialState(subscribedAttr, isSubscribe));
    const [asking, setAsking] = useState<boolean>(false);

    // another Subscribe button of the same channel on this page (the watch page has two) follows this one
    useEffect(() => {
        const follow = (event: Event) => {
            const detail = (event as CustomEvent<{user: string, subscribed: boolean}>).detail;
            if (detail && String(detail.user) === String(user)) {
                setSubscribed(detail.subscribed);
            }
        };

        document.addEventListener('avd:subscription', follow);

        return () => document.removeEventListener('avd:subscription', follow);
    }, [user]);

    const {isPending, mutate} = useMutation({
        mutationKey: ['subscribe', user],
        mutationFn: (state: 'on' | 'off') => jsonFetch(API_URL + `/subscribe/${user}`, 'POST', {state}) as Promise<SubscribeAnswer>,
        onSuccess: (answer: SubscribeAnswer, state: 'on' | 'off') => {
            const now = answer ? Boolean(answer.subscribed) : state === 'on';
            setSubscribed(now);
            setAsking(false);
            showCount(user, now);
            document.dispatchEvent(new CustomEvent('avd:subscription', {detail: {user: String(user), subscribed: now}}));
        },
        onError: (error: unknown) => {
            setAsking(false);
            // jsonFetch throws a plain Error with the server's message; a request with no answer (TypeError "Failed to
            // fetch") or no JSON (SyntaxError) gets the page's own sentence
            const message = error instanceof Error && error.constructor === Error && error.message !== '' && error.message !== 'Oups something went wrong !'
                ? error.message
                : t('That did not work. Check your connection and try again.');
            if (typeof window.avdToast === 'function') {
                window.avdToast(escapeHtml(message), 'danger');
            }
        },
    });

    const small = size === 'sm';
    const channel = name && name.trim() !== '' ? name : t('this channel');
    // avd-mk: the kit's own variables (control height, radius, timing) on the button itself, so it looks the same
    // outside a member page root (the watch page's channel row, the likes list: watch-35)
    const buttonClass = clsx('avd-mk mk-btn mk-btn--fixed mk-subscribe mk-subscribe--faces', subscribed ? 'mk-btn--secondary' : 'mk-btn--primary', {'mk-btn--sm': small}, elementClass, className);
    // the bell menu comes with its component (and its route, which come together); bell="0" leaves it out
    const bellAvailable = ChannelBellMenu !== null && bell !== '0';
    const withBell = subscribed && bellAvailable;

    // One width in both states, in every language and look (R1-125, channel-17), and before and after the look's web
    // font arrives (R2A-027): the button holds both faces invisibly in one grid cell, "Subscribe" and "Subscribed"
    // (with the bell menu's chevron when there is one), drawn in the look's local fallback font, and the face of its
    // state is drawn over them in the look's own font (member/_shared.scss .mk-subscribe--faces). The invisible faces
    // are hidden from screen readers. Icons are fixed-width (fa-fw, the chevron 1em) and the busy spinner takes the
    // icon's place, so nothing inside changes the width.
    const icon = (classes: string, busy: boolean) => busy
        ? <span className="mk-spinner" aria-hidden="true"></span>
        : <i className={clsx(classes, 'fa-fw')} aria-hidden="true"></i>;
    const offFace = (visible: boolean) => (
        <span className="mk-subscribe__face mk-subscribe__face--off" aria-hidden={visible ? undefined : 'true'}>
            {icon('fa-solid fa-bell', visible && isPending)}
            <span>{t('Subscribe')}</span>
        </span>
    );
    const onFace = (visible: boolean) => (
        <span className="mk-subscribe__face mk-subscribe__face--on" aria-hidden={visible ? undefined : 'true'}>
            {icon(clsx('fa-solid', bellAvailable ? 'fa-bell' : 'fa-check'), visible && isPending)}
            <span>{t('Subscribed')}</span>
            {bellAvailable && <i className="fa-solid fa-chevron-down mk-bell-menu__chevron" aria-hidden="true"></i>}
        </span>
    );
    const sizer = <>{offFace(false)}{onFace(false)}</>;

    return (
        <>
            {withBell && ChannelBellMenu ? (
                <ChannelBellMenu
                    user={user}
                    name={channel}
                    label={<span>{t('Subscribed')}</span>}
                    sizer={sizer}
                    toggleClassName={clsx(buttonClass, 'mk-subscribe--bell')}
                    disabled={isPending}
                    onUnsubscribe={() => setAsking(true)}
                    align="end"
                />
            ) : (
                <button
                    type="button"
                    onClick={() => (subscribed ? setAsking(true) : mutate('on'))}
                    className={buttonClass}
                    disabled={isPending}
                    aria-pressed={subscribed}
                    aria-busy={isPending}
                    data-avd-control="subscribe"
                >
                    {subscribed ? onFace(true) : offFace(true)}
                    {sizer}
                </button>
            )}
            <Modal
                show={asking}
                onHide={() => setAsking(false)}
                centered
                dialogClassName="mk-dialog mk-confirm avd-mk"
                contentClassName="avd-mk"
                aria-labelledby={`mkUnsubscribe${user}`}
            >
                <div className="mk-sheet__head">
                    <h2 className="mk-sheet__title" id={`mkUnsubscribe${user}`}>{t('Unsubscribe from {name}?', {name: channel})}</h2>
                    <button type="button" className="mk-icon-btn mk-icon-btn--muted" onClick={() => setAsking(false)} aria-label={t('Close')} title={t('Close')}>
                        <i className="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                <div className="mk-sheet__body">
                    <p className="mk-sheet__lead">{t('Their new videos stop showing in your subscriptions feed and notifications. You can subscribe again any time.')}</p>
                </div>
                <div className="mk-sheet__foot">
                    <button type="button" className="mk-btn mk-btn--secondary" onClick={() => setAsking(false)}>{t('Cancel')}</button>
                    {/* the one filled button of a confirmation is its final, danger action (5.4, sheets-gallery) */}
                    <button type="button" className="mk-btn mk-btn--danger" onClick={() => mutate('off')} disabled={isPending} aria-busy={isPending} data-avd-control="unsubscribe">
                        {isPending
                            ? <span className="mk-spinner" aria-hidden="true"></span>
                            : <i className="fa-solid fa-user-minus" aria-hidden="true"></i>}
                        <span>{t('Unsubscribe')}</span>
                    </button>
                </div>
            </Modal>
        </>
    )
}

export function Subscribe(props: Props) {

    // one client for the button's life (a new one on every render would forget a request under way)
    const [queryClient] = useState(() => new QueryClient());

    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props} />
        </QueryClientProvider>
    )
}
