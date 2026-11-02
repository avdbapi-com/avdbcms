<?php

namespace App\Support;

use App\Http\Middleware\ViewOnlyDemo;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Str;
use Throwable;

/**
 * Data of the admin's Ctrl+K search (resources/admin/palette.ts, scored by resources/admin/lib/search.ts): every page
 * and tab this admin can open, every setting at every level (plain label, help, the old label, the setting key and the
 * address that opens it), search synonyms ("smtp", "logo", "Mass Broadcast", "api key"), translated for the admin's
 * language. Members and videos are searched on the server (members(), videos()) as the admin types.
 *
 * Nothing is left out for being a developer one (1.2.3): while Developer mode is off, a developer page or setting is
 * listed with x: 1 ("Developer mode" in the results) and opens a page that says how to see it, with a one-click switch.
 * A hidden setting carries its hint (n) and opens the setting or page part that replaced it.
 *
 * The settings are found by the rule the search of Settings uses too (AdminSettingsSearch): each row carries the same
 * texts (label t, help h, card c, old names o, and, for an admin language other than English, the English ones in e),
 * pageLabels the page names in both languages, and fold whether accents are folded, so "See all N settings" opens
 * Settings with the same N settings. a says which fold holds the setting on its page: 1 "More options", 2 "Advanced".
 *
 * The index is fetched once, the first time the search opens, from a versioned URL (cached by the browser).
 */
final class AdminSearchIndex
{
    private const SETTINGS_FALLBACK = 'admin/lib/settings-index.json';

    /**
     * The shape of the index; a new shape makes a new version, so no browser or cache keeps an older one. 3 (1.2.3 round
     * 1): the settings in catalogue order with their English label, help and card apart (et, eh, ec), so the Ctrl+K
     * search ranks them by the rule of the search of Settings (R1-155); the first tab's name finds its page (R1-157).
     */
    private const FORMAT = 3;

    /**
     * Version of the index for this admin: site address, language, Developer mode, a view-only demo account (it is not
     * offered the closed pages: ViewOnlyDemo::hides()) and the source and translation files.
     */
    public static function version(?User $user = null): string
    {
        $files = [config_path('admin_nav.php'), config_path('admin_settings.php'), resource_path(self::SETTINGS_FALLBACK), resource_path(self::FORMER_NAMES), lang_path(app()->getLocale().'.json'), lang_path('en.json')];
        $stamp = implode('|', array_map(fn (string $file) => is_file($file) ? (string) filemtime($file) : '-', $files));
        $developer = AdminPreferences::developerMode($user) ? 1 : 0;
        $viewOnly = ViewOnlyDemo::active() ? '|view-only' : '';

        return substr(md5(self::FORMAT.'|'.url('/').'|'.app()->getLocale().'|'.$developer.'|'.config('admin.operational_tools_enabled').'|'.(AdminSettingsSearch::foldsAccents() ? 1 : 0).'|'.$stamp.$viewOnly), 0, 12);
    }

