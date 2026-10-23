<?php

/*
|--------------------------------------------------------------------------
| Translation check (release gate)
|--------------------------------------------------------------------------
|
| Required keys
|   lang/<locale>.json: scripts/i18n/inventory.json (the new admin + theme strings) + every literal __() key in
|     the source paths of scripts/i18n/sources.json (resources/views, app, routes, config/admin_settings.php ...);
|   resources/js/lang/<locale>.ts: every literal t() key of the Preact code in resources/js.
|
| Fails (exit 1) when
|   - a required key is missing from the Vietnamese files, is still the English text there (proper nouns of
|     scripts/i18n/keep_english.json and values confirmed in scripts/i18n/accepted.json excepted) or is corrupted
|     text ("Th?m" for "Thêm");
|   - any locale's translation of a required key drops or invents a placeholder (:name, {word}, HTML tag);
|   - a trans_choice() line would print a form without the number for some count in that locale ("1 video" for 5
|     videos: Laravel picks an implicit form by the locale's plural rule, see Plurals in scripts/i18n/I18n.php), or its
|     form for 1 says neither the digit nor a number word although the English one shows the digit ("1 found nothing."
|     -> "I found nothing.", Plurals::lostOne);
|   - a translation changed a protected brand of scripts/i18n/brands.json ("Stripe" -> "stripe/band": Brands::lost);
|   - a translation still holds a whole English sentence of its source (4+ words; placeholders, brands, names and
|     example lists excepted: PartialEnglish);
|   - a translation holds zero-width or invisible characters its English text does not have (ZeroWidth);
|   - a translation file cannot be read as a flat object of strings;
|   - with --min-coverage=N: another locale translates fewer than N% of the required keys. Translated means a
|     translation of its own (or the old label's), or English by policy (keep_english.json); English a locale
|     merely accepted (accepted.json: single words a machine translator returned unchanged) does not count.
| Prints a per-locale coverage report: "shown" = translated + accepted English, "transl" = translated only.
| Exit 2 = usage error.
|
|   php scripts/i18n_check.php [--root=DIR] [--strict=vi[,xx]] [--min-coverage=95] [--json] [--list] [--no-sources] [--no-js]
|
*/

require __DIR__ . '/i18n/I18n.php';

use Avd\I18n\Brands;
use Avd\I18n\Cli;
use Avd\I18n\Extractor;
use Avd\I18n\Inventory;
use Avd\I18n\JsLangFile;
use Avd\I18n\LangFile;
use Avd\I18n\PartialEnglish;
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

$strict = array_filter(array_map('trim', explode(',', is_string($opt['strict'] ?? null) ? $opt['strict'] : 'vi')));
$minCoverage = is_string($opt['min-coverage'] ?? null) ? (float) $opt['min-coverage'] : 0.0;
$sets = [
    'locales' => ['label' => 'lang/*.json', 'file' => LangFile::class, 'codes' => Paths::locales($root),
        'path' => static fn (string $code) => Paths::lang($root, $code), 'required' => Requirements::collect($root, empty($opt['no-sources']))],
];
if (empty($opt['no-js']) && is_file(JsLangFile::path($root, 'en'))) {
    $sets['js'] = ['label' => 'resources/js/lang/*.ts', 'file' => JsLangFile::class, 'codes' => JsLangFile::locales($root),
        'path' => static fn (string $code) => JsLangFile::path($root, $code), 'required' => Requirements::collectJs($root)];
}
$errors = [];
$report = [];
$policy = Inventory::keepEnglish($root);

