<?php

namespace App\Http\Controllers\Admin;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Support\RuntimeEnv;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/**
 * The Money section: earnings at a glance, ads, payment setup and the Premium page text. The three settings pages
 * are drawn from config/admin_settings.php like the Settings pages and save through PUT /admin/settings/save.
 */
class MoneyController
{
    /**
     * Payment keys the setup page may write to .env. They are never sent back to the browser: the page only
     * shows whether each one is set and how a long one ends. Patterns keep a pasted value on one line and roughly
     * in the right shape.
     */
    public const PAYMENT_KEYS = [
        'STRIPE_KEY' => ['label' => 'Publishable key', 'pattern' => '/^pk_(test|live)_[A-Za-z0-9]+$/', 'secret' => true],
        'STRIPE_SECRET' => ['label' => 'Secret key', 'pattern' => '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', 'secret' => true],
        'STRIPE_WEBHOOK_SECRET' => ['label' => 'Webhook signing secret', 'pattern' => '/^whsec_[A-Za-z0-9]+$/', 'secret' => true],
        'CASHIER_CURRENCY' => ['label' => 'Currency', 'pattern' => '/^[a-z]{3}$/', 'secret' => false],
        'PAYPAL_MODE' => ['label' => 'PayPal mode', 'pattern' => '/^(sandbox|live)$/', 'secret' => false],
        'PAYPAL_CLIENT_ID' => ['label' => 'Client ID', 'pattern' => '/^[A-Za-z0-9_\-]{10,200}$/', 'secret' => true],
        'PAYPAL_CLIENT_SECRET' => ['label' => 'Secret', 'pattern' => '/^[A-Za-z0-9_\-]{10,200}$/', 'secret' => true],
        'PAYPAL_WEBHOOK_ID' => ['label' => 'Webhook ID', 'pattern' => '/^[A-Za-z0-9_\-]{5,100}$/', 'secret' => true],
    ];

    /** Copies of .env kept in storage/app/env-backups (each holds every secret of the site, so only a few). */
    private const BACKUPS = 5;

    /** A saved key shows its last 4 characters ("Saved - ends in 4242") only when it is at least this long. */
    private const ENDING_MIN_LENGTH = 12;

    public function index(Request $request): View|RedirectResponse
    {
        if (! SettingsPageController::usesNewAdmin($request->user())) {
            return redirect()->route('admin.settings.index', ['tab' => 'monetization']);
        }

        $monetization = Setting::getGroup('monetization');
        $keys = $this->paymentKeyStatus();
        $stats = Cache::remember('admin.money.overview.v1', now()->addMinutes(5), fn (): array => $this->earnings());

        $steps = [
            ['done' => ($monetization['premium_enabled'] ?? '0') === '1', 'label' => __('Turn on premium membership'), 'url' => route('admin.money.setup').'#premium_enabled'],
            ['done' => $stats['plans'] > 0, 'label' => __('Add at least one premium plan'), 'url' => route('admin.plans.index')],
            ['done' => (($monetization['stripe_enabled'] ?? '0') === '1' && $keys['STRIPE_KEY'] && $keys['STRIPE_SECRET'])
                || (($monetization['paypal_enabled'] ?? '0') === '1' && $keys['PAYPAL_CLIENT_ID'] && $keys['PAYPAL_CLIENT_SECRET']),
                'label' => __('Connect card (Stripe) or PayPal payments'), 'url' => route('admin.money.setup').'#card-payment-keys'],
            ['done' => ($monetization['ads_enabled'] ?? '0') === '1', 'label' => __('Optional: show ads'), 'url' => route('admin.money.ads')],
        ];

        return view('admin.money.index', [
            'stats' => $stats,
            'steps' => $steps,
            'currency' => strtoupper((string) RuntimeEnv::get('CASHIER_CURRENCY', 'usd')),
        ]);
    }

    public function ads(Request $request): View|RedirectResponse
    {
        return app(SettingsPageController::class)->render($request, 'money.ads');
    }

    public function premiumPage(Request $request): View|RedirectResponse
    {
        return app(SettingsPageController::class)->render($request, 'money.premium_page', [
            'headerActions' => '<a class="ad-btn" href="'.e(url('/premium')).'" target="_blank" rel="noopener">'.SettingsPageController::icon('external').e(__('View the Premium page')).'</a>',
        ]);
    }

