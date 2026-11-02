import {CursorPaginator, NotificationType} from "@/types";
import {jsonFetch} from "@/functions/api";
import {API_URL} from "./config";

// A piece of a notification's sentence: the names and titles are the bold parts (App\Notifications\Support\NotificationText::parts).
export type NotificationPart = {t: string, b: boolean};

// One notification of the bell, as the API sends it since 1.2.3: its sentence already in the reader's language (also in
// parts, the names in bold), the owner's title for a notification an administrator sent, a kind for the icon, and the
// avatar of the member it names and the picture of the video it is about when it names them (App\Notifications\Support\BellResource).
export type BellNotification = NotificationType & {
    kind?: string,
    title?: string | null,
    parts?: NotificationPart[],
    avatar?: string | null,
    thumb?: string | null,
    // The row's group and its time in words, worked out by the server in the SITE's time zone and date format, the same
    // as on the notifications page (BellResource::time); iso: the moment with the site's offset. A row pushed live has
    // none of them (it is new: "today").
    group?: 'today' | 'yesterday' | 'week' | 'earlier',
    when?: string,
    iso?: string,
}

// A page of the bell's list: the newest first, by cursor; "unread" is the member's unread count up to 100 (99+).
export type BellPage = CursorPaginator<BellNotification> & {
    unread?: number,
    unread_cap?: number,
}

export type UnreadAnswer = {unread: number};

// The answer to "Mark all as read" / "Delete all": "before" is the moment of the click (ISO, UTC); every notification
// created up to it is read / deleted, also those the last batches have not reached yet.
export type ClickAnswer = UnreadAnswer & {finished: boolean, before?: string};

// The bell of a Subscribe button: which new videos of a channel reach the member's bell ("all", "highlights": its videos,
// not its Shorts, "none"). It wins over the member's "New videos from channels I follow".
export type ChannelBell = 'all' | 'highlights' | 'none';

export async function getNotifications(cursor: string | null): Promise<BellPage> {
    return jsonFetch(API_URL + '/notifications' + (cursor ? `?cursor=` + encodeURIComponent(cursor) : ''));
}

export async function handleNotification(id: string, type: 'read' | 'unread'): Promise<UnreadAnswer> {
    return jsonFetch(API_URL + `/notifications/${encodeURIComponent(id)}/${type}`, 'POST');
}

export async function deleteNotification(id: string): Promise<UnreadAnswer> {
    return jsonFetch(API_URL + `/notifications/${encodeURIComponent(id)}/delete`, 'DELETE');
}

export async function deleteSelectedNotifications(ids: string[]): Promise<UnreadAnswer & {deleted: number}> {
    return jsonFetch(API_URL + `/notifications/delete-selected`, 'POST', {ids});
}

export async function readAllNotifications(): Promise<ClickAnswer> {
    return jsonFetch(API_URL + `/notifications/read-all`, 'POST');
}

export async function deleteAllNotifications(): Promise<ClickAnswer> {
    return jsonFetch(API_URL + `/notifications/delete-all`, 'DELETE');
}

// The member's bell for a channel they follow (404 when they do not follow it). $channel: the channel's route key.
export async function getChannelBell(channel: string): Promise<{level: ChannelBell}> {
    return jsonFetch(API_URL + `/notifications/channels/${encodeURIComponent(channel)}`);
}

export async function setChannelBell(channel: string, level: ChannelBell): Promise<{level: ChannelBell}> {
    return jsonFetch(API_URL + `/notifications/channels/${encodeURIComponent(channel)}`, 'POST', {level});
}