foreach ($sets as $set => $s) {
    $prefix = $set === 'js' ? 'js ' : '';
    foreach ($s['codes'] as $code) {
        try {
            [$data] = $s['file']::read(($s['path'])($code));
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
            continue;
        }
        $keep = Inventory::accepted($root, $code);
        $counts = [Status::MISSING => 0, Status::ENGLISH => 0, Status::OLD_LABEL => 0, Status::TRANSLATED => 0, Status::KEPT => 0, Status::CORRUPT => 0];
        $acceptedEnglish = 0;
        $untranslated = [];
        $broken = [];
        $plurals = [];
        $quality = ['brand' => [], 'english_sentence' => [], 'zero_width' => [], 'one_form' => []];
        foreach ($s['required'] as $key => $meta) {
            $status = Status::of($key, $meta['old_label'], $data, $code, $keep, $meta['source']);
            $counts[$status]++;
            if ($status === Status::KEPT && !isset($policy[$key]) && preg_match('/\p{L}/u', $key)) {
                $acceptedEnglish++;
            }
            if (in_array($status, [Status::MISSING, Status::ENGLISH, Status::CORRUPT], true)) {
                $untranslated[$key] = $status;
            }
            if ($status !== Status::MISSING && !Placeholders::same($meta['source'], $data[$key])) {
                $broken[$key] = Placeholders::diff($meta['source'], $data[$key]);
            } elseif ($set === 'locales' && $status !== Status::MISSING && ($wrong = Plurals::wrongNumbers($meta['source'], $data[$key], $code, $root))) {
                $plurals[$key] = $wrong;
            }
            // translation quality (a value of its own only: English kept by policy or accepted is not checked here)
            if ($code !== 'en' && $status === Status::TRANSLATED) {
                $value = $data[$key];
                if (($lost = Brands::lost($root, $key, $meta['source'], $value, $code)) !== []) {
                    $quality['brand'][$key] = 'changed ' . implode(', ', $lost);
                }
                if (!isset($policy[$key]) && ($sentence = PartialEnglish::sentence($meta['source'], $value, Brands::in($root, $key, $meta['source']))) !== null) {
                    $quality['english_sentence'][$key] = 'keeps "' . mb_strimwidth($sentence, 0, 60, '...') . '"';
                }
                if (($chars = ZeroWidth::found($meta['source'], $value, $code)) !== []) {
                    $quality['zero_width'][$key] = implode(' ', $chars);
                }
                if ($set === 'locales' && !isset($plurals[$key]) && Plurals::lostOne($meta['source'], $value, $code, $root)) {
                    $quality['one_form'][$key] = 'the form for 1 has no number';
                }
            }
        }
        $total = max(1, count($s['required']));
        // shown = right as it is (an old label's translation and accepted English count; English, missing or corrupt
        // do not); translated = the same without the English a locale merely accepted
        $shown = $counts[Status::TRANSLATED] + $counts[Status::KEPT] + $counts[Status::OLD_LABEL];
        $report[$set][$code] = [
            'required' => count($s['required']),
            'translated' => $counts[Status::TRANSLATED],
            'kept_english' => $counts[Status::KEPT] - $acceptedEnglish,
            'accepted_english' => $acceptedEnglish,
            'old_label_fallback' => $counts[Status::OLD_LABEL],
            'english' => $counts[Status::ENGLISH],
            'corrupt' => $counts[Status::CORRUPT],
            'missing' => $counts[Status::MISSING],
            'coverage' => $code === 'en' ? 100.0 : round($shown * 100 / $total, 2),
            'translated_coverage' => $code === 'en' ? 100.0 : round(($shown - $acceptedEnglish) * 100 / $total, 2),
            'placeholder_errors' => count($broken),
            'plural_errors' => count($plurals) + count($quality['one_form']),
            'brand_errors' => count($quality['brand']),
            'english_sentence_errors' => count($quality['english_sentence']),
            'zero_width_errors' => count($quality['zero_width']),
        ];
        if ($code === 'en') {
            if ($counts[Status::MISSING] > 0) {
                $errors[] = "{$prefix}en: {$counts[Status::MISSING]} required keys missing from {$s['label']}";
            }
            continue;
        }
        foreach ($broken as $key => $diff) {
            $errors[] = sprintf('%s%s: placeholders differ in "%s" (missing %s, extra %s)', $prefix, $code, mb_strimwidth($key, 0, 90, '...'),
                json_encode($diff['missing'], JSON_UNESCAPED_UNICODE), json_encode($diff['extra'], JSON_UNESCAPED_UNICODE));
        }
        foreach ($plurals as $key => $numbers) {
            $errors[] = sprintf('%s%s: plural forms of "%s" print no number for %s', $prefix, $code, mb_strimwidth($key, 0, 90, '...'),
                implode(', ', array_slice($numbers, 0, 6)) . (count($numbers) > 6 ? ' ...' : ''));
        }
        $labels = ['one_form' => 'plural form for 1 of', 'brand' => 'brand', 'english_sentence' => 'English sentence in', 'zero_width' => 'invisible characters in'];
        foreach ($quality as $kind => $rows) {
            foreach ($rows as $key => $why) {
                $errors[] = sprintf('%s%s: %s "%s" (%s)', $prefix, $code, $labels[$kind], mb_strimwidth($key, 0, 90, '...'), $why);
            }
        }
        if (in_array($code, $strict, true)) {
            foreach ($untranslated as $key => $status) {
                $why = [Status::MISSING => 'missing', Status::ENGLISH => 'untranslated (English)', Status::CORRUPT => 'corrupted text'][$status];
                $errors[] = sprintf('%s%s: %s "%s" [%s]', $prefix, $code, $why, mb_strimwidth($key, 0, 110, '...'), $s['required'][$key]['origin']);
            }
        } elseif ($minCoverage > 0 && $report[$set][$code]['translated_coverage'] < $minCoverage) {
            $errors[] = sprintf('%s%s: translated %.2f%% is below %.2f%% (%d strings still English, %d accepted as English)', $prefix, $code,
                $report[$set][$code]['translated_coverage'], $minCoverage, $counts[Status::ENGLISH], $acceptedEnglish);
        }
        if (!empty($opt['list']) && $untranslated !== []) {
            $report[$set][$code]['untranslated'] = array_keys($untranslated);
        }
        if (!empty($opt['json']) && array_filter($quality) !== []) {
            // full keys of the quality errors (the error lines shorten them): {kind: {key: why}}
            $report[$set][$code]['quality'] = array_filter($quality);
        }
    }
}

