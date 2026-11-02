import { useState, useEffect} from 'preact/hooks';
import {useInView} from "react-intersection-observer";
import {Subscribe} from "@/components/Actions";
import {QueryClient, QueryClientProvider, useInfiniteQuery} from "@tanstack/react-query";
import {getInteractions} from "@/api/avdbclip";
import {Fragment} from "preact";
import {InteractionType, InteractionsFilters} from "@/types";
import {ChangeEvent} from "react";
import {useDebounce} from "@/hooks/useDebounce";
import {useTranslation} from "react-i18next";
import {ApiError} from "@/components/Commons";
import {formatRelativeTime} from "@/utils/format";

/**
 * "Who rated it" on a video's Analytics page (member design 6.5, perf-13, studio-35): the likes and the
 * dislikes of the video, filtered (All / Likes / Dislikes) and searched by name, in the kit's markup. The list box keeps
 * its height from the first frame (the dialog reserves it, the skeleton rows fill it), so nothing jumps when it loads.
 */

type Props = {
    target: number
}

const interactionsQueryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: false,
            staleTime: 30_000,
            gcTime: 5 * 60_000,
            refetchOnWindowFocus: false,
        }
    }
});

function Main ({target} : Props) {

    const [filter, setFilter] = useState<InteractionsFilters>('all');
    const [search, setSearch] = useState<string>('');

    const debouncedSearchQuery: string = useDebounce(search, 300);

    const { ref, inView} = useInView();

    const { t } = useTranslation();

    const {
        data: interactions,
        isLoading,
        isError,
        refetch,
        error,
        isFetching,
        fetchNextPage,
        hasNextPage,
    } = useInfiniteQuery({
        queryKey: ['interactions', target, filter, debouncedSearchQuery],
        queryFn: ({pageParam}) => getInteractions(target, filter, debouncedSearchQuery, pageParam),
        initialPageParam: 1,
        gcTime: 0,
        getNextPageParam: (lastPage, allPages, lastPageParam) => {
            if (lastPage.meta.current_page === lastPage.meta.last_page) {
                return undefined
            }
            return lastPageParam + 1
        }
    });

    useEffect( () => {
        if (inView && hasNextPage && !isFetching && !isError) {
            fetchNextPage()
        }
    }, [inView, hasNextPage, isFetching, isError, fetchNextPage]);

    const searching = (e: ChangeEvent<HTMLInputElement>) => setSearch(e.currentTarget.value)

    const total = interactions?.pages[0]?.meta?.total ?? 0;
    const empty = interactions !== undefined && total === 0;
    const choices: [InteractionsFilters, string][] = [['all', t('All')], ['up', t('Likes')], ['down', t('Dislikes')]];

    return (
        <div className="mk-likes">
            <div className="mk-likes__tools">
                <div className="mk-seg" role="group" aria-label={t('Show')}>
                    {choices.map(([value, label]) => (
                        <button type="button" className="mk-seg__item" aria-pressed={filter === value ? 'true' : 'false'} aria-current={filter === value ? 'true' : undefined} onClick={() => setFilter(value)}>{label}</button>
                    ))}
                </div>
                <label className="mk-search">
                    <span className="mk-sr">{t('Search names')}</span>
                    <i className="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input onInput={searching} type="search" className="mk-input" placeholder={t('Search names')}/>
                </label>
            </div>
            <p className="mk-muted mk-likes__count" aria-live="polite">
                {isLoading ? t('Loading ...') : t('Results', { count: total })}
            </p>
            {isError && <ApiError error={error} refetch={refetch}/>}
            <div className="mk-likes__list">
                {empty && !isFetching && (
                    <div className="mk-empty mk-empty--compact">
                        <span className="mk-empty__icon" aria-hidden="true"><i className="fa-regular fa-thumbs-up"></i></span>
                        <p className="mk-empty__text">{t('Nobody rated it yet.')}</p>
                    </div>
                )}
                <ul className="mk-rows">
                    {interactions && interactions.pages.map((group, i) => (
                        <Fragment key={i}>
                            {group.data.map((interaction) => <Row interaction={interaction}/>)}
                        </Fragment>
                    ))}
                    {(isFetching || isLoading) && [...Array(5).keys()].map(i => <SkeletonRow key={i}/>)}
                </ul>
                {hasNextPage && <span ref={ref}></span>}
            </div>
        </div>
    );
}

export function InteractionList(props: Props) {
    return (
        <QueryClientProvider client={interactionsQueryClient}>
            <Main {...props} />
        </QueryClientProvider>
    )
}

function SkeletonRow () {
    return (
        <li className="mk-item" aria-hidden="true">
            <span className="mk-skel" style={{inlineSize: '40px', blockSize: '40px', borderRadius: '50%'}}></span>
            <div className="mk-item__body">
                <span className="mk-skel mk-skel--title" style={{inlineSize: '40%'}}></span>
                <span className="mk-skel mk-skel--line" style={{inlineSize: '25%'}}></span>
            </div>
        </li>
    );
}

function Row ({interaction} : {interaction: InteractionType}) {

    const { t } = useTranslation();

    return (
        <li className="mk-item mk-item--media">
            <img className="mk-avatar" src={interaction.user.avatar} alt="" width={40} height={40} loading="lazy" decoding="async"/>
            <div className="mk-item__body">
                <a className="mk-item__title" href={interaction.user.route}>{interaction.user.username}</a>
                <div className="mk-row__meta">
                    <span>{interaction.status
                        ? <><i className="fa-solid fa-thumbs-up" aria-hidden="true" style={{color: 'var(--avd-success)'}}></i> {t('Liked it')}</>
                        : <><i className="fa-solid fa-thumbs-down" aria-hidden="true" style={{color: 'var(--mk-danger)'}}></i> {t('Disliked it')}</>}</span>
                    <span>{formatRelativeTime(interaction.perform_at)}</span>
                </div>
            </div>
            {
                interaction.user.id !== window.USER?.id &&
                <div className="mk-item__aside">
                    <Subscribe isSubscribe={interaction.user.subscribed_by_auth_user} user={interaction.user.id} size={'sm'}/>
                </div>
            }
        </li>
    )
}
