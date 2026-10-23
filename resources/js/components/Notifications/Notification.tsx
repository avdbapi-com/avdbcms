import {memo} from 'preact/compat';
import {NotificationType, Paginator} from "@/types";
import {Dropdown} from 'react-bootstrap';
import {useErrorMutation} from "@/hooks";
import {deleteNotification, handleNotification} from "@/api/avdbclip";
import {InfiniteData, useQueryClient} from "@tanstack/react-query";
import {produce} from "immer";
import {useTranslation} from "react-i18next";
import {formatRelativeTime} from "@/utils/format";

type Props = {
    notification: NotificationType,
    setUnread: (value: number | ((prev: number) => number)) => void,
    selectMode?: boolean,
    isSelected?: boolean,
    onToggleSelect?: (id: string) => void,
}

const Notification = memo(({notification, setUnread, selectMode, isSelected, onToggleSelect} : Props) => {

    const queryClient = useQueryClient();

    const { t } = useTranslation();

    const {mutate: remove}= useErrorMutation({
        mutationFn: () => deleteNotification(notification.id),
        onMutate: () => {
            queryClient.setQueryData(['notifications'], (oldData: InfiniteData<Paginator<NotificationType>> | undefined) => {
                if (!oldData) return undefined;
                return produce(oldData, draft => {
                    draft.pages.forEach(page => {
                        page.data = page.data.filter(n => n.id !== notification.id);
                    });
                });
            });
            if (!notification.is_read) {
                setUnread(v => v - 1);
            }

        }
    });

    const {mutate: handle}= useErrorMutation({
        mutationFn: (type: 'read' | 'unread') => handleNotification(notification.id, type),
        onMutate: () => {
            queryClient.setQueryData(['notifications'], (oldData: InfiniteData<Paginator<NotificationType>> | undefined) => {
                if (!oldData) return undefined;
                return produce(oldData, draft => {
                    draft.pages.forEach(page => {
                        page.data.forEach(n => {
                            if (n.id === notification.id) {
                                n.is_read = !n.is_read
                            }
                        });
                    });
                });
            })
            notification.is_read ? setUnread(v => v + 1) : setUnread(v => v - 1);
        }
    });

    const handleClick = (e: any) => {
        if (selectMode && onToggleSelect) {
            e.preventDefault();
            e.stopPropagation();
            onToggleSelect(notification.id);
            return;
        }
    };

    return (
        <div
            className={`position-relative d-flex align-items-center list-group-item gap-3 hover-primary ${isSelected ? 'bg-primary bg-opacity-10' : ''}`}
            onClick={selectMode ? handleClick : undefined}
            style={selectMode ? {cursor: 'pointer'} : undefined}
        >
            {selectMode && (
                <input
                    type="checkbox"
                    className="form-check-input mt-0 flex-shrink-0"
                    checked={isSelected || false}
                    onChange={() => onToggleSelect?.(notification.id)}
                    onClick={(e) => e.stopPropagation()}
                    style={{minWidth: '18px'}}
                />
            )}
            {notification.url ? (
                <a href={selectMode ? undefined : `/profile/notifications/${notification.id}/click`} className="text-decoration-none text-black w-100 align-items-center" onClick={selectMode ? handleClick : undefined}>
                    <div className="position-relative d-flex align-items-center justify-content-between">
                        <div>
                            <p className="mb-0 text-sm text-break">{notification.message}</p>
                            <p className="text-muted text-sm mb-0 mt-2">{formatRelativeTime(notification.created_at)}</p>
                        </div>
                    </div>
                    {!selectMode && <span style="position: absolute;inset: 0;"></span>}
                </a>
            ) : (
                <div className="text-decoration-none text-black w-100 align-items-center" style={{cursor: selectMode ? 'pointer' : 'default'}}>
                    <div className="position-relative d-flex align-items-center justify-content-between">
                        <div>
                            <p className="mb-0 text-sm text-break">{notification.message}</p>
                            <p className="text-muted text-sm mb-0 mt-2">{formatRelativeTime(notification.created_at)}</p>
                        </div>
                    </div>
                </div>
            )}
            {!notification.is_read && !selectMode && <span className="bg-primary rounded-circle" style="width: 12px;height: 10px"/> }
            {!selectMode && (
                <Dropdown>
                    <Dropdown.Toggle variant="transparent" id="dropdown-basic" className="without-caret btn-sm py-1 px-2">
                        <i className="fa-solid fa-ellipsis-vertical"></i>
                    </Dropdown.Toggle>
                    <Dropdown.Menu>
                        {notification.is_read && <Dropdown.Item as={'button'} onClick={() => handle('unread')}>{t('Mark as unread')}</Dropdown.Item>}
                        {!notification.is_read && <Dropdown.Item as={'button'}  onClick={() => handle('read')}>{t('Mark as read')}</Dropdown.Item>}
                        <Dropdown.Item as={'button'} onClick={() => remove()}>{t('Delete')}</Dropdown.Item>
                    </Dropdown.Menu>
                </Dropdown>
            )}
        </div>
    )
}, (prev, next) => prev.notification.is_read === next.notification.is_read && prev.selectMode === next.selectMode && prev.isSelected === next.isSelected);

export default Notification;
