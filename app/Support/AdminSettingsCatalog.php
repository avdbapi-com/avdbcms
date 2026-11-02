<?php

namespace App\Support;

/**
 * Where each setting of config/admin_settings.php can be changed, for the admin's searches (Ctrl+K and Settings), the
 * notes of the settings pages and "What moved where" (AVDBCMS 1.2.3: every setting can be found and reached).
 *
 *  - a setting with a row: its page, #<key> (a developer page adds ?setting=<key>, so the page that turns Developer
 *    mode on can name it);
 *  - a setting set by a gallery or picker ("link"): that page part;
 *  - a hidden setting: the setting that replaced it ("see"), the page part that sets it ("link") or its card
 *    ("anchor"), with a one-line hint.
 */
final class AdminSettingsCatalog
{
    /** @return array<string, array> key => setting */
    public static function settings(): array
    {
        return (array) config('admin_settings.settings', []);
    }

    public static function setting(string $key): ?array
    {
        $setting = self::settings()[$key] ?? null;

        return is_array($setting) ? $setting : null;
    }

    /** A page of the catalogue (page ids contain a dot, so they are looked up in the array, not as a config path). */
    public static function page(?string $pageId): ?array
    {
        $page = $pageId === null ? null : (((array) config('admin_settings.pages', []))[$pageId] ?? null);

        return is_array($page) ? $page : null;
    }

    /** Whether the setting is shown only in Developer mode: a developer setting, or one on a developer page. */
    public static function needsDeveloperMode(string $key): bool
    {
        $setting = self::setting($key);

        if ($setting === null || $setting['level'] === 'hidden') {
            return false;
        }

        return $setting['level'] === 'expert' || (self::page($setting['page'])['level'] ?? null) === 'expert';
    }

    /**
     * The address the searches open for a setting, or null when its page cannot be opened on this site (the route is
     * missing, or the operational tools are switched off).
     */
    public static function url(string $key): ?string
    {
        $setting = self::setting($key);

        if ($setting === null) {
            return null;
        }

        if (! empty($setting['link'])) {
            return self::open((string) $setting['link']) ? url((string) $setting['link']) : null;
        }

        if (! empty($setting['see'])) {
            return self::url((string) $setting['see']);
        }

        $page = self::page($setting['page']);
        $base = $page !== null ? self::pageUrl($setting['page'], $page) : null;

        if ($base === null) {
            return null;
        }

        if (! empty($setting['anchor'])) {
            return $base.'#'.$setting['anchor'];
        }

        // A developer page opens as the page that turns Developer mode on: ?setting= lets it name the setting.
        return $base.(($page['level'] ?? null) === 'expert' ? '?setting='.rawurlencode($key) : '').'#'.$key;
    }

    /**
     * The fold that holds a setting on its page (SettingsPageController::form()): 'advanced' when its card is an
     * advanced (or developer) one, which the page folds under "Advanced"; 'more' for an advanced or developer setting
     * of an everyday card ("More options"); null when it shows at once.
     */
    public static function fold(string $key): ?string
    {
        $setting = self::setting($key);

        if ($setting === null) {
            return null;
        }

        $cardLevel = self::page($setting['page'])['cards'][$setting['card']]['level'] ?? 'basic';

        if ($cardLevel !== 'basic') {
            return self::unfoldsAdvanced((string) $setting['page']) ? null : 'advanced';
        }

        return in_array($setting['level'], ['advanced', 'expert'], true) ? 'more' : null;
    }

    /**
     * A page the settings form draws by itself (Settings > Advanced) whose cards are all advanced: they show at once.
     * Folded under "Advanced" they hid the whole page behind one fold, and both searches placed such a setting under
     * "Advanced > Advanced" (R1-156). Pages that show a settings card below their own content (a list) keep the fold.
     */
    public static function unfoldsAdvanced(string $pageId): bool
    {
        $page = self::page($pageId);

        if ($page === null || empty($page['rendered_by']) || (array) ($page['cards'] ?? []) === []) {
            return false;
        }

        foreach ((array) $page['cards'] as $card) {
            if (($card['level'] ?? 'basic') === 'basic') {
                return false;
            }
        }

        return true;
    }

