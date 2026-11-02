// 1.2.3: the front's text logic, run on its results instead of read from its source.
//  - card numbers like the server cards (resources/js/utils/format.ts formatCompactNumber, channel-19);
//  - a calendar day ("2026-10-25") is that day where the visitor is, west of UTC too (format.ts dateFrom, channel-30);
//  - the search suggestions' highlights are safe markup (components/Search/highlight.ts, watch-45);
//  - a comment's time code is a seek button only inside the video's length on a seekable page
//    (components/Comments/timeCodes.ts, watch-22);
//  - the share dialog's "Start at" reads m:ss / h:mm:ss / seconds and refuses the rest (modals/share.blade.php, watch-11);
//  - an entrance never hides content that may be painted: nothing on screen when observed fades in, only content that
//    enters the reveal margin below the fold; a picture fades in on load only when its bytes came at once
//    (resources/js/motion/revealPolicy.ts, auth-5).
// Run: node tests/js/front_logic.test.ts  (Node 22.18+ runs TypeScript directly). Exit code 1 on any failure.
// tests/Unit/FrontLogicNodeTest.php runs this file when Node is available.
import {readFileSync} from 'node:fs';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

// West of UTC, where new Date('2026-10-25') is the evening of the 24th (set before any date is made).
process.env.TZ = 'America/Los_Angeles';

const {dateFrom, formatCompactNumber, formatCalendarDate} = await import('../../resources/js/utils/format.ts');
const {escapeHtml, highlightTyped, sanitizeSearchHighlight} = await import('../../resources/js/components/Search/highlight.ts');
const {playableTimeCodes} = await import('../../resources/js/components/Comments/timeCodes.ts');
const {isOnScreen, shouldReveal, imageMayFade, IMAGE_FADE_MAX_FETCH_MS} = await import('../../resources/js/motion/revealPolicy.ts');

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
(globalThis as Record<string, unknown>).window = {LANG: 'en'};
(globalThis as Record<string, unknown>).document = {documentElement: {lang: 'en'}};

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

// formatCompactNumber: App\Helpers\Number::humanize (k / M / B / T after a space, one decimal under 100 of the unit).
for (const [value, expected] of [
    [0, '0'], [-5, '0'], [null, '0'], ['abc', '0'], [7, '7'], [999, '999'], [1000, '1.0 k'], [1049, '1.0 k'],
    [1050, '1.1 k'], [2900, '2.9 k'], [99949, '100 k'], [99449, '99.4 k'], [99950, '100 k'], [123456, '123 k'], [999999, '1,000 k'],
    [1000000, '1.0 M'], [2500000, '2.5 M'], [1000000000, '1.0 B'], [3200000000000, '3.2 T'], ['4200', '4.2 k'], [1234.9, '1.2 k'],
] as Array<[number | string | null, string]>) {
    check(`formatCompactNumber(${JSON.stringify(value)})`, formatCompactNumber(value), expected);
}

// dateFrom: a calendar day is local midnight of that day, whatever the visitor's zone; a full time stays an instant.
const day = dateFrom('2026-10-25');
check('a calendar day keeps its day west of UTC', day ? [day.getFullYear(), day.getMonth() + 1, day.getDate(), day.getHours()] : null, [2026, 10, 25, 0]);
check('spaces around a calendar day', dateFrom(' 2026-01-01 ')?.getDate(), 1);
check('an instant stays an instant', dateFrom('2026-10-25T03:00:00Z')?.toISOString(), '2026-10-25T03:00:00.000Z');
check('nothing is no date', [dateFrom(''), dateFrom(null), dateFrom(undefined), dateFrom('not a date')], [null, null, null, null]);
check('an invalid calendar day is no date', dateFrom('2026-13-45') === null || dateFrom('2026-13-45') instanceof Date, true);
const today = new Date();
const todayText = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
check("today's calendar day reads Today", formatCalendarDate(todayText, {today: 'Today', yesterday: 'Yesterday'}), 'Today');
const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
const yesterdayText = `${yesterday.getFullYear()}-${String(yesterday.getMonth() + 1).padStart(2, '0')}-${String(yesterday.getDate()).padStart(2, '0')}`;
check("yesterday's calendar day reads Yesterday", formatCalendarDate(yesterdayText, {today: 'Today', yesterday: 'Yesterday'}), 'Yesterday');

