// Counted texts in the admin's scripts, chosen the way Laravel's trans_choice() chooses them on the server
// (Illuminate\Translation\MessageSelector). The page prints the translated line of a "one|other" key ("1 unsaved
// change|:count unsaved changes"); its translation may hold three forms (Russian), six (Arabic), explicit forms
// ("{1} ...|[0,*] ..." in most languages) or one (Vietnamese). A form with an explicit {n} or [from,to] that fits the
// number wins; else the form of the language's plural rule. No page access here except the default language, so
// tests/js/plural.test.ts runs it in Node against PHP's own choice for every line of every language file.

type Rule = (n: number) => number;

const one: Rule = (n) => (n === 1 ? 0 : 1);

/** Laravel's plural rules by language (a region such as pt_BR or fr-CA follows its language there too). */
const RULES: Array<[string[], Rule]> = [
    [['az', 'bo', 'dz', 'id', 'ja', 'jv', 'ka', 'km', 'kn', 'ko', 'ms', 'th', 'tr', 'vi', 'zh'], () => 0],
    [['af', 'bn', 'bg', 'ca', 'da', 'de', 'el', 'en', 'eo', 'es', 'et', 'eu', 'fa', 'fi', 'fo', 'fur', 'fy', 'gl', 'gu', 'ha', 'he',
        'hu', 'is', 'it', 'ku', 'lb', 'ml', 'mn', 'mr', 'nah', 'nb', 'ne', 'nl', 'nn', 'no', 'om', 'or', 'pa', 'pap', 'ps', 'pt',
        'so', 'sq', 'sv', 'sw', 'ta', 'te', 'tk', 'ur', 'zu'], one],
    [['am', 'bh', 'fil', 'fr', 'gun', 'hi', 'hy', 'ln', 'mg', 'nso', 'ti', 'wa', 'xbr'], (n) => (n === 0 || n === 1 ? 0 : 1)],
    [['be', 'bs', 'hr', 'ru', 'sr', 'uk'], (n) => (n % 10 === 1 && n % 100 !== 11 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2)],
    [['cs', 'sk'], (n) => (n === 1 ? 0 : n >= 2 && n <= 4 ? 1 : 2)],
    [['ga'], (n) => (n === 1 ? 0 : n === 2 ? 1 : 2)],
    [['lt'], (n) => (n % 10 === 1 && n % 100 !== 11 ? 0 : n % 10 >= 2 && (n % 100 < 10 || n % 100 >= 20) ? 1 : 2)],
    [['sl'], (n) => (n % 100 === 1 ? 0 : n % 100 === 2 ? 1 : n % 100 === 3 || n % 100 === 4 ? 2 : 3)],
    [['mk'], (n) => (n % 10 === 1 ? 0 : 1)],
    [['mt'], (n) => (n === 1 ? 0 : n === 0 || (n % 100 > 1 && n % 100 < 11) ? 1 : n % 100 > 10 && n % 100 < 20 ? 2 : 3)],
    [['lv'], (n) => (n === 0 ? 0 : n % 10 === 1 && n % 100 !== 11 ? 1 : 2)],
    [['pl'], (n) => (n === 1 ? 0 : n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14) ? 1 : 2)],
    [['cy'], (n) => (n === 1 ? 0 : n === 2 ? 1 : n === 8 || n === 11 ? 2 : 3)],
    [['ro'], (n) => (n === 1 ? 0 : n === 0 || (n % 100 > 0 && n % 100 < 20) ? 1 : 2)],
    [['ar'], (n) => (n === 0 ? 0 : n === 1 ? 1 : n === 2 ? 2 : n % 100 >= 3 && n % 100 <= 10 ? 3 : n % 100 >= 11 && n % 100 <= 99 ? 4 : 5)],
];

/** The form index of a language's plural rule for n (0 for a language Laravel lists no rule for, such as tl). */
export const pluralIndex = (locale: string, n: number): number => {
    const language = locale.toLowerCase().split(/[-_]/)[0];
    const rule = RULES.find(([languages]) => languages.includes(language));

    return rule ? rule[1](n) : 0;
};

const CONDITION = /^[{[]([\d,.*]*)[}\]]([\s\S]*)$/;

/** The form of `line` for n in `locale`, as MessageSelector::choose() returns it (before :count is filled in). */
export const choose = (line: string, n: number, locale: string): string => {
    const segments = line.split('|');

    for (const part of segments) {
        const match = CONDITION.exec(part);

        if (!match) continue;

        const [, condition, value] = match;
        const comma = condition.indexOf(',');

        if (comma >= 0) {
            const from = condition.slice(0, comma);
            const to = condition.slice(comma + 1);

            if ((to === '*' && n >= Number(from)) || (from === '*' && n <= Number(to)) || (n >= Number(from) && n <= Number(to))) {
                return value.trim();
            }
        } else if (condition !== '' && Number(condition) === n) {
            return value.trim();
        }
    }

    const forms = segments.map((part) => part.replace(/^[{[][\d,.*]*[}\]]/, ''));
    const form = forms[pluralIndex(locale, n)];

    return forms.length === 1 || form === undefined ? forms[0] : form;
};

/** The counted text: the right form of the translated line for n (in the page's language), with :count filled in. */
export const plural = (line: string, n: number, locale?: string): string =>
    choose(line, n, locale || (typeof document !== 'undefined' && document.documentElement.lang) || 'en').split(':count').join(String(n));