    /**
     * @return array{v: string, locale: string, fold: string, sections: array<string, string>, pageUrls: array<string, string>, pageLabels: array<string, list<string>>, pages: list<array>, settings: list<array>, synonyms: array<string, list<string>>, texts: array<string, string>, hub: ?string}
     */
    public static function build(?User $user = null): array
    {
        $version = self::version($user);
        $developer = AdminPreferences::developerMode($user);

        return SafeCache::remember('admin.search_index.'.$version, 3600, function () use ($version, $developer): array {
            $sections = [];
            $pages = [];
            $pageUrls = [];

            foreach ((array) (AdminNav::config()['sections'] ?? []) as $sectionId => $section) {
                $sections[$sectionId] = __($section['label']);
            }

            $oldNames = [];

            foreach ((array) (AdminNav::config()['old_menu'] ?? []) as $entry) {
                $oldNames[$entry['page']][] = Str::afterLast($entry['old'], '> ');
            }

            foreach (AdminNav::pages() as $pageId => $page) {
                if (($url = AdminNav::url($page)) === null) {
                    continue;
                }

                // A developer page while Developer mode is off: found, marked, and it opens the page that turns it on.
                $locked = ! AdminNav::visibleTo($page, $developer);
                $pageUrls[$pageId] = $url;
                $keywords = array_values(array_unique(array_filter(array_merge(
                    array_map(fn (string $old) => Str::afterLast($old, '> '), (array) ($page['replaces'] ?? [])),
                    $oldNames[$pageId] ?? [],
                    // The first tab is the page itself, under its own name ("Settings file" is the Developer zone's,
                    // which the account menu names): the page is found by it too (R1-157).
                    isset($page['tabs'][0]['label']) && $page['tabs'][0]['label'] !== $page['label'] ? [(string) $page['tabs'][0]['label']] : [],
                ))));

                $pages[] = array_filter([
                    'id' => $pageId,
                    't' => __($page['label']),
                    's' => $page['section'],
                    'd' => __($page['description'] ?? ''),
                    'u' => $url,
                    'k' => self::withTranslations($keywords),
                    'i' => AdminNav::config()['sections'][$page['section']]['icon'] ?? 'file',
                    'x' => $locked ? 1 : null,
                ], fn ($value) => $value !== null);

                foreach ((array) ($page['tabs'] ?? []) as $index => $tab) {
                    if ($index === 0 || ($tabUrl = AdminNav::url(['route' => $tab['route'], 'params' => []])) === null) {
                        continue;
                    }

                    $pages[] = array_filter([
                        'id' => $pageId.'#'.$tab['route'],
                        't' => __($tab['label']),
                        'p' => __($page['label']),
                        's' => $page['section'],
                        'd' => __($page['description'] ?? ''),
                        'u' => $tabUrl,
                        'k' => [],
                        'i' => AdminNav::config()['sections'][$page['section']]['icon'] ?? 'file',
                        'x' => $locked ? 1 : null,
                    ], fn ($value) => $value !== null);
                }
            }

            $settings = [];
            $catalogue = (array) config('admin_settings.settings', []);
            $catalogPages = (array) config('admin_settings.pages', []);
            $pageLabels = [];

            // In the catalogue's order (the order the search of Settings goes through), so both searches break ties alike.
            $rowsByKey = [];
            foreach (self::settingRows() as $row) {
                $rowsByKey[(string) ($row['key'] ?? '')] = $row;
            }
            $ordered = $catalogue !== [] ? array_map(fn ($key): array => $rowsByKey[(string) $key] ?? ['key' => (string) $key], array_keys($catalogue)) : array_values($rowsByKey);

            foreach ($ordered as $row) {
                $key = (string) ($row['key'] ?? '');
                $url = $key !== '' ? AdminSettingsSearch::url($key) : null;

                if ($url === null) {
                    // Its page cannot be opened on this site (route missing, operational tools switched off).
                    continue;
                }

                // The texts the search of Settings matches too: the catalogue's (the rows are a copy of it).
                $item = is_array($catalogue[$key] ?? null) ? $catalogue[$key] : [
                    'label' => (string) ($row['label'] ?? ''),
                    'help' => (string) ($row['help'] ?? ''),
                    'page' => (string) ($row['page'] ?? ''),
                    'card' => '',
                    'level' => (string) ($row['level'] ?? 'basic'),
                    'old' => [null, (string) ($row['old'] ?? '')],
                ];
                $level = (string) ($item['level'] ?? 'basic');
                $pageId = (string) ($item['page'] ?? $row['page'] ?? '');
                $catalogPage = is_array($catalogPages[$pageId] ?? null) ? $catalogPages[$pageId] : null;
                $label = (string) ($item['label'] ?? '');
                $help = (string) ($item['help'] ?? '');
                $card = (string) ($catalogPage['cards'][$item['card'] ?? '']['title'] ?? ($row['card'] ?? ''));
                $old = (string) ($item['old'][1] ?? '');

                $setting = [
                    'k' => $key,
                    't' => AdminSettingsSearch::translate($label),
                    'h' => AdminSettingsSearch::translate($help),
                    'p' => $pageId,
                    'c' => AdminSettingsSearch::translate($card),
                    'u' => $url,
                ];

                if ($old !== '' && $old !== $label) {
                    $setting['o'] = self::withTranslations([$old]);
                }

                // The English label, help and card where the admin's language words them differently: the ranking of
                // the search of Settings weighs a label, a help text and a card in both languages (lib/search.ts).
                foreach (['et' => $label, 'eh' => $help, 'ec' => $card] as $field => $english) {
                    if ($english !== '' && $english !== $setting[substr($field, 1)]) {
                        $setting[$field] = $english;
                    }
                }

                // In another language the English texts too (owners follow the English help pages); in English only
                // a text the language file words differently.
                $originals = array_values(array_unique(array_filter([$label, $help, $card], fn (string $text): bool => $text !== '')));
                $originals = array_values(array_filter($originals, fn (string $text): bool => ! in_array($text, [$setting['t'], $setting['h'], $setting['c']], true)));

                if ($originals !== []) {
                    $setting['e'] = $originals;
                }

                if ($catalogPage !== null && ! isset($pageLabels[$pageId])) {
                    $pageLabel = (string) ($catalogPage['label'] ?? '');
                    $pageLabels[$pageId] = array_values(array_unique(array_filter([$pageLabel, AdminSettingsSearch::translate($pageLabel)])));
                }

                // The fold that holds it on its page (shown in the result): 1 "More options", 2 "Advanced".
                $fold = $level === 'hidden' || ! empty($item['link']) || ! empty($item['see']) ? null : AdminSettingsCatalog::fold($key);

                if ($fold !== null) {
                    $setting['a'] = $fold === 'advanced' ? 2 : 1;
                }

                if (! $developer && AdminSettingsCatalog::needsDeveloperMode($key)) {
                    $setting['x'] = 1;
                }

                if ($level === 'hidden' && ($hint = AdminSettingsCatalog::hint($key)) !== null) {
                    $setting['n'] = $hint;
                }

                $settings[] = $setting;
            }

            try {
                $hub = route('admin.settings.index');
            } catch (Throwable) {
                $hub = null;
            }

            return [
                'v' => $version,
                'locale' => app()->getLocale(),
                // How the settings search folds texts and words (AdminSettingsSearch::fold()): 'nfd' takes accents off.
                'fold' => AdminSettingsSearch::foldsAccents() ? 'nfd' : 'plain',
                'sections' => $sections,
                'pageUrls' => $pageUrls,
                'pageLabels' => $pageLabels,
                'pages' => $pages,
                'settings' => $settings,
                'synonyms' => self::synonyms(),
                'texts' => [
                    'developer' => __('Developer mode'),
                    'seeAll' => __('See the 1 setting|See all :count settings'),
                    'seeAllHelp' => __('Opens Settings with this search.'),
                    'advanced' => __('Advanced'),
                    'moreOptions' => __('More options'),
                    // "Page: tab" and "Section: description" the way the admin's language writes a label and its
                    // value (AdminText: "：" in Chinese, " : " in French; 1.2.3 round 3, R3-048)
                    'label' => AdminText::punctuation()['label'],
                ],
                'hub' => $hub,
            ];
        });
    }

