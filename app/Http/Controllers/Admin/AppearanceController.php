<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Setting;
use App\Models\SettingRevision;
use App\Models\User;
use App\Models\Video;
use App\Services\SeoService;
use App\Support\AdminCache;
use App\Support\AdminNav;
use App\Support\AdminPreferences;
use App\Support\AppearanceApplier;
use App\Support\AppearanceDraft;
use App\Support\AppearancePages;
use App\Support\AppearanceSettings;
use App\Support\DailyDesignCatalog;
use App\Support\MasterExperienceCatalog;
use App\Support\SettingsRevision;
use App\Support\ThemeCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Appearance (admin section): the Themes page and nine settings pages. Every change is a draft of this admin
 * (AppearanceDraft) that they see on the real site through a signed preview link, until they publish it; publishing
 * writes the look keys through AppearanceApplier (one revision) and the other settings of these pages in the same
 * transaction, and History / Undo restore any of those revisions.
 */
class AppearanceController extends Controller
{
    /** Gallery JSON page size (admin spec speed rule S4). */
    public const PER_PAGE = 24;

    /** Themes shown on the Themes page before "Show all". */
    private const FIRST_THEMES = 12;

    /** Lines in History. */
    private const HISTORY_ITEMS = 20;

    private const THUMBS = 'images/admin/looks';

    public function index(Request $request): View|RedirectResponse|JsonResponse
    {
        if (! AdminPreferences::usesNewAdmin($request->user())) {
            return $this->oldTab($request, 'appearance.themes');
        }

        $user = $request->user();
        $draft = AppearanceDraft::get((int) $user->getKey());
        $keys = array_merge(['theme_preset', 'theme_flavor', 'active_master_experience', 'theme', 'brand_contrast_autofix'], ThemeCatalog::COLOUR_KEYS);
        $published = AppearancePages::published($keys);
        $current = AppearanceSettings::resolveThemeFlavor(['theme_preset' => $published['theme_preset'], 'theme_flavor' => $published['theme_flavor']]);
        $shown = $this->shownValues($keys, $draft['values']);
        $shownTheme = AppearanceSettings::resolveThemeFlavor(['theme_preset' => $shown['theme_preset'], 'theme_flavor' => $shown['theme_flavor']]);

        return view('admin.appearance.themes', array_merge($this->frame($request, 'appearance.themes', $draft), [
            'current' => $this->currentLook($published, $current),
            'looks' => array_map(fn (array $look): array => $this->lookItem($look, (string) ($published['active_master_experience'] ?? '')), array_values(MasterExperienceCatalog::all())),
            'families' => array_map(fn (array $family): array => ['id' => $family['id'], 'name' => __($family['name']), 'count' => count($family['themes'])], array_values(ThemeCatalog::families())),
            'themes' => array_slice($this->themeItems($shownTheme), 0, self::FIRST_THEMES),
            'themeCount' => count(ThemeCatalog::themes()),
            'flavorCount' => array_sum(array_map(fn (array $theme): int => count($theme['flavors']), ThemeCatalog::themes())),
            'shownTheme' => $this->themeItem(ThemeCatalog::theme($shownTheme['theme']) ?? [], $shownTheme),
            'shownFlavor' => $shownTheme['flavor'],
            'mode' => $shown['theme'],
            'colourNote' => $this->colourNote($shown),
            'autofix' => $shown['brand_contrast_autofix'],
            'daily' => $this->pageFields($request, 'appearance.themes', ['daily'], $draft),
            'modeFields' => $this->pageFields($request, 'appearance.themes', ['mode'], $draft),
            'autofixFields' => array_values(array_filter($this->pageFields($request, 'appearance.brand', ['colors'], $draft), fn (array $f): bool => $f['key'] === 'brand_contrast_autofix')),
            'published' => AppearancePages::published(array_merge($keys, ['daily_design_auto_enabled', 'daily_design_scope', 'daily_design_layouts_enabled', 'daily_design_show_badge', 'daily_design_atmosphere_particles', 'daily_design_override_day'])),
            'month' => (int) now()->month,
            'tab' => in_array($request->query('tab'), ['looks', 'themes', 'daily'], true) ? $request->query('tab') : 'looks',
        ]));
    }

    public function page(Request $request, string $page): View|RedirectResponse|JsonResponse
    {
        $pageId = AppearancePages::PAGES[$page] ?? null;
        abort_if($pageId === null, 404);

        if (! AdminPreferences::usesNewAdmin($request->user())) {
            return $this->oldTab($request, $pageId);
        }

        $user = $request->user();
        $draft = AppearanceDraft::get((int) $user->getKey());
        $form = $this->withCardLinks($pageId, app(SettingsPageController::class)->form($request, $pageId));
        $keys = [];

        foreach (array_merge($form['cards'], $form['advancedCards']) as $card) {
            foreach (array_merge($card['fields'], $card['more']) as $field) {
                $keys[] = $field['key'];
            }
        }

        $shown = $this->shownValues($keys, $draft['values']);
        $categories = null;

        foreach (['cards', 'advancedCards'] as $list) {
            foreach ($form[$list] as $c => $card) {
                foreach (['fields', 'more'] as $part) {
                    foreach ($card[$part] as $f => $field) {
                        if (str_ends_with($field['key'], '_category_id')) {
                            $categories ??= ['0' => 'Any category'] + Category::query()->orderBy('title')->limit(500)->pluck('title', 'id')->map(fn ($title): string => (string) $title)->all();
                            $field['options'] = array_map('strval', $categories);
                        }

                        $field = $this->normalizeField($field);
                        $field['value'] = $shown[$field['key']] ?? $field['value'];
                        $field['changed'] = array_key_exists($field['key'], $draft['values']);

                        $form[$list][$c][$part][$f] = $field;
                    }
                }
            }
        }

        // Long code boxes load their value after the page (GET /admin/settings/values), so it stays out of the page too.
        $codeKeys = [];
        foreach (array_merge($form['cards'], $form['advancedCards']) as $card) {
            foreach (array_merge($card['fields'], $card['more']) as $field) {
                if (in_array($field['control'], ['code', 'code_editor'], true)) {
                    $codeKeys[] = $field['key'];
                }
            }
        }

        $data = ['form' => $form, 'shown' => $shown, 'published' => AppearancePages::published(array_values(array_diff($keys, $codeKeys)))];

        if ($pageId === 'appearance.effects') {
            $data['speed'] = AppearancePages::speed($shown);
            $data['level'] = AppearancePages::levelOf($shown);
            $data['levels'] = array_map(fn (array $level): array => $level + ['speed' => AppearancePages::speed(array_replace($shown, $level['writes']))], AppearancePages::LEVELS);
        }

        if ($pageId === 'appearance.brand') {
            $data['colourNote'] = $this->colourNote($shown + $this->shownValues(['theme_preset', 'theme_flavor', 'theme', 'brand_contrast_autofix'], $draft['values']));
        }

        return view('admin.appearance.page', array_merge($this->frame($request, $pageId, $draft), $data));
    }

