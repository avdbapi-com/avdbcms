// Retry pass of scripts/translate_engine.mjs: sends again the keys listed in <work>/<locale>.failed.json
// (service errors, rate limits, lost placeholders) - slower, smaller batches - and adds the results to
// <work>/<locale>.mt.json. Then merge with: php scripts/i18n/sync.php --from=<work>
//
//   node scripts/translate_remaining.mjs [--root DIR] [--work DIR] [--locales ar,de]
import fs from 'fs';
import path from 'path';
import { options, run } from './translate_engine.mjs';

const opts = options();

function failedKeys(locale) {
    const file = path.join(opts.work, `${locale}.failed.json`);
    if (!fs.existsSync(file)) return null;
    const failed = JSON.parse(fs.readFileSync(file, 'utf8'));
    return Object.entries(failed)
        .filter(([, reason]) => !String(reason).startsWith('plural form'))
        .map(([key]) => key);
}

run(opts, failedKeys, { maxItems: 15, delay: 1500 }).catch((err) => {
    console.error(err.message);
    process.exit(1);
});