    /** The translated hint of a hidden setting ("An old setting: ..."), or null. */
    public static function hint(string $key): ?string
    {
        $hint = self::setting($key)['hint'] ?? null;

        return is_string($hint) && $hint !== '' ? __($hint) : null;
    }

    /**
     * A setting of a developer page, named by the ?setting= of a search link, when it belongs to that page.
     *
     * @return array{key: string, label: string}|null
     */
    public static function developerSettingOf(?string $pageId, mixed $key): ?array
    {
        if ($pageId === null || ! is_string($key) || ! preg_match('/^[a-z0-9_]{1,80}$/', $key)) {
            return null;
        }

        $setting = self::setting($key);

        if ($setting === null || $setting['page'] !== $pageId || ! self::needsDeveloperMode($key)) {
            return null;
        }

        return ['key' => $key, 'label' => __($setting['label'])];
    }

    /**
     * "What moved where" for the old settings page: each old tab with the pages its settings are on now (most first),
     * how many, and whether a page needs Developer mode.
     *
     * @return list<array{tab: string, label: string, pages: list<array{label: string, url: ?string, count: int, developer: bool}>}>
     */
    public static function oldTabs(bool $developer): array
    {
        $counts = [];

        foreach (self::settings() as $setting) {
            $tab = $setting['old'][0] ?? null;

            if (is_string($tab) && $tab !== '' && $setting['level'] !== 'hidden') {
                $counts[$tab][$setting['page']] = ($counts[$tab][$setting['page']] ?? 0) + 1;
            }
        }

        $rows = [];

        foreach ((array) config('admin_settings.old_tabs', []) as $tab => $info) {
            $pages = [];
            $hidden = false;
            $byPage = $counts[$tab] ?? [];
            arsort($byPage);

            if ($byPage === [] && ! empty($info['page'])) {
                $byPage = [$info['page'] => 0];
            }

            foreach ($byPage as $pageId => $count) {
                $navPage = AdminNav::page($pageId);
                $page = self::page($pageId);

                // A view-only demo account is not offered a closed page (ViewOnlyDemo::hides()).
                if (\App\Http\Middleware\ViewOnlyDemo::hides($navPage['route'] ?? $page['route'] ?? null)) {
                    $hidden = true;

                    continue;
                }

                $label = $navPage['label'] ?? $page['label'] ?? $pageId;
                $isDeveloper = ($navPage['level'] ?? $page['level'] ?? null) === 'expert';
                $url = $navPage !== null ? AdminNav::url($navPage) : ($page !== null ? url($page['url']) : null);
                $section = AdminNav::section($navPage['section'] ?? ($page['section'] ?? ''));

                $pages[] = [
                    'label' => trim(__($section['label'] ?? '').' › '.__($label), ' ›'),
                    'url' => $url,
                    'count' => (int) $count,
                    'developer' => $isDeveloper && ! $developer,
                ];
            }

            if ($pages === [] && $hidden) {
                continue; // every page of the tab is closed for a view-only demo account
            }

            $rows[] = ['tab' => (string) $tab, 'label' => __((string) ($info['label'] ?? $tab)), 'pages' => $pages];
        }

        return $rows;
    }

    /** The page's address: its admin route when the navigation knows it (null when it cannot be opened), else its URL. */
    private static function pageUrl(string $pageId, array $page): ?string
    {
        $navPage = AdminNav::page($pageId);

        if ($navPage !== null) {
            return AdminNav::url($navPage);
        }

        return self::open((string) $page['url']) ? url((string) $page['url']) : null;
    }

    private static function open(string $path): bool
    {
        $route = strtok($path, '?#');

        // A view-only demo account is not offered a closed page (ViewOnlyDemo::hides()).
        return $route !== false && $route !== '' && (! class_exists(\App\Http\Controllers\Admin\SettingsPageController::class)
            || \App\Http\Controllers\Admin\SettingsPageController::canOpen($route))
            && ! \App\Http\Middleware\ViewOnlyDemo::hidesUrl($route);
    }
}
