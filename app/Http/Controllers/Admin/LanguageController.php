<?php

namespace App\Http\Controllers\Admin;

use App\Models\Language;
use App\Models\Setting;
use App\Support\AdminPagination;
use App\Support\AdminPreferences;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;

class LanguageController
{
    protected string $langPath;

    public function __construct()
    {
        $this->langPath = base_path('lang');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // LANGUAGE CRUD (languages table)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Language list — overview of all languages, key counts, file status.
     */
    public function index(Request $request): View
    {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.languages.list', $this->listPage());
        }

        $defaultLocale  = Language::where('is_default', true)->value('code') ?? 'en';
        $reference      = $this->buildFullReference($defaultLocale);
        $referenceCount = count($reference);

        $languages = Language::ordered()->get();

        $stats = $languages->map(function (Language $lang) use ($reference, $referenceCount) {
            // Count from both JSON + PHP files to get accurate coverage
            $allTranslations = array_merge(
                $this->loadJson($lang->code),
                $this->loadPhpFiles($lang->code)
            );

            // Count keys that are in the reference AND have a non-empty translation
            $filled = 0;
            foreach ($reference as $key => $refVal) {
                if (isset($allTranslations[$key]) && $allTranslations[$key] !== '') {
                    $filled++;
                }
            }

            return [
                'language'  => $lang,
                'locale'    => $lang->code,
                'label'     => $lang->label,
                'emoji'     => $lang->emoji,
                'direction' => $lang->direction,
                'isDefault' => $lang->is_default,
                'isActive'  => $lang->is_active,
                'hasFile'   => File::exists($this->langPath . '/' . $lang->code . '.json'),
                'total'     => $referenceCount,
                'filled'    => $lang->is_default ? $referenceCount : $filled,
                'missing'   => $lang->is_default ? 0 : max(0, $referenceCount - $filled),
                'percent'   => $referenceCount > 0 ? round(($filled / $referenceCount) * 100) : 100,
            ];
        });


        return view('admin.languages.index', compact('stats', 'referenceCount'));
    }

    /**
     * The new admin's list: the languages and how much of each is translated. Counting reads every translation file
     * (seconds for 17 languages), so the counts are kept until a translation file or the languages change.
     */
    private function listPage(): array
    {
        $languages = Language::ordered()->get();
        $defaultLocale = (string) ($languages->firstWhere('is_default', true)?->code ?? 'en');
        $files = array_merge(File::glob($this->langPath.'/*.json') ?: [], File::glob($this->langPath.'/*/*.php') ?: []);
        $signature = md5(json_encode([
            $defaultLocale,
            $languages->pluck('code')->all(),
            array_map(fn (string $file): array => [basename(dirname($file)).'/'.basename($file), @filemtime($file), @filesize($file)], $files),
        ]));

        $coverage = Cache::remember('admin.languages.coverage.'.$signature, now()->addDay(), function () use ($languages, $defaultLocale): array {
            $reference = $this->buildFullReference($defaultLocale);
            $total = count($reference);
            $coverage = [];

            foreach ($languages as $language) {
                $translations = array_merge($this->loadJson($language->code), $this->loadPhpFiles($language->code));
                $filled = 0;

                foreach ($reference as $key => $value) {
                    if (isset($translations[$key]) && $translations[$key] !== '') {
                        $filled++;
                    }
                }

                $filled = $language->code === $defaultLocale ? $total : $filled;
                $coverage[$language->code] = [
                    'percent' => $total > 0 ? (int) round($filled / $total * 100) : 100,
                    'missing' => max(0, $total - $filled),
                    'file' => File::exists($this->langPath.'/'.$language->code.'.json'),
                ];
            }

            return $coverage;
        });

        return compact('languages', 'coverage');
    }

    /**
     * Show form to create a new language.
     */
    public function create(): View
    {
        if (AdminPreferences::usesNewAdmin()) {
            return view('admin.languages.record', ['language' => new Language()]);
        }

        return view('admin.languages.create');
    }

    /**
     * Store a new language.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code'      => ['required', 'string', 'max:10', 'regex:/^[a-z]{2,5}$/', Rule::unique('languages', 'code')],
            'label'     => ['required', 'string', 'max:100'],
            'emoji'     => ['required', 'string', 'max:20'],
            'direction' => ['required', Rule::in(['ltr', 'rtl'])],
            'is_active' => ['boolean'],
        ]);

        $maxOrder = Language::max('sort_order') ?? 0;

        Language::create([
            'code'       => strtolower($data['code']),
            'label'      => $data['label'],
            'emoji'      => $data['emoji'],
            'direction'  => $data['direction'],
            'is_default' => false,
            'is_active'  => $request->boolean('is_active', true),
            'sort_order' => $maxOrder + 1,
        ]);

        Language::flushCache();

        return redirect()
            ->route('admin.languages.index')
            ->with('success', __("Language ':label' (:code) added successfully.", ['label'=>$data['label'],'code'=>$data['code']]));
    }

    /**
     * Show the language edit form (metadata: label, emoji, direction, etc.).
     */
    public function editMeta(Language $language): View
    {
        return view(AdminPreferences::usesNewAdmin() ? 'admin.languages.record' : 'admin.languages.edit-meta', ['language' => $language]);
    }

