<?php

namespace Tests\Unit\Updater;

use App\Support\Updater\Manifest;
use App\Support\Updater\SwapPlanner;
use Tests\TestCase;

/**
 * What an update does with each file (updater.md amendment 1, F8 8-11): the installed manifest tells shipped files from
 * owner edits; unchanged files never move; hand-edited files are kept with the new copy next to them; owner-changed code
 * is replaced and listed; removals only in code folders and only of files the installed version shipped; old built
 * assets wait one more update; the moves come in the order of F8 11 with the manifest last.
 */
class SwapPlannerTest extends TestCase
{
    /** The installed version (1.0.0). */
    private const SHIPPED = [
        '.htaccess' => 'htaccess 1',
        'public/.htaccess' => 'public htaccess 1',
        'web.config' => 'web.config 1',
        'public/index.php' => 'public index 1',
        'index.php' => 'root index 1',
        'artisan' => 'artisan 1',
        'app/Models/User.php' => 'user 1',
        'app/Http/Kernel.php' => 'kernel 1',
        'app/Support/Old.php' => 'old 1',
        'app/Support/Skipped.php' => 'gone in a release between',
        'config/app.php' => 'config 1',
        'vendor/autoload.php' => 'autoload 1',
        'vendor/pkg/src/A.php' => 'A 1',
        'vendor/pkg/src/Gone.php' => 'gone 1',
        'public/build/manifest.json' => 'vite 1',
        'public/build/assets/app-111.js' => 'js 1',
        'lang/en.json' => '{"Hello": "Hello"}',
        'lang/xx/legacy.php' => 'removed language array',
        'public/old-banner.txt' => 'removed public file',
    ];

    /** The release (1.0.1). */
    private const RELEASE = [
        '.htaccess' => 'htaccess 2',
        'public/.htaccess' => 'public htaccess 2',
        'web.config' => 'web.config 1',
        'public/index.php' => 'public index 2',
        'index.php' => 'root index 1',
        'artisan' => 'artisan 2',
        'app/Models/User.php' => 'user 2',
        'app/Http/Kernel.php' => 'kernel 1',
        'app/Support/New.php' => 'new 2',
        'config/app.php' => 'config 2',
        'vendor/autoload.php' => 'autoload 2',
        'vendor/pkg/src/A.php' => 'A 2',
        'vendor/pkg/src/B.php' => 'B 2',
        'public/build/manifest.json' => 'vite 2',
        'public/build/assets/app-222.js' => 'js 2',
        'lang/en.json' => '{"Hello": "Hello", "New": "New"}',
    ];

