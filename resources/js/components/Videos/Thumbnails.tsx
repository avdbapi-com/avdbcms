import {QueryClient, QueryClientProvider, useQuery, useQueryClient} from "@tanstack/react-query";
import {getThumbnails} from "@/api/avdbclip";
import {ApiError} from "@/components/Commons";
import {ThumbnailType, ThumbnailStatus} from "@/types";
import {clsx} from "clsx";
import { useState, useEffect, useMemo } from 'preact/hooks';
import {produce} from "immer";
import ImageLoaded from "@/components/Images/ImageLoaded";
import {memo} from "preact/compat";
import config from "@/config";
import ImageUpload from "@/components/Images/ImageUpload";
import {OverlayTrigger, Popover} from "react-bootstrap";
import { create } from 'zustand'
import {combine} from "zustand/middleware";
import {useTranslation} from "react-i18next";

type Props = {
    video: number | string,
    initial?: string,
    allowupload?: boolean | string,
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

function Main ({video, initial, allowupload} : Props) {

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

    const {selected, setSelected} = useSelectedStore();

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
            const activeThumbnail = thumbnails?.data.find(t => t.is_active && (t.status === ThumbnailStatus.GENERATED || t.status === ThumbnailStatus.UPLOADED));
            if (activeThumbnail) {
                setSelected(activeThumbnail.id);
            }
        }
    }, [thumbnails])


    useEffect(() => {
        if (selected !== undefined) {
            const poster = document.getElementById('thumbnail-' + selected)?.getAttribute('src')!;
            document.querySelector('video')?.setAttribute('poster', poster)
        }
    }, [selected]);


   const uploaded = thumbnails?.data.find(t => t.status === ThumbnailStatus.UPLOADED);
   const generated = thumbnails?.data.filter(t => t.status !== ThumbnailStatus.UPLOADED);

   return (
       <div className="w-100">
           {isError && <ApiError refetch={refetch} error={error}/>}
           {
               thumbnails &&
               <div className="row gx-2 gy-2">
                   {(canUpload || uploaded) && <Upload thumbnail={uploaded} canUpload={canUpload}/>}
                   {generated?.map((thumbnail) => <Thumbnail thumbnail={thumbnail}/>)}
               </div>
           }
           <input type="hidden" name="thumbnail" value={selected}/>
           {canUpload && <input type="file" name='thumbnail_file' className={'d-none'}/>}
       </div>
   )
}

