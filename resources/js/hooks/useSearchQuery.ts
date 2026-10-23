import {useDebounce} from "@/hooks/useDebounce";
import {useQuery, UseQueryResult} from "@tanstack/react-query";
import {useEffect} from "preact/hooks";
import {show as showToast} from "@/functions/toast";

type Options<TData> = {
    query: string,
    key: string,
    searchFn: (query: string) => Promise<TData>,
    debounce?: number
    minLength?: number
    enabled?: boolean
}

export function useSearchQuery<TData = unknown> ({query, key, searchFn, debounce = 300, minLength = 0, enabled = true} : Options<TData>) : UseQueryResult<TData> {

    const debouncedSearchQuery = useDebounce(query, debounce);

    const queryResult = useQuery({
        queryKey: [key, debouncedSearchQuery],
        queryFn: () => searchFn(debouncedSearchQuery),
        enabled: enabled && debouncedSearchQuery.trim().length > 0 && debouncedSearchQuery.trim().length >= minLength,
        refetchOnWindowFocus: false
    });

    useEffect(() => {
        if (!queryResult.isFetching && queryResult.isError) {
            showToast(queryResult.error.message)
        }
    }, [queryResult.isError, queryResult.isFetching]);

    return queryResult;
}
