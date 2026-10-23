<?php

namespace App\Support\Updater;

/**
 * The maintenance hook of a one-click update: storage/framework/maintenance.php while the files are swapped.
 * public/index.php requires that file before vendor/autoload.php, so a request reaches this class without Laravel
 * (vendor/ may be half replaced at that moment) and gets one of three answers:
 *
 *  - the Updates page's swap call (POST, the swap token, an Origin of this site): the next slice of moves of
 *    SwapEngine, as JSON; at the end it clears the caches and OPcache, deletes the hook and Laravel's "down" file
 *    (what `artisan up` does) and the site answers again, now with the new code;
 *  - the administrator who started it (the update cookie): a small page "The update stopped before it finished."
 *    with Finish and Undo, for a swap that was cut off (closed browser, host time limit); its forms carry a token
 *    derived from the cookie;
 *  - everyone else: 503 "This site is being updated. Please come back in a minute." with Retry-After.
 *
 * A hook whose swap is over (or whose plan is gone) deletes itself and lets the request through.
 *
 * EMBEDDED (like SwapEngine): PHP built-ins only, no imports, global classes with their leading backslash. The texts
 * come translated in the config: HookWriter renders them with Laravel before the site goes down.
 */
final class MaintenanceHook
{
    /** The failure reason of a swap the administrator stopped ("Undo the update" on the page of this hook). */
    public const STOPPED = 'stopped by the administrator';

    /** @var array<string, mixed> */
    private array $config;

