<?php

namespace App\Http\Controllers;

use App\Support\Installer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class InstallController
{
    public function __construct(private readonly Installer $installer)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        if ($this->installer->isInstalled() && ! session('installer.complete')) {
            return view('install.index', $this->viewData('installed'));
        }

        $step = (string) $request->query('step', session('installer.step', 'introduction'));
        if (! in_array($step, array_column($this->installer->steps(), 'key'), true) && $step !== 'finished') {
            $step = 'introduction';
        }

        return view('install.index', $this->viewData($step));
    }

    public function store(string $step, Request $request): View|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            abort(403, 'Application is already installed. Reinstallation is locked for security.');
        }

        return match ($step) {
            'introduction' => $this->advance('requirements'),
            'requirements' => $this->storeRequirements(),
            'filesystem' => $this->storeFilesystem(),
            'database' => $this->storeDatabase($request),
            'admin' => $this->storeAdmin($request),
            'install' => $this->runInstall($request),
            default => $this->redirectToInstall(),
        };
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

    private function runInstall(Request $request): View|RedirectResponse
    {
        $database = session('installer.database');
        $admin = session('installer.admin');
        $app = session('installer.app');

        if (! is_array($database) || ! is_array($admin) || ! is_array($app)) {
            return $this->redirectToInstall('database')
                ->withErrors(['install' => 'Installation data is incomplete. Please review the database and admin steps.']);
        }

        if (($database['overwrite'] ?? false) && ! $request->boolean('confirm_overwrite')) {
            return $this->redirectToInstall('install')
                ->withErrors(['confirm_overwrite' => 'Confirm the intentional database replacement before installation can continue.']);
        }

        try {
            $messages = $this->installer->install([
                'database' => $database,
                'admin' => $admin,
                'app' => $app,
            ]);
        } catch (Throwable $e) {
            return $this->redirectToInstall('install')
                ->withErrors(['install' => $e->getMessage()]);
        }

        session()->forget(['installer.database', 'installer.database_test', 'installer.admin', 'installer.app']);

        return view('install.index', array_merge($this->viewData('finished'), [
            'messages' => $messages,
        ]));
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
        $secureScheme = \App\Support\Environment::isSecureRequest() ? 'https' : request()->getScheme();
        $detectedUrl = $secureScheme . '://' . request()->getHttpHost();
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
            'requirements' => $this->requirementsForView(),
            'filesystem' => $this->filesystemForView(),
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
            'schedulerCronCommand' => $this->safeValue(
                fn (): string => $this->installer->schedulerCronCommand(),
                'cd '.base_path().' && php artisan schedule:run >> storage/logs/schedule-run.log 2>&1'
            ),
            'database' => session('installer.database', []),
            'databaseTest' => session('installer.database_test', null),
            'admin' => session('installer.admin', []),
            'app' => array_replace($appDefaults, session('installer.app', [])),
            'messages' => session('installer.messages', []),
            'detectedUrl' => $detectedUrl,
        ];
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
