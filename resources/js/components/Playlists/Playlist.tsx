import {useEffect, useMemo, useRef, useState} from "preact/hooks";
import {useSearchQuery} from "@/hooks";
import {searchVideos} from "@/api/avdbclip";
import { ReactSortable } from "react-sortablejs";
import {QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {VideoType} from "@/types";
import {useTranslation} from "react-i18next";
import {formatCompactNumber} from "@/utils/format";

/**
 * The studio's playlist editor (member design 6.7), in the kit's markup: the panes "In this playlist" and
 * "Add videos" that the server prints inside <playlist-videos> before this mounts (the same markup, so nothing moves).
 *
 * studio-4 (data loss): the page carries the WHOLE list of the playlist (initial). A save posts what the member changed,
 * never a snapshot: videos_order, the list as it ends in its order (also the videos further down that
 * are not shown yet, "Show N more"), and videos_loaded, the list the page loaded (`loaded`), one comma-separated field
 * each, so PHP's max_input_vars never cuts a long playlist; the server merges them with what the playlist holds now.
 * The "Add videos" search is not part of the playlist: its field is data-mk-pristine (typing never marks the page
 * changed); adding, removing, moving and the Order select do.
 * studio-22: the drag handle only with the Manual order (another order sorts the list itself); dragging works on a plain
 * copy of the rows (no frozen objects: no more "Cannot assign to read only property"); search results say "Added" for a
 * video that is already in the list and are never added twice; the count is the list's own.
 * 1.2.3 round 1: the server prints the first rows once and the whole list once, as compact rows in a JSON script
 * (`source`, R1-022: a 5,000-video playlist printed every video twice, 4.7 MB); what was typed in the server-printed
 * "Add videos" field before this mounted is kept, with the focus (R1-107).
 */

type Item = {
    id: number,
    title: string,
    thumb: string,
    dur: string,
    views: string,
    private: boolean,
    added: string | number,
    published: string | number,
    viewsCount: number,
    chosen?: boolean,
    selected?: boolean,
};

/** One row of the server's list (PlaylistController::editorItem): [id, title, thumb, duration, views text, private (1/0),
 * added (Unix seconds, 0 = unknown), published (Unix seconds, 0 = none), views] */
type Row = [number, string, string, string, string, number, number, number, number];

type Texts = Record<string, string>;

type Props = {
    uuid?: string,
    sort?: string,
    first?: string | number,
    loaded?: string,
    initial?: string,
    source?: string,
    texts?: string,
    sorts?: string,
    // From the server-printed "Add videos" field the editor replaces (components/index.ts, R1-107): the text typed
    // there and whether it had the focus.
    typed?: string,
    refocus?: string | boolean,
};

const MANUAL = '5';

const playlistQueryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: false,
            staleTime: 30_000,
            gcTime: 5 * 60_000,
            refetchOnWindowFocus: false,
        }
    }
});

function parse<T>(value: string | undefined, fallback: T): T {
    if (!value) return fallback;
    try {
        return JSON.parse(value) as T;
    } catch {
        return fallback;
    }
}

/** A row's time in milliseconds: Unix seconds from the server, an ISO string for a video added on this page. */
const time = (value: string | number): number => {
    if (typeof value === 'number') return Number.isFinite(value) ? value * 1000 : 0;
    const parsed = Date.parse(value);
    return Number.isFinite(parsed) ? parsed : 0;
};

/** The playlist's rows: the compact JSON script the server prints (`source`), else an `initial` attribute. */
function readItems(source: string | undefined, initial: string | undefined): Item[] {
    if (source) {
        const el = document.getElementById(source);
        const rows = parse<Row[]>(el?.textContent ?? '', []);
        if (Array.isArray(rows)) {
            return rows.filter(row => Array.isArray(row)).map(row => ({
                id: Number(row[0]),
                title: String(row[1] ?? ''),
                thumb: String(row[2] ?? ''),
                dur: String(row[3] ?? ''),
                views: String(row[4] ?? ''),
                private: Boolean(row[5]),
                added: Number(row[6]) || 0,
                published: Number(row[7]) || 0,
                viewsCount: Number(row[8]) || 0,
            }));
        }
    }
    return parse<Item[]>(initial, []).map(item => ({...item}));
}

