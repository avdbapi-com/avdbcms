// Where the admin's toast stack goes (resources/admin/lib/placement.ts, used by modules/toast.ts): never over a bar at the
// bottom of the window. The numbers are the ones measured on Settings > Site basics in AVDBCMS 1.2.2 (the
// error toast covered Save changes and Discard at 1440x900) and on a phone at 390x844.
// Run: node tests/js/toast_placement.test.ts  (Node 22.18+ runs TypeScript directly). Exit code 1 on any failure.
// tests/Unit/AdminToastPlacementTest.php runs this file when Node is available.
import {GAP, liftFor, stickyReach, type Reach} from '../../resources/admin/lib/placement.ts';

let failures = 0;
let passed = 0;
const check = (name: string, actual: unknown, expected: unknown): void => {
    if (JSON.stringify(actual) === JSON.stringify(expected)) {
        passed++;
    } else {
        failures++;
        console.error(`FAIL ${name}: got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);
    }
};

/** Whether the stack (bottom edge at `bottom`, `height` tall) overlaps the bar wherever the bar stands. */
const overlaps = (bottom: number, height: number, bar: Reach): boolean => !(bottom + height <= bar.low || bottom >= bar.high);

// 1440x900: the settings save bar sticks 12 px above the bottom (62 px tall) and stands 48 px above it at the end of
// the page (page 2074 px). The stack: right side, 420 px wide, one error toast 66 px tall, normally 24 px up.
const desktopBar = stickyReach(2074 - 48, 62, 12, 900, 2074, 386, 1302);
check('desktop bar: stuck 12 px up, at most 110 px up', [desktopBar.low, desktopBar.high], [12, 110]);
const desktop = liftFor({left: 996, right: 1416, height: 66}, 24, [desktopBar], 900);
check('desktop: above the bar wherever it stands', desktop, 110 + GAP);
check('desktop: Save changes and Discard stay clear', overlaps(desktop, 66, desktopBar), false);
check('desktop: three toasts too', overlaps(liftFor({left: 996, right: 1416, height: 172}, 24, [desktopBar], 900), 172, desktopBar), false);

// 390x844: the save bar is fixed at the bottom (65 px tall); the view-only note stands 96 px up while it shows (46 px).
const phoneBar: Reach = {low: 0, high: 65, left: 0, right: 390};
const note: Reach = {low: 96, high: 142, left: 16, right: 374};
const phone = liftFor({left: 16, right: 374, height: 87}, 16, [phoneBar, note], 844);
check('phone: above the save bar and the note', phone, 142 + GAP);
check('phone: clear of the bar', overlaps(phone, 87, phoneBar), false);
check('phone: clear of the note', overlaps(phone, 87, note), false);

// no bar, or a bar elsewhere: the stack keeps its place
check('no bar: its own place', liftFor({left: 996, right: 1416, height: 66}, 24, [], 900), 24);
check('a bar in other columns (the centred note on a wide screen)', liftFor({left: 996, right: 1416, height: 66}, 24, [{low: 16, high: 46, left: 520, right: 920}], 900), 24);
check('a bar below the window', liftFor({left: 996, right: 1416, height: 66}, 24, [{low: -200, high: -10, left: 386, right: 1302}], 900), 24);

// a short page: the bar stands high up and the stack fits below it
const shortBar = stickyReach(620, 62, 12, 900, 900, 386, 1302);
check('short page: the bar stays 280 px up', [shortBar.low, shortBar.high], [280, 342]);
check('short page: the stack stays below it', liftFor({left: 996, right: 1416, height: 66}, 24, [shortBar], 900), 24);

// the stack never leaves the window
check('a very tall bar: the stack stops at the top of the window', liftFor({left: 0, right: 400, height: 300}, 24, [{low: 0, high: 800, left: 0, right: 400}], 900), 900 - 300 - GAP);

// right to left: the stack on the left, the bar across the content
check('right to left', liftFor({left: 24, right: 444, height: 66}, 24, [stickyReach(2026, 62, 12, 900, 2074, 138, 1054)], 900), 110 + GAP);

console.log(`toast placement: ${passed} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
