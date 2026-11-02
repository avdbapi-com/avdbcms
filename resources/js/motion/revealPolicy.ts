// When an entrance may run (auth-5, member design 4.2: "never hide or animate elements that are already painted").
//
// An entrance starts from opacity 0, so it may only run on content the visitor has not seen yet. The motion chunk loads
// after the page has usually painted, and paint timing cannot tell whether it has: Chromium posts the
// first-contentful-paint entry only after the frame is presented, so a check right after the first paint read "nothing
// painted yet" and the old page intro hid the painted header and menu, then faded them back in for about a second. So
// the rule is spatial instead of temporal: whatever is on screen when it is first observed keeps its painted state, and
// only content that enters the early-reveal margin below the fold (not visible yet) fades in as it scrolls up.
// Pure functions, so tests/js/front_logic.test.ts runs them.

export type RevealBox = {
    isIntersecting: boolean;
    top: number;
    bottom: number;
    viewportHeight: number;
};

/** On screen right now (not just inside the early-reveal margin): it may have been painted. */
export const isOnScreen = (box: Pick<RevealBox, 'top' | 'bottom' | 'viewportHeight'>): boolean =>
    box.bottom > 0 && box.top < box.viewportHeight;

/** Fade in only what has entered the reveal margin below the fold and is not on screen yet. */
export const shouldReveal = (box: RevealBox): boolean => box.isIntersecting && !isOnScreen(box);

/**
 * The longest download (ms) after which a loaded image may still fade in. A picture whose bytes came slowly may already
 * be partly on screen (the browser draws an image as its data arrives), and fading it from opacity 0 would blink it;
 * one that came at once (a cache, a fast link) was not drawn before its load event.
 */
export const IMAGE_FADE_MAX_FETCH_MS = 50;

/** Whether a picture that just loaded may fade in: only when its download time is known and short. */
export const imageMayFade = (fetchMs: number | null | undefined): boolean =>
    typeof fetchMs === 'number' && Number.isFinite(fetchMs) && fetchMs >= 0 && fetchMs <= IMAGE_FADE_MAX_FETCH_MS;
