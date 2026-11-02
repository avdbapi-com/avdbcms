import {QueryClient, QueryClientProvider, useQuery, useQueryClient} from "@tanstack/react-query";
import {getThumbnails} from "@/api/avdbclip";
import {ApiError} from "@/components/Commons";
import {ThumbnailType, ThumbnailStatus} from "@/types";
import { useState, useEffect, useMemo, useRef } from 'preact/hooks';
import {produce} from "immer";
import {memo} from "preact/compat";
import ImageUpload from "@/components/Images/ImageUpload";
import { create } from 'zustand'
import {combine} from "zustand/middleware";
import {useTranslation} from "react-i18next";

/**
 * The thumbnail picker of Video details and the draft page (member design 6.4). It renders the kit's picker
 * (ul.mk-thumbs: radio tiles named "thumbnail", the chosen one with an inset ring and a tick, skeleton tiles for the
 * pictures still being made, the "Upload picture" tile while the admin allows own pictures), the SAME markup the server
 * prints inside <thumbnails-select> before this mounts, so the page never moves when it does (studio-25).
 */

type Props = {
    video: number | string,
    initial?: string,
    allowupload?: boolean | string,
    /** pictures still expected while a draft is processed (skeleton tiles until they arrive) */
    expected?: number | string,
    /** the poster address of a video without an active picture of its own (an import): shown as "Current picture" */
    poster?: string,
    /** the picture picked in the server-printed picker before this mounted (components/index.ts): its radio's value,
     *  '' for "Current picture" */
    picked?: string,
}

type ThumbnailsResponse = {
    data: ThumbnailType[]
}

function normalizeThumbnailStatus(status: any): number {
    if (typeof status === 'number') return status;

    if (typeof status === 'string') {
        const numeric = Number(status);
        if (Number.isFinite(numeric)) return numeric;

        const byName = ThumbnailStatus[status.toUpperCase() as keyof typeof ThumbnailStatus];
        if (typeof byName === 'number') return byName;
    }

    if (status && typeof status === 'object' && 'value' in status) {
        const numeric = Number(status.value);
        if (Number.isFinite(numeric)) return numeric;
    }

    return ThumbnailStatus.ERROR;
}

function normalizeThumbnail(thumbnail: any): ThumbnailType {
    return {
        ...thumbnail,
        status: normalizeThumbnailStatus(thumbnail.status),
        is_active: Boolean(thumbnail.is_active),
    };
}

function normalizeThumbnailsResponse(response: ThumbnailsResponse): ThumbnailsResponse {
    return {
        ...response,
        data: (response?.data ?? []).map(normalizeThumbnail),
    };
}

function parseInitialThumbnails(initial?: string): ThumbnailsResponse {
    if (!initial) return {data: []};

    try {
        const parsed = JSON.parse(initial);
        return normalizeThumbnailsResponse({
            data: Array.isArray(parsed) ? parsed : parsed?.data ?? [],
        });
    } catch {
        return {data: []};
    }
}

const useSelectedStore = create(
    combine({selected: undefined as undefined | number}, (set) => ({
        setSelected: (id: number) => set({ selected: id }),
    })),
);

function allowsUpload(value: boolean | string | undefined): boolean {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'string') return !['0', 'false', 'off', 'no'].includes(value.toLowerCase());

    return true;
}

/** The value of the "Current picture" tile: an empty thumbnail field keeps the poster (ThumbnailService::save). */
const CURRENT = -1;

