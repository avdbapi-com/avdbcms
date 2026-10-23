<?php

namespace App\Http\Controllers\Admin;

use App\Enums\VideoStatus;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Services\CaptchaService;
use App\Services\ViewHistoryRetentionService;
use App\Support\AdminIpLock;
use App\Support\AdminPreferences;
use App\Support\PremiumPageSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\In;

/**
 * The redesigned Settings section: the hub ("What do you want to change?") and the nine short settings pages
 * that replace the 19-tab settings page. Every page is drawn from config/admin_settings.php (generated from the
 * admin design spec) and saves through PUT /admin/settings/save, so a page may mix settings groups.
 */
class SettingsPageController
{
    /** URL segment => page id of the pages rendered here (routes/admin/settings.php constrains {page} to these). */
    public const PAGES = [
        'site' => 'settings.site',
        'features' => 'settings.features',
        'members' => 'settings.members',
        'videos' => 'settings.videos',
        'email' => 'settings.email',
        'seo' => 'settings.seo',
        'security' => 'settings.security',
        'maintenance' => 'settings.maintenance',
        'advanced' => 'settings.advanced',
    ];

    /** Where an admin who switched back to the old admin finds a page's settings. */
    public const OLD_TABS = [
        'settings.site' => 'general',
        'settings.features' => 'general',
        'settings.members' => 'users',
        'settings.videos' => 'uploads',
        'settings.email' => 'email',
        'settings.seo' => 'seo',
        'settings.security' => 'security',
        'settings.maintenance' => 'maintenance',
        'settings.advanced' => 'general',
        'money.ads' => 'monetization',
        'money.setup' => 'monetization',
        'money.premium_page' => 'pages',
    ];

    private const SEARCH_LIMIT = 30;

    private const SCRIPT = __DIR__.'/../../../../resources/views/admin/settings/page.js';

    /** Whether this admin sees the redesigned admin (site switch v2 and not switched back to the old one). */
    public static function usesNewAdmin(?User $user): bool
    {
        if (class_exists(AdminPreferences::class)) {
            return AdminPreferences::usesNewAdmin($user);
        }

        return config('admin.ui', 'v2') === 'v2';
    }

    /**
     * Whether an admin address of this site opens a page: some route answers it and, for an operational tool, the
     * tools are switched on here (otherwise that route answers 404). Old /admin/settings?tab=X links only go to
     * their new page when it opens; a page that is not installed (yet) or switched off keeps the old tab.
     */
    public static function canOpen(string $url): bool
    {
        $request = Request::create($url, 'GET');

        // Only admin routes count: the public catch-all route of video addresses answers any path.
        foreach (app('router')->getRoutes()->get('GET') as $route) {
            if (! $route->isFallback && str_starts_with($route->uri().'/', 'admin/') && $route->matches($request)) {
                return ! in_array('admin.ops', $route->gatherMiddleware(), true) || (bool) config('admin.operational_tools_enabled', false);
            }
        }

        return false;
    }

    /** An icon of the admin shell's set, when the shell is installed (nothing otherwise). */
    public static function icon(string $name): string
    {
        return method_exists(\App\Support\AdminTheme::class, 'icon') ? (string) \App\Support\AdminTheme::icon($name) : '';
    }

    /** Passwords and keys whose stored value is never sent to the browser. */
    public static function isWriteOnly(string $key): bool
    {
        $setting = config("admin_settings.settings.{$key}", []);

        return ($setting['secret'] ?? false) === true || ($setting['reveal'] ?? false) === true;
    }

    public function hub(Request $request): View|JsonResponse
    {
        $query = is_string($request->query('q')) ? trim($request->query('q')) : '';
        $results = $query === '' ? [] : $this->search($query, $request->user());

        // The live search asks for the results list alone (it sends X-Requested-With, so check this first).
        if ($request->boolean('partial')) {
            return view('admin.settings.partials.hub-results', ['query' => $query, 'results' => $results]);
        }

        if ($request->expectsJson()) {
            return response()->json(['query' => $query, 'results' => $results]);
        }

        return view('admin.settings.hub', [
            'topics' => config('admin_settings.hub', []),
            'query' => $query,
            'results' => $results,
        ]);
    }

