<?php

/*
| Lists the translation keys the code needs and the ones that are not in the translation files yet - the
| "final extraction" step: re-run it after the work packages merge, then sync.php (adds the keys to every
| locale with fallbacks + todo lists), the translate engine and i18n_check.php.
|   - Blade/PHP: inventory.json + literal __() keys in the source paths of sources.json + the labels of its config
|     files and catalog classes -> lang/en.json
|   - Preact: literal t() keys in resources/js -> resources/js/lang/en.ts
|
|   php scripts/i18n/extract.php [--root=DIR] [--json] [--no-inventory]
*/

require __DIR__ . '/I18n.php';

use Avd\I18n\Cli;
use Avd\I18n\Extractor;
use Avd\I18n\Inventory;
use Avd\I18n\JsLangFile;
use Avd\I18n\LangFile;
use Avd\I18n\Paths;

$opt = Cli::options($argv);

try {
    $root = Paths::root(is_string($opt['root'] ?? null) ? $opt['root'] : null);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(2);
}

$fromSources = Extractor::scan($root);
$inventory = empty($opt['no-inventory']) ? Inventory::load($root) : [];
[$en] = LangFile::read(Paths::lang($root, 'en'));
$jsKeys = Extractor::scanJs($root);
[$enJs] = JsLangFile::read(JsLangFile::path($root, 'en'));

$new = [];
foreach ($inventory as $key => $row) {
    if (!isset($en[$key])) {
        $new[$key] = 'inventory:' . $row['where'];
    }
}
foreach ($fromSources as $key => $where) {
    if (!isset($en[$key]) && !isset($new[$key])) {
        $new[$key] = $where;
    }
}
$newJs = array_diff_key($jsKeys, $enJs);

if (!empty($opt['json'])) {
    $rows = static fn (array $list) => array_map(static fn ($k, $w) => ['key' => $k, 'where' => $w], array_keys($list), $list);
    echo json_encode([
        'root' => $root,
        'source_keys' => count($fromSources),
        'inventory_keys' => count($inventory),
        'required_keys' => count($inventory + $fromSources),
        'not_in_lang_files' => $rows($new),
        'js_keys' => count($jsKeys),
        'js_not_in_lang_files' => $rows($newJs),
        'catalogs_skipped' => Extractor::$skipped,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

printf("root: %s\nBlade/PHP - source keys: %d | inventory keys: %d | required: %d | not in lang/en.json yet: %d\n",
    $root, count($fromSources), count($inventory), count($inventory + $fromSources), count($new));
foreach ($new as $key => $where) {
    printf("  + %s   [%s]\n", strlen($key) > 110 ? substr($key, 0, 107) . '...' : $key, $where);
}
foreach (Extractor::$skipped as $callable => $why) {
    printf("  note: catalog %s not read (%s): its labels are not required keys here\n", $callable, $why);
}
printf("Preact - t() keys: %d | not in resources/js/lang/en.ts yet: %d\n", count($jsKeys), count($newJs));
foreach ($newJs as $key => $where) {
    printf("  + %s   [%s]\n", strlen($key) > 110 ? substr($key, 0, 107) . '...' : $key, $where);
}
if ($new !== [] || $newJs !== []) {
    echo "next: php scripts/i18n/sync.php --todo=storage/framework/i18n  (adds them to every locale with fallbacks)\n";
}
