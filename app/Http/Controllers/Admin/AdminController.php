<?php

namespace App\Http\Controllers\Admin;

use App\Charts\PremiumEarningsChart;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use App\Models\Video;
use App\Models\Plan;
use App\Support\AdminCache;
use App\Support\AdminNav;
use App\Support\AdminPreferences;
use App\Support\AppearanceSettings;
use App\Support\AppVersion;
use App\Support\MasterExperienceCatalog;
use App\Support\PendingMigrations;
use App\Support\SettingsRevision;
use App\Support\ThemeCatalog;
use App\Support\Updater\PlanStore;
use App\Support\Updater\ReleaseCheck;
use App\Support\Updater\UpdatePaths;
use App\Support\Updater\Updater;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AdminController
{
    /**
     * Notices an administrator can hide on Home (AdminPreferences "dismissed"); 'blocked-words': the videos the update to
     * 1.2.3 hid (App\Support\BlockedWordsPass::NOTICE).
     */
    public const NOTICES = ['home-checklist', 'blocked-words'];

    /** @var array<string, true>|null see hasTable() */
    private ?array $tables = null;

    /** @var array{recent: list<array>, failed: list<array>}|null see importRuns() */
    private ?array $runs = null;

    public function index(Request $request): View
    {
        // 1.2.3: on a site too big for the update's own slice, a visit of Home goes on with the hiding of the videos that
        // use words that are always blocked for a few seconds, after the page is sent (App\Support\BlockedWordsPass; the
        // scheduler does the same every minute, so a site whose cron job does not run yet still gets there).
        if (\App\Support\BlockedWordsPass::due()) {
            app()->terminating(static fn () => rescue(fn () => \App\Support\BlockedWordsPass::run(\App\Support\BlockedWordsPass::VISIT_SECONDS), null, false));
        }

        if (! AdminPreferences::usesNewAdmin($request->user())) {
            return $this->dashboard();
        }

        return view('admin.home.index', $this->home($request));
    }

    /** Hide a Home notice for this administrator ("Hide this list"), or show it again (show=1). */
    public function dismiss(Request $request): JsonResponse|RedirectResponse
    {
        $notice = (string) $request->validate(['notice' => ['required', 'string', 'in:'.implode(',', self::NOTICES)]])['notice'];
        $show = $request->boolean('show');
        $user = $request->user();

        if (AdminPreferences::available($user)) {
            if ($show) {
                AdminPreferences::set($user, 'dismissed', array_values(array_diff((array) AdminPreferences::get($user, 'dismissed', []), [$notice])));
            } else {
                AdminPreferences::dismiss($user, $notice);
            }
        }

        // Before "Finish update" there is nowhere to keep it: this session remembers it.
        $session = array_values(array_diff((array) $request->session()->get('admin.dismissed', []), [$notice]));
        $request->session()->put('admin.dismissed', $show || AdminPreferences::available($user) ? $session : [...$session, $notice]);

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : redirect()->route('admin.index');
    }

    public static function dismissed(Request $request, string $notice): bool
    {
        return AdminPreferences::dismissed($request->user(), $notice)
            || in_array($notice, (array) $request->session()->get('admin.dismissed', []), true);
    }

    /**
     * Home: the update banner, the getting-started steps, what needs the administrator, this week in numbers, the
     * latest imports and comments. Database numbers are cached for a minute (any admin change clears them); the slow
     * server checks (scheduler, disk, health check, providers) load after the page appears (StatusProbeController).
     */
    private function home(Request $request): array
    {
        // v2 (1.2.3): the week's earnings are cents (v1 held them divided by 100).
        $data = AdminCache::remember('home.v2', now()->addMinute(), fn (): array => [
            'counts' => $this->homeCounts(),
            'metrics' => $this->weekMetrics(),
            'imports' => $this->recentImports(),
            'comments' => $this->latestComments(),
        ]);
        $pending = PendingMigrations::pending();
        $appearance = $this->appearanceRows();

        return $data + [
            'siteName' => (string) config('app.name'),
            'update' => $this->updateState($request, $pending, $appearance),
            'release' => $this->releaseNotice(),
            'steps' => $this->steps($data['counts'], $appearance),
            // "Hide this list": a link under "Needs you" brings it back while steps are left.
            'stepsHidden' => self::dismissed($request, 'home-checklist'),
            'needs' => $this->needs($data['counts']),
            'probes' => $this->probes($data['counts']),
        ];
    }

    /**
     * What Home says about the update: pending database changes (Finish update), language files with texts this version
     * corrected after an upload by hand without a database change (Correct the texts), the look the upgrade moved
     * (Restore my previous look, 30 days) or the previous look in use after a restore (Use the new look again).
     */
    private function updateState(Request $request, array $pending, ?array $appearance): array
    {
        $state = ['pending' => count($pending), 'moves_look' => false, 'look' => null, 'snapshot' => null, 'texts' => []];

        if ($pending !== []) {
            $state['moves_look'] = collect($pending)->contains(fn (string $name): bool => str_contains($name, 'theme_catalog_v2'));

            return $state;
        }

        // Language files the owner changed that still have texts as an older version shipped them (Updater::pendingTexts():
        // one cached answer, read again only when a language file changes). Finish update does them when it shows.
        $state['texts'] = (array) rescue(fn (): array => (app()->bound(Updater::class) ? app(Updater::class) : new Updater())->pendingTexts(), [], false);

        if ($appearance === null || ! SettingsRevision::available()) {
            return $state;
        }

        $snapshot = SettingsRevision::upgradeSnapshot();

        // Each notice can be answered once per upgrade snapshot: "Keep the new look" / "Keep my previous look".
        if (! AppearanceSettings::isUpgraded($appearance)) {
            $state['look'] = self::dismissed($request, 'look-previous-'.($snapshot['id'] ?? 0)) ? null : 'previous';
        } elseif ($snapshot !== null && ! self::dismissed($request, 'look-moved-'.$snapshot['id'])) {
            $state['look'] = 'moved';
        }

        $state['snapshot'] = $snapshot;

        return $state;
    }

    /**
     * Home's notice about AVDBCMS itself, from what the server keeps (rendering Home never asks GitHub): an update
     * that runs or stopped (any administrator's), else a newer version from the kept check of Tools > Updates. With
     * nothing kept, "checking": the page asks the `updates` status probe after it appears (it may ask GitHub, at most
     * once in 12 hours) and shows its answer in place.
     *
     * @return array{running: ?string, action: ?string, available: ?array{version: string, name: string}, checking: bool}
     */
    private function releaseNotice(): array
    {
        $notice = ['running' => null, 'action' => null, 'available' => null, 'checking' => false];

        try {
            // plan.json read as it is (Updater::plan() may also settle a failed swap; Tools > Updates does that).
            $paths = app()->bound(Updater::class) ? app(Updater::class)->paths() : UpdatePaths::forApp();
            $plan = PlanStore::read($paths->plan());

            if (Updater::active($plan)) {
                $stalled = Updater::stalled($plan);

                return array_merge($notice, [
                    'running' => $stalled ? Updater::stalledMessage($plan) : Updater::busyMessage($plan),
                    'action' => $stalled ? __('See the update') : __('Show progress'),
                ]);
            }

            $check = (new ReleaseCheck())->cached();

            if ($check === null) {
                // Not while the probe holds an answer of its own: that would ask for the page twice on every visit.
                $notice['checking'] = StatusProbeController::peek('updates') === null;
            } elseif (($check['state'] ?? null) === 'available' && AppVersion::isVersion($check['release']['version'] ?? null)) {
                $notice['available'] = ['version' => (string) $check['release']['version'], 'name' => (string) ($check['release']['name'] ?? '')];
            }
        } catch (Throwable) {
            // Home never fails because of this notice.
        }

        return $notice;
    }

    /** The old dashboard's one link to Tools > Updates: a newer AVDBCMS in the kept check (never asks GitHub). */
    private function availableRelease(): ?string
    {
        try {
            $check = (new ReleaseCheck())->cached();
        } catch (Throwable) {
            return null;
        }

        $version = $check['release']['version'] ?? null;

        return ($check['state'] ?? null) === 'available' && AppVersion::isVersion($version) ? (string) $version : null;
    }

    /** @return array<string, ?string>|null the stored appearance rows (null when they cannot be read) */
    private function appearanceRows(): ?array
    {
        try {
            return SettingsRevision::rows('appearance');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The database's tables, read once per request (a check per table would be one query each); an old database simply
     * lacks some of them.
     */
    private function hasTable(string $table): bool
    {
        if ($this->tables === null) {
            try {
                $this->tables = array_fill_keys(array_map('strtolower', Schema::getTableListing(Schema::getCurrentSchemaName(), false)), true);
            } catch (Throwable) {
                $this->tables = [];
            }
        }

        return isset($this->tables[strtolower(DB::getTablePrefix().$table)]);
    }

    private function homeCounts(): array
    {
        // The single numbers come in one query (a sub-select each, for the tables this database has): [query, aggregate].
        $numbers = array_filter([
            'videos' => $this->hasTable('videos') ? [DB::table('videos')->whereNull('deleted_at'), 'COUNT(*)'] : null,
            'avdb_videos' => $this->hasTable('videos') ? [DB::table('videos')->whereNull('deleted_at')->whereNotNull('avdb_id'), 'COUNT(*)'] : null,
            'unread_messages' => $this->hasTable('contact_messages') ? [DB::table('contact_messages')->where('is_read', false), 'COUNT(*)'] : null,
            'last_message_at' => $this->hasTable('contact_messages') ? [DB::table('contact_messages')->where('is_read', false), 'MAX(created_at)'] : null,
            'failed_jobs' => $this->hasTable('failed_jobs') ? [DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7)), 'COUNT(*)'] : null,
            'avdb_schedules' => $this->hasTable('avdb_import_schedules') ? [DB::table('avdb_import_schedules')->where('enabled', true), 'COUNT(*)'] : null,
            'xvid_schedules' => $this->hasTable('xvid_import_schedules') ? [DB::table('xvid_import_schedules')->where('is_active', true), 'COUNT(*)'] : null,
            'plans' => $this->hasTable('plans') ? [DB::table('plans'), 'COUNT(*)'] : null,
        ]);
        $row = [];

        if ($numbers !== []) {
            try {
                $query = DB::query();

                foreach ($numbers as $alias => [$table, $aggregate]) {
                    $query->selectSub((clone $table)->selectRaw($aggregate), $alias);
                }

                $row = (array) $query->first();
            } catch (Throwable) {
                // A table of another shape (an older database): each number on its own, so the others still show.
                foreach ($numbers as $alias => [$table, $aggregate]) {
                    try {
                        $row[$alias] = $table->value(DB::raw($aggregate));
                    } catch (Throwable) {
                    }
                }
            }
        }

        $reports = [];

        try {
            $reports = Report::query()
                ->where('status', ReportStatus::PENDING)
                ->selectRaw('reportable_type, COUNT(DISTINCT reportable_id) as total')
                ->groupBy('reportable_type')
                ->pluck('total', 'reportable_type')
                ->mapWithKeys(fn ($total, $type): array => [Str::lower(class_basename((string) $type)) => (int) $total])
                ->all();
        } catch (Throwable) {
        }

        $failedImports = array_map(
            fn (array $run): array => ['provider' => $run['provider'], 'id' => (int) $run['id'], 'message' => (string) $run['message']],
            $this->importRuns()['failed']
        );

        return [
            'videos' => (int) ($row['videos'] ?? 0),
            'avdb_videos' => (int) ($row['avdb_videos'] ?? 0),
            'reports' => $reports,
            'unread_messages' => (int) ($row['unread_messages'] ?? 0),
            'last_message_at' => $row['last_message_at'] ?? null,
            'failed_jobs' => (int) ($row['failed_jobs'] ?? 0),
            'failed_imports' => $failedImports,
            'import_schedules' => (int) ($row['avdb_schedules'] ?? 0) + (int) ($row['xvid_schedules'] ?? 0),
            'backups' => $this->latestBackup(),
            'plans' => (int) ($row['plans'] ?? 0),
        ];
    }

    private function latestBackup(): ?int
    {
        $latest = null;

        foreach (glob(storage_path('app/backups/*')) ?: [] as $file) {
            $time = is_file($file) ? (int) @filemtime($file) : 0;
            $latest = max((int) $latest, $time) ?: null;
        }

        return $latest;
    }

    /**
     * Daily numbers of the last 14 days: this week (7 days) against the week before, and a sparkline per number. The
     * four series come in one query (UNION ALL of a daily count per table).
     */
    private function weekMetrics(): array
    {
        $from = today()->subDays(13);
        $days = collect(range(0, 13))->map(fn (int $i): string => $from->copy()->addDays($i)->toDateString())->all();
        $sources = [
            'views' => $this->hasTable('video_view_daily_stats') ? ['video_view_daily_stats', 'view_date', 'SUM(views_count)', false] : ['views', 'view_at', 'COUNT(*)', false],
            'videos' => ['videos', 'created_at', 'COUNT(*)', true],
            'members' => ['users', 'created_at', 'COUNT(*)', true],
            'comments' => ['comments', 'created_at', 'COUNT(*)', true],
        ];
        $series = array_fill_keys(array_keys($sources), []);
        $query = null;

        foreach ($sources as $key => [$table, $column, $aggregate, $deleted]) {
            if (! $this->hasTable($table)) {
                continue;
            }

            $part = DB::table($table)
                ->where($column, '>=', $from->toDateString())
                ->when($deleted, fn ($query) => $query->whereNull('deleted_at'))
                ->selectRaw("'".$key."' as series, DATE(".$column.') as day, '.$aggregate.' as total')
                ->groupByRaw('DATE('.$column.')');
            $query = $query === null ? $part : $query->unionAll($part);
        }

        try {
            foreach ($query?->get() ?? [] as $row) {
                $series[$row->series][(string) $row->day] = (int) $row->total;
            }
        } catch (Throwable) {
        }

        $metrics = [];

        foreach ($series as $key => $values) {
            $points = array_map(fn (string $day): int => (int) ($values[$day] ?? 0), $days);
            $week = array_sum(array_slice($points, 7));
            $before = array_sum(array_slice($points, 0, 7));
            $metrics[$key] = [
                'week' => $week,
                'before' => $before,
                'today' => $points[13],
                'points' => $points,
            ];
        }

        $metrics['earnings'] = $this->weekEarnings();

        return $metrics;
    }

    private function weekEarnings(): array
    {
        // The site's own reading of the setting (AppServiceProvider: on unless it is switched off). 'week' is cents as
        // the payments store them (JPY: whole yen): Home writes it with PremiumAvailability::money(), like Payments.
        $enabled = (bool) config('site.premium_enabled', true);
        $earnings = ['enabled' => $enabled, 'week' => 0];

        if ($enabled) {
            try {
                $earnings['week'] = (int) round((float) DB::table('transactions')->where('date', '>=', now()->subDays(7))->sum('amount'));
            } catch (Throwable) {
            }
        }

        return $earnings;
    }

    private function recentImports(): array
    {
        $runs = $this->importRuns()['recent'];
        usort($runs, fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_slice($runs, 0, 3);
    }

    /**
     * The import runs Home shows, in one query (UNION ALL of a part per provider and kind, each with its own order and
     * limit): the 3 latest runs of each provider and its 3 latest failures of the last 2 days.
     *
     * @return array{recent: list<array>, failed: list<array>}
     */
    private function importRuns(): array
    {
        if ($this->runs !== null) {
            return $this->runs;
        }

        $this->runs = ['recent' => [], 'failed' => []];
        $columns = ['id', 'status', 'imported', 'failed', 'completed_pages', 'total_pages', 'message', 'created_at', 'finished_at'];
        $kinds = [
            'recent' => fn ($query) => $query,
            'failed' => fn ($query) => $query->where('status', 'failed')->where('created_at', '>=', now()->subDays(2)),
        ];
        $query = null;

        foreach (['avdb_import_runs' => 'AVDBAPI', 'xvid_import_runs' => 'XVIDAPI'] as $table => $provider) {
            if (! $this->hasTable($table)) {
                continue;
            }

            foreach ($kinds as $kind => $scope) {
                $part = $scope(DB::table($table))
                    ->select($columns)
                    ->selectRaw("'".$provider."' as provider, '".$kind."' as kind")
                    ->orderByDesc('id')
                    ->limit(3);
                $query = $query === null ? $part : $query->unionAll($part);
            }
        }

        try {
            foreach ($query?->get() ?? [] as $run) {
                $this->runs[$run->kind][] = (array) $run;
            }
        } catch (Throwable) {
        }

        return $this->runs;
    }

    private function latestComments(): array
    {
        try {
            // A video's address reads its creator and category: loaded here, not once per comment; no poster.
            return Comment::query()
                ->with(['user:id,username', 'video' => fn ($query) => $query->withTrashed()->without('thumbnail')->with(['user:id,username', 'category:id,title'])])
                ->whereNull('parent_id')
                ->latest('id')
                ->limit(3)
                ->get(['id', 'video_id', 'user_id', 'content', 'banned_at', 'created_at'])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The getting-started steps (a real sequence: numbered). Steps whose page this site cannot open are left out.
     *
     * @return list<array{title: string, text: ?string, done: bool, action: ?array}>
     */
    private function steps(array $counts, ?array $appearance): array
    {
        $url = fn (string $page): ?string => ($found = AdminNav::page($page)) !== null ? AdminNav::url($found) : null;
        $siteName = (string) config('app.name');
        $logo = (string) config('site.logo_url', '') !== '';
        $named = $siteName !== '' && ! in_array(Str::lower($siteName), ['laravel', 'avdbclip', 'avdbcms'], true);
        $mailer = (string) config('mail.default', 'smtp');
        $emails = ! in_array($mailer, ['log', 'array'], true) && ($mailer !== 'smtp' || (string) config('mail.mailers.smtp.host', '') !== '');
        $health = StatusProbeController::peek('health');
        $look = $this->lookName($appearance ?? []);

        $steps = [
            [
                'title' => __('Name your site and add a logo'),
                'text' => $named && $logo ? __(':name, logo uploaded', ['name' => $siteName]) : __('Shown in the header, in emails and in Google.'),
                'done' => $named && $logo,
                'action' => [__('Add your logo'), $url('appearance.brand')],
            ],
            [
                'title' => __('Choose a look'),
                'text' => $look ?? __('A complete look sets the theme, the homepage and the video page in one click.'),
                'done' => $look !== null,
                'action' => [__('Choose a look'), $url('appearance.themes')],
            ],
            [
                'title' => __('Import your first videos'),
                'text' => $counts['videos'] > 0
                    ? trans_choice('1 video|:count videos', $counts['videos'], ['count' => number_format($counts['videos'])])
                    : __('From AVDBAPI or XVIDAPI: pick categories and how many, then start.'),
                'done' => $counts['videos'] > 0,
                'action' => [__('Import videos'), $url('content.import')],
            ],
            [
                'title' => __('Make sure emails arrive'),
                'text' => $emails ? __('Emails are sent.') : __('Password resets and sign-up emails are not delivered yet.'),
                'done' => $emails,
                'action' => [__('Set up email'), $url('settings.email')],
            ],
            [
                'title' => __('Back up your database'),
                'text' => $counts['backups'] !== null && $counts['backups'] >= now()->subDays(30)->getTimestamp()
                    ? __('Last backup :when', ['when' => \Carbon\Carbon::createFromTimestamp($counts['backups'])->diffForHumans()])
                    : __('Keeps a copy of your database in case something goes wrong.'),
                'done' => $counts['backups'] !== null && $counts['backups'] >= now()->subDays(30)->getTimestamp(),
                'action' => [__('Make a backup'), $url('tools.backups')],
            ],
            [
                'title' => __('Run the health check'),
                'text' => $health !== null && (int) ($health['data']['counts']['danger'] ?? 1) === 0
                    ? __('No problems found.')
                    : __('Checks folders, scheduled tasks and server settings in one go.'),
                'done' => $health !== null && (int) ($health['data']['counts']['danger'] ?? 1) === 0,
                'action' => [__('Run check'), $url('tools.health')],
            ],
        ];

        // A step whose page cannot open here (e.g. backups while the operational tools are off) is not shown.
        $steps = array_values(array_filter($steps, fn (array $step): bool => $step['done'] || $step['action'][1] !== null));

        return in_array(false, array_column($steps, 'done'), true) ? $steps : [];
    }

    private function lookName(array $appearance): ?string
    {
        $master = (string) ($appearance['active_master_experience'] ?? '');
        $look = $master !== '' ? MasterExperienceCatalog::find($master) : null;

        if ($look !== null) {
            return __($look['label']);
        }

        $theme = (string) ($appearance['theme_preset'] ?? '');
        $found = $theme !== '' ? ThemeCatalog::theme($theme) : null;

        return $found !== null ? __($found['name']) : null;
    }

    /**
     * What needs the administrator now (only things that are not zero, each with one action). The server checks
     * (scheduler, disk, health check, providers) are added after the page appears.
     *
     * @return list<array{icon: string, tone: string, title: string, text: ?string, action: array{0: string, 1: ?string}}>
     */
    private function needs(array $counts): array
    {
        $needs = [];
        $url = fn (string $page): ?string => ($found = AdminNav::page($page)) !== null ? AdminNav::url($found) : null;

        if (config('site.maintenance_mode', false)) {
            $needs[] = [
                'icon' => 'lock',
                'tone' => 'warn',
                'title' => __('Visitors see the maintenance page'),
                'text' => __('Turn maintenance mode off when your site is ready.'),
                'action' => [__('Change'), $url('settings.maintenance')],
            ];
        }

        if (config('site.robots_noindex', false)) {
            $needs[] = [
                'icon' => 'eye-off',
                'tone' => 'warn',
                'title' => __('Hidden from Google'),
                'text' => __('Search engines are asked not to list your site.'),
                'action' => [__('Change'), $url('settings.seo')],
            ];
        }

        // Premium checkout closed although payments were set up: a service with its keys but no webhook value (1.2.3
        // requires it), or plans without the id of a connected service. Nothing for a site that never set up payments.
        $checkout = rescue(fn (): array => \App\Support\PremiumAvailability::setupProblems(), [], false);

        if ($checkout !== []) {
            $webhooks = rescue(fn (): array => \App\Support\PremiumAvailability::missingWebhooks(), [], false);
            // The webhook value is added on Payment setup (at its field); a plan's ids on Premium plans.
            $fix = $webhooks !== []
                ? (($setup = $url('money.setup')) === null ? null : $setup.'#pay-'.($webhooks[0] === 'stripe' ? 'STRIPE_WEBHOOK_SECRET' : 'PAYPAL_WEBHOOK_ID'))
                : $url('money.plans');
            $needs[] = [
                'icon' => 'credit',
                'tone' => 'warn',
                'title' => __('Members cannot buy premium right now'),
                'text' => implode(' ', $checkout),
                'action' => [__('Set up payments'), $fix],
            ];
        }

        // 1.2.3: the videos the update hid because their titles, tags or categories use words that are always blocked
        // (App\Support\BlockedWordsPass), with the list to review them, until the administrator dismisses it.
        $hidden = self::dismissed(request(), \App\Support\BlockedWordsPass::NOTICE) ? 0 : \App\Support\BlockedWordsPass::hiddenCount();

        if ($hidden > 0) {
            $needs[] = [
                'icon' => 'eye-off',
                'tone' => 'warn',
                'title' => trans_choice('1 video was hidden because its title, tags or category use blocked words|:count videos were hidden because their titles, tags or categories use blocked words', $hidden, ['count' => number_format($hidden)]),
                'text' => __('They are banned, so visitors no longer see them. Review them and unban any video that was hidden by mistake.'),
                'action' => [__('Review'), route('admin.videos.index', ['status' => \App\Enums\VideoStatus::BANNED->value, 'reason' => \App\Support\BlockedWordsPass::FILTER], false)],
                'dismiss' => \App\Support\BlockedWordsPass::NOTICE,
            ];
        }

        if ($counts['failed_imports'] !== []) {
            $first = $counts['failed_imports'][0];
            $failedMessage = rtrim(Str::limit($first['message'], 90), ': ');
            $needs[] = [
                'icon' => 'alert-circle',
                'tone' => 'danger',
                'title' => trans_choice('1 import failed|:count imports failed', count($counts['failed_imports']), ['count' => count($counts['failed_imports'])]),
                'text' => $failedMessage !== '' ? \App\Support\AdminText::labelled((string) $first['provider'], $failedMessage) : (string) $first['provider'],
                'action' => [__('See details'), route($first['provider'] === 'AVDBAPI' ? 'admin.avdb-import.index' : 'admin.xvid-import.index', [], false)],
            ];
        }

        $reports = array_sum($counts['reports']);

        if ($reports > 0) {
            $parts = [];

            foreach (['video' => '1 video|:count videos', 'comment' => '1 comment|:count comments', 'user' => '1 member|:count members'] as $type => $words) {
                if (($counts['reports'][$type] ?? 0) > 0) {
                    $parts[] = trans_choice($words, $counts['reports'][$type], ['count' => $counts['reports'][$type]]);
                }
            }

            $needs[] = [
                'icon' => 'flag',
                'tone' => 'accent',
                'title' => trans_choice('1 report waiting|:count reports waiting', $reports, ['count' => $reports]),
                'text' => $parts !== [] ? Str::ucfirst(\App\Support\AdminText::list($parts)).'.' : null,
                'action' => [__('Review'), route('admin.reports.index', [], false)],
            ];
        }

        if ($counts['unread_messages'] > 0) {
            $needs[] = [
                'icon' => 'inbox',
                'tone' => 'info',
                'title' => trans_choice('1 unread message|:count unread messages', $counts['unread_messages'], ['count' => $counts['unread_messages']]),
                'text' => $counts['last_message_at'] !== null
                    ? __('From the contact form, the last one :when.', ['when' => \Carbon\Carbon::parse($counts['last_message_at'])->diffForHumans()])
                    : null,
                'action' => [__('Open'), route('admin.contacts.index', [], false)],
            ];
        }

        // Only where the jobs page opens (it is one of the operational tools, which a site can switch off).
        $jobsUrl = AdminNav::url(['route' => 'admin.failed-jobs.index', 'params' => []]);

        if ($counts['failed_jobs'] > 0 && $jobsUrl !== null) {
            $needs[] = [
                'icon' => 'alert',
                'tone' => 'warn',
                'title' => trans_choice('1 background job failed this week|:count background jobs failed this week', $counts['failed_jobs'], ['count' => $counts['failed_jobs']]),
                'text' => __('Retry them, or see why they failed.'),
                'action' => [__('See jobs'), $jobsUrl],
            ];
        }

        // A view-only demo account is not offered a closed page (ViewOnlyDemo::hides()): what sends there is left out.
        return array_values(array_filter($needs, fn (array $need): bool => ! \App\Http\Middleware\ViewOnlyDemo::hidesUrl($need['action'][1] ?? null)));
    }

    /**
     * The slow checks Home shows (in "Needs you" when they find something): cached answers or null (asked later).
     *
     * @return array<string, ?array>
     */
    private function probes(array $counts): array
    {
        // 'storage': can private files under storage/ be downloaded (checked once a day, after the page appears).
        $names = ['schedule', 'disk', 'health', 'storage'];

        // The providers are only asked while imports are scheduled.
        if ($counts['import_schedules'] > 0) {
            $names[] = 'provider';
        }

        return collect($names)->mapWithKeys(fn (string $probe): array => [$probe => StatusProbeController::peek($probe)])
            // A view-only demo account is not offered a closed page (ViewOnlyDemo::hides()): a check that sends there is left out.
            ->reject(fn (?array $answer): bool => \App\Http\Middleware\ViewOnlyDemo::hidesUrl(is_array($answer['action'] ?? null) ? (string) ($answer['action']['url'] ?? '') : null))
            ->all();
    }

    /** The old admin's dashboard (one release: the admin's "Use the old admin"). */
    private function dashboard(): View
    {
        $data = AdminCache::remember('dashboard.index', now()->addSeconds(45), function () {
            return [
                'videos_count' => Video::count(),
                'users_count' => User::count(),
                'comments_count' => Comment::count(),
                'reports_count' => Report::count(),
                'videos_count_today' => $this->createdTodayCount('videos'),
                'users_count_today' => $this->createdTodayCount('users'),
                'comments_count_today' => $this->createdTodayCount('comments'),
                'totalViews' => $this->totalVideoViews(),
                'plansCount' => Plan::count(),
                'subscriptions' => DB::table('premiums')->count(),
                'activeSubscriptions' => DB::table('premiums')
                    ->whereIn('stripe_status', ['active', 'trialing'])
                    ->where(function ($query) {
                        $query->whereNull('ends_at')
                            ->orWhere('ends_at', '>', now());
                    })
                    ->count(),
                'categoriesCount' => DB::table('categories')->count(),
                'playlistsCount' => DB::table('playlists')->count(),
                'failedJobsCount' => DB::table('failed_jobs')->count(),
                'searchesCount' => DB::table('searches')->count(),
                'ipBansCount' => DB::table('ip_bans')->count(),
                'recentUsers' => User::orderByDesc('created_at')->limit(5)->get(['id', 'username', 'email', 'avatar', 'created_at', 'is_admin']),
                'recentVideos' => Video::with('user:id,username')
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(['id', 'title', 'uuid', 'views', 'status', 'created_at', 'user_id']),
                'storageUsed' => $this->storageUsed(),
                'diskFree' => $this->diskFree(),
                'dbSize' => $this->dbSize(),
                'unreadContacts' => $this->unreadContacts(),
                'reports' => $this->pendingReports(),
            ];
        });

        return view('admin.index', $data + [
            // The old dashboard also offers "Finish update" after a new version is uploaded.
            'pendingMigrations' => PendingMigrations::count(),
            // 1.2.3: and says how many videos the update hid for blocked words, as Home's "Needs you" does
            'blockedWordsHidden' => self::dismissed(request(), \App\Support\BlockedWordsPass::NOTICE) ? 0 : \App\Support\BlockedWordsPass::hiddenCount(),
            // ... and a link to Tools > Updates when a newer AVDBCMS is out (no other new page in the old admin).
            'availableRelease' => $this->availableRelease(),
            'todayUsers' => $data['users_count_today'],
            'todayVideos' => $data['videos_count_today'],
            'todayComments' => $data['comments_count_today'],
            'chart' => (new PremiumEarningsChart())->build(),
        ]);
    }

    private function pendingReports(): \Illuminate\Support\Collection
    {
        return Report::select(
            'reportable_type',
            'reportable_id',
            DB::raw('MIN(created_at) as first_report_at'),
            DB::raw('count(*) as reports_count')
        )
            ->with([
                'reportable' => function (MorphTo $morphTo) {
                    $morphTo->morphWith([
                        User::class,
                        Video::class => ['user'],
                        Comment::class => ['user']
                    ]);
                }
            ])
            ->where('status', ReportStatus::PENDING)
            ->groupBy('reportable_type', 'reportable_id')
            ->orderBy('reports_count', 'desc')
            ->orderBy('first_report_at', 'asc')
            ->limit(10)
            ->get();
    }

    private function storageUsed(): string
    {
        $storagePath = storage_path('app/public');

        if (!is_dir($storagePath) || !disk_total_space($storagePath) || !disk_free_space($storagePath)) {
            return '0 GB';
        }

        $usedBytes = (disk_total_space($storagePath) - disk_free_space($storagePath));
        return round($usedBytes / 1073741824, 2) . ' GB';
    }

    private function diskFree(): string
    {
        $bytes = disk_free_space(base_path());

        if (!is_numeric($bytes)) {
            return '0 GB';
        }

        return round($bytes / 1073741824, 2) . ' GB';
    }

    private function dbSize(): string
    {
        try {
            $dbName = config('database.connections.mysql.database');
            $result = DB::select("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb FROM information_schema.tables WHERE table_schema = ?", [$dbName]);

            return ($result[0]->size_mb ?? 0) . ' MB';
        } catch (\Exception) {
            return '0 MB';
        }
    }

    private function unreadContacts(): int
    {
        try {
            return (int) DB::table('contact_messages')->where('is_read', false)->count();
        } catch (\Exception) {
            return 0;
        }
    }

    private function createdTodayCount(string $table): int
    {
        return (int) DB::table($table)
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [today()->startOfDay(), today()->endOfDay()])
            ->count();
    }

    private function totalVideoViews(): int
    {
        $query = DB::table('videos')->whereNull('deleted_at');

        if (DB::connection()->getDriverName() === 'mysql') {
            $query->forceIndex('videos_deleted_views_idx');
        }

        return (int) $query->sum('views');
    }
}
