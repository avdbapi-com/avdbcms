import {Button} from "@/components/Commons";
import {useRef, useState} from "preact/hooks";
import {InfiniteData, useQueryClient} from "@tanstack/react-query";
import {useErrorMutation} from "@/hooks";
import {PlaylistCreateDataSchema, Paginator, PlaylistType, PlaylistCreateData, PlaylistStatus} from "@/types";
import {createPlaylist, savePlaylist} from "@/api/avdbclip";
import {produce} from "immer";
import {useTranslation} from "react-i18next";
import {enumToArray} from "@/functions/string";

type Props = {
    open: boolean
    setOpen: (value: boolean) => void
    // The video of the Save dialog: a playlist created there is saved with it, ticked (watch-15).
    video?: number
}

export function CreatePlaylist ({open, setOpen, video} : Props) {

    const [count, setCount] = useState<number>(0);

    const form = useRef<HTMLFormElement>(null);

    const queryClient = useQueryClient();

    const { t } = useTranslation();

    const {mutate, isPending} = useErrorMutation({
        mutationFn: async (data: PlaylistCreateData) => {
            const playlist = await createPlaylist(data);

            if (video) {
                await savePlaylist({playlist_id: playlist.id, video_id: video});

                return {...playlist, has_video: true};
            }

            return playlist;
        },
        mutationKey: ['playlists.create'],
        onSuccess: (playlist) => {
            queryClient.setQueryData(['save', video], (oldData: InfiniteData<Paginator<PlaylistType>> | undefined) => {
                    if (!oldData) return undefined
                    return produce(oldData, draft => {
                        draft.pages[draft.pages.length - 1].data.push(playlist);
                    });
                }
            )
            form.current!.reset();
            setOpen(false);
            setCount(0);
        }
    })

    const handleSubmit = async (event: any) => {

        event.preventDefault();

        const formData = new FormData(event.target);

        const data = Object.fromEntries(formData.entries());

        mutate(PlaylistCreateDataSchema.parse(data))
    }

    return (
        <>
            {
                open &&
                <form ref={form} onSubmit={handleSubmit} className="card card-body w-100 avd-create-playlist">
                    <div className="mb-3">
                        <label htmlFor="playlist-create-title" className="form-label">{t('Title')}</label>
                        <input
                            type="text"
                            className="form-control"
                            id="playlist-create-title"
                            name="title"
                            required
                            minLength={1}
                            maxLength={150}
                            onChange={(e: any) => setCount(e.currentTarget.value.length)}
                        />
                        <div className="form-text">
                            {count} / 150
                        </div>
                    </div>
                    <div className="mb-3">
                        <label htmlFor="playlist-create-status" className="form-label">{t('Visibility')}</label>
                        {/* New playlists start Private (they started Public): the member chooses to publish. */}
                        <select className="form-select" name="status" id="playlist-create-status" required defaultValue={String(PlaylistStatus.PRIVATE)} data-native-select>
                            {
                                enumToArray(PlaylistStatus).map(({value, label}) => (
                                    <option value={value}>{t(label)}</option>
                                ))
                            }
                        </select>
                    </div>
                    <div className="d-flex gap-2 justify-content-end">
                        <button onClick={() => setOpen(false)} type="button" className="btn btn-secondary btn-sm">
                            {t('Cancel')}
                        </button>
                        <Button type={'submit'} loading={isPending} color={'primary'}>
                            {t('Create')}
                        </Button>
                    </div>
                </form>
            }
        </>
    )
}