    /** @param  array<string, mixed>  $config  see HookWriter::config() */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * The answer to a request, or null when the hook is not (any longer) the one to answer.
     *
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $post
     * @param  array<string, mixed>  $cookies
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public function handle(array $server, array $post, array $cookies): ?array
    {
        $file = (string) $this->config['plan'];

        if ($file === '' || ! is_file($file)) {
            // No plan at all (the update's work folder is gone): nothing left to protect, the site answers again.
            $this->standDown();

            return null;
        }

        $plan = self::readJson($file);

        if (! is_array($plan)) {
            // There, but not readable just now (an I/O error, no free file handle): files may be half moved, so this
            // is never a reason to let visitors in. The page and the administrator are told to try again.
            return $this->unreadable($server, $post, $cookies);
        }

        $status = (string) ($plan['status'] ?? '');

        if (($plan['id'] ?? null) !== $this->config['plan_id'] || ! in_array($status, ['swapping', 'rolling-back'], true)) {
            // A leftover of a swap that is over (or never began): the site answers again.
            $this->standDown();

            return null;
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $action = is_string($post['avdbcms_update'] ?? null) ? $post['avdbcms_update'] : '';
        $cookie = $cookies[(string) $this->config['cookie_name']] ?? null;
        // Finish / Undo of the administrator's page: the cookie, this site, the form token of that page.
        $choice = $method === 'POST' && in_array($action, ['finish', 'undo'], true) && $this->cookieValid($cookie) && $this->sameOrigin($server)
            && is_string($post['form_token'] ?? null) && hash_equals($this->formToken((string) $cookie), $post['form_token']);

        if ($status === 'swapping' && ! $choice && time() >= (int) ($this->config['token_expires'] ?? 0) && $this->abandon()) {
            // The answer that carried the token never arrived and nothing has moved: the site is back, the update
            // waits at "preparing" (its next step writes a new hook with a new token). The administrator's own choice
            // on the page of this hook is never overruled by that: it runs as it says.
            return null;
        }

        if ($method === 'POST' && $action === 'swap') {
            if (! $this->sameOrigin($server) || ! $this->tokenValid($post['token'] ?? null)) {
                return $this->visitorPage($server);
            }

            $result = $this->slice($status === 'rolling-back' ? 'rollback' : 'forward', false);

            return $this->json($result, ! empty($result['busy']) ? 409 : 200);
        }

        if (! $this->cookieValid($cookie)) {
            return $this->visitorPage($server);
        }

        if ($choice) {
            return $this->adminPage((string) $cookie, $this->slice($action === 'undo' ? 'rollback' : 'forward', $action === 'undo'), $action);
        }

        if ($this->wantsJson($server)) {
            // The Updates page asking how things stand (it lost its swap token, e.g. after a reload): it reloads and
            // gets the Finish/Undo page below.
            $progress = (array) ($plan['swap'] ?? []);

            return $this->json(['ok' => false, 'status' => $status, 'step' => $status, 'next' => 'reload',
                'label' => $this->text('admin_title'), 'progress' => ['done' => (int) ($progress['done'] ?? 0), 'total' => (int) ($progress['total'] ?? 0)],
                'message' => $this->text('admin_title')]);
        }

        return $this->adminPage((string) $cookie, null, null);
    }

    /**
     * One slice of moves (or of moves back) within the time budget, under the update lock.
     *
     * @return array<string, mixed>
     */
    private function slice(string $mode, bool $stop): array
    {
        $lock = @fopen((string) $this->config['lock'], 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return ['ok' => false, 'busy' => true, 'step' => 'swapping', 'next' => 'swap', 'message' => $this->text('busy')];
        }

        try {
            // A function in disable_functions does not exist at all since PHP 8 (calling it throws, "@" does not help),
            // and both of these are on many shared hosts' lists.
            if (\function_exists('ignore_user_abort')) {
                @\ignore_user_abort(true);
            }

            $budget = $this->budget();

            if (\function_exists('set_time_limit')) {
                @\set_time_limit((int) ceil($budget) + 60);
            }

            $deadline = microtime(true) + $budget;
            $plan = self::readJson((string) $this->config['plan']) ?? [];
            $engine = new SwapEngine($this->job());

            if (($plan['status'] ?? '') === 'rolling-back') {
                $mode = 'rollback';
            } elseif ($mode === 'rollback' && $stop) {
                $this->log('stopped by the administrator: putting the files back');
                $plan = $this->failing($plan, self::STOPPED);
            }

            if ($mode === 'forward') {
                try {
                    $result = $engine->forward($deadline);
                } catch (\Throwable $e) {
                    $this->log('a move failed: '.$e->getMessage());
                    $plan = $this->failing($plan, $e->getMessage());
                    $result = null;
                }

                if ($result !== null && $result['state'] === 'more') {
                    $plan['step_at'] = time();
                    $plan['swap'] = array_merge((array) ($plan['swap'] ?? []), ['done' => $result['done'], 'total' => $result['total']]);
                    // Progress only: the journal says what moved, so a plan that cannot be saved stops nothing.
                    $this->trySave($plan);

                    return ['ok' => true, 'step' => 'swapping', 'next' => 'swap', 'done' => $result['done'], 'total' => $result['total'],
                        'message' => strtr($this->text('installing'), [':done' => (string) $result['done'], ':total' => (string) $result['total']])];
                }

                if ($result !== null) {
                    $settle = $engine->settle();
                    $plan['status'] = 'swapped';
                    $plan['swapped_at'] = time();
                    $plan['step_at'] = time();
                    $plan['settle'] = $settle;
                    $plan['swap'] = array_merge((array) ($plan['swap'] ?? []), ['done' => $result['done'], 'total' => $result['total']]);
                    // Every file is in place: the site answers again even when the plan cannot say so yet (the
                    // updater's next step writes the hook again, finds every move done and saves it then).
                    $this->trySave($plan);
                    $this->log('all '.$result['total'].' moves done; the site is up again');
                    $this->standDown();

                    return ['ok' => true, 'step' => 'swapped', 'next' => 'finish', 'done' => $result['done'], 'total' => $result['total'],
                        'wait' => $settle['wait'], 'message' => $this->text('swapped')];
                }
            }

            $result = $engine->rollback($deadline);

            if ($result['state'] === 'more') {
                $plan['step_at'] = time();
                $this->trySave($plan);

                return ['ok' => false, 'step' => 'rolling-back', 'next' => 'swap', 'left' => $result['left'],
                    'message' => strtr($this->text('putting_back'), [':left' => (string) $result['left']])];
            }

            $settle = $engine->settle();
            $plan['status'] = 'failed';
            $plan['rolled_back'] = true;
            $plan['kept'] = $result['kept'];
            $plan['step_at'] = time();
            $plan['settle'] = $settle;

            if (! $this->trySave($plan)) {
                // Every file is back, but the plan still says the swap runs: a later swap must start again from its
                // first file, never skip one this way back put back (its "undone" mark may not have been written).
                $this->retireJournal($engine);
            }

            $this->log('every file is back as it was'.($result['kept'] !== [] ? '; left alone (changed since): '.implode(', ', array_slice($result['kept'], 0, 20)) : ''));
            $this->standDown();

            // "Continue" of a rollback after an error posts "undo" too: the plan, not the button, says who stopped it.
            $stopped = ($plan['failure']['reason'] ?? '') === self::STOPPED;

            return ['ok' => false, 'step' => 'failed', 'next' => null, 'kept' => $result['kept'], 'stopped' => $stopped,
                'message' => $stopped ? $this->text('undone') : $this->text('failed')];
        } catch (\Throwable $e) {
            // Not even the way back could run (a missing backup, a read-only folder): the site stays offline and the
            // administrator's page says how to put the files back by hand.
            $this->log('the files could not be put back: '.$e->getMessage());

            return ['ok' => false, 'step' => 'stuck', 'next' => null, 'message' => $this->text('stuck')];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * The swap goes the other way. Saving that may fail (the disk or quota that made a move fail is full): the files
     * come back anyway, the plan is saved when it can be.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function failing(array $plan, string $reason): array
    {
        $plan['status'] = 'rolling-back';
        $plan['step_at'] = time();
        $plan['failure'] = ['step' => 'swap', 'reason' => mb_substr($reason, 0, 300), 'at' => date('c'), 'audited' => false];
        $this->trySave($plan);

        return $plan;
    }

    /**
     * A swap whose token ran out before it began (no op of the main or last phase has a journal mark): the answer that
     * carried the token was lost (a closed tab, a timeout). Under the update lock the plan goes back to "preparing",
     * the hashes of the live files are dropped (they are old by now) and the hook takes itself away, so a lost answer
     * keeps the site down for the token's 30 minutes at most. True when it stood down.
     */
    private function abandon(): bool
    {
        $lock = @fopen((string) $this->config['lock'], 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return false;
        }

        try {
            $plan = self::readJson((string) $this->config['plan']);

            if (! is_array($plan) || ($plan['id'] ?? null) !== $this->config['plan_id'] || ($plan['status'] ?? '') !== 'swapping') {
                return false;
            }

            $engine = new SwapEngine($this->job());
            $marks = $engine->marks();

            foreach ($engine->ops() as $op) {
                if (($op['phase'] ?? 'main') !== 'pre' && isset($marks[(int) $op['i']])) {
                    // The swap began: only its token or the administrator's page may go on from here.
                    return false;
                }
            }

            $plan['status'] = 'preparing';
            $plan['step_at'] = time();
            unset($plan['swap']);
            $this->savePlan($plan);
            @unlink(dirname((string) $this->config['job']).'/live.json');
            $this->log('the swap token ran out before the swap began: the site answers again, the update waits for its next step');
            $this->standDown();

            return true;
        } catch (\Throwable $e) {
            $this->log('the maintenance page could not take itself away: '.$e->getMessage());

            return false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * plan.json could not be read: visitors get the 503 page; the page's swap call (valid token) is told to wait
     * (409, it asks again), the administrator's page says the same.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function unreadable(array $server, array $post, array $cookies): array
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'POST' && ($post['avdbcms_update'] ?? null) === 'swap' && $this->sameOrigin($server) && $this->tokenValid($post['token'] ?? null)) {
            return $this->json(['ok' => false, 'busy' => true, 'step' => 'swapping', 'next' => 'swap', 'message' => $this->text('busy')], 409);
        }

        $cookie = $cookies[(string) $this->config['cookie_name']] ?? null;

        if ($this->cookieValid($cookie) && ! $this->wantsJson($server)) {
            return $this->adminPage((string) $cookie, ['busy' => true], 'status');
        }

        return $this->visitorPage($server);
    }

    /** @return array<string, mixed> SwapEngine's job */
    private function job(): array
    {
        $job = self::readJson((string) $this->config['job']);

        if (! is_array($job)) {
            throw new \RuntimeException('The update job cannot be read.');
        }

        return $job + ['log' => $this->config['log'] ?? null];
    }

    /** Seconds one request may spend moving files: 20, less where PHP stops scripts sooner. */
    private function budget(): float
    {
        $budget = (float) ($this->config['budget'] ?? 20);
        $limit = (int) ini_get('max_execution_time');

        if ($limit > 0) {
            $budget = min($budget, max(3, $limit - 8));
        }

        return max(3.0, $budget);
    }

    /** Only the site's own pages may ask: Sec-Fetch-Site same-origin, or an Origin header naming this site. */
    private function sameOrigin(array $server): bool
    {
        $fetchSite = strtolower((string) ($server['HTTP_SEC_FETCH_SITE'] ?? ''));

        if ($fetchSite !== '') {
            return $fetchSite === 'same-origin';
        }

        $origin = strtolower(rtrim((string) ($server['HTTP_ORIGIN'] ?? ''), '/'));

        if ($origin === '' || $origin === 'null') {
            return false;
        }

        if (in_array($origin, array_map('strtolower', (array) ($this->config['origins'] ?? [])), true)) {
            return true;
        }

        $host = self::hostPort((string) ($server['HTTP_HOST'] ?? ''));
        $parts = parse_url($origin);

        if (! is_array($parts) || empty($parts['host']) || $host === '') {
            return false;
        }

        return self::hostPort($parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')) === $host;
    }

    /** A script's request (Accept: application/json, or an XMLHttpRequest) gets JSON instead of a page. */
    private function wantsJson(array $server): bool
    {
        return str_contains(strtolower((string) ($server['HTTP_ACCEPT'] ?? '')), 'application/json')
            || strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    private function tokenValid(mixed $token): bool
    {
        return is_string($token) && preg_match('/^[0-9a-f]{64}\z/', $token) === 1
            && time() < (int) ($this->config['token_expires'] ?? 0)
            && hash_equals((string) $this->config['token_hash'], hash('sha256', $token));
    }

    private function cookieValid(mixed $cookie): bool
    {
        return is_string($cookie) && preg_match('/^[0-9a-f]{64}\z/', $cookie) === 1
            && time() < (int) ($this->config['cookie_expires'] ?? 0)
            && hash_equals((string) $this->config['cookie_hash'], hash('sha256', $cookie));
    }

    /** The token of the Finish/Undo page's forms: only a page served to the cookie's holder can know it. */
    private function formToken(string $cookie): string
    {
        return hash_hmac('sha256', 'avdbcms-update-form|'.$this->config['plan_id'], $cookie);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function visitorPage(array $server): array
    {
        $headers = ['Retry-After' => '60', 'Cache-Control' => 'no-store, no-cache, must-revalidate', 'X-Robots-Tag' => 'noindex'];

        if ($this->wantsJson($server)) {
            return ['status' => 503, 'headers' => $headers + ['Content-Type' => 'application/json; charset=utf-8'],
                'body' => (string) json_encode(['message' => $this->text('visitor')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }

        return ['status' => 503, 'headers' => $headers + ['Content-Type' => 'text/html; charset=utf-8'],
            'body' => $this->page((string) ($this->config['site'] ?? ''), '<p>'.$this->e($this->text('visitor')).'</p>')];
    }

    /**
     * The page of the administrator who started the update (the update cookie): where the swap stands, Finish /
     * Undo, or the result of the slice just run (with a form that sends itself again while work is left).
     *
     * @param  array<string, mixed>|null  $result
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function adminPage(string $cookie, ?array $result, ?string $action): array
    {
        $token = $this->e($this->formToken($cookie));
        $form = fn (string $do, string $label, string $class): string => '<form method="post" action=""'.($class === 'auto' ? ' id="avd-next"' : '').'>'
            .'<input type="hidden" name="avdbcms_update" value="'.$this->e($do).'"><input type="hidden" name="form_token" value="'.$token.'">'
            .'<button type="submit" class="'.($class === 'auto' ? 'primary' : $this->e($class)).'">'.$this->e($label).'</button></form>';
        $admin = '<p><a class="btn primary" href="'.$this->e((string) ($this->config['admin_url'] ?? '/admin')).'">'.$this->e($this->text('open_admin')).'</a></p>';
        $help = is_string($this->config['help_url'] ?? null) && str_starts_with($this->config['help_url'], 'https://')
            ? '<p class="help"><a href="'.$this->e($this->config['help_url']).'" target="_blank" rel="noopener noreferrer">'.$this->e($this->text('help')).'</a></p>'
            : '';
        $script = '';

        if ($result === null) {
            $plan = self::readJson((string) $this->config['plan']) ?? [];
            $progress = (array) ($plan['swap'] ?? []);
            $back = ($plan['status'] ?? '') === 'rolling-back';
            $body = '<p>'.$this->e(strtr($this->text($back ? 'admin_back' : 'admin_text'), [
                ':done' => (string) (int) ($progress['done'] ?? 0),
                ':total' => (string) (int) ($progress['total'] ?? 0),
            ])).'</p><div class="row">'
                .($back ? $form('undo', $this->text('continue'), 'primary') : $form('finish', $this->text('finish'), 'primary').$form('undo', $this->text('undo'), 'secondary'))
                .'</div>'.$help;
            $title = $this->text('admin_title');
        } elseif (! empty($result['busy'])) {
            $title = $this->text('admin_title');
            $body = '<p>'.$this->e($this->text('busy')).'</p><div class="row">'.$form((string) $action, $this->text('continue'), 'primary').'</div>';
        } elseif (($result['next'] ?? null) === 'swap') {
            $title = $this->text('working');
            $body = '<p>'.$this->e((string) $result['message']).'</p>'.$form($result['step'] === 'rolling-back' ? 'undo' : (string) $action, $this->text('continue'), 'auto');
            $script = '<script>setTimeout(function(){var f=document.getElementById("avd-next");if(f){f.submit();}},400);</script>';
        } elseif (($result['step'] ?? '') === 'swapped') {
            $title = $this->text('swapped_title');
            $body = '<p>'.$this->e($this->text('swapped_text')).'</p>'.$admin;
        } elseif (($result['step'] ?? '') === 'failed') {
            $title = ! empty($result['stopped']) ? $this->text('undone_title') : $this->text('failed_title');
            $body = '<p>'.$this->e((string) $result['message']).'</p>'.$admin;
        } else {
            $title = $this->text('admin_title');
            $body = '<p>'.$this->e((string) ($result['message'] ?? $this->text('stuck'))).'</p>'.$help;
        }

        return ['status' => 200, 'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, no-cache, must-revalidate', 'X-Robots-Tag' => 'noindex'],
            'body' => $this->page($title, $body, $script)];
    }

    /** A small self-contained page: no Laravel, no /build assets, inline styles in the site's language. */
    private function page(string $title, string $body, string $script = ''): string
    {
        $lang = $this->e((string) ($this->config['strings']['lang'] ?? 'en'));
        $dir = ($this->config['strings']['dir'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr';

        return '<!doctype html><html lang="'.$lang.'" dir="'.$dir.'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            .'<title>'.$this->e($title).'</title><style>'
            .':root{color-scheme:light dark;--bg:#f4f5f7;--card:#fff;--text:#1d2330;--muted:#5b6475;--line:#d9dde5;--accent:#2f5bd3}'
            .'@media (prefers-color-scheme:dark){:root{--bg:#0f131a;--card:#171c26;--text:#e8ecf3;--muted:#a3adbf;--line:#2c3443;--accent:#7ea2ff}}'
            .'body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;background:var(--bg);color:var(--text);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}'
            .'main{width:min(560px,100%);background:var(--card);border:1px solid var(--line);border-radius:12px;padding:28px}'
            .'h1{margin:0 0 12px;font-size:22px;line-height:1.3}p{margin:0 0 16px;color:var(--muted)}.row{display:flex;flex-wrap:wrap;gap:10px}'
            .'form{margin:0}button,.btn{display:inline-block;font:inherit;font-weight:600;padding:9px 16px;border-radius:8px;border:1px solid var(--line);background:transparent;color:var(--text);cursor:pointer;text-decoration:none}'
            .'.primary{background:var(--accent);border-color:var(--accent);color:#fff}'
            .'.help{margin:16px 0 0;font-size:14px}.help a{color:var(--accent)}'
            .'</style></head><body><main><h1>'.$this->e($title).'</h1>'.$body.'</main>'.$script.'</body></html>';
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function json(array $data, int $status = 200): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'],
            'body' => (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    /** What `artisan up` does: the site answers again. */
    private function standDown(): void
    {
        foreach (['down', 'hook'] as $key) {
            $file = (string) ($this->config[$key] ?? '');

            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @param  array<string, mixed>  $plan */
    private function savePlan(array $plan): void
    {
        $file = (string) $this->config['plan'];
        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        // Never an empty file: a text that is not UTF-8 (a reason quoted from an error) becomes U+FFFD, anything else
        // that cannot be encoded throws.
        $json = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        if (@file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || ! @rename($temporary, $file)) {
            @unlink($temporary);

            throw new \RuntimeException('The update plan cannot be saved. The disk may be full.');
        }
    }

    /**
     * savePlan() where the files matter more than the plan: a plan that cannot be saved (the full disk or quota that
     * made a move fail) is logged and the work goes on. False when it was not saved.
     *
     * @param  array<string, mixed>  $plan
     */
    private function trySave(array $plan): bool
    {
        try {
            $this->savePlan($plan);

            return true;
        } catch (\Throwable $e) {
            $this->log('the update plan could not be saved: '.$e->getMessage());

            return false;
        }
    }

    /** Set the journal of a finished way back aside (journal.rolled-back.jsonl), or delete it: no later swap reads it. */
    private function retireJournal(SwapEngine $engine): void
    {
        $engine->close();
        $journal = (string) ($this->job()['journal'] ?? '');

        if ($journal !== '' && is_file($journal) && ! @rename($journal, substr($journal, 0, -strlen('.jsonl')).'.rolled-back.jsonl')) {
            @unlink($journal);
        }

        $this->log('the plan could not be saved after every file was put back: the journal was set aside, a new try starts from the first file');
    }

    private function log(string $message): void
    {
        $file = (string) ($this->config['log'] ?? '');

        if ($file !== '') {
            @file_put_contents($file, date('Y-m-d H:i:s')."\tswap\t".$message."\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function text(string $key): string
    {
        return (string) ($this->config['strings'][$key] ?? $key);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function hostPort(string $host): string
    {
        $host = strtolower(trim($host));

        return (string) preg_replace('/:(80|443)$/', '', $host);
    }

    /** @return array<string, mixed>|null */
    private static function readJson(string $file): ?array
    {
        if ($file === '' || ! is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }
}