    /**
     * Update language metadata (label, emoji, direction, active status).
     */
    public function updateMeta(Request $request, Language $language): RedirectResponse
    {
        $data = $request->validate([
            'label'     => ['required', 'string', 'max:100'],
            'emoji'     => ['required', 'string', 'max:20'],
            'direction' => ['required', Rule::in(['ltr', 'rtl'])],
            'is_active' => ['boolean'],
            'sort_order'=> ['nullable', 'integer', 'min:0'],
        ]);

        $language->update([
            'label'      => $data['label'],
            'emoji'      => $data['emoji'],
            'direction'  => $data['direction'],
            'is_active'  => $request->boolean('is_active', true),
            'sort_order' => $data['sort_order'] ?? $language->sort_order,
        ]);

        Language::flushCache();

        return redirect()
            ->route('admin.languages.index')
            ->with('success', __("Language ':label' updated.", ['label'=>$language->label]));
    }

    /**
     * Set a language as the site default (bare URL, no prefix).
     */
    public function setDefault(Language $language): RedirectResponse
    {
        if (!$language->is_active) {
            return redirect()
                ->route('admin.languages.index')
                ->withErrors(__('Cannot set an inactive language as default.'));
        }

        Language::where('is_default', true)->update(['is_default' => false]);
        $language->update(['is_default' => true]);
        Setting::set('default_locale', $language->code, 'localization');

        Language::flushCache();

        return redirect()
            ->route('admin.languages.index')
            ->with('success', __("':label' is now the default language.", ['label'=>$language->label]));
    }

    /**
     * Toggle active/inactive status.
     */
    public function toggleActive(Language $language): RedirectResponse
    {
        if ($language->is_default && $language->is_active) {
            return redirect()
                ->route('admin.languages.index')
                ->withErrors(__('Cannot deactivate the default language. Set another language as default first.'));
        }

        $language->update(['is_active' => !$language->is_active]);
        Language::flushCache();

        $state = $language->is_active ? 'activated' : 'deactivated';
        return redirect()
            ->route('admin.languages.index')
            ->with('success', __("':label' {$state}.", ['label'=>$language->label]));
    }

    /**
     * Delete a language (cannot delete default).
     */
    public function destroy(Language $language): RedirectResponse
    {
        if ($language->is_default) {
            return redirect()
                ->route('admin.languages.index')
                ->withErrors(__('Cannot delete the default language. Set another language as default first.'));
        }

        $label = $language->label;
        $code  = $language->code;

        $language->delete();

        // Optionally delete the translation JSON file
        $jsonPath = $this->langPath . '/' . $code . '.json';
        // We keep the JSON file so the data isn't lost — admin can re-add the language

        Language::flushCache();

        return redirect()
            ->route('admin.languages.index')
            ->with('success', __("Language ':label' removed. Translation file kept for future use.", ['label'=>$label]));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // TRANSLATION EDITING (JSON files)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Edit translations for a specific locale.
     */
    public function edit(Request $request, string $locale): View
    {
        $language = Language::where('code', $locale)->firstOrFail();

        $defaultLocale = Language::where('is_default', true)->value('code') ?? 'en';

        // Build comprehensive reference: JSON keys + PHP file keys
        $reference = $this->buildFullReference($defaultLocale);

        // Current locale translations: JSON + PHP files merged
        $translations = array_merge(
            $this->loadJson($locale),
            $this->loadPhpFiles($locale)
        );

        // Merge — show all reference keys with current translations
        $rows = [];
        foreach ($reference as $key => $englishValue) {
            $rows[$key] = [
                'key'         => $key,
                'english'     => $englishValue,
                'translation' => $translations[$key] ?? '',
                'missing'     => !isset($translations[$key]) || $translations[$key] === '',
                'source'      => str_contains($key, '.') ? 'php' : 'json',
            ];
        }

        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.languages.editor', $this->editorPage($request, $locale, $language, $rows));
        }

        return view('admin.languages.edit', compact('locale', 'language', 'rows'));
    }

