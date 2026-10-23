import {useEffect, useState} from 'preact/hooks';
import Notification from "@/components/Notifications/Notification";
import {InfiniteData, QueryClient, QueryClientProvider, useQueryClient} from "@tanstack/react-query";
import {getNotifications, readAllNotifications, deleteAllNotifications, deleteNotification} from "@/api/avdbclip";
import {Offcanvas} from 'react-bootstrap';
import {Fragment} from "preact";
import {useCursorQuery, useErrorMutation} from "@/hooks";
import {NotificationType, Paginator} from "@/types";
import {produce} from "immer";
import {Button, ApiError, Loader} from "@/components/Commons";
import {useTranslation} from "react-i18next";

type Props = {
    count : string
}

const Main = ({count} : Props) => {

    const [open, setOpen] = useState<boolean>(false);
    const [unread, setUnread] = useState<number>(Math.max(parseInt(count, 10) || 0, 0));
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [selectMode, setSelectMode] = useState<boolean>(false);

    const { t } = useTranslation();

    const queryClient = useQueryClient();

    const {
        data: notifications,
        isLoading,
        isError,
        refetch,
        isFetchingNextPage,
        hasNextPage,
        error,
        ref,
    } = useCursorQuery({
        queryKey: ['notifications'],
        queryFn: ({pageParam}) => getNotifications(pageParam),
        enabled: open,
        staleTime: Infinity,
    });

    const addNotification = async (notification: NotificationType) => {
        const bell = document.getElementById('bell');
        queryClient.setQueryData(['notifications'], (oldData: InfiniteData<Paginator<NotificationType>> | undefined) => {
            if (!oldData) return undefined;
            return produce(oldData, draft => {
                draft.pages[0].data.unshift(notification);
            });
        })
        bell?.classList.add('fa-shake')
        setUnread(v => v + 1);
        await new Audio('/sounds/notification.mp3').play().catch(() => undefined)
        setTimeout(() => {
            bell?.classList.remove('fa-shake')
        }, 1000)
    }

    const {mutate: readAll, isPending}= useErrorMutation({
        mutationFn: () => readAllNotifications(),
        onSuccess: () => {
            setUnread(0);
            queryClient.setQueryData(['notifications'], (oldData: InfiniteData<Paginator<NotificationType>> | undefined) => {
                if (!oldData) return undefined;
                return produce(oldData, draft => {
                    draft.pages.forEach(page => {
                        page.data.forEach(n => {
                            n.is_read = true;
                        });
                    });
                });
            })
        }
    });

    const {mutate: deleteAll, isPending: isDeleteAllPending}= useErrorMutation({
        mutationFn: () => deleteAllNotifications(),
        onSuccess: () => {
            setUnread(0);
            setSelected(new Set<string>());
            setSelectMode(false);
            queryClient.setQueryData(['notifications'], (oldData: InfiniteData<Paginator<NotificationType>> | undefined) => {
                if (!oldData) return undefined;
                return produce(oldData, draft => {
                    draft.pages.forEach(page => {
                        page.data = [];
                    });
                });
            })
        }
    });

    useEffect( () => {
        if (window.PRIVATE_CHANNEL) {
            window.PRIVATE_CHANNEL.notification(addNotification);
        }
    }, []);

    const allItems = notifications?.pages.flatMap(page => page.data) || [];
    const isAllSelected = allItems.length > 0 && allItems.every(n => selected.has(n.id));

    const toggleSelectAll = () => {
        if (isAllSelected) {
            setSelected(new Set<string>());
        } else {
            setSelected(new Set<string>(allItems.map(n => n.id)));
        }
    };

    const toggleSelected = (id: string) => {
        setSelected(prev => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    };

    const deleteSelected = () => {
        if (selected.size === 0) return;
        if (!confirm(t('Delete selected notifications?'))) return;

        // Remove from UI optimistically
        const selectedIds = new Set<string>(selected);
        let unreadRemoved = 0;
        queryClient.setQueryData(['notifications'], (oldData: InfiniteData<Paginator<NotificationType>> | undefined) => {
            if (!oldData) return undefined;
            return produce(oldData, draft => {
                draft.pages.forEach(page => {
                    page.data = page.data.filter(n => {
                        if (selectedIds.has(n.id)) {
                            if (!n.is_read) unreadRemoved++;
                            return false;
                        }
                        return true;
                    });
                });
            });
        });
        setUnread(v => Math.max(0, v - unreadRemoved));

        // Delete each via API (fire-and-forget)
        selectedIds.forEach((id: string) => {
            deleteNotification(id).catch(() => {});
        });

        setSelected(new Set<string>());
    };

    return (
        <>
            <button
                id="notifications_button"
                type="button"
                className="avd-notification-button avd-icon-button"
                aria-label={unread > 0 ? `${t('Notifications')} (${unread})` : t('Notifications')}
                aria-expanded={open}
                onClick={() => setOpen(true)}
            >
                <i id="bell" className="fa-solid fa-bell"></i>
                {
                    unread > 0 &&
                    <span className="avd-notification-badge" aria-hidden="true"></span>
                }
            </button>
            <Offcanvas show={open} onHide={() => { setOpen(false); setSelectMode(false); setSelected(new Set<string>()); }} placement="end" className="avd-notifications-panel">
                <div className="offcanvas-header avd-notifications-header border d-flex justify-content-between align-items-center py-2">
                    <h6 className="offcanvas-title">
                        {t('Notifications')} {unread > 0 && <span className="avd-notification-title-dot" aria-hidden="true"></span>}
                    </h6>
                    <div className="d-flex gap-1 align-items-center">
                        {
                            allItems.length > 0 && !selectMode &&
                            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setSelectMode(true)} title={t('Select')}>
                                <i className="fa-regular fa-square-check"></i>
                            </button>
                        }
                        {
                            unread > 0 && !selectMode &&
                            <Button type={'button'} loading={isPending} onClick={() => readAll()} small={true}>
                                <small>{t('Mark all as read')}</small>
                            </Button>
                        }
                            <button type="button" className="btn-close" data-bs-dismiss="offcanvas" aria-label={t('Close')} onClick={() => { setOpen(false); setSelectMode(false); setSelected(new Set<string>()); }}></button>
                    </div>
                </div>

                {/* Select mode toolbar */}
                {selectMode && (
                    <div className="d-flex align-items-center gap-2 px-3 py-2 border-bottom" style={{background: 'var(--bs-tertiary-bg, #f8f9fa)'}}>
                        <input
                            type="checkbox"
                            className="form-check-input mt-0"
                            checked={isAllSelected}
                            onChange={toggleSelectAll}
                        />
                        <small className="fw-bold">{selected.size > 0 ? `${selected.size} ${t('selected')}` : t('Select all')}</small>
                        <div className="ms-auto d-flex gap-1">
                            {selected.size > 0 && (
                                <button type="button" className="btn btn-sm btn-danger" onClick={deleteSelected}>
                                    <i className="fa-solid fa-trash me-1"></i>{t('Delete')}
                                </button>
                            )}
                            <Button type="button" loading={isDeleteAllPending} small={true} onClick={() => { if(confirm(t('Delete ALL notifications?'))) deleteAll(); }} className="btn btn-sm btn-outline-danger">
                                <small><i className="fa-solid fa-trash me-1"></i>{t('Clear All')}</small>
                            </Button>
                            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => { setSelectMode(false); setSelected(new Set<string>()); }}>
                                <i className="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                    </div>
                )}

                <div className="offcanvas-body p-0">
                    {
                        isLoading &&
                        <div className={'d-flex justify-content-center align-items-center h-100'}>
                            <Loader/>
                        </div>
                    }
                    {
                        isError &&
                        <div className={'mt-3'}>
                            <ApiError refetch={refetch} error={error}/>
                        </div>
                    }
                    <div className={'h-100 overflow-auto'}>
                        {
                            notifications && (
                                allItems.length > 0 ?
                                    <ul className="list-group list-group-flush">
                                        {notifications.pages.map((group, i) => (
                                            <Fragment key={i}>
                                                {group.data.map((notification) => (
                                                    <Notification
                                                        key={notification.id}
                                                        notification={notification}
                                                        setUnread={setUnread}
                                                        selectMode={selectMode}
                                                        isSelected={selected.has(notification.id)}
                                                        onToggleSelect={toggleSelected}
                                                    />
                                                ))}
                                            </Fragment>
                                        ))}
                                    </ul>
                                    :
                                    <div className="avd-empty-state d-flex flex-column justify-content-center align-items-center text-center p-4 p-md-5 m-3">
                                        <i className="fa-solid fa-bell-slash fa-2x text-muted"></i>
                                        <h5 className="mt-3 fs-6">{t('Your notifications live here')}</h5>
                                        <p className="text-muted text-sm mb-0">{t('Subscribe to your favorite channels to get notified about their latest videos')}</p>
                                    </div>
                            )
                        }
                        {
                            isFetchingNextPage &&
                            <div className={'d-flex justify-content-center align-items-center py-3'}>
                                <div class="spinner-border text-primary spinner-border-sm" role="status">
                                    <span class="visually-hidden">{t('Loading...')}</span>
                                </div>
                            </div>
                        }
                        {hasNextPage && <span ref={ref}></span>}
                    </div>
                </div>
            </Offcanvas>
        </>
    )
}

export function NotificationsList(props : Props) {

    const queryClient = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            }
        }
    });

    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props}/>
        </QueryClientProvider>
    )
}
