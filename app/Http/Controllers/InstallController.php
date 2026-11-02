<?php

namespace App\Http\Controllers;

use App\Support\Installer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class InstallController implements HasMiddleware
{
    /** Retry-After of the page an unfinished installation shows to anybody but its owner (a 503: come back later). */
    public const int UNFINISHED_RETRY_AFTER = 3600;

    /**
     * No answer of the installer is ever indexed - the wizard, its progress and result pages, "Already Installed" and
     * "Installation Not Finished" (round 3 R3-038; the page carries the robots meta too).
     *
     * @return list<\Closure>
     */
    public static function middleware(): array
    {
        return [
            static function (Request $request, \Closure $next) {
                $response = $next($request);
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

                return $response;
            },
        ];
    }

    /** Wrong owner proofs (resume()) one address may send in RESUME_DECAY seconds, and all addresses together in an hour. */
    private const RESUME_TRIES = 5;

    private const RESUME_DECAY = 600;

    private const RESUME_TRIES_ALL = 30;

    public function __construct(private readonly Installer $installer)
    {
    }

    public function index(Request $request): View|Response|RedirectResponse|JsonResponse
    {
        if ($this->installer->isInstalled() && ! session('installer.complete')) {
            if ($request->expectsJson()) {
                return response()->json(['status' => 'installed']);
            }

            return view('install.index', $this->viewData('installed'));
        }

        // An installation that did not finish serves only its owner (R1B-001): anybody else is asked for the password of
        // its database first, and sees no detail of it. While the owner's reinstall switch (INSTALLER_ALLOW_REINSTALL) is
        // set, the wizard stays open as that switch says, and its first page offers the same proof (BI-RV-02).
        $pending = $this->pendingInstallation();
        $otherRun = null;
        if ($pending !== null && ! $this->ownsPending($pending)) {
            if (! $pending['reinstall']) {
                if ($request->expectsJson()) {
                    return response()->json(['status' => 'not_owner'], 403);
                }

                // The site is not there yet: 503 with Retry-After tells a crawler to come back (round 3 R3-038; the
                // home page redirects here while the installation is unfinished). The page is the same for its owner.
                return response()->view('install.index', $this->resumeViewData($pending), 503, ['Retry-After' => (string) self::UNFINISHED_RETRY_AFTER]);
            }

            $otherRun = $this->publicPending($pending);
        }

        $step = (string) $request->query('step', session('installer.step', 'introduction'));

        if ($step === 'installing') {
            if (! $this->installer->runOwnedBy((string) session('installer.run_token', ''))) {
                return $request->expectsJson()
                    ? response()->json(['status' => 'missing'], 404)
                    : $this->redirectToInstall('install');
            }

            if ($request->expectsJson()) {
                // The progress page asks for this while a step runs (the bar moves between two steps).
                return response()->json($this->sessionRunProgress());
            }
        }

        if (! in_array($step, array_column($this->installer->steps(), 'key'), true) && ! in_array($step, ['finished', 'installing'], true)) {
            $step = 'introduction';
        }

        return view('install.index', array_merge($this->viewData($step), ['pending' => $otherRun]));
    }

    public function store(string $step, Request $request): View|RedirectResponse|JsonResponse
    {
        if ($this->installer->isInstalled()) {
            abort(403, 'Application is already installed. Reinstallation is locked for security.');
        }

        $pending = $this->pendingInstallation();
        if ($pending !== null && ! $this->ownsPending($pending)) {
            if ($step === 'resume') {
                return $this->resume($request, $pending);
            }

            // With the reinstall switch the wizard is open (Installer::startRun() decides whether Install Now may start
            // again in place of that installation); without it, only the proof.
            if (! $pending['reinstall']) {
                if ($request->expectsJson()) {
                    return response()->json(['status' => 'not_owner', 'error' => 'This installation is continued from the browser that started it, or after its database password was given on the first page of the installer.'], 403);
                }

                return $this->redirectToInstall();
            }
        }

        return match ($step) {
            'introduction' => $this->advance('requirements'),
            'requirements' => $this->storeRequirements(),
            'filesystem' => $this->storeFilesystem(),
            'database' => $this->storeDatabase($request),
            'admin' => $this->storeAdmin($request),
            'install' => $this->runInstall($request),
            'run' => $this->runStep($request),
            default => $this->redirectToInstall(),
        };
    }

    /** @return array{kind: string, id: string, target: array<string, string>, started_at: ?int, updated_at: ?int, percent: ?int, reinstall: bool, provable: bool}|null */
    private function pendingInstallation(): ?array
    {
        return $this->safeValue(fn (): ?array => $this->installer->unfinishedInstallation(), null);
    }

    /**
     * What a page may say about an unfinished installation of somebody else: never its database, host, user or a path.
     *
     * @param array{kind: string, id: string, started_at: ?int, updated_at?: ?int, percent?: ?int, reinstall?: bool, provable: bool} $pending
     * @return array{kind: string, started_at: ?int, idle_seconds: ?int, percent: ?int, reinstall: bool, provable: bool, idle_limit: int, resume_file: ?string, resume_file_present: bool}
     */
    private function publicPending(array $pending): array
    {
        $updated = $pending['updated_at'] ?? null;

        // R3-083: after too many wrong passwords from all addresses together, the name of the file that proves the owner
        // (resume()); nothing that names the server, its database or a path but the site's own storage/app
        $resumeFile = $pending['provable'] && $this->overallLimitReached() ? $this->resumeFileName($pending) : null;

        return [
            'kind' => $pending['kind'],
            'started_at' => $pending['started_at'] ?? null,
            'idle_seconds' => is_int($updated) ? max(0, time() - $updated) : null,
            'percent' => $pending['percent'] ?? null,
            'reinstall' => (bool) ($pending['reinstall'] ?? false),
            'provable' => (bool) $pending['provable'],
            'idle_limit' => Installer::RUN_IDLE_SECONDS,
            'resume_file' => $resumeFile,
            'resume_file_present' => $resumeFile !== null && is_file($this->resumeFilePath($pending)),
        ];
    }

    /** Whether the wrong passwords of all addresses together reached the hour's limit (resume()). */
    private function overallLimitReached(): bool
    {
        return RateLimiter::tooManyAttempts('installer-resume', self::RESUME_TRIES_ALL);
    }

    /**
     * The file that proves the owner once the overall limit is reached (R3-083): a random name made once for this
     * browser's session and this unfinished installation, so the file the owner makes opens the way for the owner's own
     * tries only - every other browser is shown a name of its own, which nobody makes, and stays under the overall limit
     * (one name for the whole installation would have let everybody guess again while the owner's file was there) - and
     * no page view writes the run note while the owner's steps write it.
     *
     * @param array{kind: string, id: string} $pending
     */
    private function resumeFileName(array $pending): string
    {
        $session = request()->session();
        $kept = $session->get('installer.resume_file');

        if (is_array($kept) && hash_equals((string) ($kept['for'] ?? ''), (string) $pending['id'])
            && is_string($kept['name'] ?? null) && preg_match('/^continue-install-[0-9a-f]{16}\.txt$/', $kept['name']) === 1) {
            return $kept['name'];
        }

        $name = 'continue-install-'.bin2hex(random_bytes(8)).'.txt';
        $session->put('installer.resume_file', ['for' => (string) $pending['id'], 'name' => $name]);

        return $name;
    }

    /** @param array{kind: string, id: string} $pending */
    private function resumeFilePath(array $pending): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.$this->resumeFileName($pending));
    }

    /** The progress of this session's run; "changed" when the session's database settings are not the run's (BI-RV-01). */
    private function sessionRunProgress(): array
    {
        $database = session('installer.database');

        if (is_array($database) && ! $this->installer->runMatches($database)) {
            return $this->installer->changedProgress();
        }

        return $this->installer->runProgress();
    }

    /** @param array{kind: string, id: string} $pending */
    private function ownsPending(array $pending): bool
    {
        if ($pending['kind'] === 'run') {
            return $this->installer->runOwnedBy((string) session('installer.run_token', ''));
        }

        return hash_equals($pending['id'], (string) session('installer.owner_proof', ''));
    }

    /**
     * The owner of an unfinished installation proves it with the password of its database (Installer::proveOwnership()),
     * a few tries per address only. Then this session goes on with it: a run of this installer from the Admin step (its
     * database work goes on where it stopped), an earlier installer's half-made site from the start of the wizard with
     * its database settings filled in.
     *
     * @param array{kind: string, id: string, target: array<string, string>, provable: bool} $pending
     */
    private function resume(Request $request, array $pending): RedirectResponse
    {
        $key = 'installer-resume:'.sha1((string) $request->ip());

        if (RateLimiter::tooManyAttempts($key, self::RESUME_TRIES)) {
            // The wait of the limit that was reached (BI-RV-04: 10 minutes for one address).
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return $this->redirectToInstall()->withErrors(['resume_password' => 'Too many tries. Wait '.$minutes.' minute'.($minutes === 1 ? '' : 's').' and try again.']);
        }

        // Round 3 R3-083: the overall limit (all addresses together, against guessing from many addresses) no longer
        // shuts the owner out - strangers held it shut for an hour, again and again. Once it is reached, a password is
        // compared only together with the file proof: an empty file with the name the page shows this browser, made in
        // storage/app/ through the hosting's file manager, which nobody but the owner can do. Without it the try is
        // refused before any password is compared (and not counted: nothing was tried).
        $fileProof = $this->overallLimitReached();

        if ($fileProof && ! is_file($this->resumeFilePath($pending))) {
            // the name itself stands whole, with a Copy button, in the page's "One more step" box (install/resume-file)
            return $this->redirectToInstall()->withErrors(['resume_password' => 'Too many wrong passwords were tried here from other addresses. First create the empty file named below in the folder storage/app of this site with your hosting\'s file manager, then enter the password again.']);
        }

        $password = (string) $request->input('resume_password', '');

        if (! $pending['provable'] || ! $this->installer->proveOwnership($pending, $password)) {
            RateLimiter::hit($key, self::RESUME_DECAY);
            RateLimiter::hit('installer-resume', 3600);

            return $this->redirectToInstall()->withErrors(['resume_password' => $pending['provable']
                ? 'That is not the password of this installation\'s database.'
                : 'This installation cannot be continued with a password: its database user has none. Start it again instead (see below).']);
        }

        RateLimiter::clear($key);

        if ($fileProof) {
            // the proof is used once: the file goes (the page said so)
            @unlink($this->resumeFilePath($pending));
            $request->session()->forget('installer.resume_file');
        }

        $request->session()->regenerate();

        if ($pending['kind'] === 'run') {
            $token = bin2hex(random_bytes(16));
            $this->installer->adoptRun($token);
            session([
                'installer.run_token' => $token,
                'installer.database' => $this->installer->runDatabaseSettings($password),
                'installer.database_test' => null,
            ]);

            return $this->advance('admin');
        }

        $backup = $this->safeValue(fn (): array => $this->installer->backupInfo(), ['exists' => false]);
        session([
            'installer.owner_proof' => $pending['id'],
            'installer.database' => $pending['target'] + [
                'password' => $password,
                'mode' => ($backup['exists'] ?? false) ? 'backup' : 'migrate',
                'overwrite' => false,
            ],
            'installer.database_test' => null,
        ]);

        return $this->advance('requirements');
    }

    private function storeRequirements(): RedirectResponse
    {
        try {
            $this->installer->repairRequirements();
        } catch (Throwable $e) {
            return $this->redirectToInstall('requirements')
                ->withErrors(['requirements' => 'Auto repair could not finish safely on this host: '.$e->getMessage()]);
        }

        try {
            $requirementsPass = $this->installer->requirementsPass();
        } catch (Throwable $e) {
            return $this->redirectToInstall('requirements')
                ->withErrors(['requirements' => 'Requirement checks could not finish on this host: '.$e->getMessage()]);
        }

        if (! $requirementsPass) {
            return $this->redirectToInstall('requirements')
                ->withErrors(['requirements' => 'Some required server checks still need a hosting-panel or PHP configuration change. Review the failed items below and recheck after fixing them.']);
        }

        return $this->advance('filesystem');
    }

    private function storeFilesystem(): RedirectResponse
    {
        try {
            $this->installer->repairFilesystem();
        } catch (Throwable $e) {
            return $this->redirectToInstall('filesystem')
                ->withErrors(['filesystem' => 'Filesystem repair could not finish safely on this host: '.$e->getMessage()]);
        }

        try {
            $filesystemPass = $this->installer->filesystemPass();
        } catch (Throwable $e) {
            return $this->redirectToInstall('filesystem')
                ->withErrors(['filesystem' => 'Filesystem checks could not finish on this host: '.$e->getMessage()]);
        }

        if (! $filesystemPass) {
            return $this->redirectToInstall('filesystem')
                ->withErrors(['filesystem' => 'Some required folders are missing or not writable. Update permissions and try again.']);
        }

        return $this->advance('database');
    }

    private function storeDatabase(Request $request): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'string', 'max:10'],
            'database' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'mode' => ['required', 'in:backup,migrate'],
            'overwrite' => ['nullable', 'boolean'],
        ])->validate();

        $validated['password'] = (string) ($validated['password'] ?? '');
        $validated['overwrite'] = $request->boolean('overwrite');

        if ($validated['mode'] === 'backup' && ! $this->installer->backupInfo()['exists']) {
            return $this->redirectToInstall('database')
                ->withInput($request->except('password'))
                ->withErrors(['mode' => 'The bundled SQL backup was not found. Choose migrations or include the backup file.']);
        }

        $test = $this->installer->testDatabase($validated);
        if (! $test['ok']) {
            return $this->redirectToInstall('database')
                ->withInput($request->except('password'))
                ->withErrors(['database' => $test['message']]);
        }

        if ($test['tables'] !== [] && ! $validated['overwrite']) {
            return $this->redirectToInstall('database')
                ->withInput($request->except('password'))
                ->withErrors([
                    'overwrite' => 'This database already has tables. Leave replacement disabled to preserve them, or enable it only when intentionally replacing this database.',
                ]);
        }

        session([
            'installer.database' => $validated,
            'installer.database_test' => $test,
        ]);

        return $this->advance('admin');
    }

    private function storeAdmin(Request $request): RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'app_name' => ['required', 'string', 'max:80'],
            'app_url' => ['required', 'url', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'mail_mailer' => ['required', 'in:log,smtp,sendmail'],
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'string', 'max:10'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:500'],
            'mail_encryption' => ['nullable', 'in:,tls,ssl'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
        ])->validate();

        session([
            'installer.admin' => [
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ],
            'installer.app' => [
                'name' => $validated['app_name'],
                'url' => rtrim($validated['app_url'], '/'),
                'mail_mailer' => $validated['mail_mailer'],
                'mail_host' => (string) ($validated['mail_host'] ?? ''),
                'mail_port' => (string) ($validated['mail_port'] ?? '587'),
                'mail_username' => (string) ($validated['mail_username'] ?? ''),
                'mail_password' => (string) ($validated['mail_password'] ?? ''),
                'mail_encryption' => (string) ($validated['mail_encryption'] ?? ''),
                'mail_from_address' => (string) ($validated['mail_from_address'] ?? $validated['email']),
            ],
        ]);

        return $this->advance('install');
    }

    /**
     * "Install Now": starts the installation (or goes on with the one this session started) and shows its progress page,
     * whose script runs it step by step (runStep()). Nothing long happens in this request (R1B-001).
     */
    private function runInstall(Request $request): View|RedirectResponse
    {
        $payload = $this->installPayload();

        if ($payload === null) {
            return $this->redirectToInstall('database')
                ->withErrors(['install' => 'Installation data is incomplete. Please review the database and admin steps.']);
        }

        if (($payload['database']['overwrite'] ?? false) && ! $request->boolean('confirm_overwrite')) {
            return $this->redirectToInstall('install')
                ->withErrors(['confirm_overwrite' => 'Confirm the intentional database replacement before installation can continue.']);
        }

        try {
            $this->installer->startRun($payload, $this->runToken());
        } catch (Throwable $e) {
            return $this->redirectToInstall('install')
                ->withErrors(['install' => $e->getMessage()]);
        }

        return $this->advance('installing');
    }

    /**
     * One step of the installation (Installer::runStep()). The progress page's script asks for JSON and goes on until
     * the work is done; then (or without script, from the page's button) a page request writes the final .env and the
     * lock and shows the result page.
     */
    private function runStep(Request $request): View|RedirectResponse|JsonResponse
    {
        $json = $request->expectsJson();
        $payload = $this->installPayload();

        if ($payload === null) {
            $error = 'Installation data is incomplete. Please review the database and admin steps.';

            return $json
                ? response()->json(['status' => 'failed', 'error' => $error, 'phases' => [], 'percent' => 0, 'messages' => [], 'notes' => []], 422)
                : $this->redirectToInstall('database')->withErrors(['install' => $error]);
        }

        $progress = $this->installer->runStep($payload, (string) session('installer.run_token', ''), $request->boolean('retry'), ! $json);

        if ($json) {
            return response()->json($progress, $progress['status'] === 'not_owner' ? 403 : 200);
        }

        if ($progress['status'] === 'finished') {
            session()->forget(['installer.database', 'installer.database_test', 'installer.admin', 'installer.app', 'installer.run_token', 'installer.owner_proof', 'installer.step']);

            return view('install.index', array_merge($this->viewData('finished'), [
                'messages' => $progress['messages'],
                'notes' => $progress['notes'],
                // What was done about the scheduler's cron line (Installer::schedulerAdvice(), round 3 R3-036).
                'scheduler' => $progress['scheduler'] ?? null,
            ]));
        }

        if (in_array($progress['status'], ['missing', 'not_owner'], true)) {
            return $this->redirectToInstall('install');
        }

        // The progress page shows what this very step answered (also "changed": nothing was done).
        return view('install.index', array_merge($this->viewData('installing'), ['run' => $progress]));
    }

    /** @return array{database: array<string, mixed>, admin: array<string, string>, app: array<string, string>}|null */
    private function installPayload(): ?array
    {
        $database = session('installer.database');
        $admin = session('installer.admin');
        $app = session('installer.app');

        if (! is_array($database) || ! is_array($admin) || ! is_array($app)) {
            return null;
        }

        return ['database' => $database, 'admin' => $admin, 'app' => $app];
    }

    /** The secret of this wizard session that owns its installation (Installer::startRun()). */
    private function runToken(): string
    {
        $token = (string) session('installer.run_token', '');

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            session(['installer.run_token' => $token]);
        }

        return $token;
    }

    private function advance(string $step): RedirectResponse
    {
        session(['installer.step' => $step]);

        return $this->redirectToInstall($step);
    }

    private function redirectToInstall(?string $step = null): RedirectResponse
    {
        return redirect()->to($this->installPath($step));
    }

    private function installPath(?string $step = null): string
    {
        $prefix = $this->installPrefix();

        if ($step === null || $step === '') {
            return $prefix . '/install';
        }

        return $prefix . '/install?' . http_build_query(['step' => $step]);
    }

    private function installPrefix(): string
    {
        $path = trim((string) request()->path(), '/');

        return ! empty($_SERVER['AVDBCLIP_ROOT_FALLBACK'])
            || ! empty($_SERVER['AVDBCLIP_INDEX_PHP_PREFIX'])
            || str_starts_with($path, 'index.php/install')
            ? '/index.php'
            : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(string $step): array
    {
        // The locked page of an installed site is public and shows no server detail, so it runs no check either: the
        // requirement checks fetch the site's own address (the private-files probe, up to 3 s per try) and scan its
        // folders, which made every visit of /install cost seconds and requests of the server's (R1-104).
        if ($step === 'installed') {
            return $this->lockedViewData();
        }

        $secureScheme = \App\Support\Environment::isSecureRequest() ? 'https' : request()->getScheme();
        // With the folder of a site installed in one (https://example.com/site; R2R-05).
        $detectedUrl = $secureScheme . '://' . request()->getHttpHost() . request()->getBasePath();
        $appDefaults = $this->safeValue(
            fn (): array => $this->installer->appDefaults($detectedUrl),
            [
                'name' => 'AVDBCMS',
                'url' => $detectedUrl,
                'mail_mailer' => 'log',
                'mail_host' => '',
                'mail_port' => '587',
                'mail_username' => '',
                'mail_password' => '',
                'mail_encryption' => '',
                'mail_from_address' => '',
                'upload18_api_key' => '',
                'upload18_api_url' => 'https://upload18.org/api',
                'upload18_default_cid' => '15',
                'upload18_default_mycid' => '0',
                'upload18_default_fid' => '18',
                'upload18_allow_server_fallback' => 'false',
            ]
        );

        $ownsRun = in_array($step, ['install', 'installing'], true)
            && $this->safeValue(fn (): bool => $this->installer->runOwnedBy((string) session('installer.run_token', '')), false);

        // The checks run only on the steps that show them: the requirement checks fetch the site's own address (the
        // private-files probe) and scan its folders, which every other page (and the progress page of the installation)
        // does not need.
        return [
            'step' => $step,
            'steps' => $this->safeValue(fn (): array => $this->installer->steps(), [
                ['key' => 'introduction', 'label' => 'Introduction'],
                ['key' => 'requirements', 'label' => 'Requirements'],
                ['key' => 'filesystem', 'label' => 'Filesystem'],
                ['key' => 'database', 'label' => 'Database'],
                ['key' => 'admin', 'label' => 'Admin Account'],
                ['key' => 'install', 'label' => 'Install'],
            ]),
            'requirements' => $step === 'requirements' ? $this->requirementsForView() : [],
            'filesystem' => $step === 'filesystem' ? $this->filesystemForView() : [],
            'ownership' => $step === 'filesystem'
                ? $this->safeValue(fn (): array => $this->installer->fileOwnership(), [])
                : [],
            'envOwnership' => $step === 'filesystem'
                ? $this->safeValue(fn (): ?array => $this->installer->envFileOwnership(), null)
                : null,
            'run' => $ownsRun
                ? $this->safeValue(fn (): ?array => $step === 'installing' ? $this->sessionRunProgress() : $this->installer->runProgress(), null)
                : null,
            // The Install step: whether this session's run is the installation of the settings shown there (BI-RV-01).
            'runMatches' => $ownsRun && $step === 'install' && is_array(session('installer.database'))
                && $this->safeValue(fn (): bool => $this->installer->runMatches((array) session('installer.database')), false),
            'notes' => [],
            'pending' => null,
            'reinstallPending' => false,
            'backup' => $this->safeValue(fn (): array => $this->installer->backupInfo(), [
                'exists' => false,
                'path' => null,
                'filename' => 'No SQL backup detected',
                'configured_filename' => '',
                'size' => 'Not bundled',
                'source' => 'Backup check failed',
                'available' => [],
            ]),
            'environment' => $this->safeValue(fn (): array => $this->installer->environmentSummary(), [
                'os' => PHP_OS_FAMILY,
                'sapi' => strtolower((string) php_sapi_name()),
                'web_server' => 'Unknown web server',
                'panel' => 'Unknown panel',
                'document_root' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? 'Not reported by server'),
                'project_root' => base_path(),
                'public_path' => public_path(),
                'public_document_root' => false,
                'open_basedir' => (string) ini_get('open_basedir') ?: 'Not restricted',
            ]),
            'serverGuidance' => $this->safeValue(fn (): array => $this->installer->serverGuidance(), [[
                'label' => 'Installer diagnostics',
                'ok' => false,
                'detail' => 'Could not complete web-server detection',
                'help' => 'The installer can still continue when requirements and filesystem checks pass. If routes fail later, point the domain to public/ or use a front-controller rewrite.',
            ]]),
            'serverConfigSnippet' => $this->safeValue(
                fn (): string => $this->installer->serverConfigSnippet(),
                'Set the domain document root to public/ when possible. Otherwise route all requests to index.php with the original query string.'
            ),
            // The result page's scheduler advice comes from what the last step did (runStep(): Installer::schedulerAdvice());
            // no other page needs it (finding the PHP command line starts processes: seconds on some hosts).
            'scheduler' => null,
            'database' => session('installer.database', []),
            'databaseTest' => session('installer.database_test', null),
            'admin' => session('installer.admin', []),
            'app' => array_replace($appDefaults, session('installer.app', [])),
            'messages' => session('installer.messages', []),
            'detectedUrl' => $detectedUrl,
        ];
    }

    /**
     * What the locked page needs: its address and the steps' names. Every other value is empty (the page prints none).
     *
     * @return array<string, mixed>
     */
    private function lockedViewData(): array
    {
        $secureScheme = \App\Support\Environment::isSecureRequest() ? 'https' : request()->getScheme();

        return [
            'step' => 'installed',
            'steps' => $this->installer->steps(),
            'requirements' => [],
            'filesystem' => [],
            'ownership' => [],
            'envOwnership' => null,
            'run' => null,
            'runMatches' => false,
            'notes' => [],
            'pending' => null,
            // A reinstallation next to the lock that did not finish (on another database: the site keeps working): the page
            // says how its owner goes on with it (BI-RV-02). No detail of it.
            'reinstallPending' => $this->safeValue(fn (): bool => $this->installer->hasUnfinishedReinstall(), false),
            'backup' => ['exists' => false, 'path' => null, 'filename' => '', 'configured_filename' => '', 'size' => '', 'source' => '', 'available' => []],
            'environment' => ['os' => '', 'sapi' => '', 'web_server' => '', 'panel' => '', 'document_root' => '', 'project_root' => '', 'public_path' => '', 'public_document_root' => false, 'open_basedir' => ''],
            'serverGuidance' => [],
            'serverConfigSnippet' => '',
            'scheduler' => null,
            'database' => [],
            'databaseTest' => null,
            'admin' => [],
            'app' => [],
            'messages' => [],
            'detectedUrl' => $secureScheme . '://' . request()->getHttpHost() . request()->getBasePath(),
        ];
    }

    /**
     * The page an unfinished installation shows to anybody but its owner (R1B-001): like the locked page it is public and
     * names no server detail - not the database, its host or user, nor any path - and runs no check.
     *
     * @param array{kind: string, started_at: ?int, provable: bool} $pending
     * @return array<string, mixed>
     */
    private function resumeViewData(array $pending): array
    {
        return array_merge($this->lockedViewData(), [
            'step' => 'resume',
            'reinstallPending' => false,
            'pending' => $this->publicPending($pending),
        ]);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @param T $fallback
     * @return T
     */
    private function safeValue(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array<int, array{label:string,ok:bool,required:bool,detail:string,help:string,fix:string,action:string,auto_fix:bool,docs:string,severity:string}>
     */
    private function requirementsForView(): array
    {
        try {
            return $this->installer->requirements();
        } catch (Throwable $e) {
            return [$this->installerCheck(
                'Installer diagnostics',
                false,
                true,
                'Could not complete server requirement checks: '.$e->getMessage()
            )];
        }
    }

    /**
     * @return array<int, array{label:string,path:string,ok:bool,required:bool,detail:string,help:string,fix:string,auto_fix:bool}>
     */
    private function filesystemForView(): array
    {
        try {
            return $this->installer->filesystemChecks();
        } catch (Throwable $e) {
            return [$this->filesystemCheck(
                'Runtime filesystem',
                storage_path(),
                false,
                true,
                'Could not inspect runtime folders: '.$e->getMessage()
            )];
        }
    }

    /**
     * @return array{label:string,ok:bool,required:bool,detail:string,help:string,fix:string,action:string,auto_fix:bool,docs:string,severity:string}
     */
    private function installerCheck(string $label, bool $ok, bool $required, string $detail): array
    {
        return [
            'label' => $label,
            'ok' => $ok,
            'required' => $required,
            'detail' => $detail,
            'help' => 'A host-specific probe failed before it could report details.',
            'fix' => 'Check PHP error logs and confirm PHP 8.3+, required extensions, complete vendor/, and writable storage/ plus bootstrap/cache/.',
            'action' => 'Check server logs',
            'auto_fix' => false,
            'docs' => 'The package should still show this installer page instead of a generic 500.',
            'severity' => $ok ? 'ok' : ($required ? 'danger' : 'warning'),
        ];
    }

    /**
     * @return array{label:string,path:string,ok:bool,required:bool,detail:string,help:string,fix:string,auto_fix:bool}
     */
    private function filesystemCheck(string $label, string $path, bool $ok, bool $required, string $detail): array
    {
        return [
            'label' => $label,
            'path' => $path,
            'ok' => $ok,
            'required' => $required,
            'detail' => $detail,
            'help' => 'PHP must be able to create, update, and remove files in runtime folders.',
            'fix' => 'Make storage/ and bootstrap/cache/ writable by the web/PHP user.',
            'auto_fix' => false,
        ];
    }
}