    public function setup(Request $request): View|RedirectResponse
    {
        return app(SettingsPageController::class)->render($request, 'money.setup', [
            'extrasBottom' => ['admin.money.payment-keys'],
            'paymentKeys' => self::PAYMENT_KEYS,
            'paymentKeyStatus' => $this->paymentKeyStatus(),
            'paymentKeyEnds' => $this->paymentKeyEnds(),
            'paymentPublic' => [
                'CASHIER_CURRENCY' => strtolower((string) RuntimeEnv::get('CASHIER_CURRENCY', 'usd')),
                'PAYPAL_MODE' => (string) RuntimeEnv::get('PAYPAL_MODE', 'sandbox'),
            ],
        ]);
    }

    /**
     * PUT /admin/money/setup/keys: writes the payment keys that were typed into .env (an empty field keeps the
     * saved key, clear[] removes one). Like a payout change on a payment dashboard, it needs the admin's own password
     * (`password`) whenever a key is typed or removed or anything would change: a wrong one answers 422 and writes
     * nothing. A backup of the previous file is kept outside the public folder, and every changed key is named in the
     * admin activity log. Typed values are never sent back, flashed into the session, logged or kept in a revision.
     */
    public function updateKeys(Request $request): JsonResponse|RedirectResponse
    {
        $rules = ['clear' => ['sometimes', 'array'], 'clear.*' => ['string', 'in:'.implode(',', array_keys(self::PAYMENT_KEYS))]];
        $attributes = [];

        foreach (self::PAYMENT_KEYS as $key => $definition) {
            $rules[$key] = ['nullable', 'string', 'max:255', 'regex:'.$definition['pattern']];
            $attributes[$key] = __($definition['label']);
        }

        $currency = $request->input('CASHIER_CURRENCY');
        $request->merge(['CASHIER_CURRENCY' => is_string($currency) && trim($currency) !== '' ? strtolower(trim($currency)) : $currency]);

        // Not $request->validate(): a refused form post would flash the typed keys into the session.
        $validator = Validator::make($request->only([...array_keys(self::PAYMENT_KEYS), 'clear']), $rules, [], $attributes);

        if ($validator->fails()) {
            return $this->refuse($request, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $values = [];
        foreach (array_keys(self::PAYMENT_KEYS) as $key) {
            if (($validated[$key] ?? '') !== '') {
                $values[$key] = (string) $validated[$key];
            }
        }
        foreach ($validated['clear'] ?? [] as $key) {
            $values[$key] ??= '';
        }

        [$content, $changed] = $this->plannedEnvironment($values);
        $touchesKey = array_filter(array_keys($values), fn (string $key): bool => self::PAYMENT_KEYS[$key]['secret']) !== [];

        // Asked even when a typed key equals the saved one: the answer must not tell a stranger whether it matched.
        if ($touchesKey || $changed !== []) {
            $password = SettingsPageController::checkPassword($request);

            if ($password !== 'ok') {
                return $this->refuse($request, ['password' => [match ($password) {
                    'missing' => __('Type your admin password to save payment keys.'),
                    'locked' => __('Too many tries. Wait a minute and try again.'),
                    default => __('That is not your password.'),
                }]], $password === 'locked' ? 429 : 422);
            }
        }

        if ($changed !== []) {
            $this->writeEnvironment($content);

            foreach ($changed as $key) {
                AuditLogController::log($values[$key] === '' ? 'money.payment_key.removed' : 'money.payment_key.saved', $key);
            }
        }

        $message = $changed === [] ? __('Nothing to save.') : __('Payment keys saved.');

        if ($request->expectsJson()) {
            // RuntimeEnv still holds the file as this request started: the changed keys take their state from the values.
            $status = $this->paymentKeyStatus();
            $ends = $this->paymentKeyEnds();
            foreach ($changed as $key) {
                $status[$key] = $values[$key] !== '';
                if (array_key_exists($key, $ends)) {
                    $ends[$key] = self::ending($key, $values[$key]);
                }
            }

            return response()->json(['ok' => true, 'message' => $message, 'saved' => $changed, 'status' => $status, 'ends' => $ends]);
        }

        return redirect()->route('admin.money.setup')->withSuccess($message);
    }

    /** A refused save: 422 (or 429) with the field errors; a form post goes back to the page without its input. */
    private function refuse(Request $request, array $errors, int $status = 422): JsonResponse|RedirectResponse
    {
        $message = (string) (array_values($errors)[0][0] ?? __('Nothing was saved. Check the highlighted settings.'));

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'message' => $message, 'errors' => $errors], $status);
        }

