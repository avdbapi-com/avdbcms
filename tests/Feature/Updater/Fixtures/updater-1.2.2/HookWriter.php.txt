<?php

namespace App\Support\Updater;

use App\Support\AppVersion;
use LogicException;
use ParseError;
use Throwable;

/**
 * Writes the maintenance hook of a one-click update into storage/framework/maintenance.php, plus Laravel's
 * storage/framework/down (so the scheduler and the queue worker pause), and takes both away again.
 *
 * The hook file is self-contained: the source of SwapEngine and MaintenanceHook (plain PHP, built-ins only) in a
 * namespace of its own, then the config of this swap (paths, the SHA-256 of the swap token and of the update cookie,
 * the texts already translated). It is the running version's code, frozen for the whole swap: the swap replaces
 * app/ and vendor/, never this file. Before it goes live it is parsed and loaded once (without answering anything),
 * so a hook that cannot even load never stands between the site and its visitors.
 */
final class HookWriter
{
    /** Marks our own hook and down files (an `artisan down` of the owner is never touched). */
    public const MARKER = 'AVDBCMS one-click update hook';

    public function __construct(private readonly UpdatePaths $paths)
    {
    }

    /**
     * @param  array<string, mixed>  $config  MaintenanceHook's config (plan_id, plan, job, lock, log, hook, down, ...)
     */
    public function install(array $config): void
    {
        $code = $this->build($config);

        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $e) {
            throw new UpdateException(__('The update could not prepare its maintenance page. Nothing on your site was changed.'), 'prepare', false, 'hook: '.$e->getMessage());
        }

        $file = $this->paths->hook();
        $folder = dirname($file);

        if (! is_dir($folder) && ! @mkdir($folder, 0755, true) && ! is_dir($folder)) {
            throw new UpdateException(__('Could not write :file. The disk may be full or the folder read-only.', ['file' => 'storage/framework/maintenance.php']), 'prepare');
        }

        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $code, LOCK_EX) !== strlen($code)) {
            @unlink($temporary);

            throw new UpdateException(__('Could not write :file. The disk may be full or the folder read-only.', ['file' => 'storage/framework/maintenance.php']), 'prepare');
        }

        // Load it once without a request: it only builds its handler and returns it.
        $GLOBALS['avdbcms_update_hook_probe'] = true;

        try {
            $probe = include $temporary;
        } catch (Throwable $e) {
            $probe = $e;
        } finally {
            unset($GLOBALS['avdbcms_update_hook_probe']);
        }

        if (! is_object($probe) || $probe instanceof Throwable || ! @rename($temporary, $file)) {
            @unlink($temporary);

            throw new UpdateException(__('The update could not prepare its maintenance page. Nothing on your site was changed.'), 'prepare', false,
                'hook: '.($probe instanceof Throwable ? $probe->getMessage() : 'cannot load or move it into place'));
        }

        // Where OPcache does not check time stamps, the hook of an earlier update may still sit in it under this name.
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        // Laravel's own "down" payload (scheduler and queue pause on it) plus our mark.
        $down = (string) json_encode([
            'except' => [],
            'redirect' => null,
            'retry' => 60,
            'refresh' => null,
            'secret' => null,
            'status' => 503,
            'template' => null,
            'avdbcms_update' => (string) $config['plan_id'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($this->paths->down(), $down, LOCK_EX) !== strlen($down)) {
            $this->remove();

            throw new UpdateException(__('Could not write :file. The disk may be full or the folder read-only.', ['file' => 'storage/framework/down']), 'prepare');
        }
    }

    /** The hook's PHP source for a config. */
    public function build(array $config): string
    {
        $id = preg_replace('/[^A-Za-z0-9]/', '', (string) ($config['plan_id'] ?? ''));

        if ($id === '') {
            throw new LogicException('The hook needs a plan id.');
        }

        $namespace = 'AvdbcmsUpdateHook\\H'.$id;
        // Each class only when it is not there yet: install() loads the file once to test it, and the same PHP process
        // may load it again (a test, a host that includes the file twice) without "cannot redeclare".
        $classes = '';

        foreach (['SwapEngine', 'MaintenanceHook'] as $class) {
            $classes .= "if (! \\class_exists(__NAMESPACE__.'\\\\{$class}', false)) {\n".$this->embed(__DIR__.'/'.$class.'.php')."}\n\n";
        }
        $from = preg_replace('/[^0-9.]/', '', (string) ($config['from'] ?? ''));
        $to = preg_replace('/[^0-9.]/', '', (string) ($config['version'] ?? ''));

        return "<?php\n\n"
            .'// '.self::MARKER.' ('.$from.' -> '.$to.'), written '.gmdate('Y-m-d H:i:s')." UTC.\n"
            ."// While this file and storage/framework/down exist, the site shows \"being updated\". If that lasts longer\n"
            .'// than 15 minutes, see '.AppVersion::HELP_URL."\n\n"
            ."namespace {$namespace} {\n\n".$classes."}\n\n"
            ."namespace {\n"
            .'    $avdbcmsUpdateHook = new \\'.$namespace.'\\MaintenanceHook('.var_export($config, true).");\n\n"
            ."    if (! empty(\$GLOBALS['avdbcms_update_hook_probe'])) {\n"
            ."        return \$avdbcmsUpdateHook;\n"
            ."    }\n\n"
            ."    \$avdbcmsUpdateAnswer = \$avdbcmsUpdateHook->handle(\$_SERVER, \$_POST, \$_COOKIE);\n\n"
            ."    if (\$avdbcmsUpdateAnswer !== null) {\n"
            ."        http_response_code(\$avdbcmsUpdateAnswer['status']);\n\n"
            ."        foreach (\$avdbcmsUpdateAnswer['headers'] as \$avdbcmsUpdateName => \$avdbcmsUpdateValue) {\n"
            ."            header(\$avdbcmsUpdateName.': '.\$avdbcmsUpdateValue);\n"
            ."        }\n\n"
            ."        echo \$avdbcmsUpdateAnswer['body'];\n\n"
            ."        exit;\n"
            ."    }\n\n"
            ."    unset(\$avdbcmsUpdateHook, \$avdbcmsUpdateAnswer);\n"
            ."}\n";
    }

    /** Take our hook and our down file away (what `artisan up` does); an owner's own `artisan down` stays. */
    public function remove(): void
    {
        if ($this->installed()) {
            @unlink($this->paths->hook());
        }

        if ($this->ourDown()) {
            @unlink($this->paths->down());
        }
    }

    public function installed(): bool
    {
        $file = $this->paths->hook();

        return is_file($file) && str_contains((string) @file_get_contents($file, false, null, 0, 512), self::MARKER);
    }

    /** Laravel's maintenance mode is on and not by an update (`artisan down`): the update must wait for it. */
    public function foreignDown(): bool
    {
        return is_file($this->paths->down()) && ! $this->ourDown();
    }

    private function ourDown(): bool
    {
        $data = json_decode((string) @file_get_contents($this->paths->down()), true);

        return is_array($data) && isset($data['avdbcms_update']);
    }

    /** A class file of this folder without its "<?php" and namespace lines, for the hook's own namespace block. */
    private function embed(string $file): string
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents($file));
        $header = "<?php\n\nnamespace App\\Support\\Updater;\n";

        if (! str_starts_with($source, $header) || preg_match('/^(use|declare)\b/m', $source) === 1) {
            throw new LogicException(basename($file).' cannot be embedded in the maintenance hook (it must use PHP built-ins only).');
        }

        return substr($source, strlen($header));
    }
}
