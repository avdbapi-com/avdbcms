type DateInput = Date | string | number | null | undefined;

const locale = (): string => window.LANG || document.documentElement.lang || 'en';

export const dateFrom = (value: DateInput): Date | null => {
    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    if (value === null || value === undefined || value === '') {
        return null;
    }

    // A calendar day ("2026-10-25", the history's day groups) is that day where the visitor is: new Date() reads it as
    // midnight UTC, which is the evening before west of UTC (channel-30).
    if (typeof value === 'string') {
        const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim());

        if (day) {
            const local = new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3]));

            return Number.isNaN(local.getTime()) ? null : local;
        }
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
};

// The server cards' format (App\Helpers\Number::humanize, App\Support\LocalNumber::compact), so a channel's Home tab
// (server cards) and Videos tab (these cards) print the same count (channel-19: "2.9 k views" against "2.9K views"): one
// decimal under 100 of the unit, rounded half up, then the reader's separators and word (R1-063: "18,8 k vues",
// "18.8 ألف"; "18.8 k" inside Arabic text read "k 18.8"). Keep COMPACT_UNITS the same as LocalNumber::UNITS.
const COMPACT_UNITS: Readonly<Record<string, ReadonlyArray<[number, string]>>> = {
    en: [[12, 'T'], [9, 'B'], [6, 'M'], [3, 'k']],
    ar: [[12, 'ترليون'], [9, 'مليار'], [6, 'مليون'], [3, 'ألف']],
    de: [[12, 'Bio.'], [9, 'Mrd.'], [6, 'Mio.'], [3, 'Tsd.']],
    es: [[12, 'B'], [9, 'mil M'], [6, 'M'], [3, 'mil']],
    fil: [[12, 'T'], [9, 'B'], [6, 'M'], [3, 'K']],
    fr: [[12, 'Bn'], [9, 'Md'], [6, 'M'], [3, 'k']],
    hi: [[9, 'अ॰'], [7, 'क॰'], [5, 'लाख'], [3, 'हज़ार']],
    id: [[12, 'T'], [9, 'M'], [6, 'jt'], [3, 'rb']],
    ja: [[12, '兆'], [8, '億'], [4, '万']],
    ko: [[12, '조'], [8, '억'], [4, '만'], [3, '천']],
    ms: [[12, 'T'], [9, 'B'], [6, 'J'], [3, 'K']],
    pt: [[12, 'tri'], [9, 'bi'], [6, 'mi'], [3, 'mil']],
    ru: [[12, 'трлн'], [9, 'млрд'], [6, 'млн'], [3, 'тыс.']],
    th: [[12, 'T'], [9, 'B'], [6, 'M'], [3, 'K']],
    tl: [[12, 'T'], [9, 'B'], [6, 'M'], [3, 'K']],
    tr: [[12, 'Tn'], [9, 'Mr'], [6, 'Mn'], [3, 'B']],
    vi: [[12, 'NT'], [9, 'T'], [6, 'Tr'], [3, 'N']],
    zh: [[12, '万亿'], [8, '亿'], [4, '万']],
};

// languages that write the unit right after the number ("1.9万")
const COMPACT_NO_SPACE = ['ja', 'ko', 'zh'];

const language = (): string => locale().toLowerCase().split(/[-_@]/)[0] || 'en';

// the number rounded half up to `decimals`, then written with the language's separators (Western digits, as the server
// cards: LocalNumber); English separators where the browser has no number formats
const numberFormat = (value: number, decimals: number, lang: string = 'en'): string => {
    const rounded = Math.round((value + Number.EPSILON) * 10 ** decimals) / 10 ** decimals;

    try {
        return new Intl.NumberFormat(lang, {minimumFractionDigits: decimals, maximumFractionDigits: decimals, numberingSystem: 'latn'} as Intl.NumberFormatOptions).format(rounded);
    } catch {
        const [whole, fraction] = rounded.toFixed(decimals).split('.');
        const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        return fraction ? grouped + '.' + fraction : grouped;
    }
};

export const formatCompactNumber = (value: number | string | null | undefined): string => {
    const number = Math.trunc(Number(value ?? 0));

    if (! Number.isFinite(number) || number <= 0) {
        return '0';
    }

    const lang = language();

    for (const [exponent, unit] of COMPACT_UNITS[lang] ?? COMPACT_UNITS.en) {
        if (number >= 10 ** exponent) {
            const display = number / 10 ** exponent;

            return numberFormat(display, Math.round(display) < 100 ? 1 : 0, lang) + (COMPACT_NO_SPACE.includes(lang) ? '' : ' ') + unit;
        }
    }

    return numberFormat(number, 0, lang);
};

export const formatRelativeTime = (value: DateInput): string => {
    const date = dateFrom(value);

    if (! date) {
        return '';
    }

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);
    const ranges: Array<[Intl.RelativeTimeFormatUnit, number]> = [
        ['year', 31536000],
        ['month', 2592000],
        ['week', 604800],
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
        ['second', 1],
    ];
    const [unit, unitSeconds] = ranges.find(([, threshold]) => abs >= threshold) ?? ['second', 1];
    // Whole units, as Carbon's diffForHumans on the server cards (channel-19: 49 days is "1 month ago", not 2).
    const amount = Math.trunc(seconds / unitSeconds);

    try {
        return new Intl.RelativeTimeFormat(locale(), {numeric: 'auto'}).format(amount, unit);
    } catch {
        if (abs < 60) {
            return 'just now';
        }

        const suffix = seconds < 0 ? 'ago' : 'from now';
        const value = Math.abs(amount);

        return `${value} ${unit}${value === 1 ? '' : 's'} ${suffix}`;
    }
};

export const sameCalendarDay = (left: DateInput, right: DateInput = new Date()): boolean => {
    const leftDate = dateFrom(left);
    const rightDate = dateFrom(right);

    return Boolean(leftDate && rightDate
        && leftDate.getFullYear() === rightDate.getFullYear()
        && leftDate.getMonth() === rightDate.getMonth()
        && leftDate.getDate() === rightDate.getDate());
};

export const formatCalendarDate = (
    value: DateInput,
    labels: {today: string; yesterday: string},
): string => {
    const date = dateFrom(value);

    if (! date) {
        return '';
    }

    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);

    if (sameCalendarDay(date)) {
        return labels.today;
    }

    if (sameCalendarDay(date, yesterday)) {
        return labels.yesterday;
    }

    const now = new Date();
    const diffDays = Math.round((now.setHours(0, 0, 0, 0) - new Date(date).setHours(0, 0, 0, 0)) / 86400000);

    try {
        if (diffDays > 1 && diffDays < 7) {
            return new Intl.DateTimeFormat(locale(), {weekday: 'long'}).format(date);
        }

        return new Intl.DateTimeFormat(locale(), {
            day: 'numeric',
            month: 'long',
            ...(date.getFullYear() === new Date().getFullYear() ? {} : {year: 'numeric'}),
        }).format(date);
    } catch {
        return date.toLocaleDateString();
    }
};
