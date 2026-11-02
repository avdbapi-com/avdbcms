// 1.2.3 round 2 (R2A-029): the Like / Dislike buttons' click queue (resources/js/components/Interactions/likeQueue.ts),
// run against a fake server that toggles like Api\Private\InteractionController::perform().
//  - every click shows at once (none is ignored while a request runs);
//  - one request at a time, never more requests than clicks;
//  - when every answer is in, the server stores what the member chose last and the buttons show the server's counts;
//  - a refused request (403 view-only demo, 419, 429, no connection) shows what the server has.
// Every click sequence of 1-6 clicks from every starting state, with the answers coming at every possible moment.
// Run: node tests/js/like_queue.test.ts  (Node 22.18+ runs TypeScript directly). Exit code 1 on any failure.
// tests/Unit/LikeQueueNodeTest.php runs this file when Node is available.
// LIKE_QUEUE_MODULE: another implementation of the same interface (the lane's proof that the test fails on the old rule)
const {createLikeQueue, nextRequest, reducer} = await import(process.env.LIKE_QUEUE_MODULE || '../../resources/js/components/Interactions/likeQueue.ts');

type State = {liked: boolean, disliked: boolean, counterLike: number, counterDislike: number};
type Click = 'LIKE' | 'DISLIKE';

let failures = 0;
let passed = 0;
const check = (name: string, actual: unknown, expected: unknown): void => {
    if (JSON.stringify(actual) === JSON.stringify(expected)) {
        passed++;
    } else {
        failures++;
        if (failures <= 25) {
            console.error(`FAIL ${name}: got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);
        }
    }
};

// the server: one row per member and video (status true = like, false = dislike), toggled per request
const fakeServer = (start: State) => {
    const row = {...start};
    return {
        row,
        apply(type: 'like' | 'dislike'): void {
            const status = type === 'like';
            const current = row.liked ? true : row.disliked ? false : null;
            if (current === status) {
                // the same again: removed
                if (status) { row.liked = false; row.counterLike--; } else { row.disliked = false; row.counterDislike--; }
                return;
            }
            if (current === true) { row.liked = false; row.counterLike--; }
            if (current === false) { row.disliked = false; row.counterDislike--; }
            if (status) { row.liked = true; row.counterLike++; } else { row.disliked = true; row.counterDislike++; }
        },
    };
};

const flush = async (): Promise<void> => { for (let i = 0; i < 6; i++) await Promise.resolve(); };

const STARTS: Record<string, State> = {
    none: {liked: false, disliked: false, counterLike: 4, counterDislike: 2},
    liked: {liked: true, disliked: false, counterLike: 5, counterDislike: 2},
    disliked: {liked: false, disliked: true, counterLike: 4, counterDislike: 3},
};

// a run: the clicks, and after which click (index) each pending answer arrives ('end' = after the last click)
const run = async (startKey: string, clicks: Click[], answerAfter: number[], refuse: number | null = null) => {
    const start = STARTS[startKey];
    const server = fakeServer(start);
    const pending: {type: 'like' | 'dislike', resolve: () => void, reject: (e: unknown) => void}[] = [];
    let shown: State = start;
    let busyNow = false;
    let requests = 0;
    const lost: string[] = [];
    const queue = createLikeQueue(
        start,
        (type) => new Promise<void>((resolve, reject) => { requests++; pending.push({type, resolve, reject}); }),
        (next) => { shown = next; },
        (on) => { busyNow = on; },
    );
    // answer the oldest request: the server applies it (or refuses: nothing stored, the promise rejects)
    const answer = async (): Promise<void> => {
        const p = pending.shift();
        if (!p) return;
        if (refuse !== null && requests === refuse + 1 && pending.length === 0) {
            p.reject(Object.assign(new Error('refused'), {status: 403}));
        } else {
            server.apply(p.type);
            p.resolve();
        }
        await flush();
    };
    for (const [i, click] of clicks.entries()) {
        const before = shown;
        queue.click(click);
        await flush();
        const expected = reducer(before, click);
        if (JSON.stringify(shown) !== JSON.stringify(expected)) lost.push(`click ${i + 1}`);
        for (const after of answerAfter) if (after === i) await answer();
    }
    for (let guard = 0; pending.length > 0 && guard < 50; guard++) await answer();
    return {server: server.row, shown, requests, lost, busyNow, pending: pending.length};
};

// every click sequence of 1-6 clicks, the answers at every moment (none during the clicks / after each click)
const sequences = (n: number): Click[][] => n === 0 ? [[]] : sequences(n - 1).flatMap((s) => [[...s, 'LIKE' as Click], [...s, 'DISLIKE' as Click]]);
let runs = 0;
for (const startKey of Object.keys(STARTS)) {
    for (let n = 1; n <= 6; n++) {
        for (const clicks of sequences(n)) {
            const timings: number[][] = [[], clicks.map((_, i) => i), [0], [Math.floor(n / 2)], [n - 1]];
            for (const timing of timings) {
                const r = await run(startKey, clicks, timing);
                runs++;
                const label = `${startKey} ${clicks.join(',')} answers after ${JSON.stringify(timing)}`;
                // the member's last choice: every click applied in order
                const chosen = clicks.reduce((s: State, c) => reducer(s, c), STARTS[startKey]);
                check(`${label}: no click ignored`, r.lost, []);
                check(`${label}: the server stores the last choice`, [r.server.liked, r.server.disliked], [chosen.liked, chosen.disliked]);
                check(`${label}: the buttons show the server`, r.shown, r.server);
                check(`${label}: counts`, [r.server.counterLike, r.server.counterDislike], [chosen.counterLike, chosen.counterDislike]);
                check(`${label}: at most one request per click`, r.requests <= clicks.length, true);
                check(`${label}: nothing left running`, [r.pending, r.busyNow], [0, false]);
            }
        }
    }
}

// a refusal (the view-only demo account, a session that ended, too many tries): the buttons show what the server has
for (const startKey of Object.keys(STARTS)) {
    const one = await run(startKey, ['LIKE'], [], 0);
    check(`${startKey} like refused: shows the server`, one.shown, STARTS[startKey]);
    check(`${startKey} like refused: nothing stored`, one.server, STARTS[startKey]);
    // the second request refused: the first one stays
    const two = await run(startKey, ['LIKE', 'DISLIKE'], [], 1);
    check(`${startKey} like ok, dislike refused: shows the server`, two.shown, two.server);
    check(`${startKey} like ok, dislike refused: busy over`, two.busyNow, false);
}

// nextRequest: one request from any stored state reaches any chosen state
const flags = [[false, false], [true, false], [false, true]];
for (const [hl, hd] of flags) for (const [wl, wd] of flags) {
    const have = {liked: hl, disliked: hd, counterLike: 1, counterDislike: 1};
    const want = {liked: wl, disliked: wd, counterLike: 1, counterDislike: 1};
    const type = nextRequest(have, want);
    if (hl === wl && hd === wd) {
        check(`nextRequest ${JSON.stringify([hl, hd])} -> same`, type, null);
    } else {
        const after = reducer(have, type === 'like' ? 'LIKE' : 'DISLIKE');
        check(`nextRequest ${JSON.stringify([hl, hd])} -> ${JSON.stringify([wl, wd])} in one request`, [after.liked, after.disliked], [wl, wd]);
    }
}

console.log(`like queue: ${passed} passed, ${failures} failed (${runs} runs)`);
process.exit(failures === 0 ? 0 : 1);