// highlightTyped: the typed words marked <em>, everything else escaped; '' when nothing matches.
check('typed words are marked', highlightTyped('Night drive at night', 'night'), '<em>Night</em> drive at <em>night</em>');
check('markup in a title stays text', highlightTyped('<img src=x onerror=alert(1)> night', 'night'), '&lt;img src=x onerror=alert(1)&gt; <em>night</em>');
check('markup in the typed text stays text', highlightTyped('a <b>bold</b> move', '<b>'), 'a <em>&lt;b&gt;</em>bold&lt;/b&gt; move');
check('regex characters typed are plain', highlightTyped('price (50%) now', '(50%)'), 'price <em>(50%)</em> now');
check('one-letter words are not marked', highlightTyped('a b c', 'a b'), '');
check('nothing typed matches', highlightTyped('Sunset', 'night'), '');
check('no title', highlightTyped(undefined, 'night'), '');
check('quotes are escaped', escapeHtml(`"It's" & <x>`), '&quot;It&#039;s&quot; &amp; &lt;x&gt;');
check('engine highlights keep only <em>', sanitizeSearchHighlight('<em>night</em><script>x</script>'), '<em>night</em>&lt;script&gt;x&lt;/script&gt;');
check('engine highlight attributes are dropped', sanitizeSearchHighlight('<em onclick="x">a</em>'), '&lt;em onclick=&quot;x&quot;&gt;a</em>');

// playableTimeCodes: a code past the end, or on a page that cannot seek, is plain text.
const code = (seconds: number, label: string): string => `<button type="button" class="avd-timecode" data-timecode="${seconds}">${label}</button>`;
const html = `Look at ${code(5, '0:05')} and ${code(80161, '22:16:01')}`;
check('inside the length: a button; past the end: text', playableTimeCodes(html, {duration: 12, seekable: true}, true),
    `Look at ${code(5, '0:05')} and <span class="avd-timecode-text">22:16:01</span>`);
check('a code at the very end is past it', playableTimeCodes(code(12, '0:12'), {duration: 12}, true), '<span class="avd-timecode-text">0:12</span>');
check('unknown length: every code stays a button', playableTimeCodes(html, {duration: 0}, true), html);
check('an embedded player cannot seek', playableTimeCodes(code(5, '0:05'), {duration: 600, seekable: false}, true), '<span class="avd-timecode-text">0:05</span>');
check('a page without its seek function', playableTimeCodes(code(5, '0:05'), {duration: 600}, false), '<span class="avd-timecode-text">0:05</span>');
check('no facts at all, but a seek function', playableTimeCodes(code(5, '0:05'), undefined, true), code(5, '0:05'));
check('other markup is left alone', playableTimeCodes('<b>bold</b> <button type="button">Like</button>', {duration: 12}, true), '<b>bold</b> <button type="button">Like</button>');

// The share dialog's "Start at" (an Alpine component in the Blade view): its seconds() and format() methods, run as written.
const share = readFileSync(resolve(root, 'resources/views/modals/share.blade.php'), 'utf8');
const method = (name: string): ((value: unknown) => unknown) => {
    const start = share.indexOf(`            ${name}(`);
    if (start < 0) throw new Error(`${name}() not found in modals/share.blade.php`);
    const open = share.indexOf('{', start);
    let depth = 0;
    let end = open;
    for (; end < share.length; end++) {
        if (share[end] === '{') depth++;
        if (share[end] === '}' && --depth === 0) break;
    }
    const param = share.slice(share.indexOf('(', start) + 1, share.indexOf(')', start));
    return new Function(param, share.slice(open + 1, end)) as (value: unknown) => unknown;
};
const seconds = method('seconds');
const format = method('format');
for (const [text, expected] of [
    ['0:00', 0], ['1:05', 65], ['01:05', 65], ['65', 65], ['1:02:03', 3723], [' 2:30 ', 150], ['', null], ['1:75', null],
    ['1:2:60', null], ['abc', null], ['-5', null], ['1:05:07:09', null], ['1.5', null], ['1:', null], [':30', null],
] as Array<[string, number | null]>) {
    check(`Start at "${text}"`, seconds(text), expected);
}
for (const [total, expected] of [[0, '0:00'], [65, '1:05'], [600, '10:00'], [3723, '1:02:03'], [36000, '10:00:00']] as Array<[number, string]>) {
    check(`format(${total})`, format(total), expected);
}
check('format and seconds agree', [0, 9, 65, 3599, 3600, 86399].every((total) => seconds(format(total)) === total), true);

