<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Tools > Logs > Admin actions names every action in plain words (App\Support\AuditActions::LABELS); an action the
 * list does not know is shown by its raw key. R1R-05: twelve keys the code writes had no label ("queue.failed.flush",
 * "update.recount", ...). This test reads app/ and database/migrations the way PHP does (its tokenizer) and collects
 * every literal action key written to admin_audit_logs, whichever way the code writes it:
 *
 *  - AuditLogController::log('key', ...)          the first argument (both keys of a ternary)
 *  - $this->audit('key', ...)                       the admin controllers' own helper
 *  - const AUDIT_ACTION = 'key'                     classes and migrations that insert their own row
 *  - DB::table('admin_audit_logs')->insert(['action' => 'key', ...])
 *  - auditOnce($plan, $admin, ['key' => ...])        the updater's Finish rows, and 'key#name' row keys
 *  - $this->log('key', ...)                          a migration's own helper that writes the row
 *
 * and fails on any key without a label. Each way must still find the keys it is known to write, so the scan cannot
 * quietly stop seeing a part of the code.
 */
class AuditActionLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** A stored action key: "update.recount", "queue.failed.delete.blocked". */
    private const KEY = '/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/';

    public function test_every_admin_action_the_code_writes_has_a_label(): void
    {
        $found = self::writtenActions();

        foreach ([
            'AuditLogController::log' => ['update.check', 'money.payment_key.saved', 'money.payment_key.removed', 'security.admin_ip_lock.off'],
            '->audit()' => ['cron.scheduler.run', 'queue.failed.flush', 'queue.batch.delete.blocked'],
            'AUDIT_ACTION' => ['update.recount', 'update.removed_files', 'update.premium_page_texts', 'update.row_times'],
            'insert action' => ['update.sign_up_limit_default', 'update.plan_limits_defaults', 'security.known_passwords_reset'],
            'auditOnce' => ['update.files', 'update.finished', 'update.undone'],
            'row key#name' => ['update.texts'],
            'migration ->log()' => ['update.publish_dates', 'update.never_published'],
        ] as $way => $keys) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $found, "the scan finds {$key} ({$way})");
                $this->assertContains($way, array_column($found[$key], 'way'), "{$key} is found as {$way}");
            }
        }

        $missing = [];

        foreach ($found as $key => $places) {
            if (! isset(AuditActions::LABELS[$key])) {
                $missing[] = $key.' ('.implode(', ', array_map(fn (array $place): string => $place['file'].':'.$place['line'], $places)).')';
            }
        }

        $this->assertSame([], $missing, "Admin actions the code writes without a label in App\\Support\\AuditActions::LABELS (shown by their raw key):\n".implode("\n", $missing));
    }

    public function test_the_labels_are_plain_words_of_their_own(): void
    {
        foreach (AuditActions::LABELS as $key => $label) {
            $this->assertMatchesRegularExpression(self::KEY, $key);
            $this->assertNotSame('', trim($label), $key);
            $this->assertDoesNotMatchRegularExpression('/[a-z]\.[a-z]/', $label, $key.': a label, not a key');
            $this->assertSame($label, trim($label), $key);
        }

        $this->assertSame(count(AuditActions::LABELS), count(array_unique(AuditActions::LABELS)), 'every action has its own words');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function newlyLabelled(): array
    {
        return [
            'queue: failed jobs cleared' => ['queue.failed.flush', 'Failed jobs deleted, protected ones kept'],
            'queue: batch delete refused' => ['queue.batch.delete.blocked', 'Delete of an unfinished job batch refused'],
            'update: recount' => ['update.recount', 'Update: likes and comments counted again'],
            'update: sign-up limit' => ['update.sign_up_limit_default', 'Update: sign-up limit set to the default'],
        ];
    }

    #[DataProvider('newlyLabelled')]
    public function test_admin_actions_show_the_label_in_both_admins(string $key, string $label): void
    {
        $admin = User::factory()->admin()->create();
        DB::table('admin_audit_logs')->insert(['user_id' => $admin->id, 'action' => $key, 'details' => 'Details as written', 'ip_address' => '127.0.0.1', 'created_at' => now()]);

        foreach (['v1', 'v2'] as $ui) {
            config(['admin.ui' => $ui]);
            $html = (string) $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk()->getContent();

            $this->assertStringContainsString('>'.e($label).'</', $html, "{$ui}: the label is shown");
            $this->assertStringContainsString('title="'.$key.'"', $html, "{$ui}: the key stays in the tooltip");
            $this->assertStringNotContainsString('>'.$key.'</', $html, "{$ui}: never the raw key as the text");
        }
    }

    /**
     * Every literal action key app/ and database/migrations write to admin_audit_logs.
     *
     * @return array<string, list<array{file: string, line: int, way: string}>>
     */
    public static function writtenActions(): array
    {
        $found = [];
        $finder = (new Finder())->files()->name('*.php')->in([base_path('app'), base_path('database/migrations')]);

        foreach ($finder as $file) {
            $source = $file->getContents();
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
            $writesRows = str_contains($source, "'admin_audit_logs'") || str_contains($source, 'AuditLogController');
            $tokens = array_values(array_filter(token_get_all($source), fn ($token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            $add = function (array|string $token, string $way) use (&$found, $relative): void {
                $value = self::literal($token);

                if ($value === null || ! is_array($token)) {
                    return;
                }

                // "update.texts#de": a row of the action update.texts (Updater::auditOnce())
                if (str_ends_with($value, '#')) {
                    $value = substr($value, 0, -1);
                }

                if (preg_match(self::KEY, $value) === 1) {
                    $found[$value][] = ['file' => $relative, 'line' => (int) $token[2], 'way' => $way];
                }
            };

            foreach ($tokens as $i => $token) {
                $text = is_array($token) ? $token[1] : $token;

                // AuditLogController::log( <first argument> )
                if ($text === 'AuditLogController' && self::text($tokens, $i + 1) === '::' && self::text($tokens, $i + 2) === 'log' && self::text($tokens, $i + 3) === '(') {
                    foreach (self::argument($tokens, $i + 4, 0) as $part) {
                        $add($part, 'AuditLogController::log');
                    }
                }

                // ->audit( <first argument> ) of the admin controllers
                if ($text === '->' && self::text($tokens, $i + 1) === 'audit' && self::text($tokens, $i + 2) === '(') {
                    foreach (self::argument($tokens, $i + 3, 0) as $part) {
                        $add($part, '->audit()');
                    }
                }

                // const AUDIT_ACTION = '...'
                if ($text === 'AUDIT_ACTION' && self::text($tokens, $i + 1) === '=' && isset($tokens[$i + 2])) {
                    $add($tokens[$i + 2], 'AUDIT_ACTION');
                }

                // auditOnce($plan, $admin, [ 'key' => ..., ... ])
                if ($text === 'auditOnce' && self::text($tokens, $i + 1) === '(' && self::text($tokens, $i - 1) === '->') {
                    $array = self::argument($tokens, $i + 2, 2);

                    foreach ($array as $n => $part) {
                        if (self::text($array, $n + 1) === '=>') {
                            $add($part, 'auditOnce');
                        }
                    }
                }

                if (! $writesRows) {
                    continue;
                }

                // DB::table('admin_audit_logs')->insert([... 'action' => '...' ...])
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && self::literal($token) === 'admin_audit_logs'
                    && self::text($tokens, $i + 1) === ')' && self::text($tokens, $i + 2) === '->' && self::text($tokens, $i + 3) === 'insert' && self::text($tokens, $i + 4) === '(') {
                    $insert = self::argument($tokens, $i + 5, 0);

                    foreach ($insert as $n => $part) {
                        if (self::literal($part) === 'action' && self::text($insert, $n + 1) === '=>' && isset($insert[$n + 2])) {
                            $add($insert[$n + 2], 'insert action');
                        }
                    }
                }

                // $rows['update.texts#'.$locale]: the row keys "action#name" of the updater's Finish rows
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && str_ends_with((string) self::literal($token), '#') && self::text($tokens, $i - 1) === '[') {
                    $add($token, 'row key#name');
                }

                // a migration's own helper: $this->log('key', ...)
                if (str_starts_with($relative, 'database/migrations/') && $text === '->' && self::text($tokens, $i + 1) === 'log' && self::text($tokens, $i + 2) === '(') {
                    $first = self::argument($tokens, $i + 3, 0);

                    if (count($first) === 1) {
                        $add($first[0], 'migration ->log()');
                    }
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * The tokens of argument number $index (0-based) of the call whose arguments start at token $start (just after its
     * opening parenthesis), without the separating commas; nested brackets and calls stay inside one argument.
     *
     * @param  list<array|string>  $tokens
     * @return list<array|string>
     */
    private static function argument(array $tokens, int $start, int $index): array
    {
        $depth = 0;
        $current = 0;
        $out = [];

        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $text = self::text($tokens, $i);

            if (in_array($text, ['(', '[', '{'], true) || (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $current++;

                continue;
            }

            if ($current === $index) {
                // the array literal of the argument itself: its contents, not the bracket that opened it
                if (! ($depth === 1 && $text === '[' && $out === [])) {
                    $out[] = $tokens[$i];
                }
            } elseif ($current > $index) {
                break;
            }
        }

        return $out;
    }

    /** @param  list<array|string>  $tokens */
    private static function text(array $tokens, int $i): ?string
    {
        if (! isset($tokens[$i])) {
            return null;
        }

        return is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
    }

    /** The value of a plain string literal token ('...' or "..." without variables), else null. */
    private static function literal(array|string $token): ?string
    {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $raw = $token[1];

        return $raw[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($raw, 1, -1)) : stripcslashes(substr($raw, 1, -1));
    }
}
