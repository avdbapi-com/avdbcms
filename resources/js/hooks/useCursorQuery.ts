import {InfiniteData, useInfiniteQuery, UseInfiniteQueryResult, UndefinedInitialDataInfiniteOptions, QueryKey} from "@tanstack/react-query";
import {CursorPaginator} from "@/types";
import {useInView} from "react-intersection-observer";
import {useEffect} from "preact/hooks";

type Options<TData> = Omit<UndefinedInitialDataInfiniteOptions<CursorPaginator<TData>, Error, InfiniteData<CursorPaginator<TData>>, QueryKey, string|null>,  "getNextPageParam" | "initialPageParam">

type UseCursorQueryResult<TData> = UseInfiniteQueryResult<InfiniteData<CursorPaginator<TData>>> & {
    ref: (node?: Element | null) => void;
};

type SentinelOptions = {
    // How far from the viewport the `ref` sentinel starts the next page (IntersectionObserver rootMargin).
    rootMargin?: string;
};

export function useCursorQuery<TData = unknown> (options : Options<TData>, {rootMargin = '1000px 0px'}: SentinelOptions = {}) : UseCursorQueryResult<TData> {

    const { ref, inView} = useInView({
        rootMargin,
    });

    const query = useInfiniteQuery({
        ...options,
        initialPageParam: null,
        getNextPageParam: (lastPage) => lastPage.meta.next_cursor ?? undefined,
    });

    useEffect(() => {
        if (inView && query.hasNextPage && !query.isFetchingNextPage && !query.isError) {
            query.fetchNextPage()
        }
    }, [inView, query.hasNextPage, query.isFetchingNextPage, query.isError, query.fetchNextPage]);

    return {
        ...query,
        ref
    }
}