if (!empty($opt['json'])) {
    echo json_encode(['root' => $root, 'ok' => $errors === [], 'strict' => array_values($strict), 'min_coverage' => $minCoverage]
        + $report + ['errors' => $errors, 'catalogs_skipped' => Extractor::$skipped], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($errors === [] ? 0 : 1);
}

foreach ($sets as $set => $s) {
    printf("i18n check %s: %d required keys (%s) | strict: %s%s\n", $s['label'], count($s['required']), $root, implode(',', $strict),
        $minCoverage > 0 ? sprintf(' | min coverage %.0f%%', $minCoverage) : '');
    printf("%-4s %8s %8s %10s %6s %8s %8s %8s %8s %8s %9s %8s %7s %8s %6s\n", 'loc', 'shown', 'transl', 'translated', 'kept', 'accepted', 'old-lbl',
        'english', 'corrupt', 'missing', 'ph-errors', 'plurals', 'brands', 'en-sent', 'zw');
    foreach ($report[$set] ?? [] as $code => $r) {
        printf("%-4s %7.2f%% %7.2f%% %10d %6d %8d %8d %8d %8d %8d %9d %8d %7d %8d %6d\n", $code, $r['coverage'], $r['translated_coverage'], $r['translated'],
            $r['kept_english'], $r['accepted_english'], $r['old_label_fallback'], $r['english'], $r['corrupt'], $r['missing'], $r['placeholder_errors'],
            $r['plural_errors'], $r['brand_errors'], $r['english_sentence_errors'], $r['zero_width_errors']);
        foreach ($r['untranslated'] ?? [] as $key) {
            echo '       - ', mb_strimwidth($key, 0, 120, '...'), PHP_EOL;
        }
    }
}
foreach (Extractor::$skipped as $callable => $why) {
    echo "note: catalog {$callable} not read ({$why}): its labels are not checked\n";
}
foreach (array_slice($errors, 0, 200) as $error) {
    echo 'ERROR ', $error, PHP_EOL;
}
if (count($errors) > 200) {
    echo '... and ', count($errors) - 200, " more errors\n";
}
echo $errors === [] ? "i18n check OK\n" : sprintf("i18n check FAILED (%d errors)\n", count($errors));
exit($errors === [] ? 0 : 1);
