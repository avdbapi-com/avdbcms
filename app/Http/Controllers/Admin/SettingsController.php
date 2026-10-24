<?php

namespace App\Http\Controllers\Admin;

use App\Data\NotificationBroadcastSettings;
use App\Data\UploadSettings;
use App\Enums\VideoStatus;
use App\Http\Requests\Admin\SettingsSaveRequest;
use App\Models\Language;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use App\Models\Comment;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Playlist;
use App\Services\AdaptiveCreatorSpotlightService;
use App\Services\AdaptiveTrendingCollectionsService;
use App\Services\CreatorQualityPulseService;
use App\Services\CreatorRecoveryChecklistService;
use App\Services\CaptchaService;
use App\Services\PlaybackProgressSignalService;
use App\Services\SeoService;
use App\Services\SearchHistoryRetentionService;
use App\Services\SmartDiscoveryRailService;
use App\Services\StudioHealthAlertsService;
use App\Services\ViewHistoryRetentionService;
use App\Support\AdminIpLock;
use App\Support\AppearanceApplier;
use App\Support\AppearanceSettings;
use App\Support\AdminCache;
use App\Support\SettingsRevision;
use App\Support\ThemeCatalog;
use App\Support\MasterExperienceCatalog;
use App\Support\DailyDesignCatalog;
use App\Support\ChannelCustomizationSettings;
use App\Support\FooterSettings;
use App\Support\HomeWidgetVideoSettings;
use App\Support\NavigationSettings;
use App\Support\PremiumPageSettings;
use App\Support\RuntimeEnv;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class SettingsController
{
    /** Theme catalogue v2 bookkeeping (which old theme a site came from, the upgrade state): no admin page edits them. */
    private const NOT_SAVED_BY_PAGES = ['theme_preset_legacy', 'suggested_master_upgrade', 'appearance_schema_version'];

    public function index(Request $request): View|RedirectResponse|JsonResponse
    {
        if (SettingsPageController::usesNewAdmin($request->user())) {
            // Old links such as /admin/settings?tab=email open the page that holds those settings now.
            // 302, never 301: browsers keep a 301 forever, which would break switching back to the old admin.
            $tab = $request->query('tab');
            $target = is_string($tab) ? (config('admin_settings.tab_redirects', [])[$tab] ?? null) : null;

            if (! is_string($target) || $target === '') {
                return app(SettingsPageController::class)->hub($request);
            }

            if (SettingsPageController::canOpen($target)) {
                return redirect()->to($target, 302);
            }

            // That page is not on this site (not installed yet, or its tool is switched off here): the old tab is.
        }

        $schema = $this->settingsSchema();
        $groups = array_keys($schema);
        $settings = [];
        foreach ($groups as $group) {
            $settings[$group] = Setting::getGroup($group);
        }

        $env = $this->environmentSnapshot();
        $activeVideos = $this->cachedSystemMetric(
            'active_videos.v2',
            fn () => $this->activeVideoMetricQuery()->count('videos.id')
        );
        $activeVideosWithThumbnails = $this->cachedSystemMetric(
            'active_videos_with_thumbnails.v2',
            fn () => $this->activeVideoMetricQuery()
                ->whereExists(fn (QueryBuilder $query) => $query
                    ->selectRaw('1')
                    ->from('thumbnails')
                    ->whereColumn('thumbnails.video_id', 'videos.id')
                    ->where('thumbnails.is_active', true))
                ->count('videos.id')
        );
        $activeVideosWithDescriptions = $this->cachedSystemMetric(
            'active_videos_with_descriptions.v2',
            fn () => $this->estimateActiveVideosWithDescriptions((int) $activeVideos)
        );

        $systemInfo = [
            'php_version'     => PHP_VERSION,
            'laravel_version' => app()->version(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
            'db_size'         => $this->cachedSystemMetric('db_size', fn() => $this->getDatabaseSize()),
            'storage_used'    => $this->cachedSystemMetric('storage_used', fn() => $this->getStorageUsed(), 30),
            'total_users'     => $this->cachedSystemMetric('total_users', fn() => User::count()),
            'total_videos'    => $this->cachedSystemMetric('total_videos', fn() => Video::count()),
            'active_videos'   => $activeVideos,
            'active_videos_with_thumbnails' => $activeVideosWithThumbnails,
            'active_videos_with_descriptions' => $activeVideosWithDescriptions,
            'description_coverage_estimated' => true,
            'total_comments'  => $this->cachedSystemMetric('total_comments', fn() => Comment::count()),
            'total_categories'=> $this->cachedSystemMetric('total_categories', fn() => Category::count()),
            'total_plans'     => $this->cachedSystemMetric('total_plans', fn() => Plan::count()),
            'plans_missing_stripe' => $this->cachedSystemMetric('plans_missing_stripe', fn() => Plan::query()
                ->whereNull('stripe_id')
                ->orWhere('stripe_id', '')
                ->count()),
            'plans_missing_paypal' => $this->cachedSystemMetric('plans_missing_paypal', fn() => Plan::query()
                ->whereNull('paypal_plan_id')
                ->orWhere('paypal_plan_id', '')
                ->count()),
            'total_subs'      => $this->cachedSystemMetric('total_subs', fn() => Subscription::count()),
            'total_playlists' => $this->cachedSystemMetric('total_playlists', fn() => Playlist::count()),
            'disk_free'       => $this->getDiskFreeSpace(),
            'memory_limit'    => ini_get('memory_limit'),
            'max_upload'      => ini_get('upload_max_filesize'),
            'max_post'        => ini_get('post_max_size'),
            'opcache'         => function_exists('opcache_get_status') ? 'Enabled' : 'Disabled',
            'settings_groups_count' => count($groups),
            'settings_fields_count' => array_sum(array_map('count', $schema)),
            'config_cached'   => app()->configurationIsCached(),
            'routes_cached'   => method_exists(app(), 'routesAreCached') ? app()->routesAreCached() : false,
            'events_cached'   => method_exists(app(), 'eventsAreCached') ? app()->eventsAreCached() : false,
            'compiled_views'  => count(glob(storage_path('framework/views/*.php')) ?: []),
            'storage_public_link' => file_exists(public_path('storage')),
            'storage_writable' => is_writable(storage_path()),
            'framework_cache_writable' => is_writable(storage_path('framework/cache')),
            'bootstrap_cache_writable' => is_writable(base_path('bootstrap/cache')),
            'latest_log_error' => $this->getLatestLogError(),
        ];

        return view('admin.settings.index', [
            'settings' => $settings,
            'systemInfo' => $systemInfo,
            'env' => $env,
            'editableGroups' => $groups,
            'smartDiscoveryDefaults' => SmartDiscoveryRailService::settingsDefaults(),
            'creatorSpotlightDefaults' => AdaptiveCreatorSpotlightService::settingsDefaults(),
            'trendingCollectionsDefaults' => AdaptiveTrendingCollectionsService::settingsDefaults(),
            'homeFeedSources' => AppearanceSettings::homeFeedSources(),
            'homeFeedLoadModes' => AppearanceSettings::homeFeedLoadModes(),
            'homeFeedCategories' => Category::query()->orderBy('title')->get(['id', 'title']),
            'homeWidgetVideoDefaults' => HomeWidgetVideoSettings::defaults(),
            'premiumPageDefaults' => PremiumPageSettings::defaults(),
            'premiumPageIconOptions' => PremiumPageSettings::iconOptions(),
            'playbackProgressDefaults' => PlaybackProgressSignalService::settingsDefaults(),
            'creatorQualityPulseDefaults' => CreatorQualityPulseService::settingsDefaults(),
            'studioHealthAlertsDefaults' => StudioHealthAlertsService::settingsDefaults(),
            'creatorRecoveryChecklistDefaults' => CreatorRecoveryChecklistService::settingsDefaults(),
            'channelCustomizationDefaults' => ChannelCustomizationSettings::defaults(),
            'channelCustomizationOptions' => ChannelCustomizationSettings::options(),
            'searchHistoryRetentionDefaults' => SearchHistoryRetentionService::settingsDefaults(),
            'uploadDefaults' => UploadSettings::settingsDefaults(),
            'uploadVideoStatusOptions' => VideoStatus::getActive(),
            'uploadVideoLanguages' => Video::languages(),
            'notificationBroadcastDefaults' => NotificationBroadcastSettings::settingsDefaults(),
            'viewHistoryDefaults' => ViewHistoryRetentionService::settingsDefaults(),
            'viewLogModes' => ViewHistoryRetentionService::logModes(),
            'seoTemplateDefaults' => SeoService::templateDefaults(),
            'seoTemplateGroups' => SeoService::templateGroups(),
            'themePresets' => AppearanceSettings::themePresets(),
            'themeCategories' => ThemeCatalog::categories(),
            'themePresetsByCategory' => ThemeCatalog::byCategory(),
            'videoCardDurationStyles' => AppearanceSettings::videoCardDurationStyles(),
            'categoryCardStyles' => AppearanceSettings::categoryCardStyles(),
            'creatorAvatarRingStyles' => AppearanceSettings::creatorAvatarRingStyles(),
            'headerStyles' => AppearanceSettings::headerStyles(),
            'backToTopStyles' => AppearanceSettings::backToTopStyles(),
            'ambientGlowIntensities' => AppearanceSettings::ambientGlowIntensities(),
            'homeLayoutModes' => AppearanceSettings::homeLayoutModes(),
            'heroLayoutModes' => AppearanceSettings::heroLayoutModes(),
            'watchLayoutModes' => AppearanceSettings::watchLayoutModes(),
            'videoCardLayoutStyles' => AppearanceSettings::videoCardLayoutStyles(),
            'modalPresentationStyles' => AppearanceSettings::modalPresentationStyles(),
            'motionEngineLevels' => AppearanceSettings::motionEngineLevels(),
            'backgroundAtmosphereEffects' => AppearanceSettings::backgroundAtmosphereEffects(),
            'typographyPairings' => AppearanceSettings::typographyPairings(),
            'videoPlayerCinemaFilters' => AppearanceSettings::videoPlayerCinemaFilters(),
            'borderBeamSpeeds' => AppearanceSettings::borderBeamSpeeds(),
            'masterExperiences' => MasterExperienceCatalog::all(),
            'dailyDesignCatalog' => DailyDesignCatalog::all(),
            'dailyDesignToday' => DailyDesignCatalog::today(),
            'dailyDesignMonths' => DailyDesignCatalog::months(),
            'dailyDesignScopes' => AppearanceSettings::dailyDesignScopes(),
        ]);
    }

    public function updateEnv(Request $request): RedirectResponse
    {
        return redirect()
            ->route('admin.env-editor.index')
            ->withSuccess('Environment values are managed in Env Editor. Site Settings only saves runtime database settings.');
    }

    public function update(Request $request): RedirectResponse
    {
        return redirect()
            ->route('admin.settings.index')
            ->withSuccess('Choose a settings tab and save that group.');
    }

    public function updateGroup(Request $request, string $group): JsonResponse|RedirectResponse
    {
        $schema = $this->settingsSchema();

        if (!isset($schema[$group])) {
            $message = 'This settings group is not editable.';

            if ($request->wantsJson() || $request->ajax() || $request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'success' => false,
                    'message' => $message,
                    'group' => $group,
                ], 422);
            }

            return redirect()
                ->route('admin.settings.index')
                ->withErrors(['group' => $message]);
        }

        $fields = $schema[$group];
        $rules = [];

        foreach ($fields as $key => $field) {
            $rules[$key] = $field['rules'];
        }

        $validated = $request->validate($rules);

        // Remember which preset was active BEFORE this save so we can tell a real
        // preset switch apart from a plain edit of other appearance fields. A preset
        // that was never stored (fresh install) is the default the form displays and
        // posts back, so a plain Save must not read as a switch to it. With the theme
        // catalogue v2 that default depends on the stored rows (v1 until "Finish update").
        $storedAppearance = $group === 'appearance' ? Setting::getGroup('appearance') : [];
        $previousThemePreset = $group === 'appearance'
            ? (string) ($storedAppearance['theme_preset'] ?? (method_exists(AppearanceSettings::class, 'fallbacks')
                ? AppearanceSettings::fallbacks($storedAppearance)
                : AppearanceSettings::defaults())['theme_preset'] ?? '')
            : '';

        foreach ($fields as $key => $field) {
            // Settings added after the old settings page are written only when the request sends them, so old
            // clients (that page, fleet scripts) keep writing exactly the keys they wrote before.
            if (! empty($field['only_when_sent']) && ! $request->exists($key)) {
                continue;
            }

            $value = ($field['type'] ?? 'string') === 'bool'
                ? $request->input($key, false)
                : ($validated[$key] ?? '');

            $this->storeSetting($group, $key, $this->normalizeSettingValue($group, $key, $field, $value));
        }

        if ($group === 'appearance') {
            // `mobile_bottom_nav_enabled` is the canonical public setting. Keep
            // the older dock key synchronized so old exports and integrations do
            // not silently diverge from the navigation rendered by the layout.
            $this->syncMobileNavigationSettings($request);
        }

        if ($group === 'appearance' && $request->boolean('apply_theme_preset') && $request->filled('theme_preset')) {
            $presetKey = (string) $request->input('theme_preset');
            // Only re-apply the full preset contract when the admin actually switched
            // presets. Re-applying on every Save silently reverted edits made in the
            // same form (Default Theme: Light, colors, header/home layout, ...) because
            // the "Sync Styles on Save" checkbox is on by default.
            if ($presetKey !== $previousThemePreset && isset(AppearanceSettings::themePresets()[$presetKey])) {
                $this->applyAppearancePreset($presetKey, true);
            }
        }

        $this->flushSettingsCaches();

        if ($request->wantsJson() || $request->ajax() || $request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'success' => true,
                'message' => ucfirst($group) . ' settings saved in real-time.',
                'group' => $group,
                'timestamp' => now()->toIso8601String(),
                'formatted_time' => now()->format('H:i:s'),
            ]);
        }

        return redirect()
            ->route('admin.settings.index', ['tab' => $group])
            ->withSuccess(ucfirst($group) . ' settings updated successfully.');
    }

    /**
     * PUT /admin/settings/save (interface I7): saves the fields of one admin page, which may belong to several
     * settings groups, in one transaction. SettingsSaveRequest has validated every listed key with the same rules
     * as the group save; values are cast and stored exactly as updateGroup() stores them.
     */
    public function save(SettingsSaveRequest $request): JsonResponse|RedirectResponse
    {
        $schema = $this->saveSchema();
        $validated = $request->validated();
        $clear = $request->clearList();
        $writes = [];
        $notes = [];

        foreach ($request->manifest() as $group => $keys) {
            foreach ($keys as $key) {
                $field = $schema[$group][$key];
                $value = ($field['type'] ?? 'string') === 'bool'
                    ? $request->input("groups.{$group}.{$key}", false)
                    : (data_get($validated, "groups.{$group}.{$key}") ?? '');

                // Passwords and keys are never sent back to the browser, so an empty field means "keep it".
                if (SettingsPageController::isWriteOnly($key) && $value === '' && ! in_array($key, $clear[$group] ?? [], true)) {
                    continue;
                }

                if ($group === 'security' && $key === 'admin_ip_whitelist') {
                    $ip = AdminIpLock::clientIp($request);
                    $kept = AdminIpLock::withAddress((string) $value, $ip);

                    if ($kept !== (string) $value) {
                        $value = $kept;
                        $notes[] = __('Your address :ip was added to the list so you keep access.', ['ip' => $ip]);
                    }
                }

                // A size typed in MB or GB is stored in bytes. Sent back exactly as the page showed it (a form posted
                // without JavaScript sends every field), the stored bytes stay as they are: the page rounds sizes
                // that are no whole number of hundredths of a MB.
                if (SettingsPageController::isSize($key) && (string) $value !== '') {
                    $bytes = SettingsPageController::sizeBytes($value, $request->input("units.{$group}.{$key}"));
                    $current = Setting::getGroup($group)[$key] ?? null;
                    $value = $current !== null && $bytes !== null
                        && SettingsPageController::sizeFieldValue($current) === SettingsPageController::sizeFieldValue($bytes)
                        ? (string) $current
                        : (string) $bytes;
                }

                $writes[$group][$key] = $this->normalizeSettingValue($group, $key, $field, $value);
            }
        }

        foreach ($clear as $group => $keys) {
            foreach ($keys as $key) {
                $writes[$group][$key] ??= '';
            }
        }

        // The old bottom-bar key follows the new one, as in the group save.
        if (isset($writes['appearance']['mobile_bottom_nav_enabled']) || isset($writes['appearance']['mobile_bottom_dock_enabled'])) {
            $bottomBar = $writes['appearance']['mobile_bottom_nav_enabled'] ?? $writes['appearance']['mobile_bottom_dock_enabled'];
            $writes['appearance']['mobile_bottom_nav_enabled'] = $bottomBar;
            $writes['appearance']['mobile_bottom_dock_enabled'] = $bottomBar;
        }

        // Theme and look settings go to AppearanceApplier (interface I5); everything else is written here.
        $looks = array_intersect_key($writes['appearance'] ?? [], array_flip($this->appearanceLookKeys()));
        $plain = $writes;
        if ($looks !== []) {
            $plain['appearance'] = array_diff_key($plain['appearance'], $looks);
            if ($plain['appearance'] === []) {
                unset($plain['appearance']);
            }
        }

        $before = [];
        $lookRevision = null;

        DB::transaction(function () use ($plain, $looks, $request, &$before, &$lookRevision): void {
            foreach ($plain as $group => $values) {
                $stored = Setting::getGroup($group);

                foreach ($values as $key => $value) {
                    $before[$group][$key] = $stored[$key] ?? null;
                    $this->storeSetting($group, $key, $value);
                }
            }

            if ($looks !== []) {
                $lookRevision = app(AppearanceApplier::class)->applyValues($looks, 'settings_save', $request->user()?->getKey())['revision'] ?? null;
            }
        });

        $this->flushSettingsCaches();

        $revision = $this->recordSaveRevision($before, $plain, $request) ?? $lookRevision;
        $message = $writes === [] ? __('Nothing to save.') : __('Changes saved.');

        if ($request->expectsJson()) {
            // The stored values (after clean-up) of everything but passwords and keys, so the page shows them.
            $values = [];
            foreach ($writes as $group => $groupValues) {
                foreach ($groupValues as $key => $value) {
                    if (! SettingsPageController::isWriteOnly($key)) {
                        // Sizes as the field shows them ("500 MB"), not the stored bytes.
                        $values[$group][$key] = SettingsPageController::isSize($key)
                            ? SettingsPageController::sizeFieldValue($value)
                            : (string) $value;
                    }
                }
            }

            return response()->json([
                'ok' => true,
                'message' => $message,
                // Objects even when empty, so the page can always look a group up.
                'saved' => (object) array_map('array_keys', $writes),
                'values' => (object) $values,
                'revision' => $revision,
                'notes' => $notes,
            ]);
        }

        return redirect()->back()->withSuccess(trim($message.' '.implode(' ', $notes)));
    }

    /**
     * The rules the multi-group save validates with: the legacy group schema (with the theme catalogue v2 keys once
     * that is installed), without the catalogue's bookkeeping keys, which only the upgrade and AppearanceApplier
     * write. Only the keys a page lists are validated and written.
     */
    public function saveSchema(): array
    {
        $schema = $this->settingsSchema();
        $schema['appearance'] = array_diff_key($schema['appearance'], array_flip(self::NOT_SAVED_BY_PAGES));

        // Sizes the pages show in MB or GB (the plan limits) keep their byte limits, checked on the bytes the typed
        // size makes. The legacy group save still takes bytes with the rules of settingsSchema().
        foreach (config('admin_settings.settings', []) as $key => $setting) {
            $group = $setting['group'] ?? null;

            if (($setting['control'] ?? null) === 'size' && is_string($group) && isset($schema[$group][$key])) {
                $schema[$group][$key]['rules'] = self::sizeRules($group, $key, $schema[$group][$key]['rules']);
            }
        }

        return $schema;
    }

    /**
     * The rules of a size typed in MB or GB from the byte rules of the group save: required / nullable stay, the byte
     * min and max apply to the bytes ("Use 1 MB or more.", "Use 1 TB or less.").
     *
     * @param  array<int, mixed>  $byteRules
     * @return array<int, mixed>
     */
    private static function sizeRules(string $group, string $key, array $byteRules): array
    {
        $limits = ['min' => null, 'max' => null];
        $rules = [];

        foreach ($byteRules as $rule) {
            if (in_array($rule, ['required', 'nullable', 'sometimes'], true)) {
                $rules[] = $rule;
            } elseif (is_string($rule) && preg_match('/^(min|max):(\d+)$/', $rule, $match)) {
                $limits[$match[1]] = (int) $match[2];
            }
        }

        $rules[] = static function (string $attribute, mixed $value, \Closure $fail) use ($group, $key, $limits): void {
            // A form posted without JavaScript sends the number and its unit (MB / GB) in two fields.
            $bytes = SettingsPageController::sizeBytes($value, request()->input("units.{$group}.{$key}"));

            if ($bytes === null) {
                $fail(__('Use a size like 500 MB or 2 GB.'));
            } elseif ($limits['min'] !== null && $bytes < $limits['min']) {
                $fail(__('Use :min or more.', ['min' => \App\Support\DatabaseBackup::sizeText($limits['min'])]));
            } elseif ($limits['max'] !== null && $bytes > $limits['max']) {
                $fail(__('Use :max or less.', ['max' => \App\Support\DatabaseBackup::sizeText($limits['max'])]));
            }
        };

        return $rules;
    }

    public function applyThemePreset(Request $request): JsonResponse|RedirectResponse
    {
        $presets = AppearanceSettings::themePresets();
        $presetKey = (string) $request->input('theme_preset');

        if (!isset($presets[$presetKey])) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => 'Invalid theme preset specified.'], 422);
            }
            return back()->withErrors(['theme_preset' => 'Invalid theme preset specified.']);
        }

        $preset = $presets[$presetKey];
        $this->applyAppearancePreset($presetKey, true);

        try {
            Setting::clearCache();
            AdminCache::bumpVersion();
            Cache::flush();
        } catch (\Throwable $e) {}
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'preset_key' => $presetKey,
                'preset' => $preset,
                'message' => "Theme '{$preset['label']}' applied successfully across the entire site!",
            ]);
        }

        return redirect()
            ->route('admin.settings.index', ['tab' => 'appearance'])
            ->withSuccess("Theme '{$preset['label']}' applied successfully!");
    }

    public function applyMasterExperience(Request $request): JsonResponse|RedirectResponse
    {
        $expKey = (string) $request->input('experience_key');
        if ($expKey === 'youtube_2026_pro') {
            $expKey = 'youtube_spotlight_pro';
        }
        if ($expKey === 'cyberpunk_hud_2077') {
            $expKey = 'cyberpunk_hud';
        }
        $exp = MasterExperienceCatalog::find($expKey);

        if (!$exp) {
            if ($request->wantsJson()) {
                return response()->json(['ok' => false, 'message' => 'Invalid Master Experience preset specified.'], 422);
            }
            return back()->withErrors(['experience_key' => 'Invalid Master Experience specified.']);
        }

        Setting::set('daily_design_auto_enabled', '0', 'appearance');
        $canonicalKey = $exp['id'] ?? $expKey;
        Setting::set('active_master_experience', $canonicalKey, 'appearance');

        if (!empty($exp['theme_preset']) && isset(AppearanceSettings::themePresets()[$exp['theme_preset']])) {
            // Apply the complete theme first so a master experience cannot leave
            // stale hero, mobile, surface, or accessibility tokens behind.
            $this->applyAppearancePreset((string) $exp['theme_preset'], false);
        }

        $expKeys = [
            'home_layout_mode',
            'watch_layout_mode',
            'video_card_layout_style',
            'modal_presentation_style',
            'motion_engine_level',
            'interactive_3d_card_tilt_enabled',
            'cinema_ambient_light_diffusion_enabled',
            'background_atmosphere_effect',
            'typography_pairing',
        ];

        foreach ($expKeys as $k) {
            if (isset($exp[$k])) {
                Setting::set($k, (string) $exp[$k], 'appearance');
            }
        }

        try {
            Setting::clearCache();
            AdminCache::bumpVersion();
            Cache::flush();
        } catch (\Throwable $e) {}
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'experience_key' => $canonicalKey,
                'experience' => $exp,
                'message' => "Master Experience '{$exp['label']}' applied successfully across the entire site!",
            ]);
        }

        return redirect()
            ->route('admin.settings.index', ['tab' => 'appearance'])
            ->withSuccess("Master Experience '{$exp['label']}' applied successfully!");
    }

    public function resetDefaultTheme(Request $request): JsonResponse|RedirectResponse
    {
        if (class_exists(AppearanceApplier::class) || app()->bound(AppearanceApplier::class)) {
            // Interface I5: only the theme and look keys go back to the factory look (logo, texts, menus, ads and
            // custom code stay), and the previous state is kept as a revision that can be restored.
            app(AppearanceApplier::class)->resetToFactory($request->user()?->getKey());
        } else {
            $defaults = AppearanceSettings::defaults();

            foreach ($defaults as $key => $val) {
                Setting::set($key, (string) $val, 'appearance');
            }
            Setting::set('active_master_experience', 'default_factory', 'appearance');
            Setting::set('theme_preset', 'cyberpunk_neon', 'appearance');
            Setting::set('daily_design_auto_enabled', '0', 'appearance');
        }

        try {
            Setting::clearCache();
            AdminCache::bumpVersion();
            Cache::flush();
        } catch (\Throwable $e) {}
        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Theme and appearance settings successfully reset to factory defaults!',
                'redirect' => route('admin.settings.index', ['tab' => 'appearance']),
            ]);
        }

        return redirect()
            ->route('admin.settings.index', ['tab' => 'appearance'])
            ->withSuccess('Theme and appearance settings successfully reset to factory defaults!');
    }

    /**
     * Persist one complete catalog preset so applying a theme cannot leave
     * stale layout, mobile, surface, or accessibility tokens behind.
     *
     * URL previews are resolved separately by the public layout. This method is
     * only for an explicit admin apply/save action and therefore writes the
     * values that the next request must render.
     */
    private function applyAppearancePreset(string $presetKey, bool $syncMasterExperience): array
    {
        $preset = AppearanceSettings::themePresets()[$presetKey] ?? null;
        $presetValues = AppearanceSettings::presetValues($presetKey);

        if (!is_array($preset) || $presetValues === []) {
            return [];
        }

        Setting::set('theme_preset', $presetKey, 'appearance');
        Setting::set('daily_design_auto_enabled', '0', 'appearance');

        if ($syncMasterExperience) {
            $activeExperience = '';
            if ($presetKey === 'cyberpunk_neon') {
                $activeExperience = 'default_factory';
            } else {
                foreach (MasterExperienceCatalog::all() as $experienceKey => $experience) {
                    if (($experience['theme_preset'] ?? '') === $presetKey) {
                        $activeExperience = (string) $experienceKey;
                        break;
                    }
                }
            }
            Setting::set('active_master_experience', $activeExperience, 'appearance');
        }

        foreach ($presetValues as $key => $value) {
            if ($key === 'theme_preset') {
                continue;
            }

            Setting::set($key, (string) $value, 'appearance');
        }

        return $preset;
    }

    private function syncMobileNavigationSettings(Request $request): void
    {
        $enabled = $request->has('mobile_bottom_nav_enabled')
            ? $request->boolean('mobile_bottom_nav_enabled')
            : $request->boolean('mobile_bottom_dock_enabled');
        $value = $enabled ? '1' : '0';

        Setting::set('mobile_bottom_nav_enabled', $value, 'appearance');
        Setting::set('mobile_bottom_dock_enabled', $value, 'appearance');
    }

    /**
     * The value a settings key is stored with: switches become '1'/'0' and video
     * addresses are cleaned up. Shared by the legacy group save and the multi-group
     * save so both write exactly the same values.
     */
    private function normalizeSettingValue(string $group, string $key, array $field, mixed $value): mixed
    {
        if (($field['type'] ?? 'string') === 'bool') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        if ($group === 'seo' && $key === 'video_url_prefix') {
            $value = trim((string) $value, '/');
            $value = preg_replace('/[^A-Za-z0-9\-\/]/', '', $value) ?: 'video';
            $value = preg_replace('#/+#', '/', $value);
            $value = strtolower(trim($value, '/')) ?: 'video';
        }

        if ($group === 'seo' && $key === 'video_url_pattern') {
            $value = trim((string) $value, '/');
        }

        return $value;
    }

    private function storeSetting(string $group, string $key, mixed $value): void
    {
        Setting::set($key, $value ?? '', $group);

        if ($group === 'localization' && $key === 'default_locale' && $value !== '') {
            $this->syncDefaultLanguage((string) $value);
        }
    }

    private function flushSettingsCaches(): void
    {
        try {
            Setting::clearCache();
            AdminCache::bumpVersion();
        } catch (\Throwable $e) {}
    }

    public function clearCache(Request $request, ?string $type = null): JsonResponse|RedirectResponse
    {
        $messages = [];
        $type = $type ?? $request->input('type', 'all');

        try {
            switch ($type) {
                case 'config':
                    Artisan::call('config:clear');
                    $messages[] = 'Configuration cache successfully cleared.';
                    break;
                case 'route':
                    Artisan::call('route:clear');
                    $messages[] = 'Route cache successfully cleared.';
                    break;
                case 'view':
                    Artisan::call('view:clear');
                    $messages[] = 'Compiled Blade views cache successfully cleared.';
                    break;
                case 'data':
                case 'application':
                    Artisan::call('cache:clear');
                    $messages[] = 'Application data cache successfully cleared.';
                    break;
                case 'all':
                default:
                    Artisan::call('cache:clear');
                    Artisan::call('config:clear');
                    Artisan::call('view:clear');
                    Artisan::call('route:clear');
                    $messages[] = 'All system caches (application, config, views, and routes) cleared successfully!';
                    break;
            }

            $successMsg = implode(' ', $messages);

            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $successMsg,
                    'type' => $type,
                    'timestamp' => now()->toTimeString(),
                ]);
            }

            return redirect()
                ->route('admin.settings.index', ['tab' => 'system'])
                ->withSuccess($successMsg);
        } catch (\Throwable $e) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cache clearing failed: ' . $e->getMessage(),
                ], 500);
            }

            return redirect()
                ->route('admin.settings.index', ['tab' => 'system'])
                ->withErrors(['cache' => $e->getMessage()]);
        }
    }

    public function sendTestEmail(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'to' => 'required|email',
            'mailer' => 'nullable|string',
            'subject' => 'nullable|string|max:200',
        ]);

        $recipient = $validated['to'];
        $subject = $validated['subject'] ?: ('[Test Email] ' . config('app.name') . ' SMTP Verification');
        $siteName = config('app.name');
        $time = now()->toDateTimeString();

        try {
            $mailer = $validated['mailer'] ?? config('mail.default', 'smtp');

            Mail::mailer($mailer)->raw(
                "Hello Admin,\n\n" .
                "This is a test email sent from your {$siteName} Admin Panel settings to verify mail delivery.\n\n" .
                "Timestamp: {$time}\n" .
                "Mailer: {$mailer}\n" .
                "Host: " . config("mail.mailers.{$mailer}.host", 'default') . "\n" .
                "Port: " . config("mail.mailers.{$mailer}.port", 'default') . "\n" .
                "From: " . config('mail.from.address') . "\n\n" .
                "If you are reading this, your email configuration is working properly!\n",
                function ($message) use ($recipient, $subject) {
                    $message->to($recipient)
                        ->subject($subject);
                }
            );

            $msg = "Test email successfully dispatched to {$recipient} via [{$mailer}]!";

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'recipient' => $recipient,
                    'timestamp' => $time,
                ]);
            }

            return redirect()
                ->route('admin.settings.index', ['tab' => 'email'])
                ->withSuccess($msg);
        } catch (\Throwable $e) {
            $errMsg = 'Email delivery failed: ' . $e->getMessage();

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $errMsg,
                    'error' => $e->getMessage(),
                ], 422);
            }

            return redirect()
                ->route('admin.settings.index', ['tab' => 'email'])
                ->withErrors(['email' => $errMsg]);
        }
    }

    public function testVideoStream(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => 'required|url',
        ]);

        $url = $validated['url'];

        try {
            $response = Http::timeout(8)->head($url);
            if ($response->failed() && in_array($response->status(), [405, 403], true)) {
                $response = Http::timeout(8)->withHeaders(['Range' => 'bytes=0-1024'])->get($url);
            }

            $contentType = $response->header('Content-Type') ?? 'unknown';
            $contentLength = $response->header('Content-Length');
            $sizeFormatted = $contentLength ? round($contentLength / 1024 / 1024, 2) . ' MB' : 'Stream / Unknown';

            return response()->json([
                'success' => $response->successful(),
                'status' => $response->status(),
                'contentType' => $contentType,
                'size' => $sizeFormatted,
                'url' => $url,
                'message' => $response->successful()
                    ? "Media stream reachable! Status: {$response->status()} ({$contentType}, {$sizeFormatted})"
                    : "Media server returned HTTP {$response->status()}",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to reach media server: ' . $e->getMessage(),
            ], 422);
        }
    }

    private function getDatabaseSize(): string
    {
        try {
            $dbName = config('database.connections.mysql.database');
            $result = DB::select("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.tables WHERE table_schema = ?", [$dbName]);
            return ($result[0]->size_mb ?? 0) . ' MB';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    private function cachedSystemMetric(string $key, callable $callback, int $minutes = 10): mixed
    {
        return Cache::remember('admin.settings.system.' . $key, now()->addMinutes($minutes), $callback);
    }

    private function activeVideoMetricQuery(): QueryBuilder
    {
        return DB::table('videos')
            ->join('users', 'users.id', '=', 'videos.user_id')
            ->whereNull('videos.deleted_at')
            ->whereNull('users.deleted_at')
            ->whereNull('users.banned_at')
            ->whereNotNull('users.email_verified_at')
            ->whereNotNull('videos.uploaded_at')
            ->where(function (QueryBuilder $query): void {
                $query->where(function (QueryBuilder $query): void {
                    $query->where('videos.status', VideoStatus::PUBLIC->value)
                        ->where(function (QueryBuilder $query): void {
                            $query->whereNull('videos.published_at')
                                ->orWhere('videos.published_at', '<=', now());
                        });
                })->orWhere(function (QueryBuilder $query): void {
                    $query->where('videos.status', VideoStatus::PLANNED->value)
                        ->where('videos.scheduled_at', '<=', now());
                });
            });
    }

    private function estimateActiveVideosWithDescriptions(int $activeVideos): int
    {
        if ($activeVideos <= 0) {
            return 0;
        }

        $query = DB::table('videos')->select('description');

        if (DB::connection()->getDriverName() === 'mysql') {
            $query->forceIndex('PRIMARY');
        }

        $sample = $query
            ->whereNull('deleted_at')
            ->whereNotNull('uploaded_at')
            ->where(function (QueryBuilder $query): void {
                $query->where(function (QueryBuilder $query): void {
                    $query->where('status', VideoStatus::PUBLIC->value)
                        ->where(function (QueryBuilder $query): void {
                            $query->whereNull('published_at')
                                ->orWhere('published_at', '<=', now());
                        });
                })->orWhere(function (QueryBuilder $query): void {
                    $query->where('status', VideoStatus::PLANNED->value)
                        ->where('scheduled_at', '<=', now());
                });
            })
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        if ($sample->isEmpty()) {
            return 0;
        }

        $withDescriptions = $sample->filter(
            fn (object $video): bool => trim((string) $video->description) !== ''
        )->count();

        return (int) round($activeVideos * ($withDescriptions / $sample->count()));
    }

    private function getStorageUsed(): string
    {
        try {
            $path = storage_path('app');
            if (!is_dir($path)) return '0 MB';
            $size = 0;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) { $size += $file->getSize(); }
            return round($size / 1024 / 1024, 2) . ' MB';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    private function getDiskFreeSpace(): string
    {
        try {
            $bytes = disk_free_space(base_path());
            return round($bytes / 1024 / 1024 / 1024, 2) . ' GB free';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    private function getLatestLogError(): string
    {
        try {
            $path = storage_path('logs/laravel.log');

            if (!is_file($path)) {
                return 'No Laravel log file';
            }

            $size = filesize($path);
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return 'Log file not readable';
            }

            fseek($handle, max(0, $size - 65536));
            $chunk = stream_get_contents($handle) ?: '';
            fclose($handle);

            preg_match_all('/\[([^\]]+)\]\s+\w+\.ERROR:\s+([^\r\n]+)/', $chunk, $matches, PREG_SET_ORDER);

            if (!$matches) {
                return 'No recent ERROR entries';
            }

            $latest = end($matches);
            $message = preg_replace('/\s+/', ' ', trim($latest[2] ?? ''));
            $message = mb_strlen($message) > 220 ? mb_substr($message, 0, 217) . '...' : $message;

            return trim(($latest[1] ?? '') . ' - ' . $message);
        } catch (\Throwable $e) {
            return 'Latest log error unavailable';
        }
    }

    private function settingsSchema(): array
    {
        $bool = ['sometimes', 'boolean'];
        $color = ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $intRange = static fn (int $min, int $max): array => ['required', 'integer', 'min:' . $min, 'max:' . $max];
        $mailers = array_keys(config('mail.mailers', ['smtp' => []]));
        $localeCodes = array_keys(config('languages', [])) ?: ['en'];
        $videoUrlPrefix = [
            'required',
            'string',
            'max:60',
            'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/]*$/',
            function (string $attribute, mixed $value, \Closure $fail) use ($localeCodes) {
                // locale codes too: a prefix like 'vi' would be stripped as a language prefix before routing
                $reserved = array_merge(['api', 'admin', 'login', 'register', 'contact', 'search', 'subscriptions', 'playlist', 'playlists', 'users', 'categories', 'terms', 'premium', 'storage', 'build', 'vendor', 'images', 'icons'], $localeCodes);
                $first = strtolower(strtok(trim((string) $value, '/'), '/'));

                if (in_array($first, $reserved, true)) {
                    $fail('The video URL prefix conflicts with an existing route.');
                }
            },
        ];
        $videoUrlPattern = [
            'required',
            'string',
            'max:140',
            'regex:/^[A-Za-z0-9{}_\-\/.]+$/',
            function (string $attribute, mixed $value, \Closure $fail) {
                $value = (string) $value;

                if (!str_contains($value, '{uuid}') && !str_contains($value, '{id}')) {
                    $fail('The video URL pattern must include {uuid} or {id}.');
                }

                preg_match_all('/\{([a-z_]+)\}/', $value, $matches);
                $unknown = array_diff($matches[1] ?? [], SeoService::VIDEO_PATTERN_TOKENS);

                if ($unknown) {
                    $fail('The video URL pattern contains unsupported tokens: ' . implode(', ', $unknown) . '.');
                }
            },
        ];
        $homeWidgetVideoRules = [];

        foreach (array_keys(HomeWidgetVideoSettings::widgets()) as $prefix) {
            $homeWidgetVideoRules[$prefix . '_videos_enabled'] = ['rules' => $bool, 'type' => 'bool'];
            $homeWidgetVideoRules[$prefix . '_video_source'] = ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::homeFeedSources()))]];
            $homeWidgetVideoRules[$prefix . '_video_category_id'] = ['rules' => ['nullable', 'integer', 'min:0']];
            $homeWidgetVideoRules[$prefix . '_video_columns'] = ['rules' => $intRange(AppearanceSettings::HOME_FEED_MIN_COLUMNS, AppearanceSettings::HOME_FEED_MAX_COLUMNS)];
            $homeWidgetVideoRules[$prefix . '_video_rows'] = ['rules' => $intRange(AppearanceSettings::HOME_FEED_MIN_ROWS, AppearanceSettings::HOME_FEED_MAX_ROWS)];
        }
        foreach (HomeWidgetVideoSettings::contentTextKeys() as $key) {
            $homeWidgetVideoRules[$key] = ['rules' => ['nullable', 'string', 'max:160']];
        }
        $seoTemplateRules = collect(SeoService::templateDefaults())
            ->mapWithKeys(fn (string $default, string $key): array => [$key => [
                'rules' => ['nullable', 'string', 'max:' . (str_contains($key, '_description_') ? 1000 : 255)],
            ]])
            ->all();
        $footerRules = collect(FooterSettings::textKeys())
            ->mapWithKeys(function (string $key) use ($intRange): array {
                if ($key === 'footer_categories_limit') {
                    return [$key => ['rules' => $intRange(0, 24)]];
                }

                if (in_array($key, FooterSettings::linkKeys(), true)) {
                    return [$key => ['rules' => ['nullable', 'string', 'max:10000']]];
                }

                return [$key => ['rules' => ['nullable', 'string', 'max:' . ($key === 'footer_tagline' ? 500 : 255)]]];
            })
            ->all();
        $navigationRules = collect(NavigationSettings::textKeys())
            ->mapWithKeys(function (string $key) use ($intRange): array {
                if (in_array($key, ['sidebar_playlists_limit', 'sidebar_subscriptions_limit'], true)) {
                    return [$key => ['rules' => $intRange(1, 50)]];
                }

                if ($key === 'sidebar_explore_limit') {
                    return [$key => ['rules' => $intRange(0, 100)]];
                }

                if ($key === 'watch_related_source') {
                    return [$key => ['rules' => ['required', Rule::in(array_keys(NavigationSettings::watchRelatedSources()))]]];
                }

                if (in_array($key, NavigationSettings::menuLinkKeys(), true)) {
                    return [$key => ['rules' => ['nullable', 'string', 'max:12000']]];
                }

                return [$key => ['rules' => ['nullable', 'string', 'max:255']]];
            })
            ->all();

        return [
            'general' => [
                'site_name' => ['rules' => ['required', 'string', 'max:120']],
                'contact_email' => ['rules' => ['nullable', 'email', 'max:255']],
                'site_description' => ['rules' => ['nullable', 'string', 'max:500']],
                'site_keywords' => ['rules' => ['nullable', 'string', 'max:500']],
                'per_page_videos' => ['rules' => $intRange(1, 96)],
                'per_page_comments' => ['rules' => $intRange(1, 100)],
                'per_page_users' => ['rules' => $intRange(1, 100)],
                'per_page_search' => ['rules' => $intRange(1, 100)],
                'per_page_activity' => ['rules' => $intRange(1, 100)],
                'profile_videos_limit' => ['rules' => $intRange(1, 48)],
                'profile_playlists_limit' => ['rules' => $intRange(1, 48)],
                'dashboard_widget_limit' => ['rules' => $intRange(1, 50)],
                'discover_users_limit' => ['rules' => $intRange(1, 500)],
                'search_suggestions_limit' => ['rules' => $intRange(1, 30)],
                'suggested_videos_count' => ['rules' => $intRange(1, 30)],
                'search_history_retention_days' => ['rules' => $intRange(SearchHistoryRetentionService::MIN_RETENTION_DAYS, SearchHistoryRetentionService::MAX_RETENTION_DAYS)],
                'search_history_max_rows' => ['rules' => $intRange(SearchHistoryRetentionService::MIN_MAX_ROWS, SearchHistoryRetentionService::MAX_MAX_ROWS)],
                'search_history_cleanup_interval_minutes' => ['rules' => $intRange(SearchHistoryRetentionService::MIN_CLEANUP_INTERVAL_MINUTES, SearchHistoryRetentionService::MAX_CLEANUP_INTERVAL_MINUTES)],
                'registration_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'allow_social_login' => ['rules' => $bool, 'type' => 'bool'],
                'allow_uploads' => ['rules' => $bool, 'type' => 'bool'],
                'allow_embedding' => ['rules' => $bool, 'type' => 'bool'],
                'show_trending' => ['rules' => $bool, 'type' => 'bool'],
                'show_history' => ['rules' => $bool, 'type' => 'bool'],
            ],
            'uploads' => [
                'upload_max_duration' => ['rules' => $intRange(1, 86400)],
                'upload_allowed_formats' => ['rules' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9,\s]+$/']],
                'default_video_status' => ['rules' => ['required', Rule::in(array_map('strval', VideoStatus::validStatus()))]],
                'default_video_language' => ['rules' => ['nullable', 'string', 'max:10', Rule::in(array_keys(Video::languages()))]],
                'allow_custom_thumbnails' => ['rules' => $bool, 'type' => 'bool'],
                'thumbnail_width' => ['rules' => $intRange(160, 3840)],
                'thumbnail_height' => ['rules' => $intRange(90, 2160)],
                'upload18_api_key' => ['rules' => ['nullable', 'string', 'max:255']],
                'upload18_api_url' => ['rules' => ['required', 'url', 'max:255']],
                'upload18_default_cid' => ['rules' => $intRange(0, 999999)],
                'upload18_default_fid' => ['rules' => $intRange(0, 999999)],
                'upload18_default_mycid' => ['rules' => $intRange(0, 999999)],
                'upload18_chunk_size_mb' => ['rules' => $intRange(UploadSettings::MIN_CHUNK_SIZE_MB, UploadSettings::MAX_CHUNK_SIZE_MB)],
                'upload18_thumbnail_count' => ['rules' => $intRange(UploadSettings::MIN_THUMBNAIL_COUNT, UploadSettings::MAX_THUMBNAIL_COUNT)],
                'max_thumbnail_size' => ['rules' => $intRange(UploadSettings::MIN_MAX_THUMBNAIL_SIZE_KB, UploadSettings::MAX_MAX_THUMBNAIL_SIZE_KB)],
            ],
            'notifications' => [
                'broadcast_notification_chunk_size' => ['rules' => $intRange(NotificationBroadcastSettings::MIN_CHUNK_SIZE, NotificationBroadcastSettings::MAX_CHUNK_SIZE)],
            ],
            'email' => [
                'mail_mailer' => ['rules' => ['required', Rule::in($mailers)]],
                'smtp_host' => ['rules' => ['nullable', 'string', 'max:255']],
                'smtp_port' => ['rules' => $intRange(1, 65535)],
                'smtp_username' => ['rules' => ['nullable', 'string', 'max:255']],
                'smtp_password' => ['rules' => ['nullable', 'string', 'max:1000']],
                'smtp_encryption' => ['rules' => ['nullable', Rule::in(['', 'tls', 'ssl'])]],
                'mail_from_address' => ['rules' => ['required', 'email', 'max:255']],
                'mail_from_name' => ['rules' => ['nullable', 'string', 'max:255']],
                'support_email' => ['rules' => ['nullable', 'email', 'max:255']],
                'server_email' => ['rules' => ['nullable', 'email', 'max:255']],
            ],
            'comments' => [
                'comments_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'allow_replies' => ['rules' => $bool, 'type' => 'bool'],
                'per_page_replies' => ['rules' => $intRange(1, 100)],
                'comment_preview_length' => ['rules' => $intRange(80, 2000)],
                'view_cooldown_minutes' => ['rules' => $intRange(0, 1440)],
                'view_log_mode' => ['rules' => ['required', Rule::in(array_keys(ViewHistoryRetentionService::logModes()))]],
                'view_daily_stats_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'view_history_retention_days' => ['rules' => $intRange(ViewHistoryRetentionService::MIN_RETENTION_DAYS, ViewHistoryRetentionService::MAX_RETENTION_DAYS)],
            ],
            'users' => [
                'max_register_attempts' => ['rules' => $intRange(1, 20)],
                'max_avatar_size' => ['rules' => $intRange(128, 20480)],
                'max_banner_size' => ['rules' => $intRange(128, 51200)],
                'email_verification_required' => ['rules' => $bool, 'type' => 'bool'],
                'allow_account_deletion' => ['rules' => $bool, 'type' => 'bool'],
                'show_subscriber_count' => ['rules' => $bool, 'type' => 'bool'],
                'creator_quality_pulse_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'creator_quality_pulse_limit' => ['rules' => $intRange(CreatorQualityPulseService::MIN_LIMIT, CreatorQualityPulseService::MAX_LIMIT)],
                'creator_quality_pulse_lookback_days' => ['rules' => $intRange(CreatorQualityPulseService::MIN_LOOKBACK_DAYS, CreatorQualityPulseService::MAX_LOOKBACK_DAYS)],
                'studio_health_alerts_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'studio_health_alerts_limit' => ['rules' => $intRange(StudioHealthAlertsService::MIN_LIMIT, StudioHealthAlertsService::MAX_LIMIT)],
                'studio_health_alerts_lookback_days' => ['rules' => $intRange(StudioHealthAlertsService::MIN_LOOKBACK_DAYS, StudioHealthAlertsService::MAX_LOOKBACK_DAYS)],
                'creator_recovery_checklist_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'creator_recovery_checklist_limit' => ['rules' => $intRange(CreatorRecoveryChecklistService::MIN_LIMIT, CreatorRecoveryChecklistService::MAX_LIMIT)],
                'creator_recovery_checklist_inactivity_days' => ['rules' => $intRange(CreatorRecoveryChecklistService::MIN_INACTIVITY_DAYS, CreatorRecoveryChecklistService::MAX_INACTIVITY_DAYS)],
                ...collect(ChannelCustomizationSettings::booleanKeys())
                    ->mapWithKeys(fn (string $key): array => [$key => ['rules' => $bool, 'type' => 'bool']])
                    ->all(),
                'channel_default_layout' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['layout']))]],
                'channel_default_theme' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['theme']))]],
                'channel_default_accent' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['accent']))]],
                'channel_default_custom_accent' => ['rules' => $color],
                'channel_default_surface' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['surface']))]],
                'channel_default_banner_treatment' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['banner_treatment']))]],
                'channel_default_avatar_frame' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['avatar_frame']))]],
                'channel_default_motion' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['motion']))]],
                'channel_default_pattern' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['pattern']))]],
                'channel_default_header_shape' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['header_shape']))]],
                'channel_default_stats_style' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['stats_style']))]],
                'channel_default_video_layout' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['video_layout']))]],
                'channel_default_featured_mode' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['featured_mode']))]],
                'channel_default_featured_layout' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['featured_layout']))]],
                'channel_default_featured_columns' => ['rules' => $intRange(1, 6)],
                'channel_default_featured_rows' => ['rules' => $intRange(1, 4)],
                'channel_default_latest_uploads_layout' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['latest_uploads_layout']))]],
                'channel_default_latest_uploads_columns' => ['rules' => $intRange(1, 6)],
                'channel_default_latest_uploads_rows' => ['rules' => $intRange(1, 6)],
                'channel_default_playlists_layout' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['playlists_layout']))]],
                'channel_default_playlists_columns' => ['rules' => $intRange(1, 4)],
                'channel_default_playlists_rows' => ['rules' => $intRange(1, 6)],
                'channel_default_playlist_video_columns' => ['rules' => $intRange(1, 6)],
                'channel_default_playlist_video_rows' => ['rules' => $intRange(1, 4)],
                'channel_default_density' => ['rules' => ['required', Rule::in(array_keys(ChannelCustomizationSettings::options()['density']))]],
                'channel_tagline_max_length' => ['rules' => $intRange(20, 240)],
                'channel_cta_label_max_length' => ['rules' => $intRange(8, 80)],
            ],
            'monetization' => [
                'premium_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'stripe_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'paypal_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'show_premium_badge' => ['rules' => $bool, 'type' => 'bool'],
                'ads_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'ads_hide_for_premium' => ['rules' => $bool, 'type' => 'bool'],
                'ad_preroll_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'ad_onclick_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'ad_preroll_duration' => ['rules' => $intRange(1, 60)],
                'ad_preroll_media_url' => ['rules' => ['nullable', 'string', 'max:20000']],
                'ad_preroll_click_url' => ['rules' => ['nullable', 'url:http,https', 'max:2000']],
                'ad_grid_position' => ['rules' => $intRange(1, 48)],
                'ad_grid_max_per_feed' => ['rules' => $intRange(0, 20)],
                'ad_grid_layout' => ['rules' => ['required', Rule::in(['grid_card', 'wide_row'])]],
                'ad_grid_layout_smart_discovery' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_creator_spotlight' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_trending_actors' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_new_videos' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_trend_page' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_category_page' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_subscriptions' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_channel_videos' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_search_results' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_playlist_videos' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_watch_related' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_channel_home_featured' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_grid_layout_channel_home_latest' => ['rules' => ['required', Rule::in(['inherit', 'grid_card', 'wide_row'])]],
                'ad_code_header' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_smart_discovery' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_creator_spotlight' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_trending_actors' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_new_videos' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_trend_page' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_category_page' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_subscriptions' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_channel_videos' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_search_results' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_playlist_videos' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_watch_related' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_channel_home_featured' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_grid_channel_home_latest' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_sidebar' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_video' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_footer' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_mobile' => ['rules' => ['nullable', 'string', 'max:30000']],
                'ad_code_onclick' => ['rules' => ['nullable', 'string', 'max:30000']],
            ],
            'appearance' => [
                'theme' => ['rules' => ['required', Rule::in(['light', 'dark', 'auto'])]],
                'primary_color' => ['rules' => $color],
                'brand_secondary_color' => ['rules' => $color],
                'accent_color' => ['rules' => $color],
                'dark_bg_color' => ['rules' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']],
                'dark_surface_color' => ['rules' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']],
                'light_bg_color' => ['rules' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']],
                'light_surface_color' => ['rules' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/']],
                'grid_columns' => ['rules' => $intRange(2, 6)],
                'surface_style' => ['rules' => ['required', Rule::in(['glass', 'solid', 'contrast'])]],
                'radius_scale' => ['rules' => ['required', Rule::in(['compact', 'balanced', 'rounded'])]],
                'motion_level' => ['rules' => ['required', Rule::in(['full', 'reduced', 'off'])]],
                'content_density' => ['rules' => ['required', Rule::in(['compact', 'comfortable', 'relaxed'])]],
                'mobile_video_columns' => ['rules' => $intRange(1, 2)],
                'mobile_video_card_density' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::mobileVideoCardDensities()))]],
                'studio_video_preview_mode' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::studioVideoPreviewModes()))]],
                'font_family' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::fontFamilies()))]],
                'base_font_size' => ['rules' => $intRange(AppearanceSettings::BASE_FONT_MIN_PX, AppearanceSettings::BASE_FONT_MAX_PX)],
                'ui_font_scale' => ['rules' => $intRange(AppearanceSettings::UI_FONT_SCALE_MIN, AppearanceSettings::UI_FONT_SCALE_MAX)],
                'heading_font_scale' => ['rules' => $intRange(AppearanceSettings::HEADING_FONT_SCALE_MIN, AppearanceSettings::HEADING_FONT_SCALE_MAX)],
                'card_title_font_scale' => ['rules' => $intRange(AppearanceSettings::CARD_TITLE_FONT_SCALE_MIN, AppearanceSettings::CARD_TITLE_FONT_SCALE_MAX)],
                'text_contrast' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::textContrastLevels()))]],
                'scrollbar_style' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::scrollbarStyles()))]],
                'upload_modal_style' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::uploadModalStyles()))]],
                'logo_url' => ['rules' => ['nullable', 'string', 'max:255']],
                'favicon_url' => ['rules' => ['nullable', 'string', 'max:255']],
                'footer_text' => ['rules' => ['nullable', 'string', 'max:255']],
                'announcement_banner' => ['rules' => ['nullable', 'string', 'max:10000']],
                ...$footerRules,
                ...$navigationRules,
                'custom_css' => ['rules' => ['nullable', 'string', 'max:50000']],
                'custom_js' => ['rules' => ['nullable', 'string', 'max:50000']],
                'custom_head_html' => ['rules' => ['nullable', 'string', 'max:50000']],
                'home_intro_kicker' => ['rules' => ['nullable', 'string', 'max:80']],
                'home_intro_title' => ['rules' => ['nullable', 'string', 'max:140']],
                'home_intro_subtitle' => ['rules' => ['nullable', 'string', 'max:220']],
                'home_feed_source' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::homeFeedSources()))]],
                'home_feed_category_id' => ['rules' => ['nullable', 'integer', 'min:0']],
                'home_feed_columns' => ['rules' => $intRange(AppearanceSettings::HOME_FEED_MIN_COLUMNS, AppearanceSettings::HOME_FEED_MAX_COLUMNS)],
                'home_feed_load_mode' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::homeFeedLoadModes()))]],
                'home_widget_motion_style' => ['rules' => ['required', Rule::in(['slide', 'lift', 'zoom', 'fade'])]],
                'home_widget_motion_duration_ms' => ['rules' => $intRange(120, 2000)],
                'home_widget_motion_distance_px' => ['rules' => $intRange(0, 160)],
                'home_widget_motion_stagger_ms' => ['rules' => $intRange(0, 240)],
                'home_widget_motion_speed_px_per_second' => ['rules' => $intRange(4, 160)],
                'home_widget_fresh_picks_motion_direction' => ['rules' => ['required', Rule::in(['left', 'right', 'up', 'down', 'none'])]],
                'home_widget_smart_discovery_motion_direction' => ['rules' => ['required', Rule::in(['left', 'right', 'up', 'down', 'none'])]],
                'home_widget_creator_spotlight_motion_direction' => ['rules' => ['required', Rule::in(['left', 'right', 'up', 'down', 'none'])]],
                'home_widget_trending_collections_motion_direction' => ['rules' => ['required', Rule::in(['left', 'right', 'up', 'down', 'none'])]],
                'watch_player_frame_style' => ['rules' => ['required', Rule::in(['cinematic', 'soft', 'minimal'])]],
                'watch_sidebar_density' => ['rules' => ['required', Rule::in(['compact', 'comfortable', 'relaxed'])]],
                'secondary_page_density' => ['rules' => ['required', Rule::in(['compact', 'comfortable', 'relaxed'])]],
                'secondary_page_max_width' => ['rules' => ['required', Rule::in(['standard', 'wide', 'full'])]],
                'theme_preset' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::themePresets()))]],
                'active_master_experience' => ['rules' => ['nullable', 'string', 'max:80']],
                'video_card_duration_style' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::videoCardDurationStyles()))]],
                'video_card_new_badge_days' => ['rules' => $intRange(0, 30)],
                'video_card_title_lines' => ['rules' => $intRange(1, 4)],
                'watch_ambient_glow_intensity' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::ambientGlowIntensities()))]],
                'watch_autoplay_countdown_seconds' => ['rules' => $intRange(0, 30)],
                'header_style' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::headerStyles()))]],
                'creator_avatar_ring_style' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::creatorAvatarRingStyles()))]],
                'category_card_style' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::categoryCardStyles()))]],
                'back_to_top_style' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::backToTopStyles()))]],
                'home_layout_mode' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::homeLayoutModes()))]],
                'hero_layout_mode' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::heroLayoutModes()))]],
                'hero_slider_autoplay_seconds' => ['rules' => $intRange(3, 15)],
                'watch_layout_mode' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::watchLayoutModes()))]],
                'video_card_layout_style' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::videoCardLayoutStyles()))]],
                'modal_presentation_style' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::modalPresentationStyles()))]],
                'motion_engine_level' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::motionEngineLevels()))]],
                // Nullable: a client that does not send it saves '' and the layout falls back to auto.
                'performance_tier_mode' => ['rules' => ['nullable', Rule::in(array_keys(AppearanceSettings::performanceTierModes()))]],
                'background_atmosphere_effect' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::backgroundAtmosphereEffects()))]],
                'typography_pairing' => ['rules' => ['required', Rule::in(array_keys(AppearanceSettings::typographyPairings()))]],
                'video_player_cinema_filter' => ['rules' => ['nullable', Rule::in(array_keys(AppearanceSettings::videoPlayerCinemaFilters()))]],
                'border_beam_speed' => ['rules' => ['nullable', Rule::in(array_keys(AppearanceSettings::borderBeamSpeeds()))]],
                'daily_design_scope' => ['rules' => ['required', Rule::in(['full', 'palette_only'])]],
                'daily_design_override_day' => ['rules' => ['required', 'integer', 'min:0', 'max:366']],
                ...collect(AppearanceSettings::booleanKeys())
                    ->mapWithKeys(fn (string $key): array => [$key => ['rules' => $bool, 'type' => 'bool']])
                    ->all(),
                ...$this->themeCatalogV2Schema($bool),
            ],
            'discovery' => [
                'smart_discovery_rail_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'smart_discovery_rail_limit' => ['rules' => $intRange(SmartDiscoveryRailService::MIN_LIMIT, SmartDiscoveryRailService::MAX_LIMIT)],
                'smart_discovery_rail_lookback_days' => ['rules' => $intRange(SmartDiscoveryRailService::MIN_LOOKBACK_DAYS, SmartDiscoveryRailService::MAX_LOOKBACK_DAYS)],
                'creator_spotlight_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'creator_spotlight_limit' => ['rules' => $intRange(AdaptiveCreatorSpotlightService::MIN_LIMIT, AdaptiveCreatorSpotlightService::MAX_LIMIT)],
                'creator_spotlight_lookback_days' => ['rules' => $intRange(AdaptiveCreatorSpotlightService::MIN_LOOKBACK_DAYS, AdaptiveCreatorSpotlightService::MAX_LOOKBACK_DAYS)],
                'trending_collections_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'trending_collections_limit' => ['rules' => $intRange(AdaptiveTrendingCollectionsService::MIN_LIMIT, AdaptiveTrendingCollectionsService::MAX_LIMIT)],
                'trending_collections_lookback_days' => ['rules' => $intRange(AdaptiveTrendingCollectionsService::MIN_LOOKBACK_DAYS, AdaptiveTrendingCollectionsService::MAX_LOOKBACK_DAYS)],
                ...$homeWidgetVideoRules,
                'playback_progress_signal_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'playback_progress_signal_min_duration_seconds' => ['rules' => $intRange(PlaybackProgressSignalService::MIN_MIN_DURATION_SECONDS, PlaybackProgressSignalService::MAX_MIN_DURATION_SECONDS)],
                'playback_progress_signal_retention_days' => ['rules' => $intRange(PlaybackProgressSignalService::MIN_RETENTION_DAYS, PlaybackProgressSignalService::MAX_RETENTION_DAYS)],
            ],
            'seo' => [
                'meta_title_template' => ['rules' => ['required', 'string', 'max:160']],
                'default_meta_description' => ['rules' => ['nullable', 'string', 'max:500']],
                ...$seoTemplateRules,
                'og_image' => ['rules' => ['nullable', 'string', 'max:255']],
                'twitter_card_type' => ['rules' => ['required', Rule::in(['summary', 'summary_large_image', 'player'])]],
                'twitter_handle' => ['rules' => ['nullable', 'string', 'max:80']],
                'facebook_app_id' => ['rules' => ['nullable', 'string', 'max:80']],
                'google_verification' => ['rules' => ['nullable', 'string', 'max:255']],
                'adult_rating_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'profile_uid_urls_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'video_pages_indexable' => ['rules' => $bool, 'type' => 'bool'],
                'category_pages_indexable' => ['rules' => $bool, 'type' => 'bool'],
                'profile_pages_indexable' => ['rules' => $bool, 'type' => 'bool'],
                'playlist_pages_indexable' => ['rules' => $bool, 'type' => 'bool'],
                'search_pages_indexable' => ['rules' => $bool, 'type' => 'bool'],
                'video_url_prefix' => ['rules' => $videoUrlPrefix],
                'video_url_pattern' => ['rules' => $videoUrlPattern],
                'robots_max_snippet' => ['rules' => $intRange(-1, 1000)],
                'robots_max_video_preview' => ['rules' => $intRange(-1, 600)],
                'robots_max_image_preview' => ['rules' => ['required', Rule::in(['none', 'standard', 'large'])]],
                'schema_organization_type' => ['rules' => ['required', Rule::in(['Organization', 'WebSite'])]],
                'sitemap_video_limit' => ['rules' => $intRange(100, 1000)],
                'robots_txt' => ['rules' => ['nullable', 'string', 'max:20000']],
                'indexnow_key' => ['rules' => ['nullable', 'string', 'min:8', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/']],
                'indexnow_endpoint' => ['rules' => ['required', 'url', 'max:255']],
                'robots_noindex' => ['rules' => $bool, 'type' => 'bool'],
                'robots_nofollow' => ['rules' => $bool, 'type' => 'bool'],
                'robots_noarchive' => ['rules' => $bool, 'type' => 'bool'],
                'robots_notranslate' => ['rules' => $bool, 'type' => 'bool'],
                'robots_noimageindex' => ['rules' => $bool, 'type' => 'bool'],
                'canonical_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'hreflang_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'website_schema_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'search_action_schema_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'video_schema_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'video_seek_action_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'sitemap_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'video_sitemap_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'indexnow_enabled' => ['rules' => $bool, 'type' => 'bool'],
            ],
            'pages' => [
                'terms_content' => ['rules' => ['nullable', 'string', 'max:100000']],
                ...collect(PremiumPageSettings::booleanKeys())
                    ->mapWithKeys(fn (string $key): array => [$key => ['rules' => $bool, 'type' => 'bool']])
                    ->all(),
                ...collect(PremiumPageSettings::textKeys())
                    ->mapWithKeys(fn (string $key): array => [$key => [
                        'rules' => in_array($key, PremiumPageSettings::iconKeys(), true)
                            ? ['required', Rule::in(array_keys(PremiumPageSettings::iconOptions()))]
                            : ['nullable', 'string', 'max:2000'],
                    ]])
                    ->all(),
            ],
            'security' => [
                'force_https' => ['rules' => $bool, 'type' => 'bool'],
                'honeypot_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'hsts_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'csp_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'xss_protection' => ['rules' => $bool, 'type' => 'bool'],
                'nosniff_header' => ['rules' => $bool, 'type' => 'bool'],
                'frame_options_header' => ['rules' => $bool, 'type' => 'bool'],
                'captcha_provider' => ['rules' => ['required', Rule::in(array_keys(CaptchaService::providers()))]],
                'captcha_site_key' => ['rules' => ['nullable', 'string', 'max:1000']],
                'captcha_secret_key' => ['rules' => ['nullable', 'string', 'max:1000']],
                'captcha_on_login' => ['rules' => $bool, 'type' => 'bool'],
                'captcha_on_register' => ['rules' => $bool, 'type' => 'bool'],
                'captcha_on_contact' => ['rules' => $bool, 'type' => 'bool'],
                'captcha_on_password_reset' => ['rules' => $bool, 'type' => 'bool'],
                'hsts_max_age' => ['rules' => ['required', 'integer', 'min:0', 'max:63072000']],
                'csp_directives' => ['rules' => ['nullable', 'string', 'max:5000']],
                'admin_ip_whitelist' => ['rules' => ['nullable', 'string', 'max:5000']],
                'global_ip_blacklist' => ['rules' => ['nullable', 'string', 'max:5000']],
            ],
            'localization' => [
                'default_locale' => ['rules' => ['required', 'string', Rule::in($localeCodes)]],
                'timezone' => ['rules' => ['required', 'timezone']],
                'date_format' => ['rules' => ['required', Rule::in(['M d, Y', 'd/m/Y', 'Y-m-d', 'd.m.Y', 'm/d/Y'])]],
                'allow_language_switch' => ['rules' => $bool, 'type' => 'bool'],
                'rtl_support' => ['rules' => $bool, 'type' => 'bool'],
            ],
            'validation' => [
                'user_username_min' => ['rules' => ['required', 'integer', 'min:2', 'max:30']],
                'user_username_max' => ['rules' => ['required', 'integer', 'min:3', 'max:80']],
                'user_slug_min' => ['rules' => ['required', 'integer', 'min:2', 'max:30']],
                'user_slug_max' => ['rules' => ['required', 'integer', 'min:3', 'max:80']],
                'user_password_min' => ['rules' => ['required', 'integer', 'min:6', 'max:128']],
                'user_description_max' => ['rules' => ['required', 'integer', 'min:100', 'max:20000']],
                'user_website_max' => ['rules' => ['required', 'integer', 'min:50', 'max:500']],
                'video_title_max' => ['rules' => ['required', 'integer', 'min:20', 'max:255']],
                'video_description_max' => ['rules' => ['required', 'integer', 'min:100', 'max:30000']],
                'comment_content_max' => ['rules' => ['required', 'integer', 'min:100', 'max:30000']],
                'playlist_title_max' => ['rules' => ['required', 'integer', 'min:20', 'max:255']],
                'playlist_description_max' => ['rules' => ['required', 'integer', 'min:100', 'max:30000']],
                'report_comment_max' => ['rules' => ['required', 'integer', 'min:100', 'max:30000']],
                'contact_name_min' => ['rules' => ['required', 'integer', 'min:1', 'max:80']],
                'contact_name_max' => ['rules' => ['required', 'integer', 'min:2', 'max:120']],
                'contact_message_min' => ['rules' => ['required', 'integer', 'min:1', 'max:1000']],
                'contact_message_max' => ['rules' => ['required', 'integer', 'min:100', 'max:30000']],
            ],
            'planlimits' => [
                'free_max_uploads' => ['rules' => ['required', 'integer', 'min:0', 'max:1000000']],
                'free_max_file_size' => ['rules' => ['required', 'integer', 'min:1048576', 'max:1099511627776']],
                'free_max_storage' => ['rules' => ['required', 'integer', 'min:1048576', 'max:10995116277760']],
                'premium_max_uploads' => ['rules' => ['nullable', 'integer', 'min:0', 'max:1000000']],
                'premium_max_file_size' => ['rules' => ['required', 'integer', 'min:1048576', 'max:1099511627776']],
                'premium_max_storage' => ['rules' => ['nullable', 'integer', 'min:0', 'max:10995116277760']],
                'trial_period_days' => ['rules' => ['required', 'integer', 'min:0', 'max:365']],
                'trial_email_reminder' => ['rules' => ['required', 'integer', 'min:0', 'max:365']],
            ],
            'api' => [
                'api_rate_limit' => ['rules' => ['required', 'integer', 'min:1', 'max:10000']],
                'login_rate_limit' => ['rules' => ['required', 'integer', 'min:1', 'max:1000']],
                'upload_rate_limit' => ['rules' => ['required', 'integer', 'min:1', 'max:1000']],
                'subscribe_rate_limit' => ['rules' => ['required', 'integer', 'min:1', 'max:1000']],
                'comment_rate_limit' => ['rules' => ['required', 'integer', 'min:1', 'max:1000']],
                'report_rate_limit' => ['rules' => ['required', 'integer', 'min:1', 'max:1000']],
                'search_rate_limit' => ['rules' => ['required', 'integer', 'min:1', 'max:1000']],
                'video_view_rate_limit' => ['rules' => ['required', 'integer', 'min:1', 'max:10000']],
                'cors_allowed_origins' => ['rules' => ['nullable', 'string', 'max:2000']],
                'cors_allowed_methods' => ['rules' => ['nullable', 'string', 'max:255']],
                'embed_allowed_domains' => ['rules' => ['nullable', 'string', 'max:2000']],
                'cors_max_age' => ['rules' => ['required', 'integer', 'min:0', 'max:86400']],
                'cors_supports_credentials' => ['rules' => $bool, 'type' => 'bool'],
            ],
            'sociallinks' => [
                'social_facebook' => ['rules' => ['nullable', 'url', 'max:255']],
                'social_twitter' => ['rules' => ['nullable', 'url', 'max:255']],
                'social_instagram' => ['rules' => ['nullable', 'url', 'max:255']],
                'social_youtube' => ['rules' => ['nullable', 'url', 'max:255']],
                'social_tiktok' => ['rules' => ['nullable', 'url', 'max:255']],
                'social_discord' => ['rules' => ['nullable', 'url', 'max:255']],
                'social_telegram' => ['rules' => ['nullable', 'url', 'max:255']],
            ],
            'maintenance' => [
                'maintenance_mode_enabled' => ['rules' => $bool, 'type' => 'bool'],
                'maintenance_allow_admins' => ['rules' => $bool, 'type' => 'bool'],
                'maintenance_secret' => ['rules' => ['nullable', 'string', 'max:120']],
                'maintenance_message' => ['rules' => ['nullable', 'string', 'max:2000']],
                'maintenance_retry_after' => ['rules' => ['required', 'integer', 'min:0', 'max:86400']],
                'maintenance_allowed_ips' => ['rules' => ['nullable', 'string', 'max:5000']],
            ],
        ];
    }

    /**
     * The appearance settings of the theme catalogue v2 (theme_flavor, sidebar_style, ...), once it is installed.
     * Installs store them, so they belong to the appearance schema, but they are written only when a request sends
     * them: the old settings page and fleet scripts never send them, and their saves must keep writing exactly the
     * keys they wrote before (a missing switch would otherwise save '0', a missing text '').
     */
    private function themeCatalogV2Schema(array $bool): array
    {
        if (! method_exists(AppearanceSettings::class, 'v2TextKeys') || ! method_exists(AppearanceSettings::class, 'v2BooleanKeys')) {
            return [];
        }

        $fields = [];

        foreach (AppearanceSettings::v2TextKeys() as $key) {
            $rules = match ($key) {
                'sidebar_style' => ['sometimes', Rule::in(array_keys(AppearanceSettings::sidebarStyles()))],
                'theme_flavor' => ['sometimes', 'nullable', 'string', 'max:40'],
                'appearance_schema_version' => ['sometimes', 'nullable', 'string', 'max:8'],
                default => ['sometimes', 'nullable', 'string', 'max:80'],
            };
            $fields[$key] = ['rules' => $rules, 'only_when_sent' => true];
        }

        foreach (AppearanceSettings::v2BooleanKeys() as $key) {
            $fields[$key] = ['rules' => $bool, 'type' => 'bool', 'only_when_sent' => true];
        }

        return $fields;
    }

    /**
     * Theme and look settings (interface I5): AppearanceApplier is their only writer once it is installed, because
     * it applies the colour-style rule and keeps a revision of its own. Empty before the theme catalogue v2.
     *
     * @return list<string>
     */
    private function appearanceLookKeys(): array
    {
        return class_exists(AppearanceApplier::class) && method_exists(AppearanceSettings::class, 'lookKeys')
            ? AppearanceSettings::lookKeys()
            : [];
    }

    /**
     * Keep what a save replaced as one revision (interface I6): rows nested by group, [group => [key => value]],
     * named after every group the save wrote ("email,general"), so the history shows the whole save and a restore
     * puts back exactly those keys (null = the key was not stored). Passwords and keys are recorded as masked.
     */
    private function recordSaveRevision(array $before, array $after, Request $request): ?int
    {
        if ($after === [] || ! class_exists(SettingsRevision::class)) {
            return null;
        }

        $hidden = defined(SettingsRevision::class.'::MASK') ? SettingsRevision::MASK : '(hidden)';
        $mask = static function (array $groups) use ($hidden): array {
            foreach ($groups as $group => $values) {
                foreach ($values as $key => $value) {
                    if (SettingsPageController::isWriteOnly($key) && $value !== null && $value !== '') {
                        $groups[$group][$key] = $hidden;
                    }
                }
            }

            return $groups;
        };

        try {
            return SettingsRevision::record(
                'settings_save',
                implode(',', array_keys($after)),
                $mask($before),
                $mask($after),
                $request->user()?->getKey()
            );
        } catch (\Throwable $e) {
            // The settings are stored already; a history entry that cannot be written must not turn the save into
            // an error, but it must not go unnoticed either.
            report($e);

            return null;
        }
    }

    private function environmentSnapshot(): array
    {
        $get = static fn (string $key, mixed $default = null): mixed => RuntimeEnv::get($key, $default);

        return [
            'app_url'               => config('app.url'),
            'app_debug'             => config('app.debug'),
            'app_env'               => config('app.env', 'production'),
            'cache_driver'          => config('cache.default', 'file'),
            'queue_connection'      => config('queue.default', 'sync'),
            'session_driver'        => config('session.driver', 'file'),
            'filesystem_disk'       => config('filesystems.default', 'local'),
            'broadcast_driver'      => config('broadcasting.default', 'log'),
            'scout_driver'          => config('scout.driver', 'collection'),
            'facebook_client_id'    => config('services.facebook.client_id'),
            'facebook_client_secret'=> config('services.facebook.client_secret'),
            'google_client_id'      => config('services.google.client_id'),
            'google_client_secret'  => config('services.google.client_secret'),
            'stripe_key'            => $get('STRIPE_KEY'),
            'stripe_secret'         => $get('STRIPE_SECRET'),
            'stripe_webhook_secret' => $get('STRIPE_WEBHOOK_SECRET'),
            'cashier_currency'      => $get('CASHIER_CURRENCY', 'usd'),
            'paypal_mode'           => config('services.paypal.mode', 'sandbox'),
            'paypal_client_id'      => config('services.paypal.client_id'),
            'paypal_client_secret'  => config('services.paypal.client_secret'),
            'paypal_webhook_id'     => config('services.paypal.webhook_id'),
            'aws_key'               => config('filesystems.disks.s3.key'),
            'aws_secret'            => config('filesystems.disks.s3.secret'),
            'aws_region'            => config('filesystems.disks.s3.region', 'us-east-1'),
            'aws_bucket'            => config('filesystems.disks.s3.bucket'),
            'aws_endpoint'          => config('filesystems.disks.s3.endpoint'),
            'aws_url'               => config('filesystems.disks.s3.url'),
            'pusher_app_id'         => config('broadcasting.connections.pusher.app_id'),
            'pusher_app_key'        => config('broadcasting.connections.pusher.key'),
            'pusher_app_secret'     => config('broadcasting.connections.pusher.secret'),
            'pusher_host'           => config('broadcasting.connections.pusher.options.host'),
            'pusher_port'           => config('broadcasting.connections.pusher.options.port', 6001),
            'pusher_scheme'         => config('broadcasting.connections.pusher.options.scheme', 'http'),
            'meilisearch_host'      => config('scout.meilisearch.host'),
            'meilisearch_key'       => config('scout.meilisearch.key'),
            'youtube_api_key'       => config('services.youtube.api_key'),
            'brevo_api_key'         => config('services.brevo.api_key'),
            'support_email'         => config('mail.support_mail'),
            'server_email'          => config('mail.server_mail'),
            'statistics_enabled'    => config('app.statistics_enabled', false),
            'ignore_ips'            => implode(',', (array) config('app.ignore_ips', [])),
            'mail_mailer'           => config('mail.default', 'log'),
            'mail_host'             => config('mail.mailers.smtp.host'),
            'mail_port'             => config('mail.mailers.smtp.port', 587),
            'mail_username'         => config('mail.mailers.smtp.username'),
            'mail_encryption'       => config('mail.mailers.smtp.encryption', 'tls'),
            'mail_from_address'     => config('mail.from.address'),
            'mail_from_name'        => config('mail.from.name'),
        ];
    }

    private function syncDefaultLanguage(string $locale): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('languages')) {
                return;
            }

            $language = Language::query()
                ->where('code', $locale)
                ->where('is_active', true)
                ->first();

            if (! $language) {
                return;
            }

            Language::query()
                ->where('is_default', true)
                ->where('id', '!=', $language->id)
                ->update(['is_default' => false]);

            if (! $language->is_default) {
                $language->update(['is_default' => true]);
            }

            Language::flushCache();
        } catch (\Throwable) {
            // Keep settings save resilient if the language table is unavailable.
        }
    }
}
