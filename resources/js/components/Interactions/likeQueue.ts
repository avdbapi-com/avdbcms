/**
 * The Like / Dislike buttons' state (Interaction.tsx), kept free of Preact so tests/js/like_queue.test.ts runs it in
 * Node (1.2.3 round 2, R2A-029).
 *
 * The server toggles (Api\Private\InteractionController::perform): "like" turns a like on (a dislike becomes a like) or,
 * when the member already likes the video, off; "dislike" the same the other way. reducer() is that rule applied to
 * what the page shows, with the counts. The page shows every click at once and sends one request at a time:
 * nextRequest() is the request that takes the server from what it stores one step towards what the member chose last,
 * so the clicks made while a request runs are folded into the next one and none is lost.
 */
export interface State {
    liked: boolean;
    disliked: boolean;
    counterLike: number;
    counterDislike: number;
}

export type Action = 'LIKE' | 'DISLIKE' | {restore: State};

export const reducer = (state: State, action: Action): State => {
    if (typeof action === 'object') {
        return action.restore;
    }

    switch (action) {
        case 'LIKE':
            return {
                ...state,
                liked: !state.liked,
                disliked: false,
                counterLike: state.liked ? state.counterLike - 1 : state.counterLike + 1,
                counterDislike: state.disliked ? state.counterDislike - 1 : state.counterDislike,
            };
        case 'DISLIKE':
            return {
                ...state,
                disliked: !state.disliked,
                liked: false,
                counterDislike: state.disliked ? state.counterDislike - 1 : state.counterDislike + 1,
                counterLike: state.liked ? state.counterLike - 1 : state.counterLike,
            };
        default:
            return state;
    }
};

/** The request that takes the server's state (have) one step towards the member's last choice (want); null when equal. */
export const nextRequest = (have: State, want: State): 'like' | 'dislike' | null => {
    if (want.liked && !have.liked) {
        return 'like';
    }
    if (want.disliked && !have.disliked) {
        return 'dislike';
    }
    if (!want.liked && have.liked) {
        return 'like';
    }
    if (!want.disliked && have.disliked) {
        return 'dislike';
    }

    return null;
};

/** What a request does to the server's state (the same toggle as a click). */
export const afterRequest = (have: State, type: 'like' | 'dislike'): State => reducer(have, type === 'like' ? 'LIKE' : 'DISLIKE');

/** The clicks of one pair of buttons (Interaction.tsx creates one per pair). */
export interface LikeQueue {
    click(action: 'LIKE' | 'DISLIKE'): void;
}

// a guard against a loop: each answer brings the server to the member's choice of that moment, so another round only
// follows a click made while the request before it ran
const MAX_ROUNDS = 32;

/**
 * Every click is shown at once (show) and sent one request at a time (request: resolves when the server answered,
 * rejects on a refusal or a failure). The clicks made while a request runs are folded into the next request, never
 * ignored. A refusal shows what the server has (its toast says why: the caller's request does that). busy(true|false)
 * frames the requests.
 */
export const createLikeQueue = (
    initial: State,
    request: (type: 'like' | 'dislike') => Promise<unknown>,
    show: (state: State) => void,
    busy: (on: boolean) => void,
): LikeQueue => {
    let shown = initial;
    let stored = initial;
    let sending = false;

    const showStored = (): void => {
        shown = stored;
        show(stored);
    };

    const send = async (): Promise<void> => {
        if (sending) {
            return;
        }
        sending = true;
        busy(true);
        try {
            let type = nextRequest(stored, shown);
            for (let round = 0; type !== null; round++) {
                if (round >= MAX_ROUNDS) {
                    showStored();
                    break;
                }
                await request(type);
                stored = afterRequest(stored, type);
                type = nextRequest(stored, shown);
            }
        } catch {
            showStored();
        } finally {
            sending = false;
            busy(false);
        }
    };

    return {
        click(action: 'LIKE' | 'DISLIKE'): void {
            shown = reducer(shown, action);
            show(shown);
            void send();
        },
    };
};
