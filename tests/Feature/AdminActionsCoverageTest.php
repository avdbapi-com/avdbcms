<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AuditLogController;
use App\Support\AuditActions;
use Illuminate\Routing\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * R3-019: Tools > Logs > Admin actions says "Changes administrators make are written here." Round 3 found most admin
 * changes outside R2A-020's list writing no row (Clear All Logs, Health check Auto-fix, Premium plans, categories, tags,
 * redirects, announcements, languages, the cache clears, imports ...). This test reads every admin route that is not a
 * plain page view (POST, PUT, PATCH, DELETE under /admin, the log viewer's and Horizon's included) and fails for one that
 * neither writes a row (its controller method, or a method of its class it calls, writes one) nor is listed below with
 * the place that writes its row or the reason it changes nothing on the site. A new admin action therefore has to
 * decide. Every action key a controller writes has a label (AuditActionLabelsTest checks them all).
 */
class AdminActionsCoverageTest extends TestCase
{
    /** What writes a row (a call in the method's source, or in a method of its class that it calls). */
    private const WRITES = '/AuditLogController::log\(|\bself::log\(\'/';

    /**
     * Routes whose row is written by another class (route name => the file and an action key it must write).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const WRITTEN_ELSEWHERE = [
        // the old admin's Mass Broadcast form sends through New notification's own send()
        'admin.broadcast.send' => ['app/Http/Controllers/Admin/NotificationController.php', 'notifications.broadcast'],
        // the one-click updater writes every step that changes something (App\Support\Updater, ReleaseCheck)
        'admin.updates.check' => ['app/Support/Updater/ReleaseCheck.php', 'update.check'],
        'admin.updates.start' => ['app/Support/Updater/Updater.php', 'update.started'],
        'admin.updates.swap' => ['app/Support/Updater/Updater.php', 'update.failed'],
        'admin.updates.finish' => ['app/Support/Updater/Updater.php', 'update.finished'],
        'admin.updates.cancel' => ['app/Support/Updater/Updater.php', 'update.cancelled'],
        'admin.updates.undo' => ['app/Support/Updater/Updater.php', 'update.undone'],
        'admin.updates.backups.delete' => ['app/Support/Updater/Updater.php', 'update.backup_deleted'],
        // "Log in as this member": the package's event (App\Listeners\AuditImpersonation)
        'admin.impersonate' => ['app/Listeners/AuditImpersonation.php', 'member.impersonated'],
        // Tools > Logs' file viewer (the log-viewer package): its LogFileDeleted event (App\Listeners\AuditLogFileDeleted)
        'log-viewer.files.delete' => ['app/Listeners/AuditLogFileDeleted.php', 'tools.log.deleted'],
        'log-viewer.files.delete-multiple-files' => ['app/Listeners/AuditLogFileDeleted.php', 'tools.logs.deleted'],
        'log-viewer.folders.delete' => ['app/Listeners/AuditLogFileDeleted.php', 'tools.logs.deleted'],
        // Horizon's dashboard (Redis sites): config/horizon.php's middleware App\Http\Middleware\AuditHorizonActions
        'horizon.retry-jobs.show' => ['app/Http/Middleware/AuditHorizonActions.php', 'queue.failed.retry'],
        'horizon.jobs-batches.retry' => ['app/Http/Middleware/AuditHorizonActions.php', 'queue.batch.retry'],
    ];

    /** Routes that change nothing on the site (route name => why): the administrator's own view, tests, drafts. */
    private const CHANGE_NOTHING = [
        'admin.home.dismiss' => 'hides a Home notice for this administrator only',
        'admin.shell.preferences' => "this administrator's own choices (old or new admin, dark mode, Developer mode ...)",
        'admin.update.keep-look' => 'hides the offer of the other look for this administrator only',
        'admin.appearance.draft' => 'a draft only this administrator sees; publishing it writes appearance.published',
        'admin.appearance.draft.discard' => 'throws away an unpublished draft',
        'admin.settings.update' => 'sends the old settings form back to its tabs (each tab saves its group: settings.saved)',
        'admin.settings.update.env' => 'sends the old form to Tools > Developer zone, where the .env file is saved (tools.env.saved)',
        'admin.settings.upload' => 'stores a picture; the save that puts it into a setting writes settings.saved',
        'admin.settings.maintenance.reveal' => 'shows the secret preview link once the password is typed again',
        'admin.settings.test-email' => 'sends a test email',
        'admin.settings.test-stream' => 'tests a video address',
        'admin.email-test.send' => 'sends a test email',
        'admin.avdb-import.test' => 'tests the provider address and filters',
        'admin.xvid-import.test' => 'tests the provider address and filters',
        'admin.preflight.refresh' => 'checks the server again',
        'admin.cron.duration.runner.batch' => 'one batch of the duration check the administrator started (cron.duration.runner.*)',
        'admin.updates.step' => 'one step of an update that was started (update.started); its database backup writes update.database_backup',
        'horizon.monitoring.store' => "what Horizon's own dashboard watches",
        'horizon.monitoring-tag.destroy' => "what Horizon's own dashboard watches",
        'log-viewer.files.clear-cache' => "the file viewer's own index of a log",
        'log-viewer.files.clear-cache-all' => "the file viewer's own index of the logs",
        'log-viewer.folders.clear-cache' => "the file viewer's own index of a folder",
    ];