    /**
     * The new admin's editor shows 100 strings per page (the whole file was one 14 MB form), filtered by a search and
     * by state, and saves the page it shows (update() with partial=1 merges those keys into the files).
     */
    private function editorPage(Request $request, string $locale, Language $language, array $rows): array
    {
        $search = trim((string) $request->query('q', ''));
        $show = in_array($request->query('show'), ['missing', 'filled', 'json', 'php'], true) ? (string) $request->query('show') : 'all';
        $missing = count(array_filter($rows, fn (array $row): bool => $row['missing']));

        $filtered = array_filter($rows, function (array $row) use ($search, $show): bool {
            $state = match ($show) {
                'missing' => $row['missing'],
                'filled' => ! $row['missing'],
                'json', 'php' => $row['source'] === $show,
                default => true,
            };

            return $state && ($search === ''
                || stripos($row['key'], $search) !== false
                || stripos((string) $row['english'], $search) !== false
                || stripos((string) $row['translation'], $search) !== false);
        });

        return [
            'locale' => $locale,
            'language' => $language,
            'rows' => AdminPagination::collection($filtered, 100)->withQueryString(),
            'search' => $search,
            'show' => $show,
            'total' => count($rows),
            'missing' => $missing,
        ];
    }

    /**
     * Build a full reference key→value map from ALL sources:
     *   a) JSON files (lang/en.json + all other *.json files)
     *   b) PHP files  (lang/en/auth.php, lang/en/premium.php, etc.)
     *
     * PHP keys are namespaced as "file.key" (e.g. "auth.failed", "premium.billing")
     * to avoid collisions with JSON keys and to group them visually.
     */
    protected function buildFullReference(string $defaultLocale): array
    {
        // ── A. JSON keys ──────────────────────────────────────────────────────
        $reference = $this->loadJson($defaultLocale);

        // Add any keys missing from other locale JSON files
        $files = glob($this->langPath . '/*.json') ?: [];
        foreach ($files as $file) {
            $code = pathinfo($file, PATHINFO_FILENAME);
            if ($code === $defaultLocale) continue;
            foreach ($this->loadJson($code) as $key => $value) {
                if (!isset($reference[$key])) {
                    $reference[$key] = $key; // natural-language key = its own English text
                }
            }
        }

        // ── B. PHP keys from lang/en/*.php ────────────────────────────────────
        $phpRef = $this->loadPhpFiles($defaultLocale);
        // Merge — PHP keys already have "file.key" prefix so no collision
        foreach ($phpRef as $key => $value) {
            $reference[$key] = $value;
        }

        // Sort alphabetically (PHP keys grouped by prefix naturally)
        ksort($reference, SORT_NATURAL | SORT_FLAG_CASE);

        return $reference;
    }