    /**
     * The search words of the navigation (config/admin_nav.php) and of the settings catalogue (config/admin_settings.php,
     * which adds the words found later, such as "api key"): term => pages and settings it finds.
     *
     * @return array<string, list<string>>
     */
    public static function synonyms(): array
    {
        $synonyms = [];

        foreach ([(array) (AdminNav::config()['synonyms'] ?? []), (array) config('admin_settings.synonyms', []), self::formerNames()] as $list) {
            foreach ($list as $term => $targets) {
                $term = mb_strtolower((string) $term);
                $synonyms[$term] = array_values(array_unique(array_merge($synonyms[$term] ?? [], array_map('strval', (array) $targets))));
            }
        }

        return $synonyms;
    }

    /** The settings' former names by language (scripts/admin_settings/generate_config.py makes it; R3-071). */
    private const FORMER_NAMES = 'admin/lib/former-names.json';

    /** @var array<string, array<string, array<string, list<string>>>> the file, by its path and state */
    private static array $formerNames = [];

    /**
     * The names the admin of 1.2.2 showed for a setting where 1.2.3 shows another (1.2.3 corrected many translations):
     * those of the admin's language (or $locale), and the English ones in every language, as search words of their
     * setting (R3-071: an Arabic admin who types "قناة التليجرام" finds Telegram channel, first). Only the two searches
     * read them: they are not in the configuration every request loads.
     *
     * @return array<string, list<string>> search word => setting keys
     */
    public static function formerNames(?string $locale = null): array
    {
        $file = resource_path(self::FORMER_NAMES);
        $state = $file.'|'.(is_file($file) ? filemtime($file).'|'.filesize($file) : '-');

        if (! isset(self::$formerNames[$state])) {
            try {
                $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            } catch (Throwable) {
                $data = null;
            }

            self::$formerNames = [$state => is_array($data) ? $data : []];
        }

        $names = [];

        foreach (array_unique(['en', (string) ($locale ?? app()->getLocale())]) as $language) {
            foreach ((array) (self::$formerNames[$state][$language] ?? []) as $term => $keys) {
                $names[(string) $term] = array_values(array_unique(array_merge($names[(string) $term] ?? [], array_map('strval', (array) $keys))));
            }
        }

        return $names;
    }