    public function test_each_file_is_planned_by_what_it_is_and_the_moves_come_in_order(): void
    {
        $live = self::SHIPPED;
        $live['public/.htaccess'] = 'public htaccess 1 + the host panel';
        $live['web.config'] = 'web.config 1 + owner';
        $live['app/Models/User.php'] = 'user 1 + owner fix';
        $live['app/Mine.php'] = 'owner file, never shipped';
        $live['public/build/assets/app-000.js'] = 'js 0 (kept by the update before)';

        $plan = $this->plan($live, ['app/Support/Old.php', 'vendor/pkg/src/Gone.php', 'app/Mine.php', 'lang/xx/legacy.php', 'public/old-banner.txt'],
            ['public/build/assets/app-000.js']);

        $this->assertSame([
            // pre: new built assets and the copies next to kept files, before the site goes down
            ['pre', 'put', 'public/.htaccess.avdbcms-1.0.1', 'staging/public/.htaccess'],
            ['pre', 'put', 'public/build/assets/app-222.js', 'staging/public/build/assets/app-222.js'],
            // vendor/ first
            ['main', 'put', 'vendor/autoload.php', 'staging/vendor/autoload.php'],
            ['main', 'put', 'vendor/pkg/src/A.php', 'staging/vendor/pkg/src/A.php'],
            ['main', 'put', 'vendor/pkg/src/B.php', 'staging/vendor/pkg/src/B.php'],
            // the other files
            ['main', 'put', '.htaccess', 'staging/.htaccess'],
            ['main', 'put', 'app/Models/User.php', 'staging/app/Models/User.php'],
            ['main', 'put', 'app/Support/New.php', 'staging/app/Support/New.php'],
            ['main', 'put', 'config/app.php', 'staging/config/app.php'],
            ['main', 'put', 'lang/en.json', 'staging/lang/en.json'],
            ['main', 'put', 'public/build/manifest.json', 'staging/public/build/manifest.json'],
            // removals (code folders only, files the installed version shipped), the asset of two versions ago
            ['main', 'delete', 'app/Support/Old.php', null],
            ['main', 'delete', 'app/Support/Skipped.php', null],
            ['main', 'delete', 'public/build/assets/app-000.js', null],
            ['main', 'delete', 'vendor/pkg/src/Gone.php', null],
            // the entry points
            ['main', 'put', 'public/index.php', 'staging/public/index.php'],
            ['main', 'put', 'artisan', 'staging/artisan'],
            // the manifest last
            ['last', 'put', Manifest::FILE, 'staging/'.Manifest::FILE],
        ], array_map(fn (array $op): array => [$op['phase'], $op['do'], $op['path'], $op['src']], $plan['ops']));

        $this->assertSame(range(0, count($plan['ops']) - 1), array_column($plan['ops'], 'i'));
        $this->assertSame(9, $plan['report']['replaced']);
        $this->assertSame(3, $plan['report']['added']);
        $this->assertSame(4, $plan['report']['removed']);
        $this->assertSame(3, $plan['report']['unchanged'], 'index.php, Kernel.php, and web.config (changed by the owner, not by the release)');
        $this->assertSame([['path' => 'public/.htaccess', 'copy' => 'public/.htaccess.avdbcms-1.0.1']], $plan['report']['kept']);
        $this->assertSame(['app/Models/User.php'], $plan['report']['overwritten']);
        $this->assertSame(['public/build/assets/app-111.js'], $plan['kept_assets'], 'the assets of 1.0.0 stay for cached pages');
    }

    public function test_every_move_knows_the_hash_before_and_after(): void
    {
        $live = self::SHIPPED;
        $plan = $this->plan($live, ['app/Support/Old.php']);
        $ops = [];

        foreach ($plan['ops'] as $op) {
            $ops[$op['path']] = $op;
        }

        $this->assertSame(hash('sha256', 'user 1'), $ops['app/Models/User.php']['old']);
        $this->assertSame(hash('sha256', 'user 2'), $ops['app/Models/User.php']['new']);
        $this->assertNull($ops['app/Support/New.php']['old'], 'an added file has nothing before it');
        $this->assertSame(hash('sha256', 'old 1'), $ops['app/Support/Old.php']['old']);
        $this->assertNull($ops['app/Support/Old.php']['new']);

        foreach ($plan['ops'] as $op) {
            $this->assertNotSame($op['old'], $op['new'], $op['path'].' would move for nothing');
        }

        $entries = [];

        foreach ($plan['entries'] as $entry) {
            $entries[$entry['path']] = $entry['action'];
        }

        $this->assertSame('replaced', $entries['app/Models/User.php']);
        $this->assertSame('added', $entries['app/Support/New.php']);
        $this->assertSame('removed', $entries['app/Support/Old.php']);
        $this->assertSame('replaced', $entries[Manifest::FILE]);
        $this->assertCount(count($plan['ops']), $plan['entries'], 'backup.json lists every move');
    }

    public function test_hand_edited_files_are_replaced_only_while_they_are_as_shipped(): void
    {
        $asShipped = $this->plan(self::SHIPPED);
        $paths = array_column($asShipped['ops'], 'path');
        $this->assertContains('.htaccess', $paths);
        $this->assertContains('public/.htaccess', $paths);
        $this->assertSame([], $asShipped['report']['kept']);

        $live = self::SHIPPED;
        $live['.htaccess'] = "htaccess 1\nAddHandler application/x-httpd-php83 .php\n";
        $edited = $this->plan($live);
        $this->assertNotContains('.htaccess', array_column($edited['ops'], 'path'), 'the owner\'s .htaccess is not replaced');
        $this->assertContains('.htaccess.avdbcms-1.0.1', array_column($edited['ops'], 'path'));
        $this->assertSame([['path' => '.htaccess', 'copy' => '.htaccess.avdbcms-1.0.1']], $edited['report']['kept']);
    }

