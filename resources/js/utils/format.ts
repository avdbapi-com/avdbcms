type DateInput = Date | string | number | null | undefined;

const locale = (): string => window.LANG || document.documentElement.lang || 'en';

const dateFrom = (value: DateInput): Date | null => {
    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    if (value === null || value === undefined || value === '') {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
};

export const formatCompactNumber = (value: number | string | null | undefined): string => {
    const number = Number(value ?? 0);

    if (! Number.isFinite(number)) {
        return '0';
    }

    try {
        return new Intl.NumberFormat(locale(), {
            notation: 'compact',
            maximumFractionDigits: number >= 1000 ? 1 : 0,
        }).format(number);
    } catch {
        if (number >= 1_000_000) {
            return `${Math.round(number / 100_000) / 10}M`;
        }

        if (number >= 1000) {
            return `${Math.round(number / 100) / 10}K`;
        }

        return String(Math.round(number));
    }
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
    const amount = Math.round(seconds / unitSeconds);

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