    /**
     * Members whose name or email contains the text.
     *
     * @return list<array{t: string, d: string, u: string, i: string}>
     */
    public static function members(string $query, int $limit = 5): array
    {
        $match = '%'.addcslashes($query, '%_\\').'%';

        try {
            return User::query()
                ->where(fn ($q) => $q->where('username', 'like', $match)->orWhere('email', 'like', $match))
                ->orderByRaw('CASE WHEN username LIKE ? THEN 0 ELSE 1 END', [addcslashes($query, '%_\\').'%'])
                ->limit($limit)
                ->get(['id', 'username', 'email', 'created_at'])
                ->map(fn (User $user) => [
                    't' => (string) $user->username,
                    'd' => trim($user->email.', '.__('joined :date', ['date' => $user->created_at?->translatedFormat('M j, Y') ?? '-']), ', '),
                    'u' => route('admin.users.edit', $user->id),
                    'i' => strtoupper(mb_substr((string) $user->username, 0, 2)),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Videos whose title contains the text.
     *
     * @return list<array{t: string, d: string, u: string}>
     */
    public static function videos(string $query, int $limit = 5): array
    {
        $match = '%'.addcslashes($query, '%_\\').'%';

        try {
            return Video::query()
                ->where('title', 'like', $match)
                ->latest('id')
                ->limit($limit)
                ->get(['id', 'title', 'created_at'])
                ->map(fn (Video $video) => [
                    't' => Str::limit((string) $video->title, 90),
                    'd' => __('Added :date', ['date' => $video->created_at?->translatedFormat('M j, Y') ?? '-']),
                    'u' => route('admin.videos.edit', $video->id),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Settings rows: config/admin_settings.php (the settings pages' own definitions) when it lists them,
     * else the copy generated from the admin spec.
     *
     * @return list<array{key: string, label: string, help?: string, page: string, card?: string, level?: string, old?: string}>
     */
    public static function settingRows(): array
    {
        $configured = config('admin_settings.search');

        if (is_array($configured) && $configured !== []) {
            return array_values($configured);
        }

        $file = resource_path(self::SETTINGS_FALLBACK);

        try {
            $rows = json_decode((string) file_get_contents($file), true);
        } catch (Throwable) {
            $rows = null;
        }

        return is_array($rows) ? $rows : [];
    }

    /** English words plus their translation in the admin's language (old menu names were translated). */
    private static function withTranslations(array $words): array
    {
        $out = [];

        foreach ($words as $word) {
            $out[] = $word;
            $translated = AdminSettingsSearch::translate((string) $word);

            if ($translated !== $word) {
                $out[] = $translated;
            }
        }

        return array_values(array_unique($out));
    }
}
