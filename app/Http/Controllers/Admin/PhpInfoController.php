<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;

class PhpInfoController
{
    /**
     * Name parts of the variables whose values are secrets (APP_KEY, DB_PASSWORD, STRIPE_SECRET, ...).
     */
    private const SECRET_NAME = '/KEY|SECRET|PASSWORD|PASSWD|PASS$|_PW$|TOKEN|CREDENTIAL|PRIVATE|DSN|SALT|WEBHOOK/i';

    public function index(): View
    {
        // Without INFO_ENVIRONMENT and INFO_VARIABLES: those tables print the values of .env ($_ENV, $_SERVER,
        // getenv()), so the payment keys, APP_KEY and the database password would appear in the page.
        ob_start();
        phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES);
        $phpinfo = self::withoutSecrets((string) ob_get_clean());

        // Extract only the body content
        preg_match('/<body[^>]*>(.*)<\/body>/is', $phpinfo, $matches);
        $body = $matches[1] ?? $phpinfo;

        $info = [
            'php_version' => PHP_VERSION,
            'php_sapi' => php_sapi_name(),
            'os' => PHP_OS,
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'display_errors' => ini_get('display_errors'),
            'error_reporting' => ini_get('error_reporting'),
            'date_timezone' => date_default_timezone_get(),
            'extensions' => get_loaded_extensions(),
            'opcache' => function_exists('opcache_get_status') ? opcache_get_status(false) : null,
        ];

        return view('admin.phpinfo.index', compact('body', 'info'));
    }

    /**
     * phpinfo() output with every secret value of the environment replaced by "********", wherever a section still
     * prints them (the web server's own environment table on some hosts).
     */
    public static function withoutSecrets(string $html): string
    {
        // Plus every payment key Money > Payment setup keeps write-only (OD1), whatever its name (PAYPAL_CLIENT_ID).
        $payment = array_keys(array_filter(MoneyController::PAYMENT_KEYS, fn (array $key): bool => $key['secret']));
        $isSecret = fn (string $name): bool => preg_match(self::SECRET_NAME, $name) === 1 || in_array($name, $payment, true);

        // 1. By name: the value of every environment row whose variable is a secret, whatever its length, so a short or
        //    all-digit secret (a 5-character PayPal webhook ID, a numeric database password) is masked too. Web rows:
        //    <tr><td class="e">NAME </td><td class="v">VALUE </td></tr>, also $_SERVER['NAME'] / $_ENV['NAME'];
        //    text rows (CLI): NAME => VALUE. Rows with two values (ini directives: local => master) are left alone.
        $html = (string) preg_replace_callback(
            '~(<tr><td class="e">\s*(?:\$_(?:SERVER|ENV)\[(?:\'|&#039;|&apos;)?)?([A-Za-z_][A-Za-z0-9_]*)(?:(?:\'|&#039;|&apos;)?\])?\s*</td><td class="v">)((?:(?!</td>).)*)(</td></tr>)~s',
            fn (array $m): string => $isSecret($m[2]) ? $m[1].'********'.$m[4] : $m[0],
            $html
        );
        $html = (string) preg_replace_callback(
            '~^((?:\$_(?:SERVER|ENV)\[\'?)?([A-Za-z_][A-Za-z0-9_]*)(?:\'?\])? => )(?!.* => )(.*)$~m',
            fn (array $m): string => $isSecret($m[2]) ? $m[1].'********' : $m[0],
            $html
        );

        // 2. By value, wherever else a secret of the environment shows (another table, a command line).
        $values = [];

        foreach ([$_ENV, $_SERVER, getenv() ?: []] as $variables) {
            foreach ($variables as $name => $value) {
                if (is_string($name) && is_string($value) && $isSecret($name)) {
                    $values[] = $value;
                }
            }
        }

        // Short values ("1", "null") and plain numbers (MAX_TOKENS=1000000) are not secrets and would mask ordinary
        // settings such as pcre.backtrack_limit; longest first.
        $values = array_filter(array_unique($values), fn (string $value): bool => strlen($value) >= 6 && ! ctype_digit($value));
        usort($values, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $replace = [];
        foreach ($values as $value) {
            $replace[$value] = '********';
            $replace[htmlspecialchars($value, ENT_QUOTES)] = '********';
            $replace[htmlspecialchars($value, ENT_NOQUOTES)] = '********';
        }

        return $replace === [] ? $html : strtr($html, $replace);
    }
}
