import {CursorPaginator, NotificationType} from "@/types";
import {jsonFetch} from "@/functions/api";
import {API_URL} from "./config";

export async function getNotifications(cursor: string | null): Promise<CursorPaginator<NotificationType>> {
    return jsonFetch(API_URL + '/notifications' + (cursor ? `?cursor=` + cursor  : ''));
}

export async function handleNotification(id: string, type: 'read' | 'unread'): Promise<void> {
    return jsonFetch(API_URL + `/notifications/${id}/${type}`);
}

export async function deleteNotification(id: string): Promise<void> {
    return jsonFetch(API_URL + `/notifications/${id}/delete`, 'DELETE');
}

export async function readAllNotifications(): Promise<void> {
    return jsonFetch(API_URL + `/notifications/read-all`);
}

export async function deleteAllNotifications(): Promise<void> {
    return jsonFetch(API_URL + `/notifications/delete-all`, 'DELETE');
}
