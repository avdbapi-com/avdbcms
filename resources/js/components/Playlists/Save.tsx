import {Modal} from "react-bootstrap";
import {useState} from "preact/hooks";
import {InfiniteData, QueryClient, QueryClientProvider, useIsMutating, useQueryClient} from "@tanstack/react-query";
import {getMyPlaylists, savePlaylist} from "@/api/avdbclip";
import {Fragment} from "preact";
import {PlaylistSaveDataSchema, PlaylistSaveData, PlaylistType, Paginator} from "@/types";
import {CreatePlaylist} from "@/components/Playlists/CreatePlaylist";
import {useCursorQuery, useErrorMutation} from "@/hooks";
import {produce} from "immer";
import {useTranslation} from "react-i18next";
import {ApiError, Loader} from "@/components/Commons";
import {calmModalManager} from "@/layout/calmModalManager";

type Props = {
    video: number
}

// R1-026: the press paints first (its ripple, pressed and focus states), then the dialog renders in the task right after
// that frame. Rendering the dialog inside the click (react-bootstrap's modal, then the style and layout of its sheet and
// the page's new layers in the same frame) kept the press unanswered for 232-264 ms at CPU 4x on a 1366 px screen, over the
// 200 ms budget; split, the press is answered in the next frame and the dialog follows one frame later. A tab without
// frames (hidden) still opens it, by the timer.
const afterNextPaint = (callback: () => void): void => {
    let done = false;
    const run = (): void => {
        if (!done) {
            done = true;
            callback();
        }
    };
    const timer = window.setTimeout(run, 100);
    window.requestAnimationFrame(() => {
        window.clearTimeout(timer);
        window.setTimeout(run, 0);
    });
};