    /**
     * The page's form with a link on each card whose subject another admin page manages too
     * (AppearancePages::cardLink()), when that page opens on this site.
     */
    private function withCardLinks(string $pageId, array $form): array
    {
        foreach (['cards', 'advancedCards'] as $list) {
            foreach ($form[$list] as $c => $card) {
                $link = AppearancePages::cardLink($pageId, $card['id']);
                $page = $link !== null ? AdminNav::page($link['page']) : null;
                $url = $page !== null ? AdminNav::url($page) : null;

                if ($url !== null) {
                    $form[$list][$c]['link'] = ['text' => $link['text'], 'label' => $link['label'], 'url' => $url];
                }
            }
        }

        return $form;
    }

    /**
     * GET /admin/appearance/gallery?tab=looks|themes&family=&mode=dark|light&q=&page= - 24 items a page, as JSON for
     * the Themes page (the q search also finds old names: "formerly Tokyo Neon Horizon"). &offset=n instead of a page
     * gives the 24 items after the first n (the Themes page shows 12 first, then asks for the rest). A plain visit
     * gets the same gallery as a page.
     */
    public function gallery(Request $request): View|RedirectResponse|JsonResponse
    {
        $tab = in_array($request->query('tab'), ['looks', 'fonts'], true) ? $request->query('tab') : 'themes';

        // The font pairings with their stylesheet, for scripts/theme_thumbs.js (it draws their preview pictures; the
        // admin itself never loads a theme font).
        if ($tab === 'fonts' && $this->wantsJson($request)) {
            $fonts = [];

            foreach (AppearanceSettings::typographyPairings() as $id => $pairing) {
                $fonts[] = ['id' => (string) $id, 'name' => trim(explode(':', (string) ($pairing['label'] ?? $id))[0]), 'display' => (string) ($pairing['display'] ?? ''), 'body' => (string) ($pairing['body'] ?? ''), 'css' => (string) ($pairing['css_import'] ?? '')];
            }

            return response()->json(['tab' => 'fonts', 'items' => $fonts, 'total' => count($fonts)])->header('Cache-Control', 'private, no-store');
        }

        $tab = $tab === 'fonts' ? 'themes' : $tab;
        $page = max(1, (int) $request->query('page', 1));
        $query = is_string($request->query('q')) ? trim(Str::limit($request->query('q'), 80, '')) : '';
        $family = is_string($request->query('family')) ? $request->query('family') : '';
        $mode = in_array($request->query('mode'), ['dark', 'light'], true) ? $request->query('mode') : '';

        if ($tab === 'looks') {
            $published = AppearancePages::published(['active_master_experience']);
            $items = array_map(fn (array $look): array => $this->lookItem($look, $published['active_master_experience']), array_values(MasterExperienceCatalog::all()));
        } else {
            $published = AppearancePages::published(['theme_preset', 'theme_flavor']);
            $items = $this->themeItems(AppearanceSettings::resolveThemeFlavor($published), $query, $family, $mode);
        }

        $total = count($items);
        $offset = is_numeric($request->query('offset')) ? max(0, (int) $request->query('offset')) : ($page - 1) * self::PER_PAGE;
        $slice = array_slice($items, $offset, self::PER_PAGE);

        if ($this->wantsJson($request)) {
            return response()->json([
                'tab' => $tab,
                'page' => $page,
                'offset' => $offset,
                'per_page' => self::PER_PAGE,
                'total' => $total,
                'has_more' => $offset + count($slice) < $total,
                'items' => $slice,
            ])->header('Cache-Control', 'private, no-store');
        }

        if (! AdminPreferences::usesNewAdmin($request->user())) {
            return $this->oldTab($request, 'appearance.themes');
        }

        return view('admin.appearance.gallery', [
            'tab' => $tab,
            'items' => $slice,
            'page' => $page,
            'total' => $total,
            'query' => $query,
            'family' => $family,
            'mode' => $mode,
            'families' => ThemeCatalog::families(),
            'perPage' => self::PER_PAGE,
        ]);
    }

