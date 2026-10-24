// Where the list ads of a video feed go (VideosList.tsx). Pure functions, no imports: tests/js/feed_ad_slots.test.ts
// runs them in Node with the cases of tests/Fixtures/feed_ad_slots.json, which tests/Unit/FeedAdSlotsTest.php also
// runs against App\Support\FeedAdSlots (the server skeleton of <x-videos-area> and the channel page grids).
// KEEP IN SYNC with App\Support\FeedAdSlots.
//
// "Ad after every N" (Money > Ads) means N videos come before each ad, in both shapes:
// - a card ad (grid_card) takes the place after every N videos: place N+1, then 2N+2, ... It only stands between two
//   videos, so a list of N videos or fewer shows none. "Most ads per list" (maxAds, 0 = no limit) caps the count.
// - a full-width row (wide_row) goes between two rows: after the row that holds video N, then the row that holds
//   video 2N, ... (one column: right after video N), so at least N videos come before it; when that row is the
//   list's last and not full yet, the ad row follows the last video. Also capped by maxAds.
// N below 1 counts as 1 (the setting allows 1-48; ads are off when the position is 0).

export type GridAdLayout = 'grid_card' | 'wide_row';

export type FeedSlot<V> = {
    type: 'ad',
    key: string,
    layout: GridAdLayout,
} | {
    type: 'video',
    key: string,
    video: V,
    videoNumber: number,
};

// N: the number of videos before each ad (at least 1).
export function adEvery(gridPosition: number): number {
    const every = Math.floor(Number(gridPosition));

    return Number.isFinite(every) && every > 1 ? every : 1;
}

// Card ads that stand between `videoCount` videos: ad k follows video k*N and needs a video after it.
export function cardAdCount(videoCount: number, every: number, maxAds: number): number {
    if (videoCount <= 1) {
        return 0;
    }

    const count = Math.floor((videoCount - 1) / adEvery(every));

    return maxAds > 0 ? Math.min(count, maxAds) : count;
}

// Every video plus the card ads between them.
export function naturalSlotCount(videoCount: number, every: number, maxAds: number): number {
    return Math.max(0, videoCount) + cardAdCount(videoCount, every, maxAds);
}

// Whether `slots` places fill completely from `videoCount` videos: the ads take places N+1, 2N+2, ... (capped by
// maxAds) and an ad in the last place renders only when a video is still left to follow it.
function fillsEveryPlace(slots: number, videoCount: number, every: number, maxAds: number): boolean {
    const period = adEvery(every) + 1;
    const adPlaces = maxAds > 0 ? Math.min(Math.floor(slots / period), maxAds) : Math.floor(slots / period);
    const videosNeeded = slots - adPlaces;
    const lastIsAd = slots % period === 0 && (maxAds === 0 || slots / period <= maxAds);

    return lastIsAd ? videosNeeded < videoCount : videosNeeded <= videoCount;
}

// Grid-card feeds that grow (infinite / Load more) show complete rows only: the most places, in whole rows, that the
// loaded videos and their card ads fill. The videos left over wait for the next page. A feed that does not fill one
// row, or a single column (every row is complete), shows every video with its ads.
export function completeRowSlotBudget(videoCount: number, columnCount: number, every: number, maxAds: number): number {
    if (columnCount <= 1 || videoCount <= columnCount) {
        return naturalSlotCount(videoCount, every, maxAds);
    }

    let best = 0;
    const upperBound = videoCount * 2 + columnCount;

    for (let slots = columnCount; slots <= upperBound; slots += columnCount) {
        if (fillsEveryPlace(slots, videoCount, every, maxAds)) {
            best = slots;
        }
    }

    return best || naturalSlotCount(videoCount, every, maxAds);
}

// Video numbers after which a full-width ad row goes: the end of the row that holds video N, 2N, ... (one column:
// video N; the last video when that row is not full). 1.2.1 went back to the last full row before video N, so on 3,
// 5 or 6 columns fewer than N videos came first (N 8, 3 columns: after video 6).
export function wideAdVideoIndexes(videoCount: number, columnCount: number, every: number, maxAds: number): Set<number> {
    const indexes = new Set<number>();
    const step = adEvery(every);

    for (let candidate = step; candidate <= videoCount; candidate += step) {
        if (maxAds > 0 && indexes.size >= maxAds) {
            break;
        }

        if (columnCount <= 1) {
            indexes.add(candidate);

            continue;
        }

        indexes.add(Math.min(videoCount, Math.ceil(candidate / columnCount) * columnCount));
    }

    return indexes;
}

export function buildFeedSlots<V extends {id?: unknown}>(
    videos: V[],
    columnCount: number,
    gridPosition: number,
    maxAds: number,
    adsEnabled: boolean,
    reserveAdSlots: boolean,
    gridLayout: GridAdLayout,
    maxRows = 0
): FeedSlot<V>[] {
    const slots: FeedSlot<V>[] = [];
    const every = adEvery(gridPosition);

    if (adsEnabled && gridLayout === 'wide_row') {
        const maxVideoCount = maxRows > 0 && columnCount > 0 ? maxRows * columnCount : videos.length;
        const boundedVideos = videos.slice(0, maxVideoCount);
        const wideIndexes = wideAdVideoIndexes(boundedVideos.length, columnCount, every, maxAds);
        let wideAdNumber = 0;

        boundedVideos.forEach((video, index) => {
            const videoNumber = index + 1;
            slots.push({
                type: 'video',
                key: `video-${video.id || index}`,
                video,
                videoNumber,
            });

            if (wideIndexes.has(videoNumber)) {
                wideAdNumber += 1;
                // Keyed by its number, not its video: when the next page fills the last row, the row moves behind
                // the new videos and keeps its ad (no reload).
                slots.push({
                    type: 'ad',
                    key: `ad-wide-${wideAdNumber}`,
                    layout: 'wide_row',
                });
            }
        });

        return slots;
    }

    // Row-limited feeds (max rows, e.g. 2) keep exactly their rows: a card ad takes one of those places instead of
    // adding a place that would spill into an extra row. Growing grid-card feeds show complete rows
    // (completeRowSlotBudget); every other feed shows all its videos with the card ads between them.
    let slotBudget: number;
    if (maxRows > 0 && columnCount > 0) {
        slotBudget = maxRows * columnCount;
    } else if (adsEnabled && reserveAdSlots) {
        slotBudget = completeRowSlotBudget(videos.length, columnCount, every, maxAds);
    } else {
        slotBudget = adsEnabled ? naturalSlotCount(videos.length, every, maxAds) : videos.length;
    }

    const period = every + 1;
    let videoIndex = 0;
    let adCount = 0;

    // A card ad takes place N+1, 2N+2, ...: N videos before each one. The loop stops when the videos run out, so an
    // ad never ends a list (it needs a video after it).
    for (let slotNumber = 1; slotNumber <= slotBudget && videoIndex < videos.length; slotNumber += 1) {
        if (adsEnabled && slotNumber % period === 0 && (maxAds === 0 || adCount < maxAds)) {
            slots.push({
                type: 'ad',
                key: `ad-${slotNumber}`,
                layout: 'grid_card',
            });
            adCount += 1;

            continue;
        }

        const video = videos[videoIndex];
        slots.push({
            type: 'video',
            key: `video-${video.id || videoIndex}`,
            video,
            videoNumber: videoIndex + 1,
        });
        videoIndex += 1;
    }

    return slots;
}
