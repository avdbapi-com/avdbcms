import {QueryClient, QueryClientProvider, keepPreviousData, useInfiniteQuery} from "@tanstack/react-query";
import {useInView} from "react-intersection-observer";
import {getComments} from "@/api/avdbclip";
import {useEffect, useState} from "preact/hooks";
import {Fragment} from "preact";
import {CommentSkeleton} from "@/components/Skeletons/CommentSkeleton";
import {CommentsSort} from "@/types";
import Comment from "@/components/Comments/Comment";
import {useTranslation} from "react-i18next";
import {AddComment} from "@/components/Comments/Actions";
import {ApiError} from "@/components/Commons";

type Props = {
    video: string,
    defaultSort: CommentsSort,
    // The video's comment count when the page was rendered (show.blade.php). Sizes the loading skeleton, so a
    // video with no comments does not show four placeholder comments that then collapse under the visitor.
    count?: string | number,
}

const MAX_SKELETONS = 4;

function Main ({video, defaultSort, count} : Props) {

    const { t } = useTranslation();

    const { ref, inView} = useInView();

    const [sort, setSort] = useState<CommentsSort>(defaultSort);

    const {
        data: comments,
        isLoading,
        isError,
        error,
        refetch,
        isFetching,
        isPlaceholderData,
        fetchNextPage,
        hasNextPage,
    } = useInfiniteQuery({
        queryKey: ['comments', video, sort],
        queryFn: ({pageParam}) => getComments(video, pageParam, sort),
        initialPageParam: 1,
        gcTime: 0,
        // A new order keeps the comments on screen until the reordered list arrives (watch-14: the list emptied to
        // "Loading ...", collapsed, and the page jumped up by its height to the player).
        placeholderData: keepPreviousData,
        getNextPageParam: (lastPage, allPages, lastPageParam) => {
            if (lastPage.meta.current_page * lastPage.meta.per_page  > lastPage.meta.to) {
                return undefined
            }
            return lastPageParam + 1
        }
    });

    useEffect( () => {
        if (inView && !isFetching && !isError && !isPlaceholderData) {
            fetchNextPage()
        }
    }, [inView]);

    const activeButton = (type: CommentsSort) => sort === type ? 'primary ' : 'outline-primary ';
    // Placeholders for the comments still to come: the known total (the API's count once a page arrived, else the
    // page's count attribute) minus those shown, at most four. Without a known total, four as before.
    const knownTotal = comments ? Number(comments.pages[0]?.count) : Number.parseInt(String(count ?? ''), 10);
    const shownCount = comments ? comments.pages.reduce((sum, group) => sum + group.data.length, 0) : 0;
    const skeletonCount = Number.isFinite(knownTotal) && knownTotal >= 0
        ? Math.min(MAX_SKELETONS, Math.max(0, knownTotal - shownCount))
        : MAX_SKELETONS;

    return (
        <div className="mb-4">
            <div className="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-3">
                {
                    isLoading ? <div>{t('Loading ...')}</div> : <div>{t('Comments', {count: comments && comments.pages[0].count})}</div>
                }
                <div className={'d-flex gap-2 align-items-center flex-wrap'} role="group" aria-label={t('Sort comments')}>
                    <button type="button" onClick={() => setSort('top')} aria-pressed={sort === 'top'} className={'btn btn-' + activeButton('top') + 'btn-sm'}>{t('Top comments')}</button>
                    <button type="button" onClick={() => setSort('newest')} aria-pressed={sort === 'newest'} className={'btn btn-' + activeButton('newest') + 'btn-sm'}>{t('Newest first')}</button>
                </div>
            </div>
            <AddComment video_id={video}/>
            {isError && <ApiError refetch={refetch} error={error}/>}
            <div aria-busy={isPlaceholderData} className={isPlaceholderData ? 'avd-comments-reordering' : undefined}>
                {
                    comments &&
                    <>
                        {comments.pages.map((group, i) => (
                            <Fragment key={i}>
                                {group.data.map((comment) => <Comment key={comment.id} comment={comment}/>)}
                            </Fragment>
                        ))}
                    </>
                }
                {
                    (isFetching || isLoading) && !isPlaceholderData &&
                    [...Array(skeletonCount).keys()].map(i => <CommentSkeleton key={i}/>)
                }
                {hasNextPage && <span ref={ref}></span>}
            </div>
        </div>
    )
}

export function CommentsList(props: Props) {

    const queryClient = new QueryClient({
        defaultOptions: {
            queries: {
                retry: false,
            }
        }
    });


    return (
        <QueryClientProvider client={queryClient}>
            <Main {...props} />
        </QueryClientProvider>
    )
}
