<?php

namespace App\Support;

use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Str;
use Throwable;

/**
 * Data of the admin's Ctrl+K search (resources/admin/palette.ts): every page and tab this admin can open,
 * every setting (plain label, help, the old label and the setting key), search synonyms ("smtp", "logo",
 * "Mass Broadcast"), translated for the admin's language. Members and videos are searched on the server
 * (members(), videos()) as the admin types.
 *
 * The index is fetched once, the first time the search opens, from a versioned URL (cached by the browser).
 */
final class AdminSearchIndex
{
    private const SETTINGS_FALLBACK = 'admin/lib/settings-index.json';

    /** Version of the index for this admin: site address, language, Developer mode and the source and translation files. */
    public static function version(?User $user = null): string
    {
        $files = [config_path('admin_nav.php'), config_path('admin_settings.php'), resource_path(self::SETTINGS_FALLBACK), lang_path(app()->getLocale().'.json')];
        $stamp = implode('|', array_map(fn (string $file) => is_file($file) ? (string) filemtime($file) : '-', $files));
        $developer = AdminPreferences::developerMode($user) ? 1 : 0;

        return substr(md5(url('/').'|'.app()->getLocale().'|'.$developer.'|'.config('admin.operational_tools_enabled').'|'.$stamp), 0, 12);
    }

    /**
     * @return array{v: string, sections: array<string, string>, pages: list<array>, settings: list<array>, synonyms: array<string, list<string>>}
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
                if (! AdminNav::visibleTo($page, $developer) || ($url = AdminNav::url($page)) === null) {
                    continue;
                }

                $pageUrls[$pageId] = $url;
                $keywords = array_values(array_unique(array_filter(array_merge(
                    array_map(fn (string $old) => Str::afterLast($old, '> '), (array) ($page['replaces'] ?? [])),
                    $oldNames[$pageId] ?? [],
                ))));

                $pages[] = [
                    'id' => $pageId,
                    't' => __($page['label']),
                    's' => $page['section'],
                    'd' => __($page['description'] ?? ''),
                    'u' => $url,
                    'k' => self::withTranslations($keywords),
                    'i' => AdminNav::config()['sections'][$page['section']]['icon'] ?? 'file',
                ];

                foreach ((array) ($page['tabs'] ?? []) as $index => $tab) {
                    if ($index === 0 || ($tabUrl = AdminNav::url(['route' => $tab['route'], 'params' => []])) === null) {
                        continue;
                    }

                    $pages[] = [
                        'id' => $pageId.'#'.$tab['route'],
                        't' => __($tab['label']),
                        'p' => __($page['label']),
                        's' => $page['section'],
                        'd' => __($page['description'] ?? ''),
                        'u' => $tabUrl,
                        'k' => [],
                        'i' => AdminNav::config()['sections'][$page['section']]['icon'] ?? 'file',
                    ];
                }
            }

            $settings = [];

            foreach (self::settingRows() as $row) {
                $level = $row['level'] ?? 'basic';

                if ($level === 'hidden' || ($level === 'expert' && ! $developer) || ! isset($pageUrls[$row['page'] ?? ''])) {
                    continue;
                }

                $setting = [
                    'k' => $row['key'],
                    't' => __($row['label']),
                    'h' => __($row['help'] ?? ''),
                    'p' => $row['page'],
                    'c' => isset($row['card']) && $row['card'] !== '' ? __($row['card']) : '',
                ];

                if (! empty($row['old'])) {
                    $setting['o'] = self::withTranslations([$row['old']]);
                }

                if ($level !== 'basic') {
                    $setting['a'] = 1;
                }

                $settings[] = $setting;
            }

            return [
                'v' => $version,
                'sections' => $sections,
                'pageUrls' => $pageUrls,
                'pages' => $pages,
                'settings' => $settings,
                'synonyms' => (array) (AdminNav::config()['synonyms'] ?? []),
            ];
        });
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
            $translated = __($word);

            if ($translated !== $word) {
                $out[] = $translated;
            }
        }

        return array_values(array_unique($out));
    }
}