/** A date the API sends as an ISO string or as a Unix time (seconds or milliseconds), as an ISO string ('' if none). */
const iso = (value: string | number | null | undefined): string => {
    if (value === null || value === undefined || value === '') return '';
    if (typeof value === 'number') {
        const date = new Date(value < 1e12 ? value * 1000 : value);
        return Number.isFinite(date.getTime()) ? date.toISOString() : '';
    }
    return value;
};

/** The order a playlist shows when it is not Manual (PlaylistSort). */
function sortItems(items: Item[], order: string): Item[] {
    const copy = [...items];
    switch (order) {
        case '0': return copy.sort((a, b) => time(b.added) - time(a.added));
        case '1': return copy.sort((a, b) => time(a.added) - time(b.added));
        case '2': return copy.sort((a, b) => b.viewsCount - a.viewsCount);
        case '3': return copy.sort((a, b) => time(b.published) - time(a.published));
        case '4': return copy.sort((a, b) => time(a.published) - time(b.published));
        default: return copy;
    }
}

function number(value: number): string {
    try {
        return new Intl.NumberFormat(document.documentElement.lang || undefined).format(value);
    } catch {
        return String(value);
    }
}

/**
 * A name and its value in the reader's punctuation: the page's ':label: :value' ("Retirer de la playlist : <title>" in
 * French, a full-width colon in Chinese; 1.2.3 round 3, R3-048: it was a hard-coded ": "). One pass over the pattern, so
 * a title that holds ":value" or "$&" stays as it is.
 */
function pair(texts: Texts, label: string, value: string): string {
    return (texts.pair || ':label: :value').replace(/:label|:value/g, (token) => token === ':label' ? label : value);
}

