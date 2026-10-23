<?php

/*
| Brings every translation file up to the required key set and merges new translations:
|   - lang/<locale>.json        Laravel __() keys: inventory.json + the source paths of sources.json
|   - resources/js/lang/<locale>.ts   Preact t() keys found in resources/js
|
|   php scripts/i18n/sync.php [--root=DIR] [--from=PATH [--locale=xx] [--overwrite] [--accept-english]] [--todo=DIR]
|                             [--no-sources] [--no-js] [--dry-run]
|
| --from      a <locale>.json file ({"English": "translation"}) or a folder of them; a file name may carry a
|             suffix: vi.json, vi.batch-03.json, ar.mt.json. Entries are applied to required keys whose current
|             value is missing, English or the old label's fallback - never over a real translation unless
|             --overwrite (reviewed input only). Placeholders (:name, {word}, HTML) must match the English, and a
|             trans_choice() line must print the number wherever the English does, under the locale's plural rule
|             (Plurals::wrongNumbers: "1 video|:count video" is rejected for vi, which always takes the first form), and
|             its form for 1 must keep the digit or a number word (Plurals::lostOne). A value that changes a protected
|             brand of brands.json is rejected; zero-width characters the English does not have are stripped.
|             One entry serves both files when a key is used by Blade and by Preact.
| --accept-english  with --from: where the translator returned a single English word unchanged (Filipino keeps
|             "Username", German "Layout"), record the key in accepted.json so it leaves the todo list. A phrase
|             returned in English stays on the todo list (translate it by hand, or add a name to keep_english.json).
|             A merged translation that equals the old label's translation is recorded there too (confirmed).
| --todo      writes <DIR>/<locale>.todo.json: the keys each locale still needs translated (input of
|             scripts/translate_engine.mjs; vi.todo.json is the human review list).
| Keys that are still missing get the fallback: exact key -> old label's translation -> English (a Preact key
| first borrows the same key's translation from lang/<locale>.json; an English "one|other" plural line is written in
| its explicit shape "{1} one|[0,*] other", which every locale's trans_choice() prints right).
| Existing keys and values are never removed or changed (apart from the merge above).
*/

require __DIR__ . '/I18n.php';

use Avd\I18n\Brands;
use Avd\I18n\Cli;
use Avd\I18n\Inventory;
use Avd\I18n\JsLangFile;
use Avd\I18n\LangFile;
use Avd\I18n\Paths;
use Avd\I18n\Placeholders;
use Avd\I18n\Plurals;
use Avd\I18n\Requirements;
use Avd\I18n\Status;
use Avd\I18n\ZeroWidth;

$opt = Cli::options($argv);

try {
    $root = Paths::root(is_string($opt['root'] ?? null) ? $opt['root'] : null);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}

$dry = !empty($opt['dry-run']);
$sets = [
    'php' => ['label' => 'lang/*.json', 'file' => LangFile::class, 'locales' => Paths::locales($root),
        'path' => static fn (string $code) => Paths::lang($root, $code), 'required' => Requirements::collect($root, empty($opt['no-sources']))],
];
if (empty($opt['no-js']) && is_file(JsLangFile::path($root, 'en'))) {
    $sets['js'] = ['label' => 'resources/js/lang/*.ts', 'file' => JsLangFile::class, 'locales' => JsLangFile::locales($root),
        'path' => static fn (string $code) => JsLangFile::path($root, $code), 'required' => Requirements::collectJs($root)];
}
$files = [];
foreach ($sets as $set => $s) {
    foreach ($s['locales'] as $code) {
        [$data, $eol] = $s['file']::read(($s['path'])($code));
        $files[$set][$code] = ['data' => $data, 'eol' => $eol, 'before' => $data];
    }
}

// 1. read --from
$inputs = [];
if (is_string($opt['from'] ?? null)) {
    $from = rtrim(str_replace('\\', '/', $opt['from']), '/');
    $list = is_dir($from) ? (glob($from . '/*.json') ?: []) : [$from];
    sort($list, SORT_STRING);
    foreach ($list as $file) {
        $name = basename($file);
        if (str_ends_with($name, '.todo.json') || str_ends_with($name, '.failed.json')) {
            continue;
        }
        $code = is_string($opt['locale'] ?? null) && !is_dir($from) ? $opt['locale'] : explode('.', $name)[0];
        if (!isset($files['php'][$code])) {
            fwrite(STDERR, "skip {$name}: unknown locale '{$code}'\n");
            continue;
        }
        $doc = json_decode((string) file_get_contents($file), true);
        if (!is_array($doc)) {
            fwrite(STDERR, "skip {$name}: invalid JSON\n");
            continue;
        }
        foreach ($doc as $key => $value) {
            $inputs[$code][(string) $key] = $value;
        }
    }
}

