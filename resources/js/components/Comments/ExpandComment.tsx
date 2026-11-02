import { memo } from 'preact/compat';
import {useTranslation} from "react-i18next";
import {CommentType} from "@/types";
import {useState} from "preact/hooks";
import {playableTimeCodes} from "./timeCodes";

type Props = {
    comment: CommentType
}

declare global {
    interface Window {
        // The watch page's player facts (videos/show.blade.php): the video's length in seconds and whether the page can
        // seek it (time() is printed only for a video it plays itself).
        AVD_WATCH?: {duration?: number, seekable?: boolean};
        time?: (seconds: number) => void;
    }
}

// A time code in a comment is a seek button only where it can seek (watch-22): inside the video's length, on a page
// whose player the page drives itself (./timeCodes).
const withPlayableTimeCodes = (html: string): string => playableTimeCodes(html, window.AVD_WATCH, typeof window.time === 'function');

export const Expand = memo(({comment} : Props) => {

    const { t } = useTranslation();

    const [expand, setExpand] = useState<boolean>(false);

    return (
        <div className={comment.is_long ? 'my-2' : 'mt-2 mb-3'}>
            <p className={'text-sm text-break'} dangerouslySetInnerHTML={{__html: withPlayableTimeCodes((expand ? comment.parsed_content : comment.short_content) ?? '')}}></p>
            {comment.is_long && <button onClick={() => setExpand(v => !v)} className={'text-primary bg-transparent ps-0 text-sm mt-1'}>{expand ? t('Show less'): t('Read more')}</button>}
        </div>
    )
});