    /** @return list<Route> the admin routes that are not plain page views */
    private function changingRoutes(): array
    {
        $routes = [];

        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'admin') && array_diff($route->methods(), ['GET', 'HEAD']) !== []) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    private static function source(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName()) ?: [];

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /** Whether the method, or a method of its class it calls ($this->x(), self::x(), static::x()), writes a row. */
    private static function writesRow(string $class, string $method, array &$seen = []): bool
    {
        if (isset($seen[$class.'::'.$method]) || ! method_exists($class, $method)) {
            return false;
        }

        $seen[$class.'::'.$method] = true;
        $reflection = new ReflectionMethod($class, $method);
        $source = self::source($reflection);

        if (preg_match(self::WRITES, $source) === 1) {
            return true;
        }

        preg_match_all('/(?:\$this->|self::|static::)([A-Za-z_][A-Za-z0-9_]*)\(/', $source, $calls);

        foreach (array_unique($calls[1]) as $callee) {
            if (self::writesRow($reflection->getDeclaringClass()->getName(), $callee, $seen)) {
                return true;
            }
        }

        return false;
    }

    public function test_every_admin_action_writes_a_row_or_says_why_it_changes_nothing(): void
    {
        $missing = [];
        $names = [];

        foreach ($this->changingRoutes() as $route) {
            $name = $route->getName() ?? implode('|', $route->methods()).' '.$route->uri();
            $names[] = $name;

            if (isset(self::WRITTEN_ELSEWHERE[$name]) || isset(self::CHANGE_NOTHING[$name])) {
                continue;
            }

            $action = $route->getActionName();

            if (! str_contains($action, '@') || ! self::writesRow(...explode('@', ltrim($action, '\\'), 2))) {
                $missing[] = $name.' ('.$action.')';
            }
        }

        $this->assertGreaterThan(150, count($names), 'the admin routes were read');
        $this->assertSame([], $missing, "Admin actions that write no row in Tools > Logs > Admin actions and are not listed as changing nothing:\n".implode("\n", $missing));

        // the lists name routes that exist (a renamed route cannot leave a stale reason behind)
        $this->assertSame([], array_values(array_diff(array_keys(self::CHANGE_NOTHING + self::WRITTEN_ELSEWHERE), $names)), 'listed routes that are not admin actions');
    }

    public function test_the_classes_that_write_for_other_routes_do_write_those_rows(): void
    {
        foreach (self::WRITTEN_ELSEWHERE as $name => [$file, $action]) {
            $this->assertStringContainsString("'".$action."'", (string) file_get_contents(base_path($file)), "{$name}: {$file} writes {$action}");
            $this->assertArrayHasKey($action, AuditActions::LABELS, "{$action} has a label");
        }

        // the routes listed as changing nothing really write no row of their own
        foreach ($this->changingRoutes() as $route) {
            $name = (string) $route->getName();
            $action = $route->getActionName();

            if (isset(self::CHANGE_NOTHING[$name]) && str_contains($action, '@') && str_starts_with(ltrim($action, '\\'), 'App\\')) {
                $this->assertFalse(self::writesRow(...explode('@', ltrim($action, '\\'), 2)), "{$name} is listed as changing nothing but writes a row");
            }
        }

        $this->assertTrue(method_exists(AuditLogController::class, 'log'));
    }
}