function Main ({video, initial, allowupload, expected, poster, picked} : Props) {

    const videoId = Number(video);
    const initialThumbnails = useMemo(() => parseInitialThumbnails(initial), [initial]);
    const canUpload = allowsUpload(allowupload);

    const {
        data: thumbnails,
        isError,
        refetch,
        error
    } = useQuery({
        queryKey: ['videos', videoId, 'thumbnails'],
        queryFn: () => getThumbnails(videoId).then(normalizeThumbnailsResponse),
        initialData: initialThumbnails,
        staleTime: 10000,
    });

    const queryClient = useQueryClient();

    // A picture picked in the server's picker before this mounted stays picked (1.2.3 round 1, as R1-107 for the
    // playlist search): chosen before the first render, and the active picture does not take its place on the first
    // load of the list. Nothing changes when nothing was picked early.
    const [carried] = useState<number | undefined>(() => {
        if (typeof picked !== 'string') return undefined;
        const id = picked === '' ? CURRENT : Number(picked);
        if (!Number.isFinite(id)) return undefined;
        useSelectedStore.setState({selected: id});
        return id;
    });
    const keepCarried = useRef(carried !== undefined);

    const {selected, setSelected} = useSelectedStore();
    const { t } = useTranslation();

    useEffect(() => {
        if (!window.PRIVATE_CHANNEL) return;

        window.PRIVATE_CHANNEL
            .listen('.thumbnail.generated', (data: ThumbnailType) => {
                queryClient.setQueriesData({queryKey: ['videos', videoId, 'thumbnails']}, (oldData: {data: ThumbnailType[]} | undefined) => {
                    if (!oldData) return undefined;
                    const thumbnail = normalizeThumbnail(data);
                    return produce(oldData, draft => {
                        const index = draft.data.findIndex(t => t.id === thumbnail.id);
                        if (index !== -1) {
                            draft.data[index] = thumbnail;
                        }
                    });
                })
            })
            .listen('.thumbnail.error', (data: {id: number}) =>  {
                queryClient.setQueriesData({queryKey: ['videos', videoId, 'thumbnails']}, (oldData: {data: ThumbnailType[]} | undefined) => {
                    if (!oldData) return undefined;
                    return produce(oldData, draft => {
                        const index = draft.data.findIndex(t => t.id === data.id);
                        if (index !== -1) {
                            draft.data[index].status = ThumbnailStatus.ERROR;
                        }
                    });
                })
            })

        return () => {
            window.PRIVATE_CHANNEL
                .stopListening('.thumbnail.generated')
                .stopListening('.thumbnail.error')
        }
    }, [queryClient, videoId]);

    // Listen for auto-generated thumbnails from the processing overlay
    useEffect(() => {
        const handler = (event: Event) => {
            const generated = normalizeThumbnailsResponse({
                data: ((event as CustomEvent<ThumbnailType[]>).detail ?? []),
            }).data;

            if (generated.length > 0) {
                queryClient.setQueryData(['videos', videoId, 'thumbnails'], (oldData: ThumbnailsResponse | undefined) => {
                    const existing = oldData?.data ?? [];
                    const replaced = existing.filter(t =>
                        t.status !== ThumbnailStatus.GENERATED &&
                        t.status !== ThumbnailStatus.PENDING &&
                        !generated.some(g => g.id === t.id)
                    );

                    return {data: [...replaced, ...generated]};
                });

                const active = generated.find(t => t.is_active) ?? generated[0];
                if (active) setSelected(active.id);
            }

            queryClient.refetchQueries({queryKey: ['videos', videoId, 'thumbnails']});
        };
        window.addEventListener('thumbnails-generated', handler);
        return () => window.removeEventListener('thumbnails-generated', handler);
    }, [queryClient, setSelected, videoId]);

    useEffect(() => {
        if (thumbnails) {
            if (keepCarried.current) {
                keepCarried.current = false;
                return;
            }
            const activeThumbnail = thumbnails?.data.find(t => t.is_active && (t.status === ThumbnailStatus.GENERATED || t.status === ThumbnailStatus.UPLOADED));
            if (activeThumbnail) {
                setSelected(activeThumbnail.id);
            }
        }
    }, [thumbnails])


    useEffect(() => {
        if (selected !== undefined) {
            const poster = document.getElementById('thumbnail-' + selected)?.getAttribute('src');
            if (poster) {
                document.querySelector('.mk-edit video')?.setAttribute('poster', poster);
            }
        }
    }, [selected]);

   const uploaded = thumbnails?.data.find(t => t.status === ThumbnailStatus.UPLOADED);
   const generated = thumbnails?.data.filter(t => t.status !== ThumbnailStatus.UPLOADED) ?? [];
   // nothing chosen yet (a draft whose pictures are still being made): keep the active one, whatever its state
   const fallbackActive = selected === undefined ? thumbnails?.data.find(t => t.is_active) : undefined;
   // an imported video's poster, while no picture of its own is active
   const hasActive = thumbnails?.data.some(t => t.is_active && (t.status === ThumbnailStatus.GENERATED || t.status === ThumbnailStatus.UPLOADED)) ?? false;
   const current = poster && !hasActive ? poster : '';

   return (
       <>
           {isError && <ApiError refetch={refetch} error={error}/>}
           <ul className="mk-thumbs">
               {current && <Current source={current}/>}
               {canUpload && <Upload thumbnail={uploaded}/>}
               {!canUpload && uploaded && <Tile thumbnail={uploaded} label={t('Your picture')}/>}
               {generated.map((thumbnail, index) => <Thumbnail thumbnail={thumbnail} index={index + 1}/>)}
               {Array.from({length: Math.max(0, (Number(expected) || 0) - generated.length)}).map(() => <Waiting/>)}
               {canUpload && <AddTile hasUpload={Boolean(uploaded)}/>}
           </ul>
           {fallbackActive && <input type="hidden" name="thumbnail" value={fallbackActive.id}/>}
           {canUpload && <input type="file" name='thumbnail_file' className={'d-none'} tabIndex={-1} aria-hidden="true"/>}
       </>
   )
}

