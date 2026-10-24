// The list ad places of VideosList (resources/js/components/Videos/feedSlots.ts) against the shared cases of
// tests/Fixtures/feed_ad_slots.json (App\Support\FeedAdSlots runs the same "wide" cases: tests/Unit/FeedAdSlotsTest.php).
// Run: node tests/js/feed_ad_slots.test.ts  (Node 22.18+ runs TypeScript directly). Exit code 1 on any failure.
// tests/Unit/FeedAdSlotsTest.php runs this file when Node is available.
import {readFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {buildFeedSlots, completeRowSlotBudget, wideAdVideoIndexes} from '../../resources/js/components/Videos/feedSlots.ts';

type CardCase = {name: string, videos: number, columns: number, every: number, maxAds: number, reserve: boolean, maxRows: number, layout?: 'grid_card' | 'wide_row', adsEnabled?: boolean, expect: string};
type WideCase = {name: string, videos: number, columns: number, every: number, maxAds: number, after: number[]};
type BudgetCase = {name: string, videos: number, columns: number, every: number, maxAds: number, slots: number};

const here = dirname(fileURLToPath(import.meta.url));
const cases = JSON.parse(readFileSync(join(here, '..', 'Fixtures', 'feed_ad_slots.json'), 'utf8')) as {card: CardCase[], wide: WideCase[], budget: BudgetCase[]};

// '8v A 15v' -> 'vvvvvvvvAvvvvvvvvvvvvvvv'
const expand = (runs: string): string => runs.trim().split(/\s+/).map((run) => {
    const match = /^(\d*)([vAW])$/.exec(run);

    if (!match) {
        throw new Error(`bad run "${run}"`);
    }

    return match[2].repeat(match[1] === '' ? 1 : Number(match[1]));
}).join('');

let failures = 0;
let passed = 0;
const check = (name: string, actual: unknown, expected: unknown): void => {
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);

    if (a === e) {
        passed += 1;

        return;
    }

    failures += 1;
    console.error(`FAIL ${name}\n  expected ${e}\n  actual   ${a}`);
};

for (const c of cases.card) {
    const videos = Array.from({length: c.videos}, (_, i) => ({id: i + 1}));
    const slots = buildFeedSlots(videos, c.columns, c.every, c.maxAds, c.adsEnabled ?? true, c.reserve, c.layout ?? 'grid_card', c.maxRows);
    const shape = slots.map((slot) => slot.type === 'video' ? 'v' : (slot.layout === 'wide_row' ? 'W' : 'A')).join('');
    check(`card: ${c.name}`, shape, expand(c.expect));

    // Video numbers stay 1..n in order, whatever ads stand between them.
    const numbers = slots.flatMap((slot) => slot.type === 'video' ? [slot.videoNumber] : []);
    check(`card numbering: ${c.name}`, numbers, numbers.map((_, i) => i + 1));

    // Keys stay unique (Preact keys).
    check(`card keys: ${c.name}`, new Set(slots.map((slot) => slot.key)).size, slots.length);
}

for (const c of cases.wide) {
    check(`wide: ${c.name}`, [...wideAdVideoIndexes(c.videos, c.columns, c.every, c.maxAds)].sort((a, b) => a - b), c.after);
}

for (const c of cases.budget) {
    check(`budget: ${c.name}`, completeRowSlotBudget(c.videos, c.columns, c.every, c.maxAds), c.slots);
}

// Exhaustive rule checks on growing grid-card feeds and pagination lists: N videos before every card ad, never an ad
// at the end of a list, never more than maxAds, complete rows when the feed reserves them, no video lost elsewhere.
for (const columns of [1, 2, 3, 4, 5, 6]) {
    for (const every of [1, 2, 3, 5, 6, 7, 8, 12, 48]) {
        for (const maxAds of [0, 1, 2, 3]) {
            for (let count = 0; count <= 60; count += 1) {
                for (const reserve of [true, false]) {
                    const videos = Array.from({length: count}, (_, i) => ({id: i + 1}));
                    const slots = buildFeedSlots(videos, columns, every, maxAds, true, reserve, 'grid_card', 0);
                    const label = `rule cols=${columns} N=${every} max=${maxAds} videos=${count} reserve=${reserve}`;
                    let run = 0;
                    let ads = 0;
                    let ok = true;

                    slots.forEach((slot, index) => {
                        if (slot.type === 'video') {
                            run += 1;

                            return;
                        }

                        ads += 1;
                        ok = ok && run === every && index > 0;
                        run = 0;
                    });

                    const shown = slots.filter((slot) => slot.type === 'video').length;
                    const lastIsAd = slots.length > 0 && slots[slots.length - 1].type === 'ad';

                    ok = ok && (maxAds === 0 || ads <= maxAds);
                    ok = ok && (count > every || ads === 0);

                    if (reserve && columns > 1 && count > columns) {
                        ok = ok && slots.length % columns === 0;
                        // a last-place ad only with a video held back to follow it
                        ok = ok && (!lastIsAd || shown < count);
                    } else {
                        ok = ok && shown === count && !lastIsAd;
                    }

                    if (ok) {
                        passed += 1;
                    } else {
                        failures += 1;
                        console.error(`FAIL ${label}: ${slots.map((slot) => slot.type === 'video' ? 'v' : 'A').join('')}`);
                    }
                }
            }
        }
    }
}

// Exhaustive rule checks on full-width ad rows: each one goes between two rows (after a full row, or after the last
// video), the row before it holds video k*N (so the i-th ad row has at least i*N videos before it), every N-th video
// gets its row's ad until "Most ads per list" is reached, and a list of fewer than N videos has none.
for (const columns of [1, 2, 3, 4, 5, 6]) {
    for (const every of [1, 2, 3, 5, 6, 7, 8, 12, 48]) {
        for (const maxAds of [0, 1, 2, 3]) {
            for (let count = 0; count <= 60; count += 1) {
                const after = [...wideAdVideoIndexes(count, columns, every, maxAds)].sort((a, b) => a - b);
                const label = `wide rule cols=${columns} N=${every} max=${maxAds} videos=${count}`;
                const rowEnd = (video: number): number => Math.min(count, Math.ceil(video / columns) * columns);
                let ok = (maxAds === 0 || after.length <= maxAds) && (count >= every || after.length === 0);

                after.forEach((position, index) => {
                    let holdsMultiple = false;

                    for (let video = Math.max(1, position - columns + 1); video <= position; video += 1) {
                        holdsMultiple = holdsMultiple || video % every === 0;
                    }

                    ok = ok && position <= count && (position % columns === 0 || position === count)
                        && holdsMultiple && position >= (index + 1) * every;
                });

                if (maxAds === 0 || after.length < maxAds) {
                    for (let video = every; video <= count; video += every) {
                        ok = ok && after.includes(rowEnd(video));
                    }
                }

                if (ok) {
                    passed += 1;
                } else {
                    failures += 1;
                    console.error(`FAIL ${label}: ${JSON.stringify(after)}`);
                }
            }
        }
    }
}

console.log(`feed ad slots: ${passed} passed, ${failures} failed`);
process.exitCode = failures === 0 ? 0 : 1;