    public function test_a_hand_edited_file_the_owner_deleted_stays_deleted(): void
    {
        // public/.htaccess changes in the release, web.config does not; the site deleted both (an nginx site, a
        // robots.txt the SEO settings answer instead).
        $live = self::SHIPPED;
        unset($live['public/.htaccess'], $live['web.config']);

        $plan = $this->plan($live);
        $paths = array_column($plan['ops'], 'path');

        $this->assertNotContains('public/.htaccess', $paths, 'not put back');
        $this->assertNotContains('web.config', $paths);
        $this->assertContains('public/.htaccess.avdbcms-1.0.1', $paths, 'the new copy goes next to where it was');
        $this->assertNotContains('web.config.avdbcms-1.0.1', $paths, 'the release did not change it: nothing to compare');
        $this->assertSame([['path' => 'public/.htaccess', 'copy' => 'public/.htaccess.avdbcms-1.0.1']], $plan['report']['kept']);
        $this->assertNotContains('public/.htaccess', array_column(array_filter($plan['entries'], fn (array $e): bool => $e['action'] === 'added'), 'path'));
        $this->assertContains('.htaccess', $paths, 'a hand-edited file that is still as shipped is replaced as before');
    }

    public function test_a_file_renamed_by_case_only_is_replaced_in_place_where_case_does_not_count(): void
    {
        [$plan, $moves, $shipped] = $this->caseRename();

        // A file system that ignores case (Windows, macOS): the new name finds the old file, it is replaced in place. The
        // old name must never be deleted there (it is the new file), also when the inode check says "one file".
        $byCase = array_change_key_case($shipped + [Manifest::FILE => 'old manifest'], CASE_LOWER);

        foreach ([null, fn (): bool => true] as $sameFile) {
            $insensitive = $plan(fn (string $path): ?string => $byCase[strtolower($path)] ?? null, $sameFile);

            $this->assertContains(['put', 'app/Http/Controllers/SeoController.php'], $moves($insensitive));
            $this->assertNotContains(['delete', 'app/Http/Controllers/SEOController.php'], $moves($insensitive), 'deleting the old name would delete the new file');
            $this->assertNotContains('app/Http/Controllers/SeoController.php', $insensitive['report']['overwritten'], 'shipped under its old name: not an owner\'s change');
            $entry = array_values(array_filter($insensitive['entries'], fn (array $e): bool => $e['path'] === 'app/Http/Controllers/SeoController.php'))[0];
            $this->assertSame(['replaced', hash('sha256', 'seo 1')], [$entry['action'], $entry['old']]);
            $this->assertSame(3, $insensitive['report']['removed'], 'Old.php, Skipped.php, Gone.php: not the renamed file');
        }
    }

