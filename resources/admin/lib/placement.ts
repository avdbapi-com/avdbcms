// Where the toast stack goes (modules/toast.ts): pure geometry, no page access, so tests/js/toast_placement.test.ts runs
// it in Node. All distances are measured from the bottom edge of the window, in px.

/** Room between the stack and a bar. */
export const GAP = 12;

/** A bar at the bottom of the window: the lowest its bottom edge comes and the highest its top edge goes, and its sides. */
export type Reach = {low: number; high: number; left: number; right: number};

/** The toast stack: its sides and its height. */
export type Stack = {left: number; right: number; height: number};

/**
 * Where the stack's bottom edge goes: its own place (base) when it fits below every bar it shares columns with, else
 * above the highest such bar; never off the top of the window (viewport = the window's height).
 */
export const liftFor = (stack: Stack, base: number, bars: Reach[], viewport: number): number => {
    const beside = bars.filter((bar) => bar.left < stack.right && bar.right > stack.left && bar.high > 0);
    let bottom = base;

    // Each move only goes up, so a few passes settle it (a bar the first move lands on is cleared by the next).
    for (let pass = 0; pass < 4; pass++) {
        const before = bottom;

        for (const bar of beside) {
            const clearBelow = bottom + stack.height + GAP <= bar.low;
            const clearAbove = bottom >= bar.high + GAP;

            if (!clearBelow && !clearAbove) bottom = bar.high + GAP;
        }

        if (bottom === before) break;
    }

    return Math.max(base, Math.min(bottom, viewport - stack.height - GAP));
};

/**
 * A bar that sticks to the bottom of the window (position: sticky; bottom: offset), from where it stands in the page
 * when not stuck: its bottom edge in page coordinates (naturalBottom), its height, the page's height (pageEnd, at least
 * the window's). At the top of the page it is stuck (or higher, when the page is short); at the end of the page it
 * stands where it is in the page, or stuck when it is the last thing on the page.
 */
export const stickyReach = (naturalBottom: number, height: number, offset: number, viewport: number, pageEnd: number, left: number, right: number): Reach => ({
    low: Math.max(viewport - naturalBottom, offset),
    high: Math.max(Math.max(pageEnd, viewport) - naturalBottom, offset) + height,
    left,
    right,
});