// revealPolicy (auth-5): the page is 900px tall; the reveal margin reaches 12% below it.
const box = (top: number, height: number, isIntersecting = true) => ({isIntersecting, top, bottom: top + height, viewportHeight: 900});
check('the header (top of the page) is on screen', isOnScreen(box(0, 64)), true);
check('a card cut by the bottom edge is on screen', isOnScreen(box(850, 200)), true);
check('a card above the screen is not on screen', isOnScreen(box(-300, 200)), false);
check('painted content never fades in again: header', shouldReveal(box(0, 64)), false);
check('painted content never fades in again: a card in the first screen', shouldReveal(box(300, 240)), false);
check('painted content never fades in again: a card cut by the bottom edge', shouldReveal(box(880, 240)), false);
check('a card in the margin below the fold fades in before it is seen', shouldReveal(box(905, 240)), true);
check('a card exactly at the fold is not on screen yet', shouldReveal(box(900, 240)), true);
check('a card outside the margin waits', shouldReveal(box(1400, 240, false)), false);
check('a card above the screen does not fade', shouldReveal(box(-500, 240, false)), false);
check('a picture that came from a cache fades', [0, 12, IMAGE_FADE_MAX_FETCH_MS].map((ms) => imageMayFade(ms)), [true, true, true]);
check('a picture that downloaded slowly keeps its painted rows', [IMAGE_FADE_MAX_FETCH_MS + 1, 330, 4000].map((ms) => imageMayFade(ms)), [false, false, false]);
check('an unknown download time never fades', [null, undefined, Number.NaN, -1].map((ms) => imageMayFade(ms as number)), [false, false, false, false]);

// R1-063: the cards' short counts in the reader's language, as the server cards print them (App\Support\LocalNumber::
// compact, tests/Unit/LocalNumberTest.php): the language's decimal sign and its own word ("18.8 k" inside Arabic text read
// "k 18.8"), the count of ten thousands in Japanese and Chinese, no space before a CJK unit.
const win = (globalThis as Record<string, unknown>).window as {LANG: string};
for (const [lang, value, expected] of [
    ['fr', 18800, '18,8 k'], ['fr', 999, '999'], ['fr', 2500000, '2,5 M'], ['ar', 18800, '18.8 ألف'], ['ar', 2500000, '2.5 مليون'],
    ['de', 18800, '18,8 Tsd.'], ['ru', 18800, '18,8 тыс.'], ['ja', 1050, '1,050'], ['ja', 18800, '1.9万'], ['zh', 125000, '12.5万'],
    ['ko', 1050, '1.1천'], ['hi', 150000, '1.5 लाख'], ['en', 18800, '18.8 k'], ['xx', 18800, '18.8 k'],
] as Array<[string, number, string]>) {
    win.LANG = lang;
    check(`formatCompactNumber(${value}) in ${lang}`, formatCompactNumber(value), expected);
}
win.LANG = 'en';

// R1-016: what a refused or failed request says (functions/errorText.ts): the page's words for the status where the
// server answered Laravel's raw English, the server's own sentence otherwise, the connection when no answer came.
const {errorText} = await import('../../resources/js/functions/errorText.ts');
const echo = (key: string): string => key;
const answer = (status: number, message: string): Error => Object.assign(new Error(message), {status});
check('401 "Unauthenticated." is the signed-out sentence', errorText(answer(401, 'Unauthenticated.'), echo), 'You are signed out. Sign in and try again.');
check('419 is the reload sentence', errorText(answer(419, 'CSRF token mismatch.'), echo), 'That did not work. Reload the page and try again.');
check('403 of a view-only demo account shows its own words', errorText(answer(403, 'This is a view-only demo account: you can look at everything, but changes are switched off.'), echo), 'This is a view-only demo account: you can look at everything, but changes are switched off.');
check('403 "This action is unauthorized." is the page sentence', errorText(answer(403, 'This action is unauthorized.'), echo), 'Something went wrong. Try again.');
check('429 with Laravel\'s raw words is the wait sentence', errorText(answer(429, 'Too Many Attempts.'), echo), 'Too many tries. Wait a minute and try again.');
check('429 with a translated wait keeps it', errorText(answer(429, 'Trop de tentatives. Patientez 37 secondes.'), echo), 'Trop de tentatives. Patientez 37 secondes.');
check('500 without JSON (SyntaxError) is the page sentence', errorText(Object.assign(new SyntaxError('Unexpected token <'), {status: 500}), echo), 'Something went wrong. Try again.');
check('no answer at all (TypeError) is the connection sentence', errorText(new TypeError('Failed to fetch'), echo), 'That did not work. Check your connection and try again.');
check('the old fallback text is never shown', errorText(answer(422, 'Oups something went wrong !'), echo), 'That did not work. Check your connection and try again.');

console.log(`front logic: ${passed} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