    public function test_where_case_counts_the_old_name_of_a_file_renamed_by_case_goes_too(): void
    {
        // H10 (1.2.2): on Linux the new name is a new file and the old one would stay behind next to it.
        [$plan, $moves, $shipped] = $this->caseRename();
        $site = $shipped + [Manifest::FILE => 'old manifest'];

        $sensitive = $plan(fn (string $path): ?string => $site[$path] ?? null, fn (): bool => true);

        $this->assertContains(['put', 'app/Http/Controllers/SeoController.php'], $moves($sensitive));
        $this->assertContains(['delete', 'app/Http/Controllers/SEOController.php'], $moves($sensitive));
        $added = array_values(array_filter($sensitive['entries'], fn (array $e): bool => $e['path'] === 'app/Http/Controllers/SeoController.php'))[0];
        $removed = array_values(array_filter($sensitive['entries'], fn (array $e): bool => $e['path'] === 'app/Http/Controllers/SEOController.php'))[0];
        $this->assertSame(['added', null], [$added['action'], $added['old']]);
        $this->assertSame(['removed', hash('sha256', 'seo 1'), null], [$removed['action'], $removed['old'], $removed['new']]);
        $this->assertSame(4, $sensitive['report']['removed'], 'the three removed files and the old name');
        $order = array_column($sensitive['ops'], 'path');
        $this->assertLessThan(array_search('app/Http/Controllers/SEOController.php', $order, true), array_search('app/Http/Controllers/SeoController.php', $order, true), 'the new file comes first');

        // Both names there as two files (an earlier try, or the release before): the old one goes when the system says
        // they are two files, never when it says one or cannot tell.
        $both = $site + ['app/Http/Controllers/SeoController.php' => 'seo 2'];
        $this->assertContains(['delete', 'app/Http/Controllers/SEOController.php'], $moves($plan(fn (string $path): ?string => $both[$path] ?? null, fn (): bool => false)));
        $this->assertNotContains(['delete', 'app/Http/Controllers/SEOController.php'], $moves($plan(fn (string $path): ?string => $both[$path] ?? null, fn (): bool => true)));
        $this->assertNotContains(['delete', 'app/Http/Controllers/SEOController.php'], $moves($plan(fn (string $path): ?string => $both[$path] ?? null, null)));

        // The owner changed the old file: it is never deleted, and the page lists it next to the new name.
        $changed = ['app/Http/Controllers/SEOController.php' => 'seo 1 + owner'] + $site;
        $kept = $plan(fn (string $path): ?string => $changed[$path] ?? null, fn (): bool => false);
        $this->assertNotContains(['delete', 'app/Http/Controllers/SEOController.php'], $moves($kept));
        $this->assertContains(['path' => 'app/Http/Controllers/SEOController.php', 'copy' => 'app/Http/Controllers/SeoController.php'], $kept['report']['kept']);
    }

    public function test_same_file_tells_one_file_from_two(): void
    {
        $folder = storage_path('framework/testing/planner-same-'.bin2hex(random_bytes(4)));
        mkdir($folder, 0755, true);

        try {
            file_put_contents($folder.'/One.txt', 'one');
            file_put_contents($folder.'/two.txt', 'two');

            $this->assertFalse(SwapPlanner::sameFile($folder.'/One.txt', $folder.'/two.txt'), 'two files');
            $this->assertTrue(SwapPlanner::sameFile($folder.'/One.txt', $folder.'/One.txt'));
            $this->assertTrue(SwapPlanner::sameFile($folder.'/One.txt', $folder.'/missing.txt'), 'unsure: one');

            if (is_file($folder.'/one.txt')) {
                $this->assertTrue(SwapPlanner::sameFile($folder.'/One.txt', $folder.'/one.txt'), 'a file system that ignores case: one file under both names');
            }
        } finally {
            array_map('unlink', glob($folder.'/*') ?: []);
            rmdir($folder);
        }
    }

    /**
     * The planner for a release that renames app/Http/Controllers/SEOController.php to SeoController.php.
     *
     * @return array{0: callable(callable, ?callable): array, 1: callable(array): array, 2: array<string, string>}
     */
    private function caseRename(): array
    {
        $shipped = self::SHIPPED + ['app/Http/Controllers/SEOController.php' => 'seo 1'];
        $release = self::RELEASE + ['app/Http/Controllers/SeoController.php' => 'seo 2'];
        $plan = fn (callable $live, ?callable $sameFile): array => SwapPlanner::plan(
            new Manifest('1.0.1', '8.3.0', '1.0.0', $this->hashes($release), ['app/Http/Controllers/SEOController.php']),
            new Manifest('1.0.0', '8.3.0', '1.0.0', $this->hashes($shipped), []),
            hash('sha256', 'new manifest'),
            fn (string $path): ?string => ($content = $live($path)) === null ? null : hash('sha256', $content),
            $live,
            fn (string $path): ?string => $release[$path] ?? null,
            [],
            $sameFile,
        );
        $moves = fn (array $result): array => array_map(fn (array $op): array => [$op['do'], $op['path']], $result['ops']);

        return [$plan, $moves, $shipped];
    }