function Upload ({thumbnail, canUpload} : {thumbnail: ThumbnailType | undefined, canUpload: boolean}) {

    const [upload, setUpload] = useState<string | undefined>(thumbnail?.url);
    const [hover, setHover] = useState(false);

    const {selected, setSelected} = useSelectedStore();

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
    }, []);

    return (
        <>
            {canUpload && (
                <div class={'avd-hidden-file-input'}>
                    <ImageUpload name={'thumbnail_file'} config={'thumbnail'} readonly/>
                </div>
            )}
            <div class={'col-6'}>
                {
                    upload &&
                    <div
                        class={'position-relative cursor-pointer w-100'}
                        onMouseEnter={() => setHover(true)}
                        onMouseLeave={() => setHover(false)}
                    >
                        {canUpload && <div className={clsx('position-absolute top-5 right-5 dropdown z-2', !hover && 'd-none')}>
                            <button
                                className="bg-dark bg-opacity-75 dropdown-toggle without-caret rounded-circle w-10 p-2 d-flex align-items-center justify-content-center dot"
                                type="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <i className="fa-solid fa-ellipsis-vertical text-white fw-bold"></i>
                            </button>
                            <ul className="dropdown-menu">
                                <li>
                                    <button className="dropdown-item btn btn-file" type={'button'}>
                                        <label htmlFor="thumbnail_file" className={'text-sm d-flex align-items-center gap-2 cursor-pointer'}>
                                            <i className="fas fa-image"></i>
                                            <span>{t('Change')}</span>
                                        </label>
                                    </button>
                                </li>
                                <li>
                                    <a download className="dropdown-item d-flex align-items-center gap-2 text-sm" href={upload}>
                                        <i className="fa-solid fa-download"></i>
                                        <span>{t('Download')}</span>
                                    </a>
                                </li>
                            </ul>
                        </div>}
                        <div
                            onClick={() => setSelected(thumbnail?.id ?? 0)}
                            class={clsx('img-fluid opacity-50', hover && 'opacity-100', (selected === 0 || selected === thumbnail?.id) && 'border-4 border-primary opacity-100')}
                            style={{aspectRatio: '16/9'}}
                        >
                        <ImageLoaded
                            id={`thumbnail-${thumbnail?.id ?? 0}`}
                            source={upload}
                            class={clsx('img-fluid cursor-pointer')}
                            alt="Thumbnail"
                        />
                        </div>
                    </div>
                }
                {
                    !upload && canUpload &&
                    <div class={'h-100'}>
                        <div className={'input-file position-relative h-100'}>
                            <OverlayTrigger
                                trigger="click"
                                placement={'top'}
                                rootClose={true}
                                overlay={
                                    <Popover style={{position: 'fixed'}}>
                                        <Popover.Header as="h3">{t('Recommendations')}</Popover.Header>
                                        <Popover.Body>
                                            <ul>
                                                <li>{t('Make your thumbnail 1280 by 720 pixels (16:9 ratio)')}</li>
                                                <li>{t('Min size required is')} {config.thumbnail.minWidth} x {config.thumbnail.minHeight}</li>
                                                <li>{t('Ensure that your thumbnail file is less than')} {config.thumbnail.maxSize}MB</li>
                                                <li>{t('Use a file PNG, JPEG, JPG, WEBP format')}</li>
                                            </ul>
                                        </Popover.Body>
                                    </Popover>
                                }
                            >
                                <button className="position-absolute p-0 bg-transparent" type="button" style={{top: '10px', right: '10px'}}>
                                    <i className="fa-solid fa-circle-info"></i>
                                </button>
                            </OverlayTrigger>
                            <label htmlFor="thumbnail_file" className={'rounded h-100 p-0 w-100 d-flex align-items-center justify-content-center border border-1 border-secondary'}>
                                <div className="text-body">
                                    <i className="fas fa-image"></i>
                                    <div className="mt-2 fw-bold">{t('Upload Thumbnail')}</div>
                                </div>
                            </label>
                        </div>
                    </div>
                }
            </div>
        </>

    )
}

const Thumbnail = memo(({thumbnail} : {thumbnail: ThumbnailType}) => {

    const [hover, setHover] = useState<boolean>(false);

    const {selected, setSelected} = useSelectedStore();

    const { t } = useTranslation();

    return (
        <div className={'col-6'}>
            {
                thumbnail.status === ThumbnailStatus.PENDING && (
                    <div className={'placeholder-wave h-100 w-100 position-relative'} style='min-height: 100px'>
                        <span className="placeholder h-100 w-100"></span>
                        <span className="position-absolute top-50 start-50 translate-middle w-100 text-center text-white">{t('Auto-generating ...')}</span>
                    </div>
                )
            }
            {
                thumbnail.status === ThumbnailStatus.GENERATED && (
                    <div
                        onClick={() => selected !== thumbnail.id ? setSelected(thumbnail.id) : null}
                        class={clsx('opacity-50', hover && 'opacity-100', selected === thumbnail.id && 'border-4 border-primary opacity-100')}
                        style={{aspectRatio: '16/9'}}
                    >
                        <ImageLoaded
                            id={'thumbnail-' + thumbnail.id}
                            source={thumbnail.url}
                            class={clsx('img-fluid cursor-pointer')}
                            onMouseEnter={() => setHover(true)}
                            onMouseLeave={() => setHover(false)}
                            alt="Thumbnail"
                        />
                    </div>

                )
            }
            {
                thumbnail.status === ThumbnailStatus.ERROR && (
                    <div
                        className="w-100 h-100 bg-secondary text-white d-flex justify-content-center align-items-center w-100 h-100" style='min-height: 100px'>
                        <i className="fa-solid fa-circle-exclamation fa-2x"></i>
                    </div>
                )
            }
        </div>
    )
});

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
            <Main {...props}/>
        </QueryClientProvider>
    )
}