    /**
     * Load all PHP translation files for a locale as a flat "file.key" => "value" map.
     * e.g. lang/en/auth.php  →  ['auth.failed' => '...', 'auth.password' => '...']
     * Skips validation.php — 84 generic Laravel validation messages rarely need custom UI translation.
     */
    protected function loadPhpFiles(string $locale): array
    {
        $result  = [];
        $phpDir  = $this->langPath . '/' . $locale;

        // Skip validation.php — it's boilerplate and huge; leave it for devs
        $skipFiles = ['validation.php'];

        if (!is_dir($phpDir)) return [];

        foreach (glob($phpDir . '/*.php') ?: [] as $file) {
            $filename = basename($file);
            if (in_array($filename, $skipFiles, true)) continue;

            $fileKey = pathinfo($file, PATHINFO_FILENAME); // e.g. "auth"
            $data = @include $file;
            if (!is_array($data)) continue;

            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $result[$fileKey . '.' . $key] = $value;
                }
                // Skip nested arrays (validation 'custom', 'attributes') — they're structural
            }
        }

        return $result;
    }

    /**
     * Save translations for a locale.
     * - JSON keys  → lang/{locale}.json
     * - PHP keys   → lang/{locale}/{file}.php  (e.g. auth.failed → auth.php)
     * - New keys   → also written to en.json so they persist for all languages
     */
    public function update(Request $request, string $locale): RedirectResponse
    {
        $language = Language::where('code', $locale)->firstOrFail();

        $all = $request->get('translations', []);

        // ── Split: JSON keys vs PHP file keys (those with a "file.key" prefix) ──
        $phpKeys  = [];  // ['auth.failed' => '...', 'premium.billing' => '...']
        $jsonKeys = [];  // ['Sign In' => '...', 'Upload' => '...']

        // Which PHP files exist in the default locale (our source of truth)
        $defaultLocale = Language::where('is_default', true)->value('code') ?? 'en';
        $phpFileNames  = array_map(
            fn ($f) => pathinfo($f, PATHINFO_FILENAME),
            glob($this->langPath . '/' . $defaultLocale . '/*.php') ?: []
        );

        foreach ($all as $key => $value) {
            $dot = strpos($key, '.');
            if ($dot !== false) {
                $prefix = substr($key, 0, $dot);
                if (in_array($prefix, $phpFileNames, true)) {
                    $phpKeys[$key] = $value;
                    continue;
                }
            }
            $jsonKeys[$key] = $value;
        }

        // ── Save JSON file ────────────────────────────────────────────────────
        // The new admin's editor sends one page of keys (partial=1): they replace those keys only. Without it the
        // form holds every key and replaces the file, as it always did.
        $jsonFiltered = array_filter(
            $request->boolean('partial') ? array_replace($this->loadJson($locale), $jsonKeys) : $jsonKeys,
            fn ($v) => $v !== null && $v !== ''
        );
        File::put(
            $this->langPath . '/' . $locale . '.json',
            $this->jsonForDisk($jsonFiltered)
        );

        // ── Save PHP files ────────────────────────────────────────────────────
        // Group by file prefix and write back as PHP array files
        $phpDir = $this->langPath . '/' . $locale;
        if (!is_dir($phpDir)) {
            mkdir($phpDir, 0755, true);
        }

        if (!empty($phpKeys)) {
            // Group: ['auth.failed' => '...'] → ['auth' => ['failed' => '...']]
            $grouped = [];
            foreach ($phpKeys as $dotKey => $value) {
                [$filePrefix, $itemKey] = explode('.', $dotKey, 2);
                if ($value !== null && $value !== '') {
                    $grouped[$filePrefix][$itemKey] = $value;
                }
            }

            foreach ($grouped as $filePrefix => $items) {
                // Merge with existing PHP file to preserve untouched keys
                $phpFile = $phpDir . '/' . $filePrefix . '.php';
                $existing = File::exists($phpFile) ? (@include $phpFile) : [];
                if (!is_array($existing)) $existing = [];

                $merged  = array_merge($existing, $items);
                $content = "<?php\nreturn " . $this->phpArrayExport($merged) . ";\n";
                File::put($phpFile, $content);
            }
        }

        // ── Persist new JSON keys to en.json ──────────────────────────────────
        $defaultJsonPath = $this->langPath . '/' . $defaultLocale . '.json';
        $defaultData     = $this->loadJson($defaultLocale);
        $newKeys         = array_diff_key($jsonKeys, $defaultData);

        if (!empty($newKeys)) {
            foreach ($newKeys as $key => $value) {
                if (trim($key) !== '') {
                    $defaultData[$key] = ($locale === $defaultLocale) ? $value : $key;
                }
            }
            ksort($defaultData, SORT_NATURAL | SORT_FLAG_CASE);
            File::put($defaultJsonPath, $this->jsonForDisk($defaultData));
        }

        // The page of the editor that was saved (its search, filter and page number) opens again.
        $back = $request->boolean('partial') ? redirect()->to(url()->previous()) : redirect()->route('admin.languages.edit', $locale);

        return $back->with('success', __("Translations for :label saved successfully.", ['label'=>$language->label]));
    }

    /**
     * Export PHP array as a clean PHP string (without var_export quirks).
     */
    protected function phpArrayExport(array $data): string
    {
        $lines = ['['];
        foreach ($data as $key => $value) {
            $escapedKey = addslashes($key);
            $escapedVal = addslashes($value);
            $lines[] = "    '{$escapedKey}' => '{$escapedVal}',";
        }
        $lines[] = ']';
        return implode("\n", $lines);
    }

    /**
     * Export translations as a downloadable JSON file.
     */
    public function export(string $locale): Response
    {
        Language::where('code', $locale)->firstOrFail();

        $path = $this->langPath . '/' . $locale . '.json';
        $content = File::exists($path) ? File::get($path) : '{}';

        return response($content, 200, [
            'Content-Type'        => 'application/json',
            'Content-Disposition' => "attachment; filename={$locale}.json",
        ]);
    }

    /**
     * Import translations from uploaded JSON file.
     */
    public function import(Request $request): RedirectResponse
    {
        $codes = Language::pluck('code')->toArray();

        $request->validate([
            'locale' => ['required', 'string', Rule::in($codes)],
            'file'   => ['required', 'file', 'mimes:json', 'max:2048'],
        ]);

        $locale  = $request->get('locale');
        $content = file_get_contents($request->file('file')->path());
        $data    = json_decode($content, true);

        if (!is_array($data)) {
            return redirect()
                ->route('admin.languages.index')
                ->withErrors('Invalid JSON file — could not parse.');
        }

        $path = $this->langPath . '/' . $locale . '.json';
        File::put($path, $this->jsonForDisk($data));

        return redirect()
            ->route('admin.languages.edit', $locale)
            ->with('success', __("Translations for :locale imported successfully.", ['locale'=>$locale]));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function loadJson(string $locale): array
    {
        $path = $this->langPath . '/' . $locale . '.json';
        if (!File::exists($path)) return [];
        $data = json_decode(File::get($path), true);
        return is_array($data) ? $data : [];
    }

    protected function jsonForDisk(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    }
}