export function Main ({video}: Props) {

    const [open, setOpen] = useState(false);
    const [openCreate, setOpenCreate] = useState(false);

    const isSaving = useIsMutating({
        mutationKey: ['playlist.save'],
    });

    const { t } = useTranslation();

    // The page keeps one query client (watch-15: no "Loading your playlists ..." on every open), but the list is
    // read again each time the dialog opens: a save TOGGLES the video on the server, so a box ticked from an old list
    // (the video saved meanwhile from a card's Watch Later, or in another tab) would take it out. While that read runs
    // the old list stays on screen, dimmed, and its boxes wait for it.
    const {
        data: playlists,
        isLoading,
        isError,
        isRefetching,
        refetch,
        error,
        isFetchingNextPage,
        hasNextPage,
        ref,
    } = useCursorQuery({
        queryKey: ['save', video],
        queryFn: ({pageParam}) => getMyPlaylists(video, pageParam),
        enabled: open,
        staleTime: 0,
        refetchOnMount: 'always',
    });
    const checking = isRefetching && !isFetchingNextPage;

    return (
        <>
            <button
                type={'button'}
                className="btn bg-light-dark btn-sm rounded-4 px-4 d-flex align-items-center gap-1"
                title={t('Save video')}
                aria-haspopup="dialog"
                onClick={() => afterNextPaint(() => setOpen(true))}
            >
                <i class="fa-regular fa-bookmark"></i>&nbsp;
                {t('Save')}
            </button>
            {/* R1-026 (opening it took 300-470 ms at CPU 4x, over the 200 ms budget): the page lock without the layout thrash
                of react-bootstrap's own manager (layout/calmModalManager), and no JavaScript fade, whose reflow and two
                computed-style reads (the transition's timing, dialog and backdrop) forced the page's style three times in
                the click. The dialog still comes in with the site's own entrance (app.scss: .modal.show .modal-dialog). */}
            <Modal show={open} onHide={() => setOpen(false)} manager={calmModalManager()} animation={false}>
                <Modal.Header closeButton closeLabel={t('Close')}>
                    <h5 className="modal-title">{t('Save to playlist')}</h5>
                </Modal.Header>
                <Modal.Body className={'overflow-y-auto avd-save-playlists'}>
                    {
                        isLoading &&
                        <div className={'alert alert-primary d-flex align-items-center gap-3 mb-0'}>
                            <Loader small={true}/>
                            <span>{t('Loading your playlists ...')}</span>
                        </div>
                    }
                    {
                        isError && <ApiError error={error} refetch={refetch}/>
                    }
                    {
                        playlists && (
                            playlists?.pages.flatMap((page => page.data)).length > 0 ?
                                <form id={'playlists-from'} aria-busy={checking} className={checking ? 'opacity-50' : undefined}>
                                    {playlists.pages.map((group, i) => (
                                        <Fragment key={i}>
                                            {group.data.map((playlist) => <Playlist key={playlist.id} playlist={playlist} video={video} disabled={checking}/>)}
                                        </Fragment>
                                    ))}
                                </form>
                                :
                                <div className="avd-empty-state d-flex flex-column justify-content-center align-items-center text-center p-4 p-md-5">
                                    <i className="fa-solid fa-list-check fa-2x text-muted"></i>
                                    <h5 className="mt-3 fs-6">{t("You don't have playlists yet")}</h5>
                                    <p className="text-muted text-sm mb-0">{t('Create your first playlist to start saving videos')}</p>
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
                </Modal.Body>
                <Modal.Footer>
                    <div className="d-flex justify-content-between w-100 align-items-center flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setOpenCreate(v => !v)}
                            className="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-2 text-start"
                            style={{maxWidth: '100%', whiteSpace: 'normal'}}
                            aria-expanded={openCreate}
                        >
                            <i className="fa-solid fa-plus flex-shrink-0" aria-hidden="true"></i>
                            <span>{t('Create new playlist')}</span>
                        </button>
                        {
                            (isSaving > 0) &&
                            <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                        }
                    </div>
                    <CreatePlaylist open={openCreate} setOpen={setOpenCreate} video={video}/>
                </Modal.Footer>
            </Modal>
        </>
    )
}

type PlaylistProps = {
    playlist: PlaylistType
    video: number,
    disabled?: boolean,
}

function Playlist ({playlist, video, disabled = false} : PlaylistProps) {

    const queryClient = useQueryClient();

    // The box flips at once (the save toggles the video in this playlist on the server). If the request fails, the box
    // flips back and the list is read again, so it never shows a state the server does not have (the error itself is
    // the mutation's toast).
    const flip = (): void => {
        queryClient.setQueryData(['save', video], (oldData: InfiniteData<Paginator<PlaylistType>> | undefined) => {
                if (!oldData) return undefined
                return produce(oldData, draft => {
                    draft.pages.forEach(page => {
                        page.data.forEach(p => {
                            if (p.id === playlist.id) {
                                p.has_video = !p.has_video;
                            }
                        });
                    });
                });
            }
        )
    };

    const {mutate} = useErrorMutation({
        mutationFn: (data: PlaylistSaveData) => savePlaylist(data),
        mutationKey: ['playlist.save', playlist.uuid],
        onMutate: async () => {
            await queryClient.cancelQueries({queryKey: ['save', video]});
            flip();
        },
        onSettled: (_data, error) => {
            if (error) {
                flip();
                void queryClient.invalidateQueries({queryKey: ['save', video]});
            }
        },
    });


    const handleChange = (event: any) => {

        const data = {
            playlist_id: playlist.id,
            video_id: video,
        };

        mutate(PlaylistSaveDataSchema.parse(data));
    }

    // R1R-10: the whole row is the box's label (box, name, visibility icon; video.scss .avd-save-playlist), so a tap on the
    // box, the name or the rest of the row ticks it once, and the icon never lies on the box in Arabic.
    // R3-066: while the list is read again (each opening, and after a change the server refused) a box waits
    // (aria-disabled: a press changes nothing, dimmed as a disabled box) but it keeps the keyboard focus: a disabled box
    // lost it, so the view-only demo window, closing while the list was read again after its refusal, gave the focus to
    // the dialog's Close button instead of the box it came from (and the next Enter closed the dialog).
    return (
        <label className="form-check avd-save-playlist mb-3" htmlFor={'playlist-' + playlist.id}>
            <input
                className="form-check-input"
                name={'playlist-' + playlist.id}
                type="checkbox"
                id={'playlist-' + playlist.id}
                checked={playlist.has_video}
                aria-disabled={disabled ? 'true' : undefined}
                onClick={(event) => { if (disabled) event.preventDefault(); }}
                onChange={handleChange}
            />
            <span className="form-check-label text-break">{playlist.title}</span>
            <i className={'fa-solid fa-' + playlist.icon} aria-hidden="true"></i>
        </label>
    )
}

// One client for the page: a client made on every render dropped the loaded playlists, so every open showed
// "Loading your playlists ..." again (watch-15). The list is still read again on every open (Main).
let saveQueryClient: QueryClient | null = null;

const pageQueryClient = (): QueryClient => {
    saveQueryClient ??= new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            }
        }
    });

    return saveQueryClient;
};

export function Save(props: Props) {

    const queryClient = pageQueryClient();

    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props}/>
        </QueryClientProvider>
    )
}
