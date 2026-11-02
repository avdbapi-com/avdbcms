<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminText;
use App\Support\AuditDetails;
use App\Support\BlockedWordsPass;
use App\Support\Environment;
use App\Support\PhpBinary;
use App\Support\QueueJobInspector;
use App\Support\SafeCache;
use App\Support\VideoBans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 3's admin findings of lane AD that are not R3-019 (AdminActionsRound3Test), R3-071 / R3X-01
 * (AdminSearchFormerNamesTest) or visual only (R3-020: browser checks):
 *
 *  - R3-034: Content > All videos offers Undo after the Ban of a video of any status (only public ones had it).
 *  - R3-041: Tools > Health check's Streaming accelerator card explains the web server it runs on (nginx's sentence
 *    was shown on Apache too).
 *  - R3-069: the row of the 1.2.3 blocked-words pass is shown in the reader's language.
 *  - R3-070: a protected job's refused delete is stored in English and shown in the reader's language.
 *  - R3-074: an English menu path in a right-to-left text keeps the bracket of the sentence outside it.
 *  - R3-076: Tools > Logs' Download and Delete and the old Views list's X are 24 px targets.
 *  - R3-077: the PHP binary the imports start is worked out once, not on every visit of the import page.
 *  - R3-078: "Custom colour" next to "Look colour".
 *  - AD-X1 (with its reviews AD-R2 and AD-V1): the import page's "Permanent scheduler command" gives what Tools >
 *    Scheduled tasks gives, also the command a hosting panel's cron job can run.
 */
class AdminRound3FixesTest extends TestCase
{
    use RefreshDatabase;

    private ?string $linesDir = null;

    /** the folder of a test that runs the import page's command for a hosting panel (removed in tearDown) */
    private ?string $panelDir = null;

    private ?string $software = null;

    /** @var array{dir: string, link: string, exe: string, php: string, broken: string}|null the linked PHP of a test */
    private ?array $linked = null;

    protected function tearDown(): void
    {
        if ($this->linked !== null) {
            // the link first (never a delete through it), then only the files and folders the test made
            $this->pointLink($this->linked['link'], null);
            @unlink($this->linked['broken'].DIRECTORY_SEPARATOR.basename((string) PHP_BINARY));
            @rmdir($this->linked['broken']);
            @rmdir($this->linked['dir']);
            $this->linked = null;
        }

        unset($_ENV['R3AD_TEST_PHP'], $_ENV['CRON_PHP_BINARY']);

        if ($this->linesDir !== null) {
            @unlink($this->linesDir.DIRECTORY_SEPARATOR.'ar.json');
            @rmdir($this->linesDir);
        }

        if ($this->panelDir !== null && str_contains(basename($this->panelDir), 'avd-r3ad-panel-')) {
            // only the folder this test made (its own name), never through a link
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->panelDir);
            $this->panelDir = null;
        }

        if ($this->software !== null) {
            $_SERVER['SERVER_SOFTWARE'] = $this->software;
        } else {
            unset($_SERVER['SERVER_SOFTWARE']);
        }
        Environment::reset();