function Editor({uuid, sort, first, loaded, initial, source, texts, sorts, typed: typedText, refocus}: Props) {

    const T = useMemo(() => parse<Texts>(texts, {}), [texts]);
    const sortLabels = useMemo(() => parse<Record<string, string>>(sorts, {}), [sorts]);
    const step = Math.max(1, Number(first) || 8);
    // what was typed in the server's field before this mounted, carried over by components/index.ts (R1-107); read once
    const [typed] = useState(() => ({value: typeof typedText === 'string' ? typedText : '', focused: refocus !== undefined && refocus !== false}));

    const [items, setItems] = useState<Item[]>(() => readItems(source, initial));
    const [order, setOrder] = useState<string>(String(sort ?? MANUAL));
    const [shown, setShown] = useState<number>(step);
    const manual = order === MANUAL;

    const changed = () => window.dispatchEvent(new CustomEvent('mk-playlist-change'));

    // the tab's count (outside this element) follows the list
    useEffect(() => {
        document.querySelectorAll('[data-mk-pl-count]').forEach(el => { el.textContent = number(items.length); });
    }, [items.length]);

    const visible = items.slice(0, shown);
    const rest = items.length - visible.length;
    const more = Math.min(24, rest);

    const changeOrder = (value: string) => {
        setOrder(value);
        if (value !== MANUAL) {
            setItems(current => sortItems(current, value));
        }
        changed();
    };

    const remove = (id: number) => {
        setItems(current => current.filter(item => item.id !== id));
        changed();
    };

    const add = (video: VideoType) => {
        setItems(current => {
            if (current.some(item => item.id === video.id)) return current;
            const item: Item = {
                id: video.id,
                title: video.title,
                thumb: video.thumbnail ?? '',
                dur: video.formated_duration ?? '',
                views: '',
                private: false,
                added: new Date().toISOString(),
                published: iso(video.published_at),
                viewsCount: Number(video.views) || 0,
            };
            const next = order === MANUAL ? [...current, item] : sortItems([item, ...current], order);
            const index = next.findIndex(entry => entry.id === item.id);
            if (index >= shown) setShown(index + 1);
            return next;
        });
        changed();
    };

    const moveVisible = (sorted: Item[]) => {
        // ReactSortable hands back the shown rows in their new order; the rows further down keep theirs
        const moved = sorted.map(item => item.id).join(',') !== visible.map(item => item.id).join(',');
        setItems(current => [...sorted, ...current.slice(sorted.length)]);
        if (moved) changed();
    };

    const showing = (T.showing ?? ':shown / :count').replace(':shown', number(visible.length)).replace(':count', number(items.length));
    const moreLabel = (parse<string[]>(T.more, [])[more] ?? '+' + more);

    return (
        <>
            <section className="mk-panel mk-pl-pane mk-pl-pane--list" aria-labelledby="mk-pl-in-title">
                <div className="mk-panel__head">
                    <h2 className="mk-panel__title" id="mk-pl-in-title">{T.inList} ({number(items.length)})</h2>
                    <select className="form-select" name="sort" data-native-select style={{inlineSize: 'auto'}} aria-label={T.order} value={order} onChange={(e) => changeOrder(e.currentTarget.value)}>
                        {Object.entries(sortLabels).map(([value, label]) => <option value={value}>{label}</option>)}
                    </select>
                    <p className="mk-panel__sub">{manual ? T.dragHint : T.orderHint}</p>
                </div>
                {items.length === 0 ? (
                    <div className="mk-empty mk-empty--compact">
                        <span className="mk-empty__icon" aria-hidden="true"><i className="fa-solid fa-rectangle-list"></i></span>
                        <h2 className="mk-empty__title">{T.empty}</h2>
                        <p className="mk-empty__text">{T.emptyHint}</p>
                    </div>
                ) : (
                    <ReactSortable
                        tag="ol"
                        className="mk-rows"
                        list={visible}
                        setList={moveVisible}
                        handle=".mk-drag"
                        disabled={!manual}
                        animation={150}
                    >
                        {visible.map((item, index) => (
                            <li className="mk-item mk-item--drag" key={item.id}>
                                {manual && <span className="mk-drag" title={T.drag} aria-hidden="true"><i className="fa-solid fa-grip-vertical"></i></span>}
                                <span className="mk-item__pos">{index + 1}</span>
                                <span className="mk-item__media mk-item__media--sm">
                                    <span className="mk-thumb">
                                        {item.thumb
                                            ? <img src={item.thumb} alt="" width={160} height={90} loading="lazy" decoding="async"/>
                                            : <span className="mk-thumb__empty"><i className="fa-solid fa-lock" aria-hidden="true"></i></span>}
                                        {item.dur && <span className="mk-thumb__dur">{item.dur}</span>}
                                    </span>
                                </span>
                                <div className="mk-item__body">
                                    <span className="mk-item__title mk-clamp-2" dir="auto">{item.private ? T.private : item.title}</span>
                                    {item.views && <span className="mk-muted" style={{fontSize: '.8125rem'}}>{item.views}</span>}
                                </div>
                                <div className="mk-item__aside">
                                    <button type="button" className="mk-icon-btn mk-icon-btn--muted" title={T.remove} aria-label={pair(T, T.remove, item.private ? T.private : item.title)} onClick={() => remove(item.id)}>
                                        <i className="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ReactSortable>
                )}
                {rest > 0 && (
                    <div className="mk-panel__foot">
                        <span className="mk-muted" style={{fontSize: '.8125rem'}}>{showing}</span>
                        <button type="button" className="mk-btn mk-btn--quiet mk-btn--sm" onClick={() => setShown(shown + more)}>{moreLabel}</button>
                    </div>
                )}
                <input type="hidden" name="videos_order" value={items.map(item => item.id).join(',')}/>
                {uuid && <input type="hidden" name="videos_loaded" value={loaded ?? ''}/>}
            </section>
            <Search items={items} add={add} texts={T} typed={typed}/>
        </>
    );
}

function Search({items, add, texts, typed}: {items: Item[], add: (video: VideoType) => void, texts: Texts, typed: {value: string, focused: boolean}}) {

    const [query, setQuery] = useState<string>(typed.value);
    const field = useRef<HTMLInputElement>(null);
    const { t } = useTranslation();

    // the member was typing in the server's field: the focus and the caret stay at the end of what they typed
    useEffect(() => {
        const input = field.current;
        if (!typed.focused || !input || document.activeElement === input) return;
        input.focus({preventScroll: true});
        try { input.setSelectionRange(input.value.length, input.value.length); } catch { /* type=search without selection API */ }
    }, []);

    const {data: results, isFetching, isError, refetch} = useSearchQuery({
        query: query,
        key: 'playlist.search',
        searchFn: searchVideos,
    });

    const held = new Set(items.map(item => item.id));

    return (
        <section className="mk-panel mk-pl-pane mk-pl-pane--add" aria-labelledby="mk-pl-add-title">
            <div className="mk-panel__head"><h2 className="mk-panel__title" id="mk-pl-add-title">{texts.add}</h2></div>
            <div className="mk-panel__body">
                <label className="mk-search" data-mk-pristine="">
                    <span className="mk-sr">{texts.search}</span>
                    <i className={isFetching ? 'fa-solid fa-spinner fa-spin-pulse' : 'fa-solid fa-magnifying-glass'} aria-hidden="true"></i>
                    <input
                        ref={field}
                        className="mk-input"
                        type="search"
                        placeholder={texts.search}
                        autocomplete="off"
                        value={query}
                        onInput={(e) => setQuery(e.currentTarget.value)}
                        onKeyDown={(e) => { if (e.key === 'Enter') e.preventDefault(); }}
                    />
                </label>
            </div>
            <ul className="mk-rows" aria-live="polite">
                {isError && !isFetching && (
                    <li className="mk-item">
                        <span className="mk-muted">{t('Something went wrong')}</span>
                        <div className="mk-item__aside">
                            <button type="button" className="mk-btn mk-btn--secondary mk-btn--sm" onClick={() => refetch()}>{t('Try again')}</button>
                        </div>
                    </li>
                )}
                {results && results.data.length === 0 && query.trim() !== '' && (
                    <li className="mk-item"><span className="mk-muted">{texts.noResults}</span></li>
                )}
                {results && results.data.map(video => (
                    <li className="mk-item" key={video.id}>
                        <span className="mk-item__media mk-item__media--sm">
                            <span className="mk-thumb">
                                {video.thumbnail && <img src={video.thumbnail} alt="" width={160} height={90} loading="lazy" decoding="async"/>}
                                {video.formated_duration && <span className="mk-thumb__dur">{video.formated_duration}</span>}
                            </span>
                        </span>
                        <div className="mk-item__body">
                            <span className="mk-item__title mk-clamp-2" dir="auto">{video.title}</span>
                            <span className="mk-muted" style={{fontSize: '.8125rem'}}>{video.user?.username}{' · '}{t('Views', {count: video.views, formatted: formatCompactNumber(video.views)})}</span>
                        </div>
                        <div className="mk-item__aside">
                            {held.has(video.id)
                                ? <span className="mk-added"><i className="fa-solid fa-check" aria-hidden="true"></i> {texts.added}</span>
                                : <button type="button" className="mk-btn mk-btn--secondary mk-btn--sm" onClick={() => add(video)}><i className="fa-solid fa-plus" aria-hidden="true"></i><span>{texts.addOne}</span></button>}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export function Playlist(props: Props) {
    return (
        <QueryClientProvider client={playlistQueryClient}>
            <Editor {...props} />
        </QueryClientProvider>
    )
}
