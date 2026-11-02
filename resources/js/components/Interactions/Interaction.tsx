import { useReducer, useRef, useState } from 'preact/hooks';
import {useTranslation} from "react-i18next";
import {QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {useErrorMutation} from "@/hooks/useErrorMutation";
import {interact} from "@/api/avdbclip";
import {clsx} from "clsx";
import {InteractionsModel} from "@/types";
import {formatCompactNumber} from "@/utils/format";
import {createLikeQueue, reducer} from "./likeQueue";
import type {LikeQueue} from "./likeQueue";

type Props = {
    model: InteractionsModel,
    target: number,
    likes: number,
    dislikes: number,
    liked?: boolean,
    disliked?: boolean,
    showCount?: boolean
}

const interactionQueryClient = new QueryClient({
    defaultOptions: {
        queries: {
            retry: false,
            staleTime: 30_000,
            gcTime: 5 * 60_000,
            refetchOnWindowFocus: false,
        }
    }
});

// A name for an icon button with its count, e.g. "I like this (2)" (R1-060: the count alone was the name).
export const countedName = (text: string, count: number, showCount: boolean = true): string =>
    showCount && count > 0 ? `${text} (${formatCompactNumber(count)})` : text;

function Main ({model, target, likes, dislikes, liked = false, disliked = false, showCount = true} : Props) {

    const { t } = useTranslation();

    const [state, dispatch] = useReducer(reducer, {
        liked: liked,
        disliked : disliked,
        counterLike : likes,
        counterDislike : dislikes
    })
    const [busy, setBusy] = useState<boolean>(false);

    // The button and the count change at once, on every click (R2A-029: a click made while a request ran was ignored
    // without a sign). One request at a time, because the server toggles: what the member chose last is sent when the
    // answer before it is in (the clicks made meanwhile are folded into one request: Like, then Dislike and Like again
    // while the like runs, sends nothing more; Like then Dislike sends a dislike after the like). A refused or failed
    // request (signed out, page out of date, a view-only demo account, too many tries, no connection) puts back what
    // the server has, and the toast says why (R1-016: the thumb stayed solid and the count +1 while nothing was
    // stored). likeQueue.ts holds the rules (tests/js/like_queue.test.ts).
    const {mutateAsync} = useErrorMutation({
        mutationFn: (type: 'like' | 'dislike') => interact(type, target, model),
        mutationKey: ['interaction', model, target],
    })
    const request = useRef(mutateAsync);
    request.current = mutateAsync;
    const queue = useRef<LikeQueue | null>(null);
    if (queue.current === null) {
        queue.current = createLikeQueue(state, (type) => request.current(type), (next) => dispatch({restore: next}), setBusy);
    }

    const handleClick = (type: 'LIKE' | 'DISLIKE') => queue.current?.click(type);

    const isPending = busy;

    return (
        <div className={'d-flex justify-content-between bg-light-dark rounded-4'}>
            <button
                type="button"
                onClick={() => handleClick('LIKE')}
                className={clsx("hover-grey btn btn-sm border border-0 px-3 rounded-5 rounded-end", {
                    "text-black": state.liked,
                    "py-2": !showCount || state.counterLike === 0,
                })}
                aria-pressed={state.liked}
                aria-label={countedName(t("I like this"), state.counterLike, showCount)}
                aria-busy={isPending}
                data-bs-toggle="tooltip"
                data-bs-title={t("I like this")}
                data-bs-placement="bottom"
                data-bs-trigger="hover"
            >
                <div className={'d-flex gap-1 align-items-center'}>
                    {state.liked ? <i className="fa-solid fa-thumbs-up" aria-hidden="true"></i> : <i className="fa-regular fa-thumbs-up" aria-hidden="true"></i>}
                    { (showCount && state.counterLike > 0) && <span className={'ml-1'}>{formatCompactNumber(state.counterLike)}</span>}
                </div>
            </button>
            <div className="vr h-75 my-auto"></div>
            <button
                type="button"
                onClick={() => handleClick('DISLIKE')}
                className={clsx("hover-grey btn btn-sm border border-0 px-3 rounded-5 rounded-start", {
                    "text-black": state.disliked,
                })}
                aria-pressed={state.disliked}
                aria-label={countedName(t("I dislike this"), state.counterDislike, showCount)}
                aria-busy={isPending}
                data-bs-toggle="tooltip"
                data-bs-title={t("I dislike this")}
                data-bs-placement="bottom"
                data-bs-trigger="hover"
            >
                <div className={'d-flex gap-1  align-items-center'}>
                    {state.disliked ? <i className="fa-solid fa-thumbs-down" aria-hidden="true"></i> : <i className="fa-regular fa-thumbs-down" aria-hidden="true"></i>}
                    { (showCount && state.counterDislike > 0) && <span className={'ml-1'}>{formatCompactNumber(state.counterDislike)}</span>}
                </div>
            </button>
        </div>
    )
}

export function Interaction(props: Props) {
    return (
        <QueryClientProvider client={interactionQueryClient}>
            <Main {...props} />
        </QueryClientProvider>
    )
}
