// A time code in a comment is a seek button only where it can seek (watch-22): inside the video's length, on a page
// whose player the page drives itself. Past the end ("22:16:01" under a 12 s video) or under an embedded player it is
// plain text. The server marks the codes (App\Parsers\TimeCodeParser: <button ... data-timecode="N">...</button>).
// Kept apart from ExpandComment.tsx so tests/js/front_logic.test.ts can run it in Node.

export type WatchFacts = {
    // The video's length in seconds (0 or missing: unknown, every code stays a button).
    duration?: number;
    // false under a player the page cannot seek (an embedded iframe player).
    seekable?: boolean;
};

const TIME_CODE = /<button\b[^>]*\bdata-timecode=['"](\d+)['"][^>]*>([^<]*)<\/button>/g;

// canSeek: the page has its seek function (window.time) at all.
export const playableTimeCodes = (html: string, watch: WatchFacts | undefined, canSeek: boolean): string => {
    const seekable = canSeek && watch?.seekable !== false;
    const duration = Number(watch?.duration ?? 0);

    return html.replace(TIME_CODE, (button, seconds: string, label: string) => {
        const at = Number(seconds);

        return seekable && (!(duration > 0) || at < duration) ? button : '<span class="avd-timecode-text">' + label + '</span>';
    });
};