/** A picture the member can pick: a radio tile (the inset ring and the tick follow :checked, the kit's CSS). */
function Tile ({thumbnail, label, source, value}: {thumbnail?: ThumbnailType, label: string, source?: string, value?: number}) {

    const {selected, setSelected} = useSelectedStore();
    const id = value ?? thumbnail?.id ?? 0;
    const url = source ?? thumbnail?.url;

    return (
        <li>
            <label className="mk-thumb-pick">
                <input type="radio" name="thumbnail" value={id} checked={selected === id} onChange={() => setSelected(id)}/>
                <span className="mk-thumb">
                    {url && <img id={'thumbnail-' + id} src={url} alt="" width={160} height={90} loading="lazy" decoding="async"/>}
                </span>
                <span className="mk-thumb-pick__tick" aria-hidden="true"><i className="fa-solid fa-check"></i></span>
                <span className="mk-sr">{label}</span>
            </label>
        </li>
    )
}

/** "Current picture": the poster an imported video shows today. Checked while nothing else is chosen; its empty value
 *  keeps it when the form is saved. */
function Current ({source}: {source: string}) {

    const {selected, setSelected} = useSelectedStore();
    const { t } = useTranslation();

    return (
        <li>
            <label className="mk-thumb-pick">
                <input type="radio" name="thumbnail" value="" checked={selected === undefined || selected === CURRENT} onChange={() => setSelected(CURRENT)}/>
                <span className="mk-thumb">
                    <img src={source} alt="" width={160} height={90} loading="lazy" decoding="async"/>
                </span>
                <span className="mk-thumb-pick__tick" aria-hidden="true"><i className="fa-solid fa-check"></i></span>
                <span className="mk-sr">{t('Current picture')}</span>
            </label>
        </li>
    );
}

/** The member's own picture (uploaded before, or just cropped). */
function Upload ({thumbnail} : {thumbnail: ThumbnailType | undefined}) {

    const [upload, setUpload] = useState<string | undefined>(thumbnail?.url);

    const {setSelected} = useSelectedStore();
    const { t } = useTranslation();

    useEffect(() => {
        setUpload(thumbnail?.url);
    }, [thumbnail?.url]);

    useEffect(() => {
        const cropped = (e: Event) => {
            const file = (e as CustomEvent<File>).detail;
            const url = URL.createObjectURL(file);
            setUpload(url);
            setSelected(thumbnail?.id ?? 0);
        };

        window.addEventListener('cropped-thumbnail_file', cropped);
        return () => window.removeEventListener('cropped-thumbnail_file', cropped)
    }, [thumbnail?.id]);

    if (!upload) {
        return null;
    }

    return <Tile thumbnail={thumbnail} source={upload} value={thumbnail?.id ?? 0} label={t('Your picture')}/>;
}

/**
 * "Upload picture" (or "Change picture" once there is one): the file field of ImageUpload, which opens the crop sheet.
 * The field itself lies, invisible, over the whole tile (.mk-thumb-upload, _studio.scss): the tile is its 44px target
 * and shows its keyboard focus (it was a 1x1 field with no visible focus beside the tile).
 */
function AddTile ({hasUpload}: {hasUpload: boolean}) {

    const { t } = useTranslation();

    return (
        <li className="mk-thumb-upload">
            <div className="avd-hidden-file-input">
                <ImageUpload name={'thumbnail_file'} config={'thumbnail'} readonly/>
            </div>
            <label className="mk-thumb-add" htmlFor="thumbnail_file">
                <i className="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i>
                <span>{hasUpload ? t('Change picture') : t('Upload picture')}</span>
            </label>
        </li>
    )
}

const Thumbnail = memo(({thumbnail, index} : {thumbnail: ThumbnailType, index: number}) => {

    const { t } = useTranslation();

    if (thumbnail.status === ThumbnailStatus.GENERATED) {
        return <Tile thumbnail={thumbnail} label={t('Picture {number}', {number: index})}/>;
    }

    if (thumbnail.status === ThumbnailStatus.PENDING) {
        return <Waiting/>;
    }

    return (
        <li>
            <span className="mk-thumb-pick mk-thumb-wait">
                <span className="mk-thumb mk-thumb--icon" title={t('This picture could not be made')}><i className="fa-solid fa-circle-exclamation" aria-hidden="true"></i></span>
                <span className="mk-sr">{t('This picture could not be made')}</span>
            </span>
        </li>
    );
});

/** A picture still being made: a skeleton tile of the same size. */
function Waiting () {

    const { t } = useTranslation();

    return (
        <li>
            <span className="mk-thumb-pick mk-thumb-wait">
                <span className="mk-skel" style={{aspectRatio: '16 / 9', inlineSize: '100%'}}></span>
                <span className="mk-sr">{t('This picture is being made')}</span>
            </span>
        </li>
    );
}

export function Thumbnails (props : Props) {

    const queryClient = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
                refetchOnWindowFocus: false
            }
        }
    });

    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props} />
        </QueryClientProvider>
    )
}
