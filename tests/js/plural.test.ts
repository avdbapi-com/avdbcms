// The admin scripts' counted texts (resources/admin/lib/plural.ts) against Laravel's own choice.
// Run: node tests/js/plural.test.ts [cases.json]   (Node 22.18+ runs TypeScript directly). Exit code 1 on any failure.
// cases.json = [{"locale": "ru", "line": "...", "n": 5, "expected": "..."}, ...], written by tests/Unit/AdminPluralTest.php
// from PHP's MessageSelector for every plural line of every lang/<locale>.json and every count from 0 to 120. Without
// it, the built-in cases below run.
import {readFileSync} from 'node:fs';
import {choose, plural, pluralIndex} from '../../resources/admin/lib/plural.ts';

type Case = {locale: string; line: string; n: number; expected: string};

let failures = 0;
let passed = 0;
const check = (name: string, actual: unknown, expected: unknown): void => {
    if (actual === expected) {
        passed++;
    } else {
        failures++;
        if (failures <= 20) console.error(`FAIL ${name}: got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);
    }
};

const file = process.argv[2];

if (file) {
    for (const c of JSON.parse(readFileSync(file, 'utf8')) as Case[]) {
        check(`${c.locale} n=${c.n} ${c.line.slice(0, 60)}`, choose(c.line, c.n, c.locale), c.expected);
    }
} else {
    const english = '1 unsaved change|:count unsaved changes';
    check('en 1', plural(english, 1, 'en'), '1 unsaved change');
    check('en 0', plural(english, 0, 'en'), '0 unsaved changes');
    check('en 5', plural(english, 5, 'en'), '5 unsaved changes');

    const russian = ':count изменение|:count изменения|:count изменений';
    check('ru 1', plural(russian, 1, 'ru'), '1 изменение');
    check('ru 3', plural(russian, 3, 'ru'), '3 изменения');
    check('ru 5', plural(russian, 5, 'ru'), '5 изменений');
    check('ru 21', plural(russian, 21, 'ru'), '21 изменение');
    check('ru 112', plural(russian, 112, 'ru'), '112 изменений');

    check('ar indexes 0 1 2 3 11 100', [0, 1, 2, 3, 11, 100].map((n) => pluralIndex('ar', n)).join(','), '0,1,2,3,4,5');
    check('fr 0 is singular', pluralIndex('fr', 0), 0);
    check('pt-BR follows pt', pluralIndex('pt-BR', 0), 1);
    check('tl has no rule', pluralIndex('tl', 7), 0);

    const explicit = '{1} 1 Video|[0,*] :count Videos';
    check('explicit 1', plural(explicit, 1, 'de'), '1 Video');
    check('explicit 0', plural(explicit, 0, 'de'), '0 Videos');
    check('explicit 7', plural(explicit, 7, 'tl'), '7 Videos');
    check('a range', choose('{0} none|[1,4] few|[5,*] many', 4, 'en'), 'few');
    check('one form', plural(':count video', 9, 'vi'), '9 video');
    check('a stripped form keeps its space (as PHP)', choose('{5} five| other', 3, 'en'), ' other');
}

console.log(`plural: ${passed} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