$merged = $rejected = $skipped = $accepted = $added = $written = [];
foreach ($sets as $set => $s) {
    $required = $s['required'];

    // 2. merge the inputs into the keys this set needs
    foreach ($inputs as $code => $entries) {
        if (!isset($files[$set][$code])) {
            continue;
        }
        $keep = Inventory::accepted($root, $code);
        foreach ($entries as $key => $value) {
            if (!isset($required[$key])) {
                continue;
            }
            if (!is_string($value) || trim($value) === '') {
                $rejected[$set][$code][$key] = 'empty';
                continue;
            }
            $source = $required[$key]['source'];
            if (!Placeholders::same($source, $value)) {
                $diff = Placeholders::diff($source, $value);
                $rejected[$set][$code][$key] = 'placeholders missing ' . json_encode($diff['missing'], JSON_UNESCAPED_UNICODE)
                    . ' extra ' . json_encode($diff['extra'], JSON_UNESCAPED_UNICODE);
                continue;
            }
            if ($set === 'php' && ($wrong = Plurals::wrongNumbers($source, $value, $code, $root)) !== [] && $wrong !== null) {
                $rejected[$set][$code][$key] = 'trans_choice() would print a form without the number for ' . implode(', ', array_slice($wrong, 0, 6))
                    . (count($wrong) > 6 ? ' ...' : '');
                continue;
            }
            if ($set === 'php' && Plurals::lostOne($source, $value, $code, $root)) {
                $rejected[$set][$code][$key] = 'the form for 1 says neither the number nor a number word (scripts/i18n/number_words.json)';
                continue;
            }
            if (($lost = Brands::lost($root, $key, $source, $value, $code)) !== []) {
                $rejected[$set][$code][$key] = 'brand changed: ' . implode(', ', $lost) . ' (scripts/i18n/brands.json)';
                continue;
            }
            $value = ZeroWidth::strip($source, $value, $code);
            $data = &$files[$set][$code]['data'];
            $status = Status::of($key, $required[$key]['old_label'], $data, $code, $keep, $source);
            if (($data[$key] ?? null) !== $value && (Status::needsTranslation($status) || !empty($opt['overwrite']))) {
                $data[$key] = $value;
                $merged[$set][$code] = ($merged[$set][$code] ?? 0) + 1;
            }
            if ($code !== 'en' && ($data[$key] ?? null) === $value && (
                // the translator returned the English text, or exactly the old label's translation: that is the
                // translation here, not a fallback still waiting for one
                ($value === $source && $status === Status::ENGLISH && !empty($opt['accept-english']) && Inventory::acceptableAsEnglish($source))
                || ($value !== $source && !isset($keep[$key])
                    && $value === Status::oldLabelTranslation($required[$key]['old_label'], $data))
            )) {
                $accepted[$code][$key] = $key;
            }
            unset($data);
        }
    }

    // 3. every required key in every locale (fallback rule)
    foreach ($s['locales'] as $code) {
        $data = &$files[$set][$code]['data'];
        $new = [];
        foreach ($required as $key => $meta) {
            if (($data[$key] ?? '') !== '') {
                continue;
            }
            $value = Status::fallback($key, $meta['old_label'], $data, $code, $meta['source']);
            $sibling = $set === 'js' ? ($files['php'][$code]['data'][$key] ?? '') : '';
            if ($value === $meta['source'] && $sibling !== '' && $sibling !== $key && $code !== 'en'
                && !Status::corrupt($sibling, $key) && Placeholders::same($meta['source'], $sibling)) {
                $value = $sibling; // the same text is already translated for Blade
            }
            $how = $code === 'en' || $value === $meta['source'] || $value === Plurals::explicit($meta['source']) ? 'english' : 'translation';
            $new[$key] = $value;
            $added[$set][$code][$how] = ($added[$set][$code][$how] ?? 0) + 1;
        }
        ksort($new, SORT_STRING);
        $data += $new; // the .ts files keep their order: new keys go to the end
        unset($data);
    }

    // 4. write
    foreach ($s['locales'] as $code) {
        $f = $files[$set][$code];
        if ($f['data'] === $f['before']) {
            continue;
        }
        foreach ($f['before'] as $key => $value) {
            if (!array_key_exists($key, $f['data'])) {
                fwrite(STDERR, "refusing to write {$code} ({$s['label']}): key would be lost: {$key}\n");
                exit(1);
            }
        }
        if (!$dry && $s['file']::write(($s['path'])($code), $f['data'], $f['eol'])) {
            $written[] = $set === 'php' ? $code : 'js/' . $code;
        }
    }
}
foreach ($inputs as $code => $entries) {
    foreach ($entries as $key => $value) {
        if (!isset($sets['php']['required'][$key]) && !isset($sets['js']['required'][$key])) {
            $skipped[$code][] = $key;
        }
    }
}
if ($accepted !== [] && !$dry) {
    Inventory::accept($root, array_map('array_values', $accepted));
    $written[] = 'accepted.json (' . implode(', ', array_map(static fn ($c, $k) => $c . ' +' . count($k), array_keys($accepted), $accepted)) . ')';
}