    public function test_a_copy_that_is_already_there_is_not_moved_again(): void
    {
        $live = self::SHIPPED;
        $live['.htaccess'] = 'htaccess 1 + owner';
        $live['.htaccess.avdbcms-1.0.1'] = 'htaccess 2';

        $plan = $this->plan($live);

        $this->assertNotContains('.htaccess.avdbcms-1.0.1', array_column($plan['ops'], 'path'));
        $this->assertSame([['path' => '.htaccess', 'copy' => '.htaccess.avdbcms-1.0.1']], $plan['report']['kept'], 'still listed');
    }

    public function test_a_file_the_owner_put_where_the_release_now_ships_one_is_replaced_and_listed(): void
    {
        $live = self::SHIPPED;
        $live['app/Support/New.php'] = 'the owner made this file first';

        $plan = $this->plan($live);

        $this->assertContains('app/Support/New.php', $plan['report']['overwritten']);
        $op = array_values(array_filter($plan['ops'], fn (array $op): bool => $op['path'] === 'app/Support/New.php'))[0];
        $this->assertSame(hash('sha256', 'the owner made this file first'), $op['old'], 'it goes into the backup');
    }

    public function test_a_removed_file_the_owner_changed_is_removed_into_the_backup_and_listed(): void
    {
        $live = self::SHIPPED;
        $live['app/Support/Old.php'] = 'old 1 + owner';

        $plan = $this->plan($live, ['app/Support/Old.php']);

        $this->assertContains(['delete', 'app/Support/Old.php'], array_map(fn (array $op): array => [$op['do'], $op['path']], $plan['ops']));
        $this->assertContains('app/Support/Old.php', $plan['report']['overwritten']);
    }

    public function test_nothing_moves_when_the_site_already_has_the_release(): void
    {
        $live = self::RELEASE;
        $live[Manifest::FILE] = 'new manifest';

        $plan = $this->plan($live, [], [], 'new manifest');

        $this->assertSame([], $plan['ops']);
        $this->assertSame(count(self::RELEASE), $plan['report']['unchanged']);
    }

    public function test_paths_an_update_never_writes_are_never_planned(): void
    {
        $new = new Manifest('1.0.1', '8.3.0', '1.0.0', $this->hashes(self::RELEASE + ['.env' => 'x', 'storage/app/installed.json' => 'x', 'bin/ffmpeg' => 'x', 'public/uploads/a.jpg' => 'x']), []);
        $installed = new Manifest('1.0.0', '8.3.0', '1.0.0', $this->hashes(self::SHIPPED + ['bootstrap/cache/config.php' => 'x', 'database/install/example.sql' => 'x']), []);

        $plan = SwapPlanner::plan($new, $installed, hash('sha256', 'm'), fn (string $p): ?string => isset(self::SHIPPED[$p]) ? hash('sha256', self::SHIPPED[$p]) : null,
            fn () => null, fn () => null);

        foreach ($plan['ops'] as $op) {
            $this->assertFalse(Manifest::preserved($op['path']), $op['path']);
        }
    }

    /**
     * @param  array<string, string>  $live  the site's files (path => content)
     * @param  list<string>  $removed  the release manifest's "removed"
     * @param  list<string>  $keptAssets  state.json kept_assets
     */
    private function plan(array $live, array $removed = [], array $keptAssets = [], string $liveManifest = 'old manifest'): array
    {
        $new = new Manifest('1.0.1', '8.3.0', '1.0.0', $this->hashes(self::RELEASE), $removed);
        $installed = new Manifest('1.0.0', '8.3.0', '1.0.0', $this->hashes(self::SHIPPED), []);
        $live[Manifest::FILE] = $live[Manifest::FILE] ?? $liveManifest;

        return SwapPlanner::plan(
            $new,
            $installed,
            hash('sha256', 'new manifest'),
            fn (string $path): ?string => isset($live[$path]) ? hash('sha256', $live[$path]) : null,
            fn (string $path): ?string => $live[$path] ?? null,
            fn (string $path): ?string => self::RELEASE[$path] ?? null,
            $keptAssets,
        );
    }

    /** @return array<string, string> */
    private function hashes(array $files): array
    {
        return array_map(fn (string $content): string => hash('sha256', $content), $files);
    }
}