    /**
     * GET /admin/appearance/calendar?month=1-12 - one month of daily designs from the daily rule, with this admin's
     * draft (scope, theme, layouts) applied. JSON for the Themes page; a plain visit gets the month as a page.
     */
    public function calendar(Request $request): View|RedirectResponse|JsonResponse
    {
        $month = min(12, max(1, (int) $request->query('month', now()->month)));
        $year = (int) now()->year;
        $draft = AppearanceDraft::get((int) $request->user()->getKey());
        $settings = AppearanceSettings::applyOverrides(Setting::getGroup('appearance'), array_filter(
            $draft['values'],
            fn ($value, string $key): bool => AppearanceDraft::groupOf($key) === 'appearance',
            ARRAY_FILTER_USE_BOTH,
        ));
        $scope = AppearanceSettings::text($settings, 'daily_design_scope');
        $today = now()->toDateString();
        $days = [];

        foreach (DailyDesignCatalog::forMonth($month, $scope, $settings, $year) as $dayOfYear => $entry) {
            $days[] = [
                'day' => (int) $dayOfYear,
                'date' => (string) $entry['date'],
                'day_of_month' => (int) $entry['day_of_month'],
                'label' => __((string) $entry['theme_name']).' · '.__((string) $entry['flavor_name']),
                'holiday' => isset($entry['holiday']) ? __((string) $entry['holiday']) : null,
                'colours' => [(string) $entry['primary'], (string) $entry['secondary'], (string) $entry['accent']],
                'layout' => $entry['layout'] ?? null,
                'today' => $entry['date'] === $today,
                'preview' => url('/?'.http_build_query(['daily_design' => (int) $dayOfYear])),
            ];
        }

        $data = [
            'month' => $month,
            'year' => $year,
            'name' => now()->setDate($year, $month, 1)->translatedFormat('F Y'),
            'scope' => $scope,
            'first_weekday' => (int) now()->setDate($year, $month, 1)->dayOfWeekIso,
            'days' => $days,
        ];

        if ($this->wantsJson($request)) {
            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        if (! AdminPreferences::usesNewAdmin($request->user())) {
            return $this->oldTab($request, 'appearance.themes');
        }

        return view('admin.appearance.calendar', $data);
    }

    /**
     * POST /admin/appearance/draft - change this admin's draft (see AppearanceDraft::edit) and answer a fresh preview
     * link. An empty body only renews the link.
     */
    public function draft(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $user->getKey();
        $developer = AdminPreferences::developerMode($user);
        $edits = array_intersect_key($request->all(), array_flip(['set', 'unset', 'reset', 'look', 'theme', 'cancel']));

        if ($edits !== []) {
            $result = AppearanceDraft::edit($userId, $edits, $developer);

            if (! $result['stored']) {
                return response()->json(['ok' => false, 'message' => __('Your changes could not be kept. Try again.')], 503);
            }
        }

        $draft = AppearanceDraft::get($userId);
        $page = is_string($request->input('page')) ? $request->input('page') : '';
        $extra = [];

        if ($page === 'appearance.effects') {
            $keys = AppearancePages::cards('appearance.effects') === [] ? [] : array_merge(...array_column(AppearancePages::cards('appearance.effects'), 'fields'));
            $shown = $this->shownValues($keys, $draft['values']);
            $speed = AppearancePages::speed($shown);
            $extra = ['level' => AppearancePages::levelOf($shown), 'speed_html' => view('admin.appearance.partials.speed', ['speed' => $speed])->render()];
        }

        // Fields put back (Undo on a row, "Use the colour style") show the value they have now: the published one,
        // or for a colour the colour style's.
        $back = array_values(array_intersect(
            array_filter(array_merge((array) ($edits['reset'] ?? []), (array) ($edits['unset'] ?? [])), 'is_string'),
            AppearancePages::editableKeys($developer),
        ));

        if ($back !== []) {
            $extra['shown'] = (object) $this->shownValues($back, $draft['values']);
        }

        if (in_array($page, ['appearance.brand', 'appearance.themes'], true)) {
            $note = $this->colourNote($this->shownValues(array_merge(['theme_preset', 'theme_flavor', 'theme', 'brand_contrast_autofix'], ThemeCatalog::COLOUR_KEYS), $draft['values']));
            $extra['colour_note_html'] = view('admin.appearance.partials.colour-note', ['note' => $note, 'onThemes' => $page === 'appearance.themes'])->render();
        }

        return response()->json($extra + $this->draftState($request, $draft) + [
            'ok' => ($result['errors'] ?? []) === [],
            'errors' => (object) $this->keyedErrors($result['errors'] ?? []),
        ], ($result['errors'] ?? []) === [] ? 200 : 422)->header('Cache-Control', 'private, no-store');
    }

    /**
     * DELETE /admin/appearance/draft - forget every unpublished change of this admin.
     */
    public function discard(Request $request): JsonResponse|RedirectResponse
    {
        AppearanceDraft::forget((int) $request->user()->getKey());

        if ($this->wantsJson($request)) {
            return response()->json($this->draftState($request, AppearanceDraft::get((int) $request->user()->getKey())) + ['ok' => true, 'message' => __('Changes discarded.')]);
        }

        return redirect()->back()->withSuccess(__('Changes discarded.'));
    }

    /**
     * POST /admin/appearance/publish - publish this admin's draft. A page posted without JavaScript sends its fields
     * like the settings save does (groups[group][key] + fields[group]); they are published together with the draft.
     */
    public function publish(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $userId = (int) $user->getKey();

        if (is_array($request->input('fields'))) {
            $set = [];

            foreach ((array) $request->input('fields') as $group => $list) {
                foreach (is_string($list) ? explode(',', $list) : (array) $list as $key) {
                    if (is_string($key) && $key !== '' && AppearanceDraft::groupOf($key) === $group) {
                        $set[$key] = $request->input("groups.{$group}.{$key}", AppearancePages::isBool($key) ? '0' : '');
                    }
                }
            }

            $result = AppearanceDraft::edit($userId, ['set' => $set], AdminPreferences::developerMode($user));

            if ($result['errors'] !== []) {
                return $this->failed($request, $result['errors']);
            }
        }

        $draft = AppearanceDraft::get($userId);
        $values = AppearanceDraft::withoutNoops($draft['values']);

        if ($values === []) {
            AppearanceDraft::forget($userId);

            return $this->published($request, __('Nothing to publish.'), null, []);
        }

        $schema = app(SettingsController::class)->saveSchema();
        $errors = [];

        foreach ($values as $key => $value) {
            $message = $value === null ? null : AppearancePages::invalid($key, $value, $schema);

            if ($message !== null) {
                $errors[$key] = $message;
            }
        }

        if ($errors !== []) {
            return $this->failed($request, $errors);
        }

        $meta = ['source' => 'appearance', 'label' => $this->actionLabel($draft['action'] ?? null), 'pages' => array_values(array_unique(array_filter(array_map([AppearancePages::class, 'pageOf'], array_keys($values)))))];
        $result = $this->write($values, $userId, $meta);

        AppearanceDraft::forget($userId);

        return $this->published($request, __('Published. Your visitors see the new design now.'), $result['revision'], $result['changed']);
    }

    /**
     * GET /admin/appearance/history - the last 20 changes of the look (publishes, looks and themes applied, restores,
     * the upgrade snapshot #0 for 30 days), newest first. JSON for the History panel; a plain visit gets a page.
     */
    public function history(Request $request): View|RedirectResponse|JsonResponse
    {
        $items = $this->historyItems();

        if ($this->wantsJson($request)) {
            return response()->json(['items' => $items])->header('Cache-Control', 'private, no-store');
        }

        if (! AdminPreferences::usesNewAdmin($request->user())) {
            return $this->oldTab($request, 'appearance.themes');
        }

        return view('admin.appearance.history', ['items' => $items]);
    }

    /**
     * POST /admin/appearance/history/{revision}/restore - undo a change: the settings go back to what they were before
     * it (its appearance part through AppearanceApplier::restoreRevision, the other settings of a publish by key).
     */
    public function restore(Request $request, int $revision): JsonResponse|RedirectResponse
    {
        $userId = (int) $request->user()->getKey();
        $found = SettingsRevision::find($revision);
        $changed = [];
        $restoredRevision = null;

        if ($found !== null) {
            $pair = (int) ($found['meta']['pair'] ?? 0);
            $parts = array_values(array_filter([$found, $pair > 0 ? SettingsRevision::find($pair) : null]));

            DB::transaction(function () use ($parts, $userId, &$changed, &$restoredRevision): void {
                $made = [];

                foreach ($parts as $part) {
                    $result = $part['restorable']
                        ? AppearanceApplier::restoreRevision((int) $part['id'], $userId)
                        : $this->restoreKeys($part, $userId);

                    if ($result !== null) {
                        $changed = array_merge($changed, $result['changed']);
                        $restoredRevision ??= $result['revision'];

                        if ($result['revision'] !== null) {
                            $made[] = (int) $result['revision'];
                        }
                    }
                }

                // The undo of a publish that wrote several groups is one History line too, undone together.
                if (count($made) === 2) {
                    foreach ($made as $index => $id) {
                        $model = SettingRevision::query()->find($id);
                        $model?->update(['meta' => array_replace((array) $model->meta, ['pair' => $made[1 - $index]])]);
                    }
                }
            });
        }

        if ($found === null || ($restoredRevision === null && $changed === [] && ! $this->isRestorable($found))) {
            $message = __('This change can no longer be undone.');

            return $this->wantsJson($request)
                ? response()->json(['ok' => false, 'message' => $message], 422)
                : redirect()->back()->withErrors(['revision' => $message]);
        }

        $this->flushCaches();
        $message = $changed === [] ? __('Nothing to undo: the settings are already like that.') : __('Undone. Your site shows the earlier design again.');

        if ($this->wantsJson($request)) {
            return response()->json(['ok' => true, 'message' => $message, 'changed' => array_values(array_unique($changed)), 'revision' => $restoredRevision]);
        }

        return redirect()->back()->withSuccess($message);
    }

    /**
     * Publish values: other settings groups first (one revision for them), then the appearance keys through
     * AppearanceApplier (its own revision, which History lists and Undo restores), in one transaction; the two
     * revisions name each other (meta 'pair').
     *
     * @param  array<string, ?string>  $values
     * @return array{changed: list<string>, revision: ?int}
     */
    private function write(array $values, int $userId, array $meta): array
    {
        $appearance = [];
        $delete = [];
        $others = [];

        foreach ($values as $key => $value) {
            $group = AppearanceDraft::groupOf($key);

            if ($group === 'appearance') {
                $value === null ? $delete[] = $key : $appearance[$key] = $value;
            } elseif ($group !== null) {
                $others[$group][$key] = $value;
            }
        }

        $result = DB::transaction(function () use ($appearance, $delete, $others, $userId, $meta): array {
            $changed = [];
            $otherRevision = null;

            if ($others !== []) {
                $before = [];
                $after = [];

                foreach ($others as $group => $rows) {
                    $stored = DB::table('settings')->where('group', $group)->whereIn('key', array_keys($rows))->pluck('value', 'key')->all();

                    foreach ($rows as $key => $value) {
                        $before[$group][$key] = array_key_exists($key, $stored) ? $stored[$key] : null;

                        if ($value === null) {
                            DB::table('settings')->where('group', $group)->where('key', $key)->delete();
                        } else {
                            Setting::set($key, $value, $group);
                        }

                        $after[$group][$key] = $value;

                        if ($before[$group][$key] !== $value) {
                            $changed[] = $key;
                        }
                    }
                }

                if ($changed !== []) {
                    $otherRevision = SettingsRevision::record('publish', implode(',', array_keys($after)), $before, $after, $userId, null, $meta);
                }
            }

            $applied = ['changed' => [], 'revision' => null];

            if ($appearance !== [] || $delete !== []) {
                $applied = AppearanceApplier::applyValues($appearance, 'publish', $userId, $delete, $meta + ($otherRevision !== null ? ['pair' => $otherRevision] : []));
            }

            if ($otherRevision !== null && $applied['revision'] !== null) {
                $model = SettingRevision::query()->find($otherRevision);
                $model?->update(['meta' => array_replace((array) $model->meta, ['pair' => $applied['revision']])]);
            }

            return [
                'changed' => array_values(array_unique(array_merge($applied['changed'], $changed))),
                'revision' => $applied['revision'] ?? $otherRevision,
            ];
        });

        $this->flushCaches();

        return $result;
    }

    /**
     * Undo the settings of another group that a publish of these pages wrote, or that an undo of one put back (their
     * old values back; null: the key was not stored), and record the restore.
     *
     * @return array{changed: list<string>, revision: ?int}|null
     */
    private function restoreKeys(array $revision, int $userId): ?array
    {
        if (! $this->isRestorable($revision)) {
            return null;
        }

        $editable = array_flip(AppearancePages::editableKeys(true));
        $before = [];
        $after = [];
        $changed = [];

        foreach ((array) $revision['before'] as $group => $rows) {
            foreach ((array) $rows as $key => $value) {
                if (! isset($editable[$key]) || AppearanceDraft::groupOf($key) !== $group) {
                    continue;
                }

                $current = DB::table('settings')->where('group', $group)->where('key', $key)->value('value');
                $exists = DB::table('settings')->where('group', $group)->where('key', $key)->exists();
                $before[$group][$key] = $exists ? $current : null;

                if ($value === null) {
                    DB::table('settings')->where('group', $group)->where('key', $key)->delete();
                } else {
                    Setting::set($key, $value, $group);
                }

                $after[$group][$key] = $value;

                if ($before[$group][$key] !== $value) {
                    $changed[] = $key;
                }
            }
        }

        $record = $changed === [] ? null : SettingsRevision::record('restore', implode(',', array_keys($after)), $before, $after, $userId, null, ['source' => 'appearance', 'restored_revision' => $revision['id']]);

        return ['changed' => $changed, 'revision' => $record];
    }

    /**
     * Appearance revisions restore through the applier; the other settings a publish of these pages wrote (or an
     * undo of it put back) restore here.
     */
    private function isRestorable(array $revision): bool
    {
        if (! empty($revision['restorable'])) {
            return true;
        }

        return in_array($revision['kind'] ?? '', ['publish', 'restore'], true)
            && ($revision['meta']['source'] ?? '') === 'appearance'
            && empty($revision['meta']['masked'])
            && ($revision['expires_at'] === null || now()->lt($revision['expires_at']));
    }

    /**
     * The last 20 changes of the design, newest first: the appearance revisions (publishes, looks and themes applied,
     * restores, the upgrade snapshot #0 while it lives) and the other settings these pages published or put back
     * (meta source 'appearance'). Both are read on their own, so saves on other settings pages never push them out
     * of the list. A publish, or its undo, that wrote several settings groups is one line.
     *
     * @return list<array{id: int, kind: string, title: string, detail: string, when: string, ago: string, by: ?string, restorable: bool, snapshot: bool, expires: ?string}>
     */
    private function historyItems(): array
    {
        $rows = [];

        foreach (array_merge(SettingsRevision::latest(2 * SettingsRevision::KEEP, 'appearance'), $this->revisionsOfOtherGroups(2 * SettingsRevision::KEEP)) as $row) {
            $rows[$row['id']] = $row;
        }

        krsort($rows);

        // The other groups' half of a pair is folded into the appearance line (its changes are counted there).
        $partner = [];
        foreach ($rows as $id => $row) {
            $pair = (int) ($row['meta']['pair'] ?? 0);

            if ($pair > 0 && isset($rows[$pair]) && $this->isAppearance($row) && ! $this->isAppearance($rows[$pair])) {
                $partner[$id] = $pair;
            }
        }

        $folded = array_flip($partner);
        $known = $this->withRestoredRevisions($rows);
        $users = User::query()->whereIn('id', array_filter(array_column($rows, 'user_id')))->pluck('username', 'id')->all();
        $items = [];

        foreach ($rows as $id => $row) {
            if (isset($folded[$id])) {
                continue;
            }

            $changed = count($row['changed']) + (isset($partner[$id]) ? count($rows[$partner[$id]]['changed']) : 0);
            $created = $row['created_at'] ? \Illuminate\Support\Carbon::parse($row['created_at']) : null;
            $expires = $row['expires_at'] ? \Illuminate\Support\Carbon::parse($row['expires_at'])->isoFormat('ll') : null;
            $snapshot = $row['kind'] === 'upgrade_snapshot';
            $items[] = [
                'id' => $row['id'],
                'kind' => $row['kind'],
                'title' => $this->historyTitle($row, $known),
                'detail' => $snapshot
                    ? ($expires !== null ? __('Your look as it was before the update. You can go back to it until :date.', ['date' => $expires]) : __('Your look as it was before the update.'))
                    : trans_choice(':count setting changed|:count settings changed', $changed, ['count' => $changed]),
                'when' => $created?->isoFormat('lll') ?? '',
                'ago' => $created?->diffForHumans() ?? '',
                'by' => isset($users[$row['user_id']]) ? (string) $users[$row['user_id']] : null,
                'restorable' => $this->isRestorable($row) && $row['kind'] !== 'upgrade_result',
                'snapshot' => $snapshot,
                'expires' => $expires,
            ];

            if (count($items) >= self::HISTORY_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * Revisions of other settings groups that the Appearance pages wrote (a publish of their discovery, general or
     * users settings, or an undo of one): meta source 'appearance', newest first, shaped like SettingsRevision::latest().
     *
     * @return list<array<string, mixed>>
     */
    private function revisionsOfOtherGroups(int $limit): array
    {
        if (! SettingsRevision::available()) {
            return [];
        }

        try {
            return SettingRevision::query()
                ->whereIn('kind', ['publish', 'restore'])
                ->where('group', 'not like', '%appearance%')
                ->where('meta', 'like', '%appearance%')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderByDesc('id')
                ->limit($limit)
                ->get(['id', 'kind', 'group', 'changed', 'meta', 'user_id', 'expires_at', 'created_at'])
                ->filter(fn (SettingRevision $revision): bool => (((array) ($revision->meta ?? []))['source'] ?? '') === 'appearance')
                ->map(fn (SettingRevision $revision): array => [
                    'id' => (int) $revision->getKey(),
                    'kind' => (string) $revision->kind,
                    'group' => (string) $revision->group,
                    'changed' => (array) ($revision->changed ?? []),
                    'meta' => (array) ($revision->meta ?? []),
                    'restorable' => false,
                    'user_id' => $revision->user_id,
                    'expires_at' => $revision->expires_at?->toIso8601String(),
                    'created_at' => $revision->created_at?->toIso8601String(),
                ])
                ->values()
                ->all();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    private function isAppearance(array $row): bool
    {
        return in_array('appearance', explode(',', (string) $row['group']), true);
    }

    /**
     * The listed revisions plus the ones their undo lines name (two steps back: an undo of an undo), by id: kind and
     * meta are enough to title them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function withRestoredRevisions(array $rows): array
    {
        $known = $rows;

        for ($step = 0; $step < 2; $step++) {
            $missing = [];

            foreach ($known as $row) {
                $id = (int) ($row['meta']['restored_revision'] ?? 0);

                if ($row['kind'] === 'restore' && $id > 0 && ! isset($known[$id])) {
                    $missing[] = $id;
                }
            }

            if ($missing === [] || ! SettingsRevision::available()) {
                break;
            }

            foreach (SettingRevision::query()->whereIn('id', array_unique($missing))->get(['id', 'kind', 'meta']) as $revision) {
                $known[(int) $revision->getKey()] = ['id' => (int) $revision->getKey(), 'kind' => (string) $revision->kind, 'meta' => (array) ($revision->meta ?? [])];
            }
        }

        return $known;
    }

    /**
     * A History line's title; an undo names what it undid ("Undo: Published: Homepage").
     *
     * @param  array<int, array<string, mixed>>  $known
     */
    private function historyTitle(array $row, array $known, int $depth = 0): string
    {
        if ($row['kind'] !== 'restore') {
            return $this->kindTitle($row);
        }

        $original = $known[(int) ($row['meta']['restored_revision'] ?? 0)] ?? null;

        if ($original === null || $depth >= 2) {
            return __('Undo of an earlier change');
        }

        return $original['kind'] === 'upgrade_snapshot'
            ? __('Restored your look from before the update')
            : __('Undo: :what', ['what' => $this->historyTitle($original, $known, $depth + 1)]);
    }

    private function kindTitle(array $row): string
    {
        $label = (string) ($row['meta']['label'] ?? '');

        // A publish without a look or theme names the pages it changed.
        if ($label === '' && is_array($row['meta']['pages'] ?? null)) {
            $pages = (array) config('admin_settings.pages', []);
            $label = implode(', ', array_filter(array_map(fn ($page): string => isset($pages[$page]['label']) ? __((string) $pages[$page]['label']) : '', array_slice($row['meta']['pages'], 0, 3))));
        }

        return match ($row['kind']) {
            'upgrade_snapshot' => __('Your look before the update'),
            'upgrade_result' => __('Update to the new themes'),
            'apply_master' => __('Complete look applied'),
            'apply_theme' => __('Theme applied'),
            'reset' => __('Back to the factory look'),
            'restore' => __('Undo'),
            'settings_save' => __('Saved on a settings page'),
            default => $label !== '' ? __('Published: :what', ['what' => $label]) : __('Published'),
        };
    }

    /**
     * The view data every Appearance page shares: the page, the draft and its changes, the preview monitor.
     */
    private function frame(Request $request, string $pageId, array $draft): array
    {
        $page = (array) (config('admin_settings.pages', [])[$pageId] ?? []);

        return [
            'pageId' => $pageId,
            'page' => $page,
            'draftState' => $this->draftState($request, $draft),
            'monitorPages' => $this->monitorPages($pageId, $draft),
            'shot' => $this->currentShot(),
            'urls' => [
                'draft' => route('admin.appearance.draft'),
                'discard' => route('admin.appearance.draft.discard'),
                'publish' => route('admin.appearance.publish'),
                'history' => route('admin.appearance.history'),
                'gallery' => route('admin.appearance.gallery'),
                'calendar' => route('admin.appearance.calendar'),
                'upload' => route('admin.settings.upload'),
                'values' => route('admin.settings.values'),
            ],
        ];
    }

    /**
     * What the page script needs after every change: the preview links signed for the new draft, the list of changes
     * and the picked look or theme.
     */
    private function draftState(Request $request, array $draft): array
    {
        $userId = (int) $request->user()->getKey();
        $values = $draft['values'];
        $token = $values === [] ? null : AppearanceDraft::sign($userId, $values);
        $changes = [];

        foreach ($values as $key => $value) {
            $page = AppearancePages::pageOf($key);

            if (in_array($key, AppearancePages::SERVER_KEYS, true)) {
                continue;
            }

            $changes[] = [
                'key' => $key,
                'label' => AppearancePages::label($key),
                'page' => $page,
                'page_label' => $page !== null ? __((string) (config('admin_settings.pages', [])[$page]['label'] ?? '')) : '',
                'value' => $value,
            ];
        }

        return [
            'token' => $token,
            'expires_at' => $token !== null ? now()->addMinutes(AppearanceDraft::TOKEN_MINUTES)->toIso8601String() : null,
            'count' => count($changes) + (($draft['action'] ?? null) !== null ? 1 : 0),
            'changes' => $changes,
            'values' => (object) $values,
            'action' => $draft['action'] ?? null,
            'action_label' => $this->actionLabel($draft['action'] ?? null),
        ];
    }

    /**
     * The public pages the monitor offers for a page (preview links: relative, same origin), the first one loads first.
     *
     * @return array<string, string>
     */
    private function monitorPages(string $pageId, array $draft): array
    {
        $token = $draft['values'] === [] ? null : AppearanceDraft::sign((int) auth()->id(), $draft['values']);
        // A video, a category and a channel of this site to preview (kept for 10 minutes: three queries per page view).
        [$video, $category, $channelPath] = AdminCache::remember('appearance.preview_paths', 600, function (): array {
            $channel = User::query()->whereHas('videos')->orderBy('id')->first();

            return [
                $this->sampleVideoPath(),
                Category::query()->orderBy('id')->value('slug'),
                $channel !== null ? (string) parse_url(route('user.show', $channel), PHP_URL_PATH) : '/discover',
            ];
        });

        $pages = match ($pageId) {
            'appearance.video_page' => [$video => __('Video page'), '/' => __('Homepage')],
            'appearance.cards' => ['/' => __('Homepage'), '/trend' => __('Trending'), ($category ? '/categories/'.$category : '/categories') => __('A category')],
            'appearance.homepage', 'appearance.custom_code' => ['/' => __('Homepage'), $video => __('Video page')],
            'appearance.channels' => [$channelPath => __('A channel'), '/' => __('Homepage')],
            'appearance.other_pages' => ['/terms' => __('Info page'), '/premium' => __('Premium page'), '/' => __('Homepage')],
            default => ['/' => __('Homepage'), $video => __('Video page'), ($category ? '/categories/'.$category : '/categories') => __('A category')],
        };

        $signed = [];

        foreach ($pages as $path => $label) {
            if ($path !== '') {
                $signed[AppearanceDraft::previewUrl($path, $token)] = $label;
            }
        }

        return $signed;
    }

    private function sampleVideoPath(): string
    {
        try {
            $video = Video::query()->active()->public()->latest('id')->first();

            if ($video !== null) {
                return (string) parse_url(app(SeoService::class)->videoUrl($video), PHP_URL_PATH);
            }
        } catch (Throwable) {
        }

        return '/trend';
    }

    /**
     * The values a page shows: the draft's (a reset key shows its default; a colour the colour style's) over the
     * published ones.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function shownValues(array $keys, array $draftValues): array
    {
        $appearanceDraft = [];
        $others = [];

        foreach ($draftValues as $key => $value) {
            AppearanceDraft::groupOf($key) === 'appearance' ? $appearanceDraft[$key] = $value : $others[$key] = $value;
        }

        $shown = AppearancePages::published($keys);

        if ($appearanceDraft !== []) {
            $grouped = Setting::getAllGrouped();
            $form = AppearanceSettings::formValues(AppearanceSettings::applyOverrides($grouped['appearance'] ?? [], $appearanceDraft));

            foreach ($keys as $key) {
                if (AppearanceDraft::groupOf($key) === 'appearance' && array_key_exists($key, $form)) {
                    $shown[$key] = AppearancePages::normalize($key, $form[$key]);
                }
            }
        }

        foreach ($others as $key => $value) {
            if (in_array($key, $keys, true) && $value !== null) {
                $shown[$key] = $value;
            }
        }

        return $shown;
    }

    /**
     * The Themes page's "On your site now" card.
     */
    private function currentLook(array $published, array $theme): array
    {
        $look = $published['active_master_experience'] !== '' ? MasterExperienceCatalog::find($published['active_master_experience']) : null;
        $record = ThemeCatalog::theme($theme['theme']);
        $flavor = ThemeCatalog::flavor($theme['theme'], $theme['flavor']);
        $differences = 0;

        if (is_array($look)) {
            $lookValues = AppearancePages::lookValues((string) $look['id']) ?? [];
            $now = AppearancePages::published(array_keys($lookValues));

            foreach ($lookValues as $key => $value) {
                if ($value !== null && ! in_array($key, AppearancePages::SERVER_KEYS, true) && ($now[$key] ?? null) !== AppearancePages::normalize($key, $value)) {
                    $differences++;
                }
            }
        }

        $last = collect(SettingsRevision::latest(10, 'appearance'))->first(fn (array $row): bool => ! in_array($row['kind'], ['upgrade_snapshot', 'upgrade_result'], true));
        $snapshot = SettingsRevision::upgradeSnapshot();

        return [
            'look' => is_array($look) ? __((string) $look['label']) : null,
            'look_id' => is_array($look) ? (string) $look['id'] : null,
            'theme' => __((string) ($record['name'] ?? $theme['theme'])),
            'flavor' => __((string) ($flavor['name'] ?? $theme['flavor'])),
            'differences' => $differences,
            'shot' => $this->thumb(is_array($look) ? (string) $look['id'] : 'themes/'.$theme['theme'].'-'.($record['default_mode'] ?? 'dark')),
            'last' => $last === null ? null : [
                'id' => $last['id'],
                'when' => \Illuminate\Support\Carbon::parse($last['created_at'])->isoFormat('ll'),
                'by_you' => (int) $last['user_id'] === (int) auth()->id(),
                'restorable' => $this->isRestorable($last),
            ],
            'snapshot' => $snapshot === null ? null : [
                'id' => $snapshot['id'],
                // Calendar days in the site time zone, not 24-hour blocks: a clock change inside the 30 days makes one of them
                // 23 or 25 hours long (the stored time carries the offset of its own date).
                'days' => $snapshot['expires_at'] ? max(0, (int) ceil(now()->diffInDays(\Illuminate\Support\Carbon::parse($snapshot['expires_at'])->setTimezone(now()->getTimezone()), false))) : null,
            ],
        ];
    }

    private function lookItem(array $look, string $active): array
    {
        $theme = ThemeCatalog::theme((string) $look['theme']);
        $flavor = ThemeCatalog::flavor((string) $look['theme'], (string) $look['flavor']);

        return [
            'id' => (string) $look['id'],
            'label' => __((string) $look['label']),
            'subtitle' => __((string) ($look['subtitle'] ?? '')),
            'best_for' => __((string) ($look['best_for'] ?? '')),
            'badge' => ($look['badge'] ?? '') !== '' ? __((string) $look['badge']) : null,
            'is_new' => (bool) ($look['is_new'] ?? false),
            'default' => (bool) ($look['factory_default'] ?? false),
            'formerly' => ($look['formerly'] ?? null) ? __('formerly :names', ['names' => $look['formerly']]) : null,
            'theme' => (string) $look['theme'],
            'theme_name' => __((string) ($theme['name'] ?? $look['theme'])),
            'flavor_name' => __((string) ($flavor['name'] ?? $look['flavor'])),
            'colours' => array_values(array_slice((array) ($flavor['seeds'] ?? []), 0, 3)),
            'thumb' => $this->thumb((string) $look['id']),
            'thumb_phone' => $this->thumb((string) $look['id'].'-phone'),
            'current' => $active !== '' && MasterExperienceCatalog::resolveId($active) === $look['id'],
        ];
    }

    /**
     * The 32 themes as gallery cards, filtered by family, mode (light-first or dark-first) and a search that also
     * finds colour styles and old names.
     *
     * @param  array{theme: string, flavor: string}  $current
     * @return list<array<string, mixed>>
     */
    private function themeItems(array $current, string $query = '', string $family = '', string $mode = ''): array
    {
        $matches = null;

        if ($query !== '') {
            $matches = [];

            foreach (ThemeCatalog::search($query) as $hit) {
                $matches[$hit['theme']] ??= $hit;
            }
        }

        $items = [];

        foreach (ThemeCatalog::themes() as $id => $theme) {
            if (($family !== '' && $theme['family'] !== $family) || ($mode !== '' && $theme['default_mode'] !== $mode) || ($matches !== null && ! isset($matches[$id]))) {
                continue;
            }

            $item = $this->themeItem($theme, $current);
            $hit = $matches[$id] ?? null;

            if ($hit !== null && $hit['match'] !== 'name') {
                $item['match'] = $hit['formerly'] !== null
                    ? __('formerly :names', ['names' => $hit['formerly']])
                    : __((string) $hit['label']);
            }

            $items[] = $item;
        }

        return $items;
    }

    private function themeItem(array $theme, array $current): array
    {
        if ($theme === []) {
            return [];
        }

        $id = (string) $theme['id'];
        $formerly = ThemeCatalog::legacyLabels($id);
        $flavors = [];

        foreach ($theme['flavors'] as $flavorId => $flavor) {
            $flavors[] = [
                'id' => (string) $flavorId,
                'name' => __((string) $flavor['name']),
                'colours' => array_values(array_slice((array) $flavor['seeds'], 0, 3)),
                'formerly' => ($flavor['formerly'] ?? []) !== [] ? __('formerly :names', ['names' => implode(', ', $flavor['formerly'])]) : null,
            ];
        }

        return [
            'id' => $id,
            'name' => __((string) $theme['name']),
            'family' => (string) $theme['family'],
            'family_name' => __((string) $theme['family_name']),
            'tagline' => __((string) ($theme['tagline'] ?? '')),
            'mode' => (string) $theme['default_mode'],
            'flavors' => $flavors,
            'formerly' => $formerly !== [] ? __('formerly :names', ['names' => implode(', ', array_slice($formerly, 0, 3))]) : null,
            'thumb' => $this->thumb('themes/'.$id.'-'.$theme['default_mode']),
            'thumb_other' => $this->thumb('themes/'.$id.'-'.($theme['default_mode'] === 'dark' ? 'light' : 'dark')),
            'thumb_phone' => $this->thumb('themes/'.$id.'-'.$theme['default_mode'].'-phone'),
            'current' => $current['theme'] === $id,
            'current_flavor' => $current['theme'] === $id ? $current['flavor'] : null,
        ];
    }

    /**
     * The custom-colour note of the Themes and Logo, colours & fonts pages: how the colour engine shows an owner
     * colour on buttons so the label stays readable (brand_contrast_autofix) - the same derivation as the site's
     * #avd-theme-owner block (ThemeTokens::derive with the owner's colour), in the mode first-time visitors get.
     */
    private function colourNote(array $values): ?array
    {
        $style = AppearanceSettings::resolveThemeFlavor(['theme_preset' => $values['theme_preset'] ?? null, 'theme_flavor' => $values['theme_flavor'] ?? null]);
        $custom = AppearanceSettings::customColours(array_intersect_key($values, array_flip(ThemeCatalog::COLOUR_KEYS)) + ['theme_preset' => $style['theme'], 'theme_flavor' => $style['flavor']]);
        $primary = $custom['primary_color'] ?? null;
        $autofix = AppearancePages::normalize('brand_contrast_autofix', $values['brand_contrast_autofix'] ?? '1') === '1';

        if ($primary === null || ! class_exists(\App\Support\Color\Contrast::class)) {
            return $custom === [] ? null : ['custom' => array_values($custom), 'primary' => null, 'shown' => null, 'label' => 'white', 'autofix' => $autofix];
        }

        try {
            $theme = \App\Support\ThemeTokens::theme($style['theme']);
            $mode = in_array($values['theme'] ?? null, ['dark', 'light'], true) ? $values['theme'] : (string) (ThemeCatalog::theme($style['theme'])['default_mode'] ?? 'dark');
            $tokens = $theme !== null
                ? \App\Support\ThemeTokens::derive($theme, $mode, \App\Support\ThemeTokens::flavor($theme, $style['flavor']), ['brand' => strtolower($primary)], $autofix)
                : [];
            [$fill, $label] = isset($tokens['--avd-brand-fill'], $tokens['--avd-on-brand'])
                ? [$tokens['--avd-brand-fill'], $tokens['--avd-on-brand']]
                : \App\Support\Color\Contrast::labelFill(strtolower($primary));
        } catch (Throwable) {
            [$fill, $label] = [$primary, '#ffffff'];
        }

        return [
            'custom' => array_values($custom),
            'primary' => strtolower($primary),
            'shown' => strtolower((string) $fill) !== strtolower($primary) ? strtolower((string) $fill) : null,
            'label' => strtolower((string) $label) === '#ffffff' ? 'white' : 'dark',
            'autofix' => $autofix,
        ];
    }

    /**
     * The fields of some cards of a page (config/admin_settings.php), with the values the page shows (draft first).
     * The form of each page is built once per request.
     *
     * @param  list<string>  $cardIds
     * @return list<array<string, mixed>>
     */
    private function pageFields(Request $request, string $pageId, array $cardIds, array $draft): array
    {
        static $forms = [];
        $forms[$pageId] ??= app(SettingsPageController::class)->form($request, $pageId);
        $fields = [];

        foreach (array_merge($forms[$pageId]['cards'], $forms[$pageId]['advancedCards']) as $card) {
            if (in_array($card['id'], $cardIds, true)) {
                $fields = array_merge($fields, $card['fields'], $card['more']);
            }
        }

        $shown = $this->shownValues(array_column($fields, 'key'), $draft['values']);

        return array_map(function (array $field) use ($shown, $draft): array {
            $field = $this->normalizeField($field);
            $field['value'] = $shown[$field['key']] ?? $field['value'];
            $field['changed'] = array_key_exists($field['key'], $draft['values']);

            if ($field['key'] === 'daily_design_scope') {
                $field['options'] = ['palette_only' => 'Colours only', 'full' => 'Theme too'];
            }

            return $field;
        }, $fields);
    }

    /**
     * The settings form describes a few Appearance controls it does not draw itself: the effects level keys, pictures
     * without a wireframe and number ranges. They become the plain control that fits, with their choices.
     */
    private function normalizeField(array $field): array
    {
        static $schema = null;
        $key = $field['key'];

        if ($field['control'] === 'effects_level') {
            $field['control'] = $field['type'] === 'bool' ? 'toggle' : ($key === 'motion_level' ? 'segmented' : 'select');
        }

        if (in_array($field['control'], ['picker', 'segmented', 'select', 'radio_cards'], true) && $field['options'] === []) {
            $schema ??= app(SettingsController::class)->saveSchema();
            $field['options'] = AppearancePages::options($key, $schema[$field['group']][$key] ?? null);
        }

        if ($field['control'] === 'picker' && AppearancePages::picker($key) === []) {
            $field['control'] = count($field['options']) > 4 ? 'select' : 'segmented';
        } elseif ($field['control'] === 'segmented' && count($field['options']) > 6 && AppearancePages::picker($key) === []) {
            $field['control'] = 'select';
        }

        if ($field['options'] === [] && in_array($field['control'], ['segmented', 'select', 'radio_cards'], true)) {
            $field['control'] = 'text';
        }

        // The device rule's choices say what each one means (admin spec, Effects & speed).
        if ($key === 'performance_tier_mode' && empty($field['option_help'])) {
            $field['option_help'] = [
                'auto' => __('Each device gets the effects it can handle. Weak phones get still versions.'),
                'rich' => __('Same look everywhere. Old phones may stutter.'),
                'lite' => __('Still, simple look on every device. Fastest.'),
            ];
        }

        return $field;
    }

    private function actionLabel(?array $action): ?string
    {
        if ($action === null) {
            return null;
        }

        if (($action['type'] ?? '') === 'look') {
            $look = MasterExperienceCatalog::find((string) $action['id']);

            return $look ? __((string) $look['label']) : null;
        }

        $theme = ThemeCatalog::theme((string) ($action['id'] ?? ''));
        $flavor = ThemeCatalog::flavor((string) ($action['id'] ?? ''), (string) ($action['flavor'] ?? ''));

        return $theme ? __((string) $theme['name']).($flavor ? ' - '.__((string) $flavor['name']) : '') : null;
    }

    /** A gallery picture (scripts/theme_thumbs.js) when it was rendered, else null. */
    private function thumb(string $name): ?string
    {
        static $files = null;
        $files ??= $this->thumbFiles();

        return isset($files[$name.'.webp']) ? asset(self::THUMBS.'/'.$name.'.webp').'?v='.$files[$name.'.webp'] : null;
    }

    /** @return array<string, string> file => short content version, from the thumbnails' manifest */
    private function thumbFiles(): array
    {
        $manifest = public_path(self::THUMBS.'/manifest.json');
        $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;
        $files = [];

        foreach ((array) ($data['files'] ?? []) as $name => $file) {
            $files[(string) $name] = substr((string) ($file['hash'] ?? ''), 0, 8);
        }

        return $files;
    }

    private function currentShot(): ?string
    {
        $published = AppearancePages::published(['active_master_experience', 'theme_preset', 'theme_flavor']);

        if ($published['active_master_experience'] !== '' && MasterExperienceCatalog::find($published['active_master_experience'])) {
            return $this->thumb((string) MasterExperienceCatalog::resolveId($published['active_master_experience']));
        }

        $theme = AppearanceSettings::resolveThemeFlavor($published)['theme'];

        return $this->thumb('themes/'.$theme.'-'.(ThemeCatalog::theme($theme)['default_mode'] ?? 'dark'));
    }

    /**
     * Admins who switched back to the old admin keep the old settings page at the tab that holds these settings.
     */
    private function oldTab(Request $request, string $pageId): View|RedirectResponse|JsonResponse
    {
        $request->query->set('tab', AppearancePages::OLD_TABS[$pageId] ?? 'appearance');

        return app(SettingsController::class)->index($request);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->query('format') === 'json';
    }

    /**
     * Errors keyed "group.key" like the settings save (I7); a look or theme that does not exist keeps its own key.
     *
     * @param  array<string, string>  $errors
     * @return array<string, list<string>>
     */
    private function keyedErrors(array $errors): array
    {
        $keyed = [];

        foreach ($errors as $key => $message) {
            $group = in_array($key, ['look', 'theme'], true) ? null : AppearanceDraft::groupOf((string) $key);
            $keyed[$group !== null ? $group.'.'.$key : (string) $key] = [$message];
        }

        return $keyed;
    }

    private function failed(Request $request, array $errors): JsonResponse|RedirectResponse
    {
        $keyed = $this->keyedErrors($errors);

        if ($this->wantsJson($request)) {
            return response()->json(['ok' => false, 'message' => __('Nothing was published. Check the highlighted settings.'), 'errors' => $keyed], 422);
        }

        return redirect()->back()->withErrors($keyed)->withInput();
    }

    private function published(Request $request, string $message, ?int $revision, array $changed): JsonResponse|RedirectResponse
    {
        if ($this->wantsJson($request)) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'revision' => $revision,
                'changed' => array_values($changed),
                'undo' => $revision !== null ? route('admin.appearance.restore', ['revision' => $revision]) : null,
            ] + $this->draftState($request, AppearanceDraft::get((int) $request->user()->getKey())));
        }

        return redirect()->back()->withSuccess($message);
    }

    private function flushCaches(): void
    {
        try {
            Setting::clearCache();
            AdminCache::bumpVersion();
        } catch (Throwable) {
        }
    }
}