        return redirect()->route('admin.money.setup')->withErrors($errors);
    }

    /** @return array<string, bool> which payment keys have a value (the values themselves stay on the server) */
    private function paymentKeyStatus(): array
    {
        $status = [];

        foreach (array_keys(self::PAYMENT_KEYS) as $key) {
            $status[$key] = trim((string) RuntimeEnv::get($key, '')) !== '';
        }

        return $status;
    }

    /** @return array<string, ?string> the last 4 characters of each saved secret key (null: not set, or too short to show) */
    private function paymentKeyEnds(): array
    {
        $ends = [];

        foreach (self::PAYMENT_KEYS as $key => $definition) {
            if ($definition['secret']) {
                $ends[$key] = self::ending($key, trim((string) RuntimeEnv::get($key, '')));
            }
        }

        return $ends;
    }

    private static function ending(string $key, string $value): ?string
    {
        return self::PAYMENT_KEYS[$key]['secret'] && mb_strlen($value) >= self::ENDING_MIN_LENGTH ? mb_substr($value, -4) : null;
    }

    private function earnings(): array
    {
        $stats = ['plans' => 0, 'subscribers' => 0, 'month' => 0.0, 'month_count' => 0, 'total' => 0.0];

        try {
            $stats['plans'] = Plan::query()->count();
            $stats['subscribers'] = Schema::hasTable('premiums') ? Subscription::query()->active()->count() : 0;

            if (Schema::hasTable('transactions')) {
                $month = DB::table('transactions')->where('date', '>=', now()->startOfMonth())->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as cents')->first();
                $stats['month'] = (float) ($month->cents ?? 0) / 100;
                $stats['month_count'] = (int) ($month->n ?? 0);
                $stats['total'] = (float) DB::table('transactions')->sum('amount') / 100;
            }
        } catch (\Throwable) {
            // An install that has not run every migration yet still opens the page.
        }

        return $stats;
    }

    /**
     * Replace or add KEY=value lines, keeping the rest of the file as it is (same approach as the installer). Lines
     * that already hold the value are left alone, so nothing changes when no line does (the currency and the PayPal
     * mode are posted with every save).
     *
     * @return array{0: string, 1: list<string>} the new file and the keys whose line changed
     */
    private function plannedEnvironment(array $values): array
    {
        $path = app()->environmentFilePath();
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $changed = [];

        foreach ($values as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';

            if (preg_match($pattern, $content, $current)) {
                if (trim($current[1], " \t\r\"'") === $value) {
                    continue;
                }

                $content = preg_replace_callback($pattern, static fn (): string => $line, $content) ?? $content;
            } elseif ($value === '') {
                continue;
            } else {
                $content = rtrim($content)."\n".$line."\n";
            }

            $changed[] = $key;
        }

        return [$content, $changed];
    }

    /**
     * Write the planned file. The previous file is kept outside the public folder (the newest BACKUPS copies), and the
     * new one replaces it in one step with the same permissions, so a failed write never leaves a half-written .env.
     * Failures stay quiet (@) and throw a message of our own: a PHP warning would carry the file's content into the log.
     */
    private function writeEnvironment(string $content): void
    {
        $path = app()->environmentFilePath();
        $original = is_file($path) ? (string) @file_get_contents($path) : '';

        if ($original !== '') {
            $this->backupEnvironment($original);
        }

        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new \RuntimeException('The .env file could not be written.');
        }
        if (is_file($path)) {
            @chmod($temporary, fileperms($path) & 0777);
        }
        if (! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('The .env file could not be replaced.');
        }

        try {
            Artisan::call('config:clear');
        } catch (\Throwable) {
        }
    }

    private function backupEnvironment(string $content): void
    {
        $directory = storage_path('app/env-backups');
        if (! is_dir($directory)) {
            @mkdir($directory, 0700, true);
        }

        $backup = $directory.'/.env.'.now()->format('YmdHis');
        if (@file_put_contents($backup, $content) !== false) {
            @chmod($backup, 0600);
        }

        // Names sort by time; keep the newest copies only (each one holds every secret of the site).
        $old = glob($directory.'/.env.*') ?: [];
        rsort($old, SORT_STRING);
        foreach (array_slice($old, self::BACKUPS) as $file) {
            @unlink($file);
        }
    }
}