    public function show(Request $request, string $page): View|RedirectResponse
    {
        $data = [];

        if ($page === 'email') {
            $data = [
                'headerActions' => '<a class="ad-btn" href="#test-email">'.self::icon('send').e(__('Send a test email')).'</a>',
                'extrasTop' => ['admin.settings.extras.email-status'],
                'extrasBottom' => ['admin.settings.extras.email-test'],
                'mailer' => (string) config('mail.default', 'smtp'),
            ];
        }

        return $this->render($request, self::PAGES[$page], $data);
    }

    /**
     * Any settings page of config/admin_settings.php (also the Money pages). Expert cards and fields appear only in
     * Developer mode; advanced fields fold under "More options"; passwords and keys are never printed.
     */
    public function render(Request $request, string $pageId, array $data = []): View|RedirectResponse
    {
        if (! self::usesNewAdmin($request->user())) {
            return redirect()->route('admin.settings.index', ['tab' => self::OLD_TABS[$pageId] ?? 'general']);
        }

        // Page ids contain a dot ("settings.email"), so they are looked up in the array, not as a config path.
        $page = config('admin_settings.pages', [])[$pageId] ?? null;
        abort_unless(is_array($page), 404);

        return view('admin.settings.page', array_merge(['page' => $page], $this->form($request, $pageId), $data));
    }

    /**
     * The view data of the settings form of one page of config/admin_settings.php, for
     * resources/views/admin/settings/partials/form.blade.php. Other admin pages that carry a settings card (Premium
     * plans, Languages, Info pages, ...) render it the same way:
     *     @include('admin.settings.partials.form', app(SettingsPageController::class)->form(request(), 'money.plans'))
     *
     * @return array{pageId: string, cards: list<array>, advancedCards: list<array>, hiddenExpert: int, developer: bool,
     *               saveUrl: string, clientIp: string, config: array}
     */
    public function form(Request $request, string $pageId): array
    {
        $page = config('admin_settings.pages', [])[$pageId] ?? ['cards' => []];
        $developer = class_exists(AdminPreferences::class) && AdminPreferences::developerMode($request->user());
        $schema = app(SettingsController::class)->saveSchema();
        $stored = [];

        foreach (Setting::getAllGrouped() as $values) {
            $stored += $values;
        }

        $context = ['ip' => AdminIpLock::clientIp($request)];
        $cards = [];
        $hiddenExpert = 0;

        foreach ($page['cards'] as $cardId => $card) {
            $fields = [];

            foreach ($card['fields'] as $key) {
                $setting = config("admin_settings.settings.{$key}");

                if (! is_array($setting) || $setting['level'] === 'hidden') {
                    continue;
                }

                if ($setting['level'] === 'expert' && ! $developer) {
                    $hiddenExpert++;

                    continue;
                }

                $fields[] = $this->field($key, $setting, $schema[$setting['group']][$key] ?? null, $stored, $context);
            }

            if ($fields === []) {
                continue;
            }

            $cards[] = [
                'id' => $cardId,
                'title' => $card['title'],
                'description' => $card['description'],
                'level' => $card['level'],
                'fields' => array_values(array_filter($fields, fn (array $f): bool => $f['level'] === 'basic' || $card['level'] !== 'basic')),
                'more' => $card['level'] === 'basic'
                    ? array_values(array_filter($fields, fn (array $f): bool => $f['level'] !== 'basic'))
                    : [],
            ];
        }

        return [
            'pageId' => $pageId,
            'cards' => array_values(array_filter($cards, fn (array $c): bool => $c['level'] === 'basic')),
            'advancedCards' => array_values(array_filter($cards, fn (array $c): bool => $c['level'] !== 'basic')),
            'hiddenExpert' => $hiddenExpert,
            'developer' => $developer,
            'saveUrl' => route('admin.settings.save'),
            'clientIp' => $context['ip'],
            'config' => [
                'text' => self::scriptTexts(),
                'reveal' => route('admin.settings.maintenance.reveal'),
                'values' => route('admin.settings.values'),
                'upload' => route('admin.settings.upload'),
            ],
        ];
    }

