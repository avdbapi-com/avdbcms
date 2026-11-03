import {InfiniteData, useInfiniteQuery, UseInfiniteQueryResult, UndefinedInitialDataInfiniteOptions, QueryKey} from "@tanstack/react-query";
import {CursorPaginator} from "@/types";
import {useInView} from "react-intersection-observer";
import {useCallback, useEffect, useState} from "preact/hooks";

type Options<TData> = Omit<UndefinedInitialDataInfiniteOptions<CursorPaginator<TData>, Error, InfiniteData<CursorPaginator<TData>>, QueryKey, string|null>,  "getNextPageParam" | "initialPageParam">

type UseCursorQueryResult<TData> = UseInfiniteQueryResult<InfiniteData<CursorPaginator<TData>>> & {
    ref: (node?: Element | null) => void;
    // The sentinel has started autoLoadPages pages in a row: the caller shows its "Load more" button and starts the
    // next page with loadMore(), which lets the sentinel go on after it.
    autoLoadPaused: boolean;
    loadMore: () => Promise<unknown>;
};

type SentinelOptions = {
    // How far from the viewport the `ref` sentinel starts the next page (IntersectionObserver rootMargin).
    rootMargin?: string;
    // How many pages the sentinel may start in a row before the caller has to ask (loadMore()); 0: no limit.
    autoLoadPages?: number;
};

// Whether the sentinel sits above the viewport's top edge: the visitor scrolled past the end of the feed (the footer
// is on screen), so a page landing now would grow the feed behind them, not ahead of them. Read when a page is about
// to be started, from the element itself: the observer's last entry is from the last crossing of its margin.
function aboveTheViewport(element: Element | null | undefined): boolean {
    return Boolean(element) && (element as Element).getBoundingClientRect().bottom < 0;
}

export function useCursorQuery<TData = unknown> (options : Options<TData>, {rootMargin = '1000px 0px', autoLoadPages = 0}: SentinelOptions = {}) : UseCursorQueryResult<TData> {

    // The sentinel within its margin (ahead of a scroll: the next page starts before the end of the feed shows), and
    // the same sentinel inside the viewport itself: the margin observer reports no change while the sentinel moves
    // from above the viewport into it, so the second one tells when the end of the feed is really on screen.
    const {ref: marginRef, inView, entry} = useInView({
        rootMargin,
    });
    const {ref: viewportRef, inView: inViewport} = useInView();
    const ref = useCallback((node?: Element | null) => {
        marginRef(node);
        viewportRef(node);
    }, [marginRef, viewportRef]);

    const query = useInfiniteQuery({
        ...options,
        initialPageParam: null,
        // An answer without its meta (an error page, an empty body) ends the list instead of throwing a TypeError.
        getNextPageParam: (lastPage) => lastPage?.meta?.next_cursor ?? undefined,
    });

    // Pages the sentinel started since the visitor last asked for one (loadMore()); the cap keeps a feed from loading
    // its whole catalogue on its own.
    const [autoLoads, setAutoLoads] = useState(0);
    const autoLoadPaused = autoLoadPages > 0 && autoLoads >= autoLoadPages;
    const {hasNextPage, isFetchingNextPage, isError, fetchNextPage} = query;

    useEffect(() => {
        if (!inView || !hasNextPage || isFetchingNextPage || isError || autoLoadPaused) {
            return;
        }

        // The sentinel is within its margin: below the viewport (the visitor comes near the end) or inside it: the
        // next page lands ahead of them. Above the viewport's top edge (they scrolled past the end of the feed, e.g.
        // a footer taller than a phone's screen kept the sentinel within the margin) nothing starts: before, every
        // page that landed re-ran this effect and started the next one, until the whole catalogue was loaded.
        if (aboveTheViewport(entry?.target) && !inViewport) {
            return;
        }

        setAutoLoads((count) => count + 1);
        fetchNextPage();
    }, [inView, inViewport, hasNextPage, isFetchingNextPage, isError, fetchNextPage, autoLoadPaused, entry]);

    // The visitor's own request (the "Load more" button): the page, then the sentinel may go on for another run.
    const loadMore = useCallback(() => fetchNextPage().then((result) => {
        setAutoLoads(0);

        return result;
    }), [fetchNextPage]);

    return {
        ...query,
        ref,
        autoLoadPaused,
        loadMore,
    }
}