        parent::tearDown();
    }

    /** @param  array<string, string>  $lines  Arabic lines on top of lang/ar.json (for texts it does not have yet) */
    private function arabicLines(array $lines): void
    {
        $this->linesDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r3ad-'.bin2hex(random_bytes(5));
        mkdir($this->linesDir);
        file_put_contents($this->linesDir.DIRECTORY_SEPARATOR.'ar.json', json_encode($lines, JSON_UNESCAPED_UNICODE));
        app('translator')->addJsonPath($this->linesDir);
    }

    /**
     * This PHP under a second path: <dir>/php is a link to PHP's own folder (a junction on Windows, a symbolic link
     * elsewhere), so a test can change the program behind the same path as a host does (an alternatives link, a panel
     * switching its PHP in place): pointLink() to <dir>/broken holds a file of PHP's name that is no program.
     *
     * @return array{dir: string, link: string, exe: string, php: string, broken: string}
     */
    private function linkedPhp(): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r3ad-php-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $broken = $dir.DIRECTORY_SEPARATOR.'broken';
        mkdir($broken);
        file_put_contents($broken.DIRECTORY_SEPARATOR.basename((string) PHP_BINARY), "not a program\n");
        $this->linked = [
            'dir' => $dir,
            'link' => $dir.DIRECTORY_SEPARATOR.'php',
            'exe' => $dir.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.basename((string) PHP_BINARY),
            'php' => dirname((string) PHP_BINARY),
            'broken' => $broken,
        ];

        if (! $this->pointLink($this->linked['link'], $this->linked['php'])) {
            $this->markTestSkipped('no link can be made in '.sys_get_temp_dir());
        }

        return $this->linked;
    }

    /** Point the link at $target (null: remove the link only; its target is never touched). */
    private function pointLink(string $link, ?string $target): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            if (is_dir($link) || is_link($link)) {
                @rmdir($link);
            }

            if ($target === null) {
                return true;
            }

            exec('cmd /c mklink /J '.escapeshellarg($link).' '.escapeshellarg($target).' 2>&1', $output, $status);

            return $status === 0;
        }

        if (is_link($link)) {
            @unlink($link);
        }

        return $target === null || @symlink($target, $link);
    }

    /** The row menu template of Content > All videos (new admin). */
    private function videoMenu(User $admin): string
    {
        config(['admin.ui' => 'v2']);
        $html = (string) $this->actingAs($admin)->get(route('admin.videos.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<template id="ad-vmenu">(.*?)</template>#s', $html, $menu));

        return $menu[1];
    }

    public function test_the_ban_of_a_video_of_any_status_offers_undo(): void
    {
        $admin = User::factory()->admin()->create();
        $menu = $this->videoMenu($admin);

        preg_match_all('#<button[^>]*form="ad-video-ban"[^>]*>#', $menu, $bans);
        $this->assertCount(1, $bans[0], 'one Ban item, for every status');
        $this->assertStringContainsString('data-ad-when="!banned"', $bans[0][0]);
        $this->assertStringContainsString('data-ad-undo-url="'.route('admin.videos.unban', ['video' => '__ID__'], false).'"', $bans[0][0]);

        // and Undo gives each status back (VideoBans keeps it): private, unlisted, scheduled
        $owner = User::factory()->create();
        foreach ([VideoStatus::PRIVATE, VideoStatus::UNLISTED, VideoStatus::PLANNED] as $status) {
            $video = Video::factory()->for($owner)->create(['status' => $status->value, 'scheduled_at' => $status === VideoStatus::PLANNED ? now()->addDay() : null]);
            $this->actingAs($admin)->post(route('admin.videos.ban', $video))->assertRedirect();
            $this->assertSame(VideoStatus::BANNED, $video->fresh()->status);
            $this->actingAs($admin)->post(route('admin.videos.unban', $video))->assertRedirect();
            $this->assertSame($status, $video->fresh()->status, $status->name.' comes back');
        }
        $this->assertTrue(VideoBans::available());
    }

    /** @return array<string, array{0: ?string, 1: string, 2: bool}> server software, the card's sentence, the prefix row */
    public static function webServers(): array
    {
        return [
            'nginx' => ['nginx/1.24.0', 'nginx sends the video files itself when its configuration has the internal location of docs/server-configs/README.md ("Video files"); the site checks it and otherwise streams them with PHP.', true],
            'Apache' => ['Apache/2.4.58 (Ubuntu)', 'Apache sends the video files itself when its module mod_xsendfile is on (X-Sendfile); otherwise the site streams them with PHP.', false],
            'LiteSpeed' => ['LiteSpeed', 'LiteSpeed sends the video files itself: the site hands each file to it (X-LiteSpeed-Location).', true],
            'IIS' => ['Microsoft-IIS/10.0', 'Only nginx, Apache with mod_xsendfile and LiteSpeed can send the video files themselves: on this web server the site streams them with PHP.', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('webServers')]
    public function test_the_streaming_card_explains_the_web_server_it_runs_on(string $software, string $sentence, bool $prefix): void
    {
        $this->software = $_SERVER['SERVER_SOFTWARE'] ?? null;
        $_SERVER['SERVER_SOFTWARE'] = $software;
        Environment::reset();

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui, 'admin.operational_tools_enabled' => true]);
            $html = (string) $this->actingAs(User::factory()->admin()->create())->get(route('admin.preflight.index'))->assertOk()->getContent();

            $this->assertStringContainsString(e($sentence), $html, "{$ui}: {$software}");
            foreach (self::webServers() as [$other, $otherSentence]) {
                if ($otherSentence !== $sentence) {
                    $this->assertStringNotContainsString(e($otherSentence), $html, "{$ui}: {$software} shows no other server's sentence");
                }
            }
            $this->assertSame($prefix, str_contains($html, e(__('Internal URI prefix'))), "{$ui}: the internal address prefix only where the server uses it");
        }
    }

    public function test_the_blocked_words_row_is_shown_in_the_readers_language(): void
    {
        // what BlockedWordsPass::finish() stores (its own format, read from its source)
        preg_match("/self::audit\\(sprintf\\(\\s*'([^']+)'/", (string) file_get_contents(app_path('Support/BlockedWordsPass.php')), $format);
        $this->assertNotEmpty($format[1] ?? null, 'the pass writes its row with sprintf');
        $stored = sprintf($format[1], number_format(1204).' videos', BlockedWordsPass::REASON, '1 tag', '3 categories');
        $this->assertTrue(AuditDetails::known(BlockedWordsPass::AUDIT_ACTION, $stored), $stored);

        $template = ':version: videos, tags and categories that use words that are always blocked are hidden from visitors (videos banned: :videos, tags hidden: :tags, categories hidden: :categories; ban reason: :reason)';
        $this->assertSame('1.2.3: videos, tags and categories that use words that are always blocked are hidden from visitors (videos banned: 1,204, tags hidden: 1, categories hidden: 3; ban reason: Blocked words (1.2.3))', AuditDetails::text(BlockedWordsPass::AUDIT_ACTION, $stored));

        // this test's Arabic line, for a tree whose lang/ar.json has none yet (lang/ar.json's own line wins once merged)
        $this->arabicLines([$template => ':version: أُخفيت عن الزوار الفيديوهات والوسوم والفئات التي تستخدم كلمات محظورة دائمًا (فيديوهات محظورة: :videos، وسوم مخفية: :tags، فئات مخفية: :categories؛ سبب الحظر: :reason)']);
        app()->setLocale('ar');
        $line = __($template);
        $html = (string) AuditDetails::html(BlockedWordsPass::AUDIT_ACTION, $stored);
        app()->setLocale('en');

        // the words of the Arabic line around the values, whichever line is in use
        $this->assertNotSame($template, $line);
        foreach (preg_split('/:(?:version|videos|tags|categories|reason)\b/u', $line) ?: [] as $words) {
            if (trim($words) !== '') {
                $this->assertStringContainsString(e(trim($words)), $html);
            }
        }
        $this->assertStringContainsString('<bdi dir="ltr">1.2.3</bdi>', $html);
        $this->assertStringContainsString('<bdi dir="ltr">1,204</bdi>', $html);
        $this->assertStringNotContainsString('banned', $html, 'no English left');
        $this->assertStringNotContainsString('always blocked', $html);
    }

    public function test_a_refused_delete_of_a_protected_job_is_stored_in_english_and_shown_in_the_readers_language(): void
    {
        config(['admin.operational_tools_enabled' => true, 'admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SomethingNew', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => 'App\\Jobs\\SomethingNew']]),
            'exception' => 'x', 'failed_at' => now()]);

        // the administrator reads Arabic; the row is stored in English
        $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->delete(route('admin.queue.failed.delete', $uuid))->assertRedirect();
        $row = DB::table('admin_audit_logs')->where('action', 'queue.failed.delete.blocked')->first();
        $this->assertSame('Blocked delete for protected failed job '.$uuid.': Unknown job.', $row->details);
        $this->assertContains('Unknown job', QueueJobInspector::riskKeys());

        // read in English and in Arabic (the request left the application in Arabic)
        app()->setLocale('en');
        $this->assertSame('Job '.$uuid.' is kept: Unknown job', AuditDetails::text('queue.failed.delete.blocked', $row->details));
        app()->setLocale('ar');
        $arabic = AuditDetails::text('queue.failed.delete.blocked', $row->details);
        $this->assertStringContainsString(__('Unknown job'), $arabic);
        $this->assertStringNotContainsString('Unknown job', $arabic, 'no English reason in the Arabic admin');
        app()->setLocale('en');

        // a row an earlier version stored with the acting admin's own words is shown as it was written
        $this->assertSame('Job #5 is kept: وظيفة غير معروفة', AuditDetails::text('queue.job.delete.blocked', 'Blocked delete for protected job #5: وظيفة غير معروفة.'));
    }

    public function test_an_english_menu_path_keeps_the_sentences_bracket_outside(): void
    {
        $this->assertSame('(يُضبط في <bdi dir="ltr">Money &gt; Ads</bdi>).', (string) AdminText::html('(يُضبط في Money > Ads).', [], true));
        $this->assertSame("(\u{2066}Money > Ads\u{2069}).", AdminText::isolatePaths('(Money > Ads).', true));
        // a step's own bracket pair stays in it
        $this->assertSame("في \u{2066}Content > Import videos (AVDBAPI)\u{2069}.", AdminText::isolatePaths('في Content > Import videos (AVDBAPI).', true));
        $this->assertSame("(\u{2066}Content > Import videos (AVDBAPI)\u{2069})", AdminText::isolatePaths('(Content > Import videos (AVDBAPI))', true));
        // as before: the full stop and the space after a path stay outside
        $this->assertSame("في \u{2066}Upload18 > API > Keys & security\u{2069}، ولا", AdminText::isolatePaths('في Upload18 > API > Keys & security، ولا', true));
    }

    public function test_the_log_files_actions_and_the_old_views_x_are_24_px_targets(): void
    {
        config(['admin.operational_tools_enabled' => true]);
        $admin = User::factory()->admin()->create();
        $storage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r3076-'.bin2hex(random_bytes(4));
        mkdir($storage.'/logs', 0777, true);
        file_put_contents($storage.'/logs/laravel.log', "line\n");
        $this->app->useStoragePath($storage);

        try {
            foreach (['v2', 'v1'] as $ui) {
                config(['admin.ui' => $ui]);
                $html = (string) $this->actingAs($admin)->get(route('admin.logs.index'))->assertOk()->getContent();
                $this->assertSame(1, preg_match('#<a href="[^"]*/logs-viewer/download/laravel\.log"[^>]*style="([^"]*)"#', $html, $download), "{$ui}: Download");
                $this->assertSame(1, preg_match('#<button type="submit" class="btn btn-link btn-sm p-0[^"]*" style="([^"]*)"[^>]*aria-label="[^"]*laravel\.log"#', $html, $delete), "{$ui}: Delete");
                foreach ([$download[1], $delete[1]] as $style) {
                    $this->assertStringContainsString('min-width:24px', $style);
                    $this->assertStringContainsString('min-height:24px', $style);
                }
                $this->assertStringContainsString('style="display:flex;gap:4px;justify-content:flex-end"', $html, "{$ui}: apart from each other");
            }
        } finally {
            @unlink($storage.'/logs/laravel.log');
            @rmdir($storage.'/logs');
            @rmdir($storage);
        }

        // the old admin's Views list
        config(['admin.ui' => 'v1']);
        $video = Video::factory()->for(User::factory()->create())->create();
        DB::table('views')->insert(['video_id' => $video->id, 'ip' => '203.0.113.7', 'view_at' => now()]);
        $html = (string) $this->actingAs($admin)->get(route('admin.views.index'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<button type="submit" class="btn btn-outline-danger" style="([^"]*)" title="[^"]*" aria-label="([^"]*)"#', $html, $x));
        $this->assertStringContainsString('min-width:24px', $x[1]);
        $this->assertStringContainsString('min-height:24px', $x[1]);
        $this->assertSame(__('Delete'), html_entity_decode($x[2]), 'named for screen readers');
    }

    public function test_the_php_binary_is_worked_out_once_and_used_while_it_is_there(): void
    {
        $memo = new \ReflectionProperty(PhpBinary::class, 'memo');
        $key = new \ReflectionMethod(PhpBinary::class, 'key');
        $parts = [['R3AD_TEST_PHP' => ''], (string) PHP_BINARY, PHP_VERSION_ID, PHP_OS_FAMILY];
        $_ENV['R3AD_TEST_PHP'] = '';
        $php = $this->linkedPhp();

        try {
            PhpBinary::forget();
            $first = PhpBinary::resolve(['R3AD_TEST_PHP']);
            $this->assertNotNull($first);
            $this->assertNotNull(SafeCache::get($key->invoke(null, 'resolve', $parts)), 'the answer is kept in the cache');

            // the next request takes the kept answer (here: this PHP under its second path, put there in its place) and
            // does not look again
            SafeCache::put($key->invoke(null, 'resolve', $parts), ['v' => $php['exe']], 600);
            $memo->setValue(null, []);
            $this->assertSame($php['exe'], PhpBinary::resolve(['R3AD_TEST_PHP']));

            // ... only while that binary is there
            $this->pointLink($php['link'], null);
            $memo->setValue(null, []);
            $this->assertSame($first, PhpBinary::resolve(['R3AD_TEST_PHP']), 'a binary that has gone is looked for again');

            // a configured binary is part of the key: a changed setting is looked at at once
            $_ENV['R3AD_TEST_PHP'] = (string) PHP_BINARY;
            $this->assertSame((string) PHP_BINARY, PhpBinary::resolve(['R3AD_TEST_PHP']));
        } finally {
            PhpBinary::forget();
        }
    }

    /**
     * R3-077 (review AD-R1): a kept answer is used only while it is still the answer. When the host changes the program
     * behind the same path to one that does not work (an alternatives link, a panel switching its PHP in place), resolve()
     * works it out again at once, in this process and in the next (a kept path that does not work ran every import
     * inside the admin's request for up to 10 minutes). A configured binary that does not work gives way to one that
     * does, and is used again at once when it works again.
     */
    public function test_a_kept_php_binary_that_stops_working_is_not_used(): void
    {
        $memo = new \ReflectionProperty(PhpBinary::class, 'memo');
        $key = new \ReflectionMethod(PhpBinary::class, 'key');
        $parts = [['R3AD_TEST_PHP' => ''], (string) PHP_BINARY, PHP_VERSION_ID, PHP_OS_FAMILY];
        $_ENV['R3AD_TEST_PHP'] = '';
        $php = $this->linkedPhp();

        try {
            PhpBinary::forget();
            $first = PhpBinary::resolve(['R3AD_TEST_PHP']);
            $this->assertNotNull($first);
            $this->assertTrue(PhpBinary::usable($php['exe']), 'this PHP under its second path works');

            SafeCache::put($key->invoke(null, 'resolve', $parts), ['v' => $php['exe']], 600);
            $memo->setValue(null, []);
            $this->assertSame($php['exe'], PhpBinary::resolve(['R3AD_TEST_PHP']), 'the kept answer is used');

            // the program behind that path changes to one that does not work: not used any more, in this process ...
            $this->assertTrue($this->pointLink($php['link'], $php['broken']));
            $this->assertSame($first, PhpBinary::resolve(['R3AD_TEST_PHP']), 'this process');
            $this->assertFalse(PhpBinary::usable($php['exe']), 'the program behind the path does not work now');
            // ... nor in the next one: the kept answer itself was worked out again
            $memo->setValue(null, []);
            $this->assertSame($first, PhpBinary::resolve(['R3AD_TEST_PHP']), 'the next process');
            $this->assertSame(['v' => $first], SafeCache::get($key->invoke(null, 'resolve', $parts)));

            // a configured binary: used while it works ...
            $this->assertTrue($this->pointLink($php['link'], $php['php']));
            $_ENV['R3AD_TEST_PHP'] = $php['exe'];
            $this->assertSame($php['exe'], PhpBinary::resolve(['R3AD_TEST_PHP']));
            // ... one that works in its place while it does not ...
            $this->assertTrue($this->pointLink($php['link'], $php['broken']));
            $fallback = PhpBinary::resolve(['R3AD_TEST_PHP']);
            $this->assertNotSame($php['exe'], $fallback);
            $this->assertTrue(PhpBinary::usable($fallback), 'the binary in its place works');
            // ... and itself again at once when it works again (a configured binary that works comes first)
            $this->assertTrue($this->pointLink($php['link'], $php['php']));
            $this->assertSame($php['exe'], PhpBinary::resolve(['R3AD_TEST_PHP']), 'the configured binary works again');
            $memo->setValue(null, []);
            $this->assertSame($php['exe'], PhpBinary::resolve(['R3AD_TEST_PHP']), 'and in the next process');
        } finally {
            PhpBinary::forget();
        }
    }

    /**
     * R3-077 (review): a probe that could not run (exec failed: a process limit, the panel updating PHP) is tried again
     * within a minute; only a real answer is kept until the binary changes. A day of "unusable" would run every import
     * inline in the admin's request.
     */
    public function test_a_probe_that_could_not_run_is_tried_again_within_a_minute(): void
    {
        $fake = (string) tempnam(sys_get_temp_dir(), 'avd-nophp');
        file_put_contents($fake, 'not a program');
        $key = new \ReflectionMethod(PhpBinary::class, 'key');
        $signature = new \ReflectionMethod(PhpBinary::class, 'signature');
        $versionId = new \ReflectionMethod(PhpBinary::class, 'versionId');
        $memo = new \ReflectionProperty(PhpBinary::class, 'memo');

        try {
            PhpBinary::forget();
            $this->assertNull($versionId->invoke(null, $fake));
            $kept = $key->invoke(null, 'version', [$signature->invoke(null, $fake)]);
            $this->assertSame(['v' => null], SafeCache::get($kept), 'kept a little while');

            $this->travel(50)->seconds();
            $this->assertSame(['v' => null], SafeCache::get($kept));
            $this->travel(15)->seconds();
            $this->assertNull(SafeCache::get($kept), 'tried again after a minute, not after a day');

            // this process does not hold it longer either
            $this->assertLessThanOrEqual(time() + 60, max(array_map(fn (array $entry): int => $entry[1], $memo->getValue())));
        } finally {
            @unlink($fake);
            $this->travelBack();
            PhpBinary::forget();
        }
    }

    /**
     * R3-077 look-around (lane AD, pass 2): a PHP may print a line before its answer. On Windows with OPcache every PHP
     * started from a web request printed "<date> (<pid>): Warning Opcode handlers are unusable due to ASLR ...", and the
     * version probe read the process id as the version (25120: not usable; 81234: "8.1", usable whatever it was), which
     * R3-077 then kept a day: every import inside the admin's request. The version is read behind a marker of its own.
     */
    public function test_a_line_a_php_prints_first_is_never_read_as_its_version(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r3ad-fake-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $warning = 'Fri Oct 30 00:29:18 2026 (25120): Warning Opcode handlers are unusable due to ASLR (fall-back to file cache)';
        // a stand-in that prints the line, then hands its arguments to this PHP (as the real one answers after it)
        if (PHP_OS_FAMILY === 'Windows') {
            $fake = $dir.DIRECTORY_SEPARATOR.'php.cmd';
            file_put_contents($fake, "@echo off\r\necho {$warning}\r\n\"".PHP_BINARY."\" %*\r\n");
        } else {
            $fake = $dir.DIRECTORY_SEPARATOR.'php';
            file_put_contents($fake, "#!/bin/sh\necho '{$warning}'\nexec ".escapeshellarg((string) PHP_BINARY)." \"\$@\"\n");
            chmod($fake, 0755);
        }
        $versionId = new \ReflectionMethod(PhpBinary::class, 'versionId');

        try {
            // a temporary folder a host mounts without the right to run programs (noexec) cannot test this
            $ran = [];
            @exec(escapeshellarg($fake).' -r '.escapeshellarg("echo 'ran';").' 2>&1', $ran, $status);
            if ($status !== 0 || ! in_array('ran', array_map('trim', $ran), true)) {
                $this->markTestSkipped('the stand-in cannot run from '.sys_get_temp_dir());
            }

            PhpBinary::forget();
            $this->assertSame(PHP_VERSION_ID, $versionId->invoke(null, $fake), 'the version of the PHP behind the stand-in');
        } finally {
            PhpBinary::forget();
            @unlink($fake);
            @rmdir($dir);
        }
    }

    /**
     * AD-X1 (found by installer lane IN with R3-036): the AVDBAPI import page's "Permanent scheduler command" is the
     * installer's own line, as Tools > Scheduled tasks shows it: the cron line with its lock (and the variant for panels
     * that run cron jobs as root) on Linux, the Task Scheduler command on Windows; never the old line without the lock
     * (added next to the installer's line, the scheduler ran twice every minute).
     */
    public function test_the_import_page_shows_the_installers_scheduler_line(): void
    {
        $this->scratchStorage();
        $admin = User::factory()->admin()->create();
        // the installer's PHP (Installer::schedulerCronCommand(), LaravelSchedulerCron::ensureInstalled())
        $php = PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']) ?: 'php';

        foreach (['v1', 'v2'] as $ui) {
            config(['admin.ui' => $ui]);
            $html = (string) $this->actingAs($admin)->get(route('admin.avdb-import.index'))->assertOk()->getContent();

            $this->assertStringContainsString(e(\App\Support\LaravelSchedulerCron::lineFor($php)), $html, $ui);
            $this->assertStringNotContainsString('artisan schedule:run &gt;&gt; /dev/null', $html, "{$ui}: never the line without the lock");

            if (Environment::isWindows()) {
                $this->assertStringContainsString('schtasks /Create /SC MINUTE /MO 1', $html, $ui);
                $this->assertStringContainsString(e(__("Create this site's task in the Windows Task Scheduler with this command (once, in a Command Prompt):")), $html, $ui);
                $this->assertStringNotContainsString(e(__('Command for a cron job in your hosting panel (every minute):')), $html, "{$ui}: no panel's cron job on Windows");
            } else {
                $this->assertStringContainsString(e('/usr/bin/flock -n -o '.escapeshellarg(\App\Support\LaravelSchedulerCron::lockPath())), $html, $ui);
                $this->assertStringContainsString(e(\App\Support\LaravelSchedulerCron::panelLineFor($php)), $html, $ui);
                $this->assertStringContainsString(e(__('Cron line (every minute):')), $html, $ui);
                // a hosting panel's cron job: the command alone, in a box of its own (AD-V1)
                $this->assertStringContainsString(e(__('Command for a cron job in your hosting panel (every minute):')), $html, $ui);
                $this->assertStringContainsString('tabindex="0">'.e(\App\Support\LaravelSchedulerCron::commandFor($php)).'</pre>', $html, $ui);
            }
        }
    }

    /**
     * AD-X1 (review AD-R2): with CRON_PHP_BINARY set (.env.example offers it) to another PHP than the imports', the import
     * page still shows the installer's line, and a saved schedule adds the installer's line: the scheduler's PHP as the
     * installer and Tools > Scheduled tasks take it (CRON_PHP_BINARY first), never the import PHP.
     */
    public function test_the_import_pages_scheduler_line_is_the_installers_with_cron_php_binary_set(): void
    {
        $this->scratchStorage();
        $php = $this->linkedPhp();
        $_ENV['CRON_PHP_BINARY'] = $php['exe'];

        try {
            PhpBinary::forget();
            $import = PhpBinary::resolve(['AVDB_IMPORT_PHP_BINARY']);
            $this->assertNotNull($import);
            $this->assertNotSame($php['exe'], $import, 'the imports use another PHP');
            // the installer (Installer::schedulerCronCommand(), ensureInstalled() with no binary) and Tools > Scheduled
            // tasks (CronController::findPhpBinary(), the "php" status probe) take CRON_PHP_BINARY
            $this->assertSame($php['exe'], PhpBinary::resolve(['CRON_PHP_BINARY', 'AVDB_IMPORT_PHP_BINARY']));
            $this->assertSame($php['exe'], \App\Http\Controllers\Admin\CronController::findPhpBinary());

            $admin = User::factory()->admin()->create();
            foreach (['v1', 'v2'] as $ui) {
                config(['admin.ui' => $ui]);
                $html = (string) $this->actingAs($admin)->get(route('admin.avdb-import.index'))->assertOk()->getContent();

                $this->assertStringContainsString(e(\App\Support\LaravelSchedulerCron::lineFor($php['exe'])), $html, $ui);
                $this->assertStringNotContainsString(e(\App\Support\LaravelSchedulerCron::lineFor($import)), $html, "{$ui}: never the import PHP's line");
                if (! Environment::isWindows()) {
                    $this->assertStringContainsString(e(\App\Support\LaravelSchedulerCron::panelLineFor($php['exe'])), $html, $ui);
                    $this->assertStringContainsString('tabindex="0">'.e(\App\Support\LaravelSchedulerCron::commandFor($php['exe'])).'</pre>', $html, "{$ui}: the panel's command (AD-V1)");
                }
            }

            // A saved schedule adds the scheduler's line (ensureInstalled()). On Windows it only answers with the line (the
            // Task Scheduler is set up by hand), so the line it would add is seen without touching any crontab.
            if (Environment::isWindows()) {
                $form = ['categories' => [1], 'pages' => 1, 'start_page' => 1, 'pagesize' => 10, 'delay' => 0, 'language_mode' => 'auto', 'thumbnail_mode' => 'remote', 'sort_direction' => 'desc', 'translation_chunk' => 50, 'translation_concurrency' => 2];
                $answer = $this->actingAs($admin)->postJson(route('admin.avdb-import.schedules.store'), $form + ['schedule_name' => 'Nightly', 'schedule_frequency' => 'daily', 'schedule_time' => '02:00', 'schedule_enabled' => '1'])
                    ->assertOk()->json('scheduler_cron');
                $this->assertIsArray($answer);
                $this->assertStringContainsString($php['exe'], (string) ($answer['command'] ?? ''), 'the installer\'s PHP');
                $this->assertStringNotContainsString($import, (string) ($answer['command'] ?? ''), 'never the import PHP');
            }
        } finally {
            PhpBinary::forget();
        }
    }

    /**
     * AD-X1 (independent check of pass 2: AD-V1): the import page's scheduler card gives what Tools > Scheduled tasks
     * gives, with the same words in the same order: on Linux the crontab line (crontab -e), the command for a cron job of
     * a hosting panel run as the site's account - without the "* * * * *" a panel's shell reads as file names - and the
     * command for panels that run cron jobs as root; on Windows the Task Scheduler command. Both admins.
     */
    public function test_the_import_pages_scheduler_card_gives_what_scheduled_tasks_gives(): void
    {
        config(['admin.operational_tools_enabled' => true, 'admin.ui' => 'v1']);
        $this->scratchStorage();
        $admin = User::factory()->admin()->create();
        $php = (string) \App\Http\Controllers\Admin\CronController::findPhpBinary();
        $this->assertNotSame('', $php, 'a PHP command line is found here');

        // Tools > Scheduled tasks (the old admin works its PHP check out before answering)
        $tasks = $this->schedulerBoxes((string) $this->actingAs($admin)->get(route('admin.cron.index'))->assertOk()->getContent(), 'tasks');
        $this->assertSame(Environment::isWindows()
            ? [[__("Create this site's task in the Windows Task Scheduler with this command (once, in a Command Prompt):"), \App\Support\LaravelSchedulerCron::lineFor($php)]]
            : [
                [__('Cron line (every minute):'), \App\Support\LaravelSchedulerCron::lineFor($php)],
                [__('Command for a cron job in your hosting panel (every minute):'), \App\Support\LaravelSchedulerCron::commandFor($php)],
                [__('If your panel runs cron jobs as root, use this command instead:'), \App\Support\LaravelSchedulerCron::panelLineFor($php)],
            ], $tasks, 'Tools > Scheduled tasks');

        foreach (['v1', 'v2'] as $ui) {
            config(['admin.ui' => $ui]);
            $card = $this->schedulerBoxes((string) $this->actingAs($admin)->get(route('admin.avdb-import.index'))->assertOk()->getContent(), 'import');
            $this->assertSame($tasks, $card, "{$ui}: the import page's card gives what Scheduled tasks gives");
        }
    }

    /**
     * AD-X1 (AD-V1): the command the import page gives a hosting panel's cron job runs where a panel runs it -
     * "/bin/sh -c <command>" in the site account's home folder, which holds files of its own, with a cron job's small
     * environment - and starts the scheduler under the cron line's lock. The crontab line run that way never did: its
     * "* * * * *" became those files' names ("access-logs: not found", exit 127; installer lane IN's IN-RV-01). A stand-in
     * for PHP (this PHP answers the probes; "artisan schedule:run" is only written down) and a storage folder of the
     * test's own take the run, its lock and its log.
     */
    public function test_the_import_pages_command_for_a_hosting_panel_runs_where_the_panel_runs_it(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || ! is_executable('/usr/bin/flock') || ! is_executable('/usr/bin/env') || ! function_exists('proc_open')) {
            $this->markTestSkipped('Needs a Unix shell with /usr/bin/flock (the lock of the cron line).');
        }

        $dir = $this->scratchStorage();
        foreach (['bin', 'home/public_html'] as $folder) {
            mkdir($dir.'/'.$folder, 0777, true);
        }
        foreach (['access-logs', 'etc', 'mail', 'tmp'] as $entry) {
            touch($dir.'/home/'.$entry);
        }
        $ran = $dir.'/ran.txt';
        $fake = $dir.'/bin/php';
        file_put_contents($fake, "#!/bin/sh\n"
            ."# AdminRound3FixesTest's stand-in for PHP: this PHP answers the probes, a run is only written down\n"
            .'if [ "$1" = "-r" ]; then exec '.escapeshellarg((string) PHP_BINARY)." \"\$@\"; fi\n"
            .'printf \'%s\n\' "$*" >> '.escapeshellarg($ran)."\n");
        chmod($fake, 0755);
        $ok = [];
        @exec(escapeshellarg($fake).' -r '.escapeshellarg("echo 'ran';").' 2>&1', $ok, $status);
        if ($status !== 0 || ! in_array('ran', array_map('trim', $ok), true)) {
            $this->markTestSkipped('the stand-in cannot run from '.sys_get_temp_dir());
        }
        $_ENV['CRON_PHP_BINARY'] = $fake;

        try {
            PhpBinary::forget();
            $this->assertSame($fake, \App\Http\Controllers\Admin\CronController::findPhpBinary(), 'the stand-in is the scheduler\'s PHP');
            config(['admin.ui' => 'v1']);
            $html = (string) $this->actingAs(User::factory()->admin()->create())->get(route('admin.avdb-import.index'))->assertOk()->getContent();
            $boxes = array_column($this->schedulerBoxes($html, 'import'), 1, 0);
            $command = $boxes[__('Command for a cron job in your hosting panel (every minute):')] ?? null;
            $line = $boxes[__('Cron line (every minute):')] ?? null;
            $this->assertIsString($line, 'the crontab line');
            $this->assertIsString($command, 'the card gives a hosting panel\'s cron job a command of its own');
            $this->assertStringContainsString($fake, $command, 'the scheduler\'s PHP');

            // as a panel runs its cron job: the account's home folder, a cron job's small environment
            $run = static function (string $shown) use ($dir): \Symfony\Component\Process\Process {
                $job = new \Symfony\Component\Process\Process(['/usr/bin/env', '-i', 'PATH=/usr/bin:/bin', 'HOME='.$dir.'/home', 'SHELL=/bin/sh', '/bin/sh', '-c', $shown], $dir.'/home');
                $job->setTimeout(60);
                $job->run();

                return $job;
            };

            $job = $run($command);
            $this->assertSame(0, $job->getExitCode(), 'the panel\'s shell runs it: '.$job->getErrorOutput());
            $this->assertStringContainsString('artisan schedule:run', (string) @file_get_contents($ran), 'it started the scheduler');
            $this->assertNotSame('', trim((string) @file_get_contents($dir.'/storage/logs/schedule-run.log')), 'its date line is in the cron log');
            $this->assertFileExists(\App\Support\LaravelSchedulerCron::lockPath(), 'under the cron line\'s own lock');

            // the crontab line in a panel's command field: its "* * * * *" are the file names of the home folder
            @unlink($ran);
            $wrong = $run($line);
            $this->assertSame(127, $wrong->getExitCode(), 'the crontab line is for the crontab, not for a panel\'s command field');
            $this->assertFileDoesNotExist($ran, 'it never started the scheduler there');
        } finally {
            PhpBinary::forget();
        }
    }

    /**
     * A storage folder of the test's own (<folder>/storage; the folder is removed in tearDown). The cron's stamps are the
     * site's real ones otherwise, and the import page shows its "Permanent scheduler command" only while the scheduler
     * needs attention (no run of the cron in the last three minutes): on a machine whose cron runs this site's scheduler
     * the card would not be there. The lines' lock and log are in this folder too.
     */
    private function scratchStorage(): string
    {
        $dir = $this->panelDir ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r3ad-panel-'.bin2hex(random_bytes(4));
        foreach (['app', 'framework', 'logs'] as $folder) {
            if (! is_dir($dir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.$folder)) {
                mkdir($dir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.$folder, 0777, true);
            }
        }
        $this->app->useStoragePath($dir.DIRECTORY_SEPARATOR.'storage');

        return $dir;
    }

    /**
     * The scheduler commands a page offers, each with its label, in order: Tools > Scheduled tasks ('tasks') or the
     * import page's "Permanent scheduler command" ('import').
     *
     * @return list<array{0: string, 1: string}>
     */
    private function schedulerBoxes(string $html, string $page): array
    {
        $pattern = $page === 'tasks'
            ? '#<div class="small fw-bold mb-1">([^<]*)</div>\s*<pre class="small mb-\d p-2 border rounded" dir="ltr" tabindex="0" style="[^"]*"><code>([^<]*)</code></pre>#'
            : '#<div(?: class="mt-2")?>([^<]*)</div>\s*<pre class="bg-dark text-light p-2 rounded small mb-\d" dir="ltr" style="[^"]*" tabindex="0">([^<]*)</pre>#';
        preg_match_all($pattern, $html, $found, PREG_SET_ORDER);
        $this->assertNotSame([], $found, $page.': the page shows the scheduler commands');

        return array_map(static fn (array $box): array => [
            trim(html_entity_decode($box[1], ENT_QUOTES | ENT_HTML5)),
            html_entity_decode($box[2], ENT_QUOTES | ENT_HTML5),
        ], $found);
    }

    /**
     * Lane AD pass 2 (its look-around, in scope by the orchestrator's decision): "What moved where" names each page of
     * the old admin menu in that menu's own words, in every language. The old sidebar showed __(group) and __(title) of
     * config/menu.php; one translation of the whole path ("Community > Reports") said other words in 335 of 850 path x
     * language pairs (Arabic "المجتمع > التقارير" where the menu showed "المجتمع > البلاغات").
     */
    public function test_what_moved_where_names_each_old_page_in_the_old_menus_own_words(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();

        // the old sidebar's paths: "group > title" of each item of a group, or a title of its own (config/menu.php)
        $sidebar = [];
        foreach ((array) config('menu.admin') as $entry) {
            if (isset($entry['group'])) {
                foreach ((array) ($entry['children'] ?? []) as $child) {
                    $sidebar[$entry['group'].' > '.$child['title']] = [$entry['group'], $child['title']];
                }
            } elseif (isset($entry['title'])) {
                $sidebar[$entry['title']] = [$entry['title']];
            }
        }

        // every language file (Filipino too, which config/languages.php does not offer to visitors)
        $locales = array_map(fn (string $file): string => basename($file, '.json'), glob(lang_path('*.json')));
        $this->assertGreaterThanOrEqual(18, count($locales));
        config(['languages' => (array) config('languages') + array_fill_keys($locales, ['label' => '', 'emoji' => ''])]);
        $checked = 0;

        foreach ($locales as $locale) {
            $html = html_entity_decode((string) $this->actingAs($admin)->withSession(['app_locale' => $locale])
                ->get(route('admin.shell.moved'))->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5);
            app()->setLocale($locale);

            foreach ((array) config('admin_nav.old_menu') as $entry) {
                $this->assertArrayHasKey($entry['old'], $sidebar, 'a path of the old sidebar: '.$entry['old']);
                $menuWords = implode(' > ', array_map(fn (string $label): string => __($label), $sidebar[$entry['old']]));
                $this->assertStringContainsString('<td>'.$menuWords.'</td>', $html, "{$locale}: {$entry['old']}");
                $checked++;
            }

            app()->setLocale('en');
        }

        $this->assertSame(count($locales) * count((array) config('admin_nav.old_menu')), $checked);
    }

    /**
     * Lane AD pass 2 (the old admin's light mode): the log viewer package's page (Tools > Logs, /admin/logs) is the
     * package's own template with one style block: in light mode its small grey words (4.4:1 on its grey) and its search
     * box's edge (1.4:1) one shade darker. The copy stays the package's template apart from that block: a package
     * update that changes the template fails here.
     */
    public function test_the_log_viewer_page_is_the_packages_template_with_one_contrast_block(): void
    {
        $package = (string) file_get_contents(base_path('vendor/opcodesio/log-viewer/resources/views/index.blade.php'));
        $ours = (string) file_get_contents(resource_path('views/vendor/log-viewer/index.blade.php'));

        $this->assertSame(1, preg_match('#    \{\{-- AVDBCMS:.*?</style>\n#s', $ours, $block), 'one block of ours');
        $this->assertSame($package, str_replace($block[0], '', $ours), 'the package template apart from the block');
        $this->assertStringContainsString('html:not(.dark) .text-gray-500 { color: rgb(82 82 91); }', $block[0]);
        $this->assertStringContainsString('html:not(.dark) .search { border-color: rgb(113 113 122); }', $block[0]);
        $this->assertStringContainsString('html.dark .search { border-color: rgb(113 113 122); }', $block[0]);

        config(['admin.operational_tools_enabled' => true]);
        $html = (string) $this->actingAs(User::factory()->admin()->create())->get('/admin/logs')->assertOk()->getContent();
        $this->assertStringContainsString('<style id="avd-log-viewer-contrast">', $html, 'the page uses the copy');
    }

    /**
     * Lane AD pass 2 (the old admin's light mode, measured on the rendered pixels: 1,781 of 15,661 texts under their
     * threshold before, see runs/p2): the causes stay fixed.
     *  - The old settings page's script painted the look's DARK canvas and surface into <html> in both modes
     *    (setProperty('--avd-surface', darkSurface)): its hero, cards and tab bar read 1.04-2.4:1 in light mode. It
     *    writes both modes' colours as rules now (applyLiveCanvas), never one inline value.
     *  - The Appearance tab's studio panels were painted dark in both modes (#11141d, rgba(15, 23, 42, .65)): their
     *    fills are the mode's (--avd-studio-*), dark mode keeping the values it had.
     *  - Its script wrote its buttons' words in English after an activation: the admin's language now.
     *  - The rules of the old admin for both modes: the selected list row's darker blue (AD-R3), TomSelect's and the
     *    header search's field edge (AD-R5), the mood strip's label (AD-R4), the code words, the words on fills.
     */
    public function test_the_old_settings_page_paints_each_colour_mode_with_its_own_colours(): void
    {
        $view = (string) file_get_contents(resource_path('views/admin/settings/index.blade.php'));

        $this->assertStringNotContainsString("setProperty('--avd-surface'", $view, 'no single inline surface colour');
        $this->assertStringNotContainsString("setProperty('--avd-bg'", $view, 'no single inline canvas colour');
        $this->assertSame(1, substr_count($view, 'applyLiveCanvas(darkBg, darkSurface, lightBg, lightSurface);'), 'the preset strip');
        $this->assertSame(1, substr_count($view, 'applyLiveCanvas(dbg, dsurf, lbg, lsurf);'), 'the custom colours');
        $this->assertStringContainsString('html[data-bs-theme="light"]{--avd-bg:', $view);
        $this->assertStringNotContainsString('#11141d; border-color', $view, 'the master panel follows the mode');
        $this->assertStringNotContainsString('background: rgba(15, 23, 42, 0.65)', $view, 'today\'s design row follows the mode');
        $this->assertMatchesRegularExpression('/\[data-bs-theme="light"\] \.avd-settings-page \{\s*--avd-studio-panel-bg: var\(--settings-card, #fff\);/', $view);
        $this->assertMatchesRegularExpression('/\.avd-settings-page \{\s*--avd-studio-panel-bg: #11141d;/', $view, 'dark mode keeps its panel colour');
        $this->assertMatchesRegularExpression('/#moodQuickFilterStrip > span \{\s*flex: 0 0 auto;\s*min-width: max-content;\s*white-space: nowrap;/', $view);
        foreach (['Apply Theme Now', '>Currently Active<', "'Restore Default' : 'Activate'"] as $english) {
            $this->assertStringNotContainsString($english, $view, 'the script writes the admin\'s words');
        }

        // the page itself: the translated words reach the script
        config(['admin.ui' => 'v1']);
        $this->arabicLines(['Currently Active' => 'نشط حاليا (اختبار)']);
        app()->setLocale('ar');
        $html = (string) $this->actingAs(User::factory()->admin()->create())->withSession(['app_locale' => 'ar'])
            ->get(route('admin.settings.index'))->assertOk()->getContent();
        app()->setLocale('en');
        $this->assertMatchesRegularExpression('/const studioWords = \{"active":"(?:\\\\u[0-9a-f]{4}|[^"])*"/i', $html);
        $this->assertStringContainsString('function applyLiveCanvas(', $html);

        $sass = (string) file_get_contents(resource_path('sass/dark.scss'));
        foreach ([
            'body.avd-admin .list-group {',
            '--bs-list-group-active-bg: var(--avd-admin-list-active);',
            'body.avd-admin .ts-wrapper:is(.form-select, .form-control):not(.disabled) .ts-control {',
            'border: 1px solid var(--avd-admin-field-edge) !important;',
            'body.avd-admin .avd-search-wrap:not(:focus-within) .input-group {',
            '--bs-code-color: #ab296a;',
            ':is(.bg-warning, .text-bg-warning, .btn-warning, .bg-gradient-warning) { color: avd-admin-label(--avd-on-warning) !important; }',
            'html[data-bs-theme="light"] body.avd-admin .table-dark .text-muted {',
        ] as $rule) {
            $this->assertStringContainsString($rule, $sass);
        }
    }

    public function test_the_custom_accent_reads_colour_like_the_rest_of_the_admin(): void
    {
        $options = \App\Support\ChannelCustomizationSettings::options()['accent'];

        $this->assertSame('Look colour', $options['look']);
        $this->assertSame('Custom colour', $options['custom']);
    }

    /**
     * R3-019 look-around (the view-only demo account): the settings pages' script is loaded by a <script> tag, which
     * cannot mark itself as a background request, and its address became "the page you came from". A closed page the
     * account opened without a Referer (typed, a bookmark) went "back" to the script's text. The script reads the
     * session as the picture routes do (ReadOnlySession): never stored as the previous page, no session cookie.
     */
    public function test_the_settings_script_is_never_the_page_a_closed_page_goes_back_to(): void
    {
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('admin.settings.script');
        $this->assertContains(\App\Http\Middleware\ReadOnlySession::class, $route->gatherMiddleware());
        foreach (\App\Http\Middleware\ReadOnlySession::REPLACES as $removed) {
            $this->assertContains($removed, $route->excludedMiddleware(), 'still runs '.$removed);
        }
        // the session starts before the middleware that ask for the account (the admin check, the view-only guard)
        $resolved = array_map(fn ($m) => is_string($m) ? explode(':', $m)[0] : get_class($m), app('router')->gatherRouteMiddleware($route));
        $session = array_search(\App\Http\Middleware\ReadOnlySession::class, $resolved, true);
        $this->assertIsInt($session);
        foreach ([\App\Http\Middleware\IsAdmin::class, \App\Http\Middleware\ViewOnlyDemo::class] as $reader) {
            $this->assertIsInt($at = array_search($reader, $resolved, true), 'runs '.$reader);
            $this->assertLessThan($at, $session, $reader);
        }

        $demo = User::factory()->admin()->create();
        $demo->forceFill([\App\Http\Middleware\ViewOnlyDemo::COLUMN => true])->save();
        $id = Str::random(40);
        app('session')->driver()->getHandler()->write($id, serialize([
            \Illuminate\Support\Facades\Auth::guard('web')->getName() => $demo->getAuthIdentifier(),
            '_token' => Str::random(40),
            '_previous' => ['url' => url('/admin/users'), 'route' => 'admin.users.index'],
            '_flash' => ['old' => [], 'new' => []],
        ]));
        $cookie = config('session.cookie');

        // the script answers the signed-in administrator, with no session cookie
        $script = $this->withCookie($cookie, $id)->get(route('admin.settings.script', ['v' => 'x'], false))->assertOk();
        $this->assertStringContainsString('javascript', (string) $script->headers->get('Content-Type'));
        foreach ($script->headers->getCookies() as $sent) {
            $this->assertNotSame($cookie, $sent->getName(), 'the script sent the session cookie');
        }
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->flushSession();

        // a closed page opened from the address bar (no Referer) goes back to the page the account was on, not the script
        $refusal = $this->withCookie($cookie, $id)->get('/admin/system')->assertStatus(303);
        $this->assertSame(url('/admin/users'), $refusal->headers->get('Location'));
    }
}