    /** GET /admin/settings/page.js?v=hash: the settings page script, cached by the browser until it changes. */
    public function script(): Response
    {
        return response(file_get_contents(self::SCRIPT), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    public static function scriptVersion(): string
    {
        return substr(md5_file(self::SCRIPT) ?: '', 0, 12);
    }

    /**
     * GET /admin/settings/values?keys=a,b: the values of long code boxes (ad codes, robots.txt, security headers),
     * loaded after the page so a site with large ad codes keeps a small settings page. Never passwords or keys.
     */
    public function values(Request $request): JsonResponse
    {
        $stored = [];
        foreach (Setting::getAllGrouped() as $values) {
            $stored += $values;
        }

        $values = [];
        $keys = is_string($request->query('keys')) ? explode(',', $request->query('keys')) : [];

        foreach (array_slice($keys, 0, 100) as $key) {
            $setting = config("admin_settings.settings.{$key}");

            if (is_array($setting) && in_array($setting['control'], ['code', 'code_editor'], true) && ! self::isWriteOnly($key)) {
                $values[$key] = array_key_exists($key, $stored) && $stored[$key] !== null
                    ? (string) $stored[$key]
                    : $this->defaultValue($setting);
            }
        }

        return response()->json(['values' => $values])->header('Cache-Control', 'private, no-store');
    }

    /**
     * POST /admin/settings/upload (key, file): stores a picture for a setting shown as an upload field (the sharing
     * picture) on the public disk and answers its address. Nothing is saved here: the page puts the address into
     * the field, so the picture is used once the admin saves the page (and Discard leaves the setting alone).
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'key' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (config('admin_settings.settings.'.$value.'.control') !== 'upload') {
                    $fail(__('This setting cannot be saved here.'));
                }
            }],
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ], [
            'file.image' => __('Choose a picture (JPG, PNG, WebP or GIF).'),
            'file.mimes' => __('Choose a picture (JPG, PNG, WebP or GIF).'),
            'file.max' => __('Use a picture of 5 MB or less.'),
        ]);

        $path = $request->file('file')->store('settings', 'public');
        abort_if($path === false, 500, __('The picture could not be stored.'));

        // A path, not an absolute address: the site turns it into its own address (site_asset_url), so the
        // setting keeps working after a domain change.
        $value = 'storage/'.$path;

        return response()->json(['ok' => true, 'value' => $value, 'preview' => site_asset_url($value)]);
    }

    /**
     * POST /admin/settings/maintenance/reveal: the secret preview link is shown only after the admin types their
     * password again (it lets anyone past the maintenance page).
     */
    public function revealMaintenanceSecret(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $check = self::checkPassword($request);

        if ($check === 'locked') {
            return response()->json([
                'ok' => false,
                'message' => __('Too many tries. Wait a minute and try again.'),
            ], 429);
        }

        if ($check !== 'ok') {
            return response()->json([
                'ok' => false,
                'errors' => ['password' => [__('That is not your password.')]],
                'message' => __('That is not your password.'),
            ], 422);
        }

        $secret = trim((string) (Setting::getGroup('maintenance')['maintenance_secret'] ?? ''), '/');

        return response()->json([
            'ok' => true,
            'secret' => $secret,
            'link' => $secret !== '' ? url('/'.$secret) : null,
            'message' => $secret !== '' ? __('Anyone with this link can see the site during maintenance.') : __('There is no secret link yet. Type one and save.'),
        ]);
    }

    /**
     * Whether the signed-in admin typed their own password again (field `password`) before a sensitive change: the
     * maintenance link, Money > Payment setup keys. 'ok', 'missing', 'wrong', or 'locked' after 5 wrong tries in a
     * minute (one limit per admin for both places). The password is only compared: never logged, flashed or kept.
     *
     * @return 'ok'|'missing'|'wrong'|'locked'
     */
    public static function checkPassword(Request $request): string
    {
        $user = $request->user();
        $limiter = 'admin-password:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            return 'locked';
        }

        $password = $request->input('password');

        if (! is_string($password) || $password === '') {
            return 'missing';
        }

        try {
            $matches = Hash::check($password, (string) $user->getAuthPassword());
        } catch (\Throwable) {
            // A hash this site cannot read (another algorithm): a wrong password, and no trace of the typed one.
            $matches = false;
        }

        if (! $matches) {
            RateLimiter::hit($limiter, 60);

            return 'wrong';
        }

        RateLimiter::clear($limiter);

        return 'ok';
    }

    /** @return array<string, string> the page script's words, in the admin's language */
    private static function scriptTexts(): array
    {
        return [
            'one' => __('1 unsaved change'),
            'many' => __(':count unsaved changes'),
            'saving' => __('Saving...'),
            'save' => __('Save changes'),
            'saved' => __('Changes saved.'),
            'failed' => __('Nothing was saved. Check the highlighted settings.'),
            'offline' => __('Could not reach your site. Nothing was saved.'),
            'cancel' => __('Cancel'),
            'secretSaved' => __('Saved'),
            'secretNotSet' => __('Not set'),
            'secretRemove' => __('Will be removed when you save'),
            'revealSaved' => __('A secret link is saved. It is hidden until you type your password.'),
            'revealNone' => __('No secret link yet.'),
            'keyReplace' => __('Type a new value to replace it'),
            'keyEnds' => __('Saved - ends in :end'),
            'keyPassword' => __('Type your admin password to save payment keys.'),
            'savedNow' => __('Saved now: :value'),
            'defaultIs' => __('Default: :value'),
            'reset' => __('Reset'),
            'uploading' => __('Uploading...'),
            'copied' => __('Link copied.'),
            'wrongPassword' => __('That is not your password.'),
        ];
    }

    /** The view model of one field: its control, current value (never for passwords and keys) and choices. */
    private function field(string $key, array $setting, ?array $rule, array $stored, array $context): array
    {
        $writeOnly = self::isWriteOnly($key);
        $default = $this->defaultValue($setting);
        $value = array_key_exists($key, $stored) && $stored[$key] !== null ? (string) $stored[$key] : $default;

        if ($setting['type'] === 'bool') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            $default = $default === '' ? null : (filter_var($default, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }

        $confirm = isset($setting['confirm']) ? __($setting['confirm']) : null;

        if ($confirm !== null) {
            $confirm = str_replace('{ip}', $context['ip'] !== '' ? $context['ip'] : __('unknown'), $confirm);
        }

        return [
            'key' => $key,
            'group' => $setting['group'],
            'type' => $setting['type'],
            'control' => $setting['control'],
            'level' => $setting['level'],
            'label' => $setting['label'],
            'help' => $setting['help'],
            'suffix' => $setting['suffix'] ?? null,
            'lang' => $setting['lang'] ?? null,
            'name' => "groups[{$setting['group']}][{$key}]",
            'value' => $writeOnly ? '' : $value,
            // "Saved" when a value is in use, whether it was saved here or comes from .env (the default).
            'saved' => $writeOnly && (trim((string) ($stored[$key] ?? '')) !== '' || trim((string) $default) !== ''),
            'secret' => $setting['secret'] ?? false,
            'reveal' => $setting['reveal'] ?? false,
            'public' => $setting['public'] ?? false,
            'default' => $writeOnly ? null : $default,
            'options' => $this->options($key, $setting, $rule),
            'option_help' => $setting['option_help'] ?? [],
            'more_options' => $this->allowedChoices($setting['more_options'] ?? [], $rule),
            'confirm' => $confirm,
            'confirm_when' => $setting['confirm_when'] ?? null,
            'confirm_action' => $setting['confirm_action'] ?? null,
            'required' => $rule !== null && in_array('required', $rule['rules'], true),
            'min' => $this->ruleNumber($rule, 'min'),
            'max' => $this->ruleNumber($rule, 'max'),
        ];
    }

    private function defaultValue(array $setting): ?string
    {
        $default = $setting['default'] ?? null;

        if (is_array($default) && isset($default['config'])) {
            $default = config($default['config'], $default['fallback'] ?? null);
        } elseif (is_array($default) && isset($default['translate'])) {
            $default = __($default['translate']);
        }

        return $default === null || is_array($default) ? '' : (string) $default;
    }

    /** @return array<string, string> value => English label (translated in the view) */
    private function options(string $key, array $setting, ?array $rule): array
    {
        if (! in_array($setting['control'], ['select', 'segmented', 'radio_cards'], true)) {
            return [];
        }

        $options = match (true) {
            $key === 'default_video_status' => array_map('strval', VideoStatus::getActive()),
            $key === 'default_video_language' => ['' => 'Not set'] + Video::languages(),
            $key === 'view_log_mode' => ViewHistoryRetentionService::logModes(),
            $key === 'captcha_provider' => CaptchaService::providers(),
            $key === 'default_locale' => array_map(fn (array $language): string => (string) ($language['label'] ?? ''), (array) config('languages', [])),
            $key === 'timezone' => array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()),
            $key === 'date_format' => collect(['M d, Y', 'd/m/Y', 'Y-m-d', 'd.m.Y', 'm/d/Y'])
                ->mapWithKeys(fn (string $format): array => [$format => now()->format($format)])
                ->all(),
            in_array($key, PremiumPageSettings::iconKeys(), true) => PremiumPageSettings::iconOptions(),
            $key === 'twitter_card_type' => ['summary' => 'Small picture', 'summary_large_image' => 'Large picture', 'player' => 'Video player'],
            $key === 'robots_max_image_preview' => ['none' => 'None', 'standard' => 'Standard', 'large' => 'Large'],
            $key === 'schema_organization_type' => ['Organization' => 'Organization', 'WebSite' => 'Website'],
            $key === 'ad_grid_layout' => ['grid_card' => 'Card in the grid', 'wide_row' => 'Full-width row'],
            str_starts_with($key, 'ad_grid_layout_') => ['inherit' => 'Same as above', 'grid_card' => 'Card', 'wide_row' => 'Full-width row'],
            default => $setting['options'] ?? [],
        };

        if ($options === [] && $rule !== null) {
            foreach ($rule['rules'] as $part) {
                if ($part instanceof In) {
                    foreach (str_getcsv(substr((string) $part, 3)) as $value) {
                        $options[$value] = $value === '' ? 'None' : Str::of($value)->replace(['_', '-'], ' ')->ucfirst()->toString();
                    }
                }
            }
        }

        // The spec names some choices (radio cards); keep only the ones this install can save, in the spec's order.
        if (isset($setting['options'])) {
            $options = $this->allowedChoices($options, $rule);
        }

        return array_map('strval', $options);
    }

    /** @return array<string, string> the choices the setting's validation rule accepts */
    private function allowedChoices(array $choices, ?array $rule): array
    {
        foreach ($rule['rules'] ?? [] as $part) {
            if ($part instanceof In) {
                $allowed = str_getcsv(substr((string) $part, 3));
                $choices = array_filter($choices, fn ($value): bool => in_array((string) $value, $allowed, true), ARRAY_FILTER_USE_KEY);
            }
        }

        return $choices;
    }

    private function ruleNumber(?array $rule, string $name): ?int
    {
        foreach ($rule['rules'] ?? [] as $part) {
            if (is_string($part) && str_starts_with($part, $name.':') && is_numeric(substr($part, strlen($name) + 1))) {
                return (int) substr($part, strlen($name) + 1);
            }
        }

        return null;
    }

    /**
     * Settings whose label, help, old name, card or page match every word of the query (translated text too),
     * plus the search synonyms of the spec ("smtp", "adsense", "dark mode" ...).
     *
     * @return list<array{label: string, where: string, help: string, url: string}>
     */
    private function search(string $query, ?User $user): array
    {
        $developer = class_exists(AdminPreferences::class) && AdminPreferences::developerMode($user);
        $words = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($query)) ?: []));
        $pages = config('admin_settings.pages', []);
        $boost = [];

        foreach (config('admin_settings.synonyms', []) as $term => $targets) {
            if (str_contains(mb_strtolower($query), $term)) {
                foreach ($targets as $target) {
                    $boost[$target] = true;
                }
            }
        }

        $scored = [];

        foreach (config('admin_settings.settings', []) as $key => $setting) {
            if ($setting['level'] === 'hidden' || ($setting['level'] === 'expert' && ! $developer)) {
                continue;
            }

            $page = $pages[$setting['page']] ?? null;
            $card = $page['cards'][$setting['card']]['title'] ?? '';
            $label = mb_strtolower($setting['label'].' '.__($setting['label']));
            $text = mb_strtolower(implode(' ', [
                $label, $setting['help'], __($setting['help']), $setting['old'][1] ?? '', __($card), $card,
                __($page['label'] ?? ''), $page['label'] ?? '', str_replace('_', ' ', $key),
            ]));

            $score = isset($boost[$key]) || isset($boost[$setting['page']]) ? 5 : 0;

            if ($score === 0) {
                foreach ($words as $word) {
                    if (! str_contains($text, $word)) {
                        continue 2;
                    }

                    $score += str_contains($label, $word) ? 3 : 1;
                }
            }

            $scored[] = [$score, [
                'label' => __($setting['label']),
                'where' => trim(__($page['label'] ?? '').' > '.__($card), ' >'),
                'help' => __($setting['help']),
                'url' => ($page['url'] ?? '/admin/settings').'#'.$key,
            ]];
        }

        // Stable: equal scores keep the page and card order of the catalogue.
        usort($scored, fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(fn (array $row): array => $row[1], array_slice($scored, 0, self::SEARCH_LIMIT));
    }
}
