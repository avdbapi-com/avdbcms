import { useState } from 'preact/hooks';
import {VideosList} from "@/components/Videos/VideosList";
import {useTranslation} from "react-i18next";
import {VideosSort} from "@/types";

type Props = {
    user: number
}

const SORTS: VideosSort[] = ['latest', 'popular', 'oldest'];

/**
 * A channel's videos with the Latest / Popular / Oldest sort (<user-videos user="id">). Since 1.2.3 the channel page
 * prints its own Videos tab (resources/views/users/show.blade.php); this element stays for other markup that uses it.
 * It imports the list directly, never through the components/Videos index (QA channel-4: that index also pulled in the
 * 519 KB player chunk), and its sort control is a group of pressed / not pressed buttons (QA channel-35).
 */
export function UserVideos ({user} : Props) {

    const { t } = useTranslation();

    const [sort, setSort] = useState<VideosSort>('latest');

    const label = (type: VideosSort) => type === 'latest' ? t('Latest') : (type === 'popular' ? t('Popular') : t('Oldest'));

    return (
        <>
            <div className="mk-seg my-3" role="group" aria-label={t('Sort videos')}>
                {SORTS.map((type) => (
                    <button
                        key={type}
                        type="button"
                        className={'mk-seg__item' + (sort === type ? ' is-active' : '')}
                        aria-pressed={sort === type}
                        onClick={() => type !== sort && setSort(type)}
                    >
                        {label(type)}
                    </button>
                ))}
            </div>
            <VideosList url={`/api/users/${user}/videos?sort=${sort}`} adContext="channel_videos"/>
        </>
    )
}