// 5. todo lists + report
$todoDir = is_string($opt['todo'] ?? null) ? rtrim(str_replace('\\', '/', $opt['todo']), '/') : null;
if ($todoDir !== null && !$dry && !is_dir($todoDir)) {
    mkdir($todoDir, 0777, true);
}
$todo = [];
foreach ($sets as $set => $s) {
    printf("%s: %s | required keys: %d | locales: %d%s\n", $s['label'], $root, count($s['required']), count($s['locales']), $dry ? ' | DRY RUN' : '');
    printf("  %-4s %7s %8s %6s %10s %8s %7s %8s %8s\n", 'loc', 'merged', 'rejected', 'added', 'translated', 'missing', 'english', 'old-lbl', 'corrupt');
    foreach ($s['locales'] as $code) {
        $data = $files[$set][$code]['data'];
        $keep = Inventory::accepted($root, $code);
        $counts = [Status::MISSING => 0, Status::ENGLISH => 0, Status::OLD_LABEL => 0, Status::TRANSLATED => 0, Status::KEPT => 0, Status::CORRUPT => 0];
        foreach ($s['required'] as $key => $meta) {
            $status = Status::of($key, $meta['old_label'], $data, $code, $keep, $meta['source']);
            $counts[$status]++;
            // plural forms differ per language: they stay on the human list
            if ($code !== 'en' && Status::needsTranslation($status) && ($set === 'php' || !Placeholders::plural($meta['source']))) {
                $todo[$code][$key] = true;
            }
        }
        printf("  %-4s %7d %8d %6d %10d %8d %7d %8d %8d\n", $code, $merged[$set][$code] ?? 0, count($rejected[$set][$code] ?? []),
            array_sum($added[$set][$code] ?? []), $added[$set][$code]['translation'] ?? 0, $counts[Status::MISSING],
            $counts[Status::ENGLISH], $counts[Status::OLD_LABEL], $counts[Status::CORRUPT]);
    }
    foreach ($rejected[$set] ?? [] as $code => $rows) {
        foreach ($rows as $key => $why) {
            printf("  rejected %s: %s -> %s\n", $code, strlen($key) > 80 ? substr($key, 0, 77) . '...' : $key, $why);
        }
    }
}
if ($todoDir !== null && !$dry) {
    foreach ($sets['php']['locales'] as $code) {
        if ($code !== 'en') {
            file_put_contents($todoDir . '/' . $code . '.todo.json',
                json_encode(array_keys($todo[$code] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }
    }
}
foreach ($skipped as $code => $keys) {
    printf("skipped %d %s entries that are not required keys (e.g. %s)\n", count($keys), $code, json_encode(array_slice($keys, 0, 3), JSON_UNESCAPED_UNICODE));
}
echo $dry ? "dry run: nothing written\n" : ('written: ' . ($written === [] ? 'none' : implode(', ', $written)) . "\n");
if ($todoDir !== null && !$dry) {
    echo "todo lists: {$todoDir}/<locale>.todo.json\n";
}
exit($rejected === [] ? 0 : 3);
