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
use App\Support\AdminSettingsCatalog;
use App\Support\AdminSettingsSearch;
use App\Support\ChannelCustomizationSettings;
use App\Support\ConfigFiles;
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

    /** Results the settings search lists at once; "N more" says how many more match (a longer query narrows them). */
    private const SEARCH_LIMIT = 60;

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

    private const MB = 1048576;

    private const GB = 1073741824;

    /** Sizes stored in bytes that the pages show and take in MB or GB (the plan upload limits: control "size"). */
    public static function isSize(string $key): bool
    {
        return config("admin_settings.settings.{$key}.control") === 'size';
    }

    /**
     * A stored size (bytes) as its field shows it: GB from 1 GB up when that is a whole number or has at most two
     * decimals ("5 GB", "1.5 GB", "1.2 GB" as typed), else MB ("500 MB", "10000 MB"), rounded to two decimals only
     * when the bytes are no exact number of hundredths of a MB. Empty stays empty (no limit); anything that is not a
     * byte count is left as it is.
     */
    public static function sizeFieldValue(mixed $bytes): string
    {
        $text = trim((string) $bytes);

        if (! preg_match('/^\d{1,19}$/', $text)) {
            return $text;
        }

        $bytes = (int) $text;

        // Hundredths of a GB are no whole number of bytes (only quarters are), so a size typed as "1.2 GB" is stored
        // rounded to the byte (1288490189): within one byte of a whole hundredth of a GB it shows as that GB value
        // again, so the field keeps what the owner typed after a save.
        $gb = round($bytes / self::GB, 2);

        if ($bytes >= self::GB && abs($gb * self::GB - $bytes) < 1) {
            return self::sizeNumber($gb).' GB';
        }

        return self::sizeNumber(round($bytes / self::MB, 2)).' MB';
    }

    /**
     * Bytes from a typed size: "500 MB", "1.5 GB", "2gb", or a number with the unit chosen next to the field ($unit:
     * a form posted without JavaScript sends it separately; MB when there is none). Null when it is no size.
     */
    public static function sizeBytes(mixed $value, mixed $unit = null): ?int
    {
        if (! is_scalar($value) || ! preg_match('/^(\d{1,13}(?:\.\d{1,6})?)\s*(MB|GB|MIB|GIB|M|G)?$/', strtoupper(trim((string) $value)), $match)) {
            return null;
        }

        $name = ($match[2] ?? '') !== '' ? $match[2] : strtoupper(trim(is_scalar($unit) ? (string) $unit : ''));
        $bytes = (float) $match[1] * (str_starts_with($name, 'G') ? self::GB : self::MB);

        return $bytes < PHP_INT_MAX ? (int) round($bytes) : null;
    }

    /**
     * The number and unit of a field value ("500 MB" -> ['500', 'MB']); a bare number takes $unit (the unit choice
     * a form posted without JavaScript sent back), else MB.
     *
     * @return array{0: string, 1: string}
     */
    public static function sizeParts(mixed $value, mixed $unit = null): array
    {
        $text = trim((string) $value);
        $unit = strtoupper(is_scalar($unit) ? trim((string) $unit) : '');
        $fallback = in_array($unit, ['MB', 'GB'], true) ? $unit : 'MB';

        if (preg_match('/^(\S*?)\s*(MB|GB)$/i', $text, $match)) {
            return [$match[1], strtoupper($match[2])];
        }

        return [$text, $fallback];
    }

    private static function sizeNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function hub(Request $request): View|JsonResponse
    {
        $query = is_string($request->query('q')) ? trim(mb_substr($request->query('q'), 0, 200)) : '';
        $all = $query === '' ? [] : self::searchSettings($query, $request->user(), PHP_INT_MAX);
        $results = array_slice($all, 0, self::SEARCH_LIMIT);
        $more = count($all) - count($results);

        // The live search asks for the results list alone (it sends X-Requested-With, so check this first).
        if ($request->boolean('partial')) {
            return view('admin.settings.partials.hub-results', ['query' => $query, 'results' => $results, 'more' => $more]);
        }

        if ($request->expectsJson()) {
            return response()->json(['query' => $query, 'results' => $results, 'more' => $more]);
        }

        return view('admin.settings.hub', [
            'topics' => config('admin_settings.hub', []),
            'query' => $query,
            'results' => $results,
            'more' => $more,
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

        if ($page === 'seo' && ! \App\Http\Middleware\ViewOnlyDemo::isViewOnlyUser($request->user())) {
            // Opening Settings > Google & sharing brings the site's own robots.txt up to date with its settings (round 4,
            // R3M-02): a site updated from 1.2.2 serves the shipped file until then, and a save writes it only when it
            // changes a robots setting. A robots.txt the owner wrote by hand stays as it is; one the site may not write
            // is said on the page (what to do, then open the page again).
            try {
                $failed = \App\Support\RobotsTxt::refresh(null, true)['failed'];
            } catch (\Throwable $e) {
                report($e);
                $failed = ['public/robots.txt'];
            }

            if ($failed !== []) {
                $data = ['extrasTop' => ['admin.settings.extras.robots-status'], 'robotsWarning' => \App\Support\RobotsTxt::warning($failed)];
            }
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
     * While Developer mode is off, the developer settings of the page are not editable here; developerOnly lists them
     * by card (label and help only, never a value), so the page can say they appear in Developer mode, with a one-click
     * switch, and a search link to one of them (#key) still finds its row.
     *
     * @return array{pageId: string, cards: list<array>, advancedCards: list<array>, hiddenExpert: int,
     *               developerOnly: list<array{id: string, title: string, fields: list<array{key: string, label: string, help: string}>}>,
     *               developer: bool, saveUrl: string, clientIp: string, config: array}
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
        $developerOnly = [];

        foreach ($page['cards'] as $cardId => $card) {
            $fields = [];

            foreach ($card['fields'] as $key) {
                $setting = config("admin_settings.settings.{$key}");

                // A value of the .env file with a form of its own (Money > Payment setup's keys) is only listed for the
                // searches: this form never draws or saves it.
                if (! is_array($setting) || $setting['level'] === 'hidden' || ! empty($setting['env'])) {
                    continue;
                }

                if ($setting['level'] === 'expert' && ! $developer) {
                    $hiddenExpert++;
                    $developerOnly[$cardId] ??= ['id' => (string) $cardId, 'title' => $card['title'], 'fields' => []];
                    $developerOnly[$cardId]['fields'][] = ['key' => $key, 'label' => $setting['label'], 'help' => $setting['help']];

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

        // A settings page whose cards are all advanced (Settings > Advanced) shows them at once, unfolded (R1-156).
        $unfold = AdminSettingsCatalog::unfoldsAdvanced($pageId);

        return [
            'pageId' => $pageId,
            'cards' => array_values(array_filter($cards, fn (array $c): bool => $unfold || $c['level'] === 'basic')),
            'advancedCards' => $unfold ? [] : array_values(array_filter($cards, fn (array $c): bool => $c['level'] !== 'basic')),
            'hiddenExpert' => $hiddenExpert,
            'developerOnly' => array_values($developerOnly),
            'developer' => $developer,
            'saveUrl' => route('admin.settings.save'),
            'clientIp' => $context['ip'],
            'config' => [
                'text' => self::scriptTexts(),
                'reveal' => route('admin.settings.maintenance.reveal'),
                'values' => route('admin.settings.values'),
                'upload' => route('admin.settings.upload'),
                // the long values arrive after the page as a background GET (it leaves the session as it is)
                'background' => \App\Http\Middleware\BackgroundRequest::HEADER,
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
            // the save bar's count: the whole plural line, the page script picks the form (window.adminPlural)
            'changes' => __('1 unsaved change|:count unsaved changes'),
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

        // the default accent of creator channels shows what channels use: the untouched "coral" of a site installed
        // before 1.2.3 is the look's colour (a page posted whole then keeps that, instead of turning it into a choice)
        if ($key === 'channel_default_accent') {
            $value = ChannelCustomizationSettings::adminAccentValue($value);
        }

        // the Support / Alerts e-mail: while it is empty or still a sample address of the package, the site uses .env's
        // address, else the contact e-mail (round 3 R3-006): the field and its default show that address, never one the
        // site does not use (IN-RV-03); nothing stored changes until the admin saves another
        if (isset(\App\Support\SiteMailAddress::ENV_KEYS[$key])) {
            $default = \App\Support\SiteMailAddress::fallback($key, $stored['contact_email'] ?? null) ?? $default;
            $value = \App\Support\SiteMailAddress::shownValue($key, $value, $stored['contact_email'] ?? null);
        }

        if ($setting['type'] === 'bool') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
            $default = $default === '' ? null : (filter_var($default, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }

        // Sizes are stored in bytes and shown in MB or GB ("500 MB"), the default too (Default: ... Reset).
        if ($setting['control'] === 'size') {
            $value = self::sizeFieldValue($value);
            $default = $default === null ? null : self::sizeFieldValue($default);
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

        // the configuration files' value, not the admin's (R2A-055: config() holds the saved value at this point, so the
        // Site name's 'Default: ... Reset' showed the saved name and Reset changed nothing)
        if (is_array($default) && isset($default['config'])) {
            $default = ConfigFiles::value($default['config'], $default['fallback'] ?? null);
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
            // today in each format, as the site shows dates: in the admin's language (R2A-055: English month names)
            $key === 'date_format' => collect(['M d, Y', 'd/m/Y', 'Y-m-d', 'd.m.Y', 'm/d/Y'])
                ->mapWithKeys(fn (string $format): array => [$format => now()->locale(app()->getLocale())->translatedFormat($format)])
                ->all(),
            in_array($key, PremiumPageSettings::iconKeys(), true) => PremiumPageSettings::iconOptions(),
            $key === 'twitter_card_type' => ['summary' => 'Small picture', 'summary_large_image' => 'Large picture', 'player' => 'Video player'],
            $key === 'robots_max_image_preview' => ['none' => 'None', 'standard' => 'Standard', 'large' => 'Large'],
            $key === 'schema_organization_type' => ['Organization' => 'Organization', 'WebSite' => 'Website'],
            $key === 'ad_grid_layout' => ['grid_card' => 'Card in the grid', 'wide_row' => 'Full-width row'],
            // the creator channel defaults (Appearance > Channels): the names channels use, 'Look colour' and 'Custom
            // color' for the accent (R2A-006: 'Look' and the generic 'Custom' made from the rule's values)
            str_starts_with($key, 'channel_default_') && isset(ChannelCustomizationSettings::options()[substr($key, 16)]) => ChannelCustomizationSettings::options()[substr($key, 16)],
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
     * Settings that match the query by the rule both admin searches share (App\Support\AdminSettingsSearch: every word
     * in the setting's key, label, old name, help, card or page, English or translated, or a search word such as
     * "smtp" or "api key" made for it or its page): every setting at every level, so Ctrl+K's "See all N settings"
     * opens exactly these N. An exact key comes first, then an exact label, an exact old name, a label that starts with
     * the query, an exact help text, then the settings whose label, card or help holds the query; equal scores keep the
     * catalogue's page and card order. A developer setting while Developer mode is off carries the note "Developer
     * mode" and opens its page, which says how to see it; a hidden one carries its hint (shown instead of its help) and
     * opens the setting or page part that replaced it. Settings whose page cannot be opened on this site are left out.
     * "where" names the fold that holds the setting on its page: "Advanced" (an advanced card) or "More options".
     *
     * @return list<array{key: string, label: string, where: string, help: string, url: string, note: ?string, developer: bool, hidden: bool}>
     */
    public static function searchSettings(string $query, ?User $user, int $limit = self::SEARCH_LIMIT): array
    {
        $developer = AdminSettingsSearch::developer($user);
        $folded = AdminSettingsSearch::fold($query);
        $words = AdminSettingsSearch::words($folded);

        if ($words === []) {
            return [];
        }

        $targets = AdminSettingsSearch::targets($words);
        // A search word that is the whole query names the setting the owner means ("premium", "smtp"): first.
        $exact = AdminSettingsSearch::exactTargets($folded);
        $scored = [];
        $position = 0;

        foreach (AdminSettingsSearch::entries() as $entry) {
            $position++;

            if (! AdminSettingsSearch::matches($entry, $words, $targets)) {
                continue;
            }

            $key = $entry['key'];
            $setting = $entry['setting'];
            $score = 0;

            // A key typed as it is comes first; a key that is a plain word ("theme") is no key search.
            if ($entry['keyText'] === $folded && str_contains($key, '_')) {
                $score = 1000;
            } elseif (in_array($folded, $entry['labels'], true)) {
                $score = 500;
            } elseif (in_array($folded, $entry['olds'], true)) {
                $score = 400;
            } elseif (self::startsWithAny($entry['labels'], $folded)) {
                $score = 300;
            } elseif (in_array($folded, $entry['helps'], true)) {
                $score = 250;
            } elseif (AdminSettingsSearch::wordsMatch($entry, $words)) {
                foreach ($words as $word) {
                    $score += self::containsAny($entry['labels'], $word) ? 3 : 1;
                }

                if (in_array($folded, $entry['cards'], true)) {
                    // The card's own name: its settings first (in Chinese or Japanese a card's name is also part of many
                    // labels, which put them out of sight). lib/search.ts settingRank() has the same rule.
                    $score += 110;
                } elseif (self::containsAny(array_merge($entry['labels'], $entry['olds']), $folded)) {
                    $score += 100;
                } elseif (self::containsAny($entry['cards'], $folded)) {
                    $score += 70;
                } elseif (self::containsAny($entry['helps'], $folded)) {
                    $score += 50;
                }
            }

            if (isset($exact[$key])) {
                $score += 600;
            } elseif (isset($targets[$key])) {
                $score += 150;
            } elseif (isset($targets[(string) $setting['page']])) {
                $score += 20;
            }

            if (($url = AdminSettingsSearch::url($key)) === null) {
                continue;
            }

            $locked = ! $developer && AdminSettingsCatalog::needsDeveloperMode($key);
            $hidden = $setting['level'] === 'hidden';
            // A little lower: developer settings while Developer mode is off, and hidden ones.
            $score -= $locked ? 2 : ($hidden ? 5 : 0);

            $scored[] = [$score, $position, [
                'key' => $key,
                'label' => AdminSettingsSearch::translate((string) $setting['label']),
                'where' => self::searchWhere($entry, $developer),
                'help' => AdminSettingsSearch::translate((string) $setting['help']),
                'url' => $url,
                'note' => $hidden ? AdminSettingsCatalog::hint($key) : ($locked ? __('Developer mode') : null),
                'developer' => $locked,
                'hidden' => $hidden,
            ]];
        }

        usort($scored, fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return array_map(fn (array $row): array => $row[2], array_slice($scored, 0, max(1, $limit)));
    }

    /**
     * Where a search result lives on its page: "Page > Card", with the fold that holds it ("Page > Advanced > Card" for
     * an advanced card, "Page > Card > More options" for an advanced setting of an everyday card). While Developer mode
     * is off a developer setting has no fold (its page lists it under "Settings for developers").
     */
    private static function searchWhere(array $entry, bool $developer): string
    {
        $setting = $entry['setting'];
        $page = $entry['page'];
        $card = $page['cards'][$setting['card']] ?? null;
        $parts = [AdminSettingsSearch::translate((string) ($page['label'] ?? ''))];
        $locked = ! $developer && AdminSettingsCatalog::needsDeveloperMode($entry['key']);
        $fold = $locked || $setting['level'] === 'hidden' || ! empty($setting['link']) || ! empty($setting['see']) ? null : AdminSettingsCatalog::fold($entry['key']);

        if ($fold === 'advanced') {
            $parts[] = __('Advanced');
        }

        $parts[] = AdminSettingsSearch::translate((string) ($card['title'] ?? ''));

        if ($fold === 'more') {
            $parts[] = __('More options');
        }

        return implode(' > ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /** @param list<string> $haystacks */
    private static function startsWithAny(array $haystacks, string $needle): bool
    {
        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $haystacks */
    private static function containsAny(array $haystacks, string $needle): bool
    {
        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
