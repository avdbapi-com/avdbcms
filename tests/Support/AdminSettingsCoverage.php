<?php

namespace Tests\Support;

use App\Http\Controllers\Admin\SettingsController;

/**
 * The checks of the settings coverage (AVDBCMS 1.2.3): every setting of config/admin_settings.php can be
 * reached in the admin, and every setting the site stores, saves or reads has a field there or a reason why not.
 * Each check returns the problems it found (an empty list is a pass), so a test can also show that a broken catalogue
 * is caught.
 */
trait AdminSettingsCoverage
{
    /**
     * The catalogue itself: each setting's page and card exist and list it; a setting without a row of its own says
     * where it is changed ("see" a shown setting, a "link" with a "marker", or an "anchor" of its page); a hidden one
     * has a reason and a hint.
     *
     * @return list<string>
     */
    protected function catalogueProblems(array $catalogue): array
    {
        $problems = [];
        $pages = (array) ($catalogue['pages'] ?? []);
        $settings = (array) ($catalogue['settings'] ?? []);

        foreach ($settings as $key => $setting) {
            $pageId = (string) ($setting['page'] ?? '');
            $page = $pages[$pageId] ?? null;

            if (! is_array($page)) {
                $problems[] = "{$key}: its page {$pageId} does not exist";

                continue;
            }

            if (empty($page['url'])) {
                $problems[] = "{$key}: its page {$pageId} has no address";
            }

            $card = $page['cards'][$setting['card'] ?? ''] ?? null;

            if (! is_array($card) || ! in_array($key, (array) ($card['fields'] ?? []), true)) {
                $problems[] = "{$key}: the card {$pageId}/".($setting['card'] ?? '?').' does not list it';
            }

            $targets = array_values(array_filter(['see', 'link', 'anchor'], fn (string $field): bool => ! empty($setting[$field])));

            if (($setting['level'] ?? '') === 'hidden') {
                if (trim((string) ($setting['level_reason'] ?? '')) === '' || trim((string) ($setting['hint'] ?? '')) === '') {
                    $problems[] = "{$key}: a hidden setting needs a level_reason and a hint";
                }

                if (count($targets) !== 1) {
                    $problems[] = "{$key}: a hidden setting needs exactly one of see, link or anchor";
                }
            }

            if (! empty($setting['see'])) {
                $see = $settings[$setting['see']] ?? null;

                if (! is_array($see) || ($see['level'] ?? '') === 'hidden') {
                    $problems[] = "{$key}: it points to {$setting['see']}, which the admin does not show";
                }
            }

            if (! empty($setting['link']) && (! str_starts_with((string) $setting['link'], '/admin') || empty($setting['marker']))) {
                $problems[] = "{$key}: its link needs an /admin address and a marker";
            }
        }

        return $problems;
    }

    /**
     * Every key the save schema accepts and every key the bundled example.sql stores is a setting of the catalogue
     * or listed in no_field with a reason.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function unlistedKeys(array $catalogue, array $keys, string $source): array
    {
        $problems = [];

        foreach (array_unique($keys) as $key) {
            if (isset($catalogue['settings'][$key])) {
                continue;
            }

            if (trim((string) ($catalogue['no_field'][$key] ?? '')) === '') {
                $problems[] = "{$key} ({$source}): no admin field and no reason in no_field";
            }
        }

        return $problems;
    }

    /** @return list<string> every key of SettingsController::saveSchema() */
    protected function saveSchemaKeys(): array
    {
        $keys = [];

        foreach (app(SettingsController::class)->saveSchema() as $fields) {
            array_push($keys, ...array_keys($fields));
        }

        return $keys;
    }

    /** @return list<string> the keys of the settings rows of database/install/example.sql */
    protected function exampleSqlKeys(): array
    {
        $sql = (string) file_get_contents(base_path('database/install/example.sql'));
        $start = strpos($sql, 'INSERT INTO `settings` VALUES');
        $this->assertNotFalse($start, 'example.sql stores settings');
        $end = strpos($sql, ";\n", $start);
        $body = substr($sql, $start, $end === false ? null : $end - $start);
        preg_match_all("/\\(\\d+,'((?:[^'\\\\]|\\\\.)*)',(?:NULL|'(?:[^'\\\\]|\\\\.)*'),'((?:[^'\\\\]|\\\\.)*)',/", $body, $matches);

        return $matches[1];
    }

    /**
     * config('site.<name>') values the code reads (a literal name: app/, resources/views, routes), as "site.<name>".
     * A name that is a setting needs no entry; any other needs a reason in no_field.
     *
     * @return array<string, list<string>> "site.<name>" => where it is read
     */
    protected function siteConfigReads(): array
    {
        $reads = [];
        $pattern = '/(?:\bconfig|Config::get|config\(\)->get)\(\s*[\'"]site\.([A-Za-z0-9_]+)[\'"]\s*[,)]/';

        foreach (['app', 'resources/views', 'routes'] as $folder) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($folder), \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                foreach (file($file->getPathname()) ?: [] as $number => $line) {
                    // The lines that fill the values (AppServiceProvider) and comments are no reads.
                    if (str_contains($line, 'Config::set') || preg_match('#^\s*(?://|\*|/\*|\#|\{\{--)#', $line)) {
                        continue;
                    }

                    if (preg_match_all($pattern, $line, $found)) {
                        foreach ($found[1] as $name) {
                            $reads['site.'.$name][] = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1)).':'.($number + 1);
                        }
                    }
                }
            }
        }

        ksort($reads);

        return $reads;
    }

    /**
     * The read config('site.*') values that are neither a setting nor listed in no_field.
     *
     * @param  array<string, list<string>>  $reads
     * @return list<string>
     */
    protected function unlistedSiteReads(array $catalogue, array $reads): array
    {
        $problems = [];

        foreach ($reads as $name => $where) {
            $key = substr($name, 5);

            if (isset($catalogue['settings'][$key]) || trim((string) ($catalogue['no_field'][$name] ?? '')) !== '') {
                continue;
            }

            $problems[] = "{$name} (read in ".implode(', ', array_slice($where, 0, 2)).'): no admin field and no reason in no_field';
        }

        return $problems;
    }

    /**
     * Which settings of a page the HTML shows: a row id="<key>-row" (settings and Appearance pages, plans, ...).
     *
     * @param  list<string>  $keys
     * @return list<string> the keys it does not show
     */
    protected function notShown(string $html, array $keys): array
    {
        return array_values(array_filter($keys, fn (string $key): bool => ! str_contains($html, 'id="'.$key.'-row"')));
    }
}
