import {memo} from 'preact/compat';
import {useTranslation} from "react-i18next";
import {BellNotification} from "@/api/avdbclip/notifications";
import {sitePath} from "@/functions/sitePath";

// One icon per kind of notification (App\Models\Notification::KINDS, App\Notifications\Support\BellResource).
const ICONS: Record<string, string> = {
    video: 'fa-circle-play',
    comment: 'fa-comment',
    subscriber: 'fa-user-plus',
    ready: 'fa-circle-check',
    failed: 'fa-triangle-exclamation',
    suspended: 'fa-ban',
    export: 'fa-file-arrow-down',
    invoice: 'fa-file-invoice-dollar',
    message: 'fa-bullhorn',
};

// The small badge on an avatar: the kit's tones by kind (_notify.scss .mk-notif__type--*).
const BADGES: Record<string, string> = {
    video: 'video',
    ready: 'video',
    failed: 'video',
    suspended: 'video',
    comment: 'comment',
};

type Props = {
    notification: BellNotification,
    when: string,
    selectMode: boolean,
    isSelected: boolean,
    onToggleSelect: (id: string) => void,
}

// A row of the bell's sheet (member design 6.14): a link (the member's avatar with a small type badge, or the type
// icon; the sentence with the names and titles in bold; the time; the video's picture) that opens the notification (it
// is marked read there). Unread rows keep the full text colour and an 8px dot (no weight change: marking read never
// re-wraps a row). In select mode a check box leads the row and the row selects instead of opening. Rows only render
// again when their own notification changes (the list shares the unchanged ones), so a long list stays quick; a row
// off screen skips its style and layout work (notifications.css).
const Notification = memo(({notification, when, selectMode, isSelected, onToggleSelect}: Props) => {

    const {t} = useTranslation();
    const kind = notification.kind ?? 'other';
    const icon = ICONS[kind] ?? 'fa-bell';
    const href = notification.url && !selectMode ? sitePath(`/profile/notifications/${encodeURIComponent(notification.id)}/click`) : null;
    const toggle = () => onToggleSelect(notification.id);
    const parts = Array.isArray(notification.parts) && notification.parts.length > 0
        ? notification.parts
        : [{t: notification.message ?? '', b: false}];

    const body = (
        <>
            <span className="mk-notif__icon">
                {notification.avatar
                    ? <>
                        <img className="mk-avatar" src={notification.avatar} alt="" width={40} height={40} loading="lazy" decoding="async"/>
                        <span className={`mk-notif__type mk-notif__type--${BADGES[kind] ?? ''}`} aria-hidden="true"><i className={`fa-solid ${icon}`}></i></span>
                    </>
                    : <span className="mk-notif__kind" aria-hidden="true"><i className={`fa-solid ${icon}`}></i></span>}
            </span>
            <span className="mk-notif__text">
                {notification.title && <strong className="mk-notif__title">{notification.title}</strong>}
                <span>{parts.map((part, index) => part.b ? <strong key={index}>{part.t}</strong> : <span key={index}>{part.t}</span>)}</span>
                <span className="mk-notif__time">
                    <time dateTime={String(notification.iso || notification.created_at)}>{when}</time>
                    {!notification.is_read && <span className="mk-sr">, {t('Unread')}</span>}
                </span>
            </span>
            {notification.thumb
                ? <span className="mk-notif__thumb"><span className="mk-thumb"><img src={notification.thumb} alt="" width={160} height={90} loading="lazy" decoding="async"/></span></span>
                : <span></span>}
        </>
    );

    return (
        <li
            className={'mk-notif avd-bell-row' + (notification.is_read ? '' : ' is-unread') + (isSelected ? ' is-selected' : '') + (selectMode ? ' is-selecting' : '')}
            data-avd-notification={notification.id}
        >
            {selectMode && (
                <label className="mk-notif__check">
                    <input
                        type="checkbox"
                        className="mk-check"
                        checked={isSelected}
                        onChange={toggle}
                        aria-label={notification.message}
                    />
                </label>
            )}
            {href
                ? <a className="mk-notif__link" href={href}>{body}</a>
                : <div className="mk-notif__link" onClick={selectMode ? toggle : undefined}>{body}</div>}
        </li>
    )
}, (prev, next) => prev.notification === next.notification && prev.when === next.when && prev.selectMode === next.selectMode && prev.isSelected === next.isSelected);

export default Notification;
