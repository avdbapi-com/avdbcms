<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AdminText;
use App\Support\AuditActions;
use App\Support\AuditDetails;
use App\Support\EngagementCounters;
use App\Support\LegacyRowTimes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The details of Tools > Logs > Admin actions are shown in the admin's language (R2A-054) and the Scheduled tasks and
 * Background jobs activity cards name the actions in plain words with the same details (R2A-051), while every row
 * keeps the English it was stored with.
 *
 * Each sample below is what the code really writes (the writers' own methods where they have one: the update
 * migrations' auditDetails(), App\Support\LegacyRowTimes::auditDetails(), the constants), so a change of a writer's
 * wording that the translator does not follow fails here (e.g. the old-times line of R2A-001 at the merge).
 */
class AdminActionsDetailsTest extends TestCase
{
    use RefreshDatabase;

    private ?string $linesDir = null;

    protected function tearDown(): void
    {
        if ($this->linesDir !== null) {
            @unlink($this->linesDir.DIRECTORY_SEPARATOR.'ar.json');
            @rmdir($this->linesDir);
        }

        parent::tearDown();
    }

    /**
     * Arabic lines for this test, on top of lang/ar.json (the merged files carry the lane's translations; a test must
     * not depend on them). A JSON path of their own: Translator::addLines() would split a key at its dots.
     *
     * @param  array<string, string>  $lines
     */
    private function arabicLines(array $lines): void
    {
        $this->linesDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'avd-r2a054-'.bin2hex(random_bytes(5));
        mkdir($this->linesDir);
        file_put_contents($this->linesDir.DIRECTORY_SEPARATOR.'ar.json', json_encode($lines, JSON_UNESCAPED_UNICODE));
        app('translator')->addJsonPath($this->linesDir);
    }

    /** @return array<string, array{0: string, 1: string}> action, stored details */
    public static function storedDetails(): array
    {
        $migration = fn (string $file) => require dirname(__DIR__, 2).'/database/migrations/'.$file;
        $limits = $migration('2026_10_24_120000_correct_bundled_plan_upload_limits.php');
        $passwords = $migration('2026_10_24_120200_lock_bundled_sample_accounts.php');
        $telegram = $migration('2026_10_26_200000_new_telegram_links.php');
        $premium = $migration('2026_10_27_130000_correct_premium_page_default_texts.php');
        $source = fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 2).'/'.$file);
        // the literal details of writers that build them inline
        $literal = function (string $file, string $pattern) use ($source): string {
            preg_match($pattern, $source($file), $match);

            return stripcslashes($match[1] ?? 'not found in '.$file);
        };

        return [
            'plan limits' => ['update.plan_limits_defaults', $limits::auditDetails(['free_max_uploads', 'free_max_file_size', 'free_max_storage', 'premium_max_file_size'])],
            'known passwords, 1' => ['security.known_passwords_reset', $passwords::auditDetails(['pbergstrom'])[0]],
            'known passwords, 2' => ['security.known_passwords_reset', $passwords::auditDetails(['pbergstrom', 'blackbox_creator'])[0]],
            'homepage feed ad' => ['update.homepage_feed_list_ad', $migration('2026_10_25_120000_add_homepage_feed_list_ad.php')::AUDIT_DETAILS],
            'publish dates' => ['update.publish_dates', sprintf($literal('database/migrations/2026_10_26_100100_check_publish_dates_of_videos.php', "/'(1\\.2\\.3: %d scheduled videos[^']*)'/"), 4)],
            'never published' => ['update.never_published', sprintf($literal('database/migrations/2026_10_26_100100_check_publish_dates_of_videos.php', "/'(1\\.2\\.3: %d public videos[^']*)'/"), 7)],
            'recount done' => ['update.recount', sprintf($literal('app/Support/EngagementCounters.php', "/'(1\\.2\\.3: likes, dislikes and comments of every video[^']*)'/"), EngagementCounters::videos(12345), number_format(17))],
            'recount going on' => ['update.recount', sprintf($literal('database/migrations/2026_10_26_100200_recount_video_likes_and_comments.php', "/'(1\\.2\\.3: likes, dislikes and comments counted again[^']*)'/"), EngagementCounters::videos(1), number_format(0))],
            'telegram' => ['update.telegram_links', $telegram::auditDetails(['Telegram channel', 'footer Support links (group + support)'])],
            'telegram, more' => ['update.telegram_links', $telegram::auditDetails(['Telegram channel', 'footer Support links', 'A long setting name one', 'A long setting name two', 'A long setting name three'])],
            'schema dump' => ['update.removed_files', $migration('2026_10_27_100000_remove_shipped_schema_dump.php')::AUDIT_DETAILS],
            'docker' => ['update.removed_files', $migration('2026_10_27_140000_remove_shipped_docker_files.php')::AUDIT_DETAILS],
            'docker, only the compose file' => ['update.removed_files', $migration('2026_10_27_140000_remove_shipped_docker_files.php')::details(['docker-compose.yml'], 0)],
            'docker, 1 changed' => ['update.removed_files', $migration('2026_10_27_140000_remove_shipped_docker_files.php')::details(['docker-compose.yml', 'docker/Dockerfile', 'docker/nginx.conf', 'docker/policy.json'], 1)],
            'docker, 2 changed' => ['update.removed_files', $migration('2026_10_27_140000_remove_shipped_docker_files.php')::details(['docker/Dockerfile'], 2)],
            'pictures' => ['update.removed_files', $migration('2026_10_29_100000_remove_unused_sign_in_and_error_pictures.php')::details(20, 0)],
            'pictures, 1 changed' => ['update.removed_files', $migration('2026_10_29_100000_remove_unused_sign_in_and_error_pictures.php')::details(19, 1)],
            'database backup of an update' => ['update.database_backup', 'backup_before_update_1.2.3_2026-10-28_195014.sql.gz (12.30 MB, mysqldump)'],
            'sign-up limit' => ['update.sign_up_limit_default', $literal('database/migrations/2026_10_27_100000_unset_bundled_sign_up_limit.php', "/'details' => '([^']*)'/")],
            'premium texts' => ['update.premium_page_texts', $premium::auditDetails(['premium_billing_trial_summary', 'premium_cancel_anytime_label', 'premium_annual_savings_text'])],
            // an explicit zone: a data provider runs before the application starts (no settings to read the site's zone from)
            'row times, views' => ['update.row_times', LegacyRowTimes::auditDetails('views', 1204, null, 'Asia/Bangkok')],
            'row times, 1 subscription' => ['update.row_times', LegacyRowTimes::auditDetails('subscriptions', 1, null, 'America/New_York')],
            'row times, playlists' => ['update.row_times', LegacyRowTimes::auditDetails('saved_playlists', 3, null, 'UTC')],
            'upload18 key' => ['update.upload18_key', $literal('app/Support/Upload18/BundledKey.php', "/'details' => '([^']*)'/")],
            'admin IP lock' => ['security.admin_ip_lock.off', sprintf('Turned off from the command line: %d %s removed.', 3, 'entries')],
            'update check' => ['update.check', 'available 1.2.4 (api)'],
            'update finished' => ['update.finished', '1.2.2 -> 1.2.3 (16 database changes)'],
            'update files' => ['update.files', '812 replaced, 40 added, 3 removed, 2 language files merged (zip engine ZipArchive, OPcache reset)'],
            'update texts' => ['update.texts', 'lang/de.json (Deutsch): 3 texts corrected to the wording of this version, your own wording kept for 2 texts this version corrects, 73 texts this version no longer uses removed'],
            'update texts, none of the owner' => ['update.texts', 'lang/de.json (Deutsch): 1 text corrected to the wording of this version'],
            'update texts, not saved' => ['update.texts', 'lang/de.json (Deutsch): not corrected (it could not be saved), your own wording kept for 1 text this version corrects'],
            'update texts, a row of RC1/RC2' => ['update.texts', 'lang/de.json (Deutsch): 3 texts corrected to the wording of this version, 2 own texts kept'],
            'task paused' => ['cron.task.pause', 'Paused scheduled task: views:prune-history'],
            'scheduler run' => ['cron.scheduler.run', 'Started schedule:run in the background.'],
            'duration reset' => ['cron.duration.reset', 'Reset duration retry tracking for 25 missing-duration AVDB videos.'],
            'job paused' => ['queue.job.pause', 'Paused job #12 for 60 seconds.'],
            'jobs deleted' => ['queue.jobs.delete_all', 'Deleted 4 deletable queued jobs on imports; kept 1 protected job.'],
            'failed retried' => ['queue.failed.retry', 'Retried failed job 3f2a9c1e-0000-4000-8000-000000000001.'],
            'member blocked' => ['member.blocked', 'alice (#12), with their videos and comments'],
            'settings saved' => ['settings.saved', 'site_name, date_format'],
        ];
    }

    #[DataProvider('storedDetails')]
    public function test_every_detail_the_code_writes_is_known(string $action, string $details): void
    {
        $this->assertArrayHasKey($action, AuditActions::LABELS);
        $this->assertTrue(AuditDetails::known($action, $details), "no template knows the {$action} details: {$details}");
        $this->assertNotSame('', AuditDetails::text($action, $details));
    }

    public function test_the_details_are_shown_in_the_admins_language_with_the_values_set_apart(): void
    {
        $this->arabicLines([
            ':version: Premium page texts set to the new wording: :texts' => ':version: نصوص صفحة بريميوم بالصياغة الجديدة: :texts',
            'billing line' => 'سطر الفوترة',
            'cancel line' => 'سطر الإلغاء',
            'savings note' => 'ملاحظة التوفير',
            'Task: :command' => 'المهمة: :command',
            ':member, with their videos and comments' => ':member، مع فيديوهاته وتعليقاته',
        ]);
        app()->setLocale('ar');
        // the Arabic template with its placeholder replaced by what the row shows there
        $shown = fn (string $key, string $placeholder, string $value): string => str_replace(':'.$placeholder, $value, e(__($key)));

        $premium = '1.2.3: Premium page texts set to the new wording: billing line, cancel line, savings note';
        // joined the way the admin's language joins a list (its own punctuation: R2CD-RV-04)
        $texts = AdminText::list([__('billing line'), __('cancel line'), __('savings note')]);
        $expected = __(':version: Premium page texts set to the new wording: :texts', ['version' => '1.2.3', 'texts' => $texts]);
        $this->assertStringNotContainsString('new wording', $expected, 'an Arabic line');
        $this->assertSame($expected, AuditDetails::text('update.premium_page_texts', $premium));
        // the version set apart, left to right
        $this->assertSame(str_replace(':texts', e($texts), $shown(':version: Premium page texts set to the new wording: :texts', 'version', '<bdi dir="ltr">1.2.3</bdi>')),
            (string) AuditDetails::html('update.premium_page_texts', $premium));
        $this->assertSame($shown('Task: :command', 'command', '<bdi dir="ltr">views:prune-history</bdi>'),
            (string) AuditDetails::html('cron.task.pause', 'Paused scheduled task: views:prune-history'));

        // a member's name or a video's title is set apart in its own direction, and escaped
        $this->assertSame($shown(':member, with their videos and comments', 'member', '<bdi>&lt;b&gt;x&lt;/b&gt; (#12)</bdi>'),
            (string) AuditDetails::html('member.blocked', '<b>x</b> (#12), with their videos and comments'));
        $this->assertSame('<bdi>Sunset &lt;drive&gt; (#45)</bdi>', (string) AuditDetails::html('video.banned', 'Sunset <drive> (#45)'));

        // details nobody wrote a template for: as stored, escaped
        $this->assertSame('finish: Disk &lt;full&gt;', (string) AuditDetails::html('update.failed', 'finish: Disk <full>'));
        $this->assertFalse(AuditDetails::known('update.failed', 'finish: Disk <full>'));
    }

    public function test_the_stale_rows_cleanup_counts_are_shown_in_the_admins_language(): void
    {
        $admin = User::factory()->admin()->create();
        config(['admin.operational_tools_enabled' => true, 'admin.ui' => 'v2']);

        // Tools > Background jobs > Clean up: the row stores the command's own lines (English, with the counts)
        $this->actingAs($admin)->post(route('admin.queue.cleanup-stale'), ['realtime_hours' => 1, 'queued_days' => 30])->assertRedirect();
        $stored = (string) DB::table('admin_audit_logs')->where('action', 'queue.cleanup.stale')->value('details');
        $this->assertStringContainsString('Deleted 0 stale realtime broadcast jobs older than 1 hour.', $stored);
        $this->assertTrue(AuditDetails::known('queue.cleanup.stale', $stored), 'every line of the command is known');
        $this->assertSame(7, substr_count(AuditDetails::text('queue.cleanup.stale', $stored), '; ') + 1, 'one part per line');

        $this->arabicLines([
            'Realtime broadcast jobs older than :age deleted: :count' => 'مهام البث الفوري الأقدم من :age المحذوفة: :count',
            '{1} :count hour|[2,*] :count hours' => ':count ساعة|ساعة واحدة|ساعتان|:count ساعات|:count ساعة|:count ساعة',
        ]);
        app()->setLocale('ar');
        $text = AuditDetails::text('queue.cleanup.stale', $stored);
        $this->assertStringContainsString('مهام البث الفوري الأقدم من ساعة واحدة المحذوفة: 0', $text);
        $this->assertStringNotContainsString('stale realtime broadcast', $text);

        // a line the template does not know (a later version of the command): the whole detail as stored
        $this->assertFalse(AuditDetails::known('queue.cleanup.stale', $stored."\nDeleted 3 something new."));
        $this->assertSame($stored."\nDeleted 3 something new.", AuditDetails::text('queue.cleanup.stale', $stored."\nDeleted 3 something new."));
    }

    /**
     * Lists inside a detail are joined with the punctuation of the admin's language, never with a Latin comma or
     * semicolon inside an Arabic or Chinese sentence (judge R2CD-RV-04: "عنوان الخادم, كلمة المرور", "...إجمالًا; خطة").
     * The punctuation is the language's own (AdminText::punctuation(), by script); English keeps its own.
     */
    public function test_lists_in_a_detail_take_the_punctuation_of_the_admins_language(): void
    {
        $settings = AuditDetails::names(['smtp_host', 'smtp_password', 'site_name', 'an_old_key', 'another_old_key']);
        $limits = '1.2.3: package upload limits set to the defaults: free 10 videos, 500 MB per file, 5 GB in all; premium 2 GB per file';
        $files = '812 replaced, 40 added, 3 removed, 2 language files merged (zip engine ZipArchive, OPcache reset)';
        $texts = 'lang/de.json (Deutsch): 3 texts corrected to the wording of this version, your own wording kept for 2 texts this version corrects, 73 texts this version no longer uses removed';
        $member = 'carol (#12): username, email, password';
        $cleanup = "Deleted 0 obsolete AVDB import queue stubs.\nDeleted 2 stale realtime broadcast jobs older than 1 hour.";

        // English: as before
        $this->assertStringContainsString(', ', AuditDetails::text('settings.saved', $settings));
        $this->assertStringContainsString('; ', AuditDetails::text('settings.saved', $settings));
        $this->assertSame('carol (#12): '.__('Username').', '.__('Email').', '.__('Password'), AuditDetails::text('member.updated', $member));

        // the lane's Arabic lines (lang/ar.json has them after the merge, and its own lines win over these)
        $this->arabicLines([
            ':version: the upload limits of the package were set to the defaults: :limits' => ':version: ضُبطت حدود الرفع التي جاءت مع الحزمة على القيم الافتراضية: :limits',
            ':size per file' => ':size لكل ملف',
            ':size in all' => ':size إجمالًا',
            'Files replaced: :replaced, added: :added, removed: :removed (zip engine :engine, OPcache :opcache)' => 'الملفات المستبدلة: :replaced، المضافة: :added، المحذوفة: :removed (محرك zip :engine، OPcache :opcache)',
            '1 language file merged with your own texts|:count language files merged with your own texts' => 'لم يُدمج أي ملف لغة مع نصوصك|دُمج ملف لغة واحد مع نصوصك|دُمج ملفا لغة مع نصوصك|دُمجت :count ملفات لغة مع نصوصك|دُمج :count ملف لغة مع نصوصك|دُمج :count ملف لغة مع نصوصك',
            '1 text corrected to the wording of this version|:count texts corrected to the wording of this version' => 'لم يُصحَّح أي نص|صُحِّح نص واحد إلى صياغة هذا الإصدار|صُحِّح نصان إلى صياغة هذا الإصدار|صُحِّحت :count نصوص إلى صياغة هذا الإصدار|صُحِّح :count نصًا إلى صياغة هذا الإصدار|صُحِّح :count نص إلى صياغة هذا الإصدار',
            'your own wording kept for 1 text this version corrects|your own wording kept for :count texts this version corrects' => 'لم تُحفظ صياغتك لأي نص|حُفظت صياغتك لنص واحد يصححه هذا الإصدار|حُفظت صياغتك لنصين يصححهما هذا الإصدار|حُفظت صياغتك لـ:count نصوص يصححها هذا الإصدار|حُفظت صياغتك لـ:count نصًا يصححها هذا الإصدار|حُفظت صياغتك لـ:count نص يصححه هذا الإصدار',
            '1 text this version no longer uses removed|:count texts this version no longer uses removed' => 'لم يُحذف أي نص|حُذف نص واحد لم يعد هذا الإصدار يستخدمه|حُذف نصان لم يعد هذا الإصدار يستخدمهما|حُذفت :count نصوص لم يعد هذا الإصدار يستخدمها|حُذف :count نصًا لم يعد هذا الإصدار يستخدمها|حُذف :count نص لم يعد هذا الإصدار يستخدمها',
            'Obsolete AVDB import queue entries deleted: :count' => 'إدخالات قائمة انتظار استيراد AVDB القديمة المحذوفة: :count',
            'Realtime broadcast jobs older than :age deleted: :count' => 'مهام البث الفوري الأقدم من :age المحذوفة: :count',
        ]);
        app()->setLocale('ar');

        foreach ([
            ['settings.saved', $settings], ['update.plan_limits_defaults', $limits], ['update.files', $files],
            ['update.texts', $texts], ['member.updated', $member], ['queue.cleanup.stale', $cleanup],
        ] as [$action, $details]) {
            $this->assertTrue(AuditDetails::known($action, $details), $action);
            // the punctuation between the translated parts (the values set apart keep theirs: a member's name, "#12")
            $shown = preg_replace('/<bdi[^>]*>.*?<\/bdi>/su', '<v>', (string) AuditDetails::html($action, $details));
            $this->assertDoesNotMatchRegularExpression('/[,;] /u', (string) $shown, "{$action}: a Latin comma or semicolon in Arabic: {$shown}");
        }

        $this->assertStringContainsString('، ', AuditDetails::text('settings.saved', $settings));
        $this->assertStringContainsString('؛ ', AuditDetails::text('settings.saved', $settings));
        $limitsHtml = (string) AuditDetails::html('update.plan_limits_defaults', $limits);
        $this->assertStringContainsString('، <bdi dir="ltr">500 MB</bdi> '.e(trim(str_replace(':size', '', __(':size per file')))), $limitsHtml);
        $this->assertStringContainsString('؛ '.e(__('Premium plan')).': <bdi dir="ltr">2 GB</bdi>', $limitsHtml);
        $this->assertSame('carol (#12): '.__('Username').'، '.__('Email').'، '.__('Password'), AuditDetails::text('member.updated', $member));
    }

    /** The punctuation follows the script of the admin's language, also of a language an owner adds (Persian). */
    public function test_the_punctuation_follows_the_script_of_the_language(): void
    {
        foreach ([
            'en' => ['a, b', 'a; b', 'x: y'],
            'vi' => ['a, b', 'a; b', 'x: y'],
            'ar' => ['a، b', 'a؛ b', 'x: y'],
            'fa' => ['a، b', 'a؛ b', 'x: y'],
            'zh' => ['a、b', 'a；b', 'x：y'],
            'zh_TW' => ['a、b', 'a；b', 'x：y'],
            'ja' => ['a、b', 'a。b', 'x: y'],
            'fr' => ['a, b', "a\u{00A0}; b", "x\u{00A0}: y"],
        ] as $locale => $expected) {
            app()->setLocale($locale);
            $this->assertSame($expected, [AdminText::list(['a', 'b']), AdminText::groups(['a', 'b']), AdminText::labelled('x', 'y')], $locale);
        }

        // a whole detail in Chinese: no Latin comma or semicolon between its translated parts
        app()->setLocale('zh');
        $shown = AuditDetails::text('settings.saved', AuditDetails::names(['smtp_host', 'smtp_password', 'site_name']));
        $this->assertStringContainsString('、', $shown);
        $this->assertStringContainsString('；', $shown);
        $this->assertStringNotContainsString(', ', $shown);
        $this->assertSame('', AdminText::list([]));
    }

    /**
     * The old-times lines of the update (App\Support\LegacyRowTimes::auditDetails(), lane CL's R2A-001) in every form
     * the code writes them: the rows put right with the zone they were put in, and a column whose old rows needed no
     * change (judge R2CD-RV-07). Once lane CL's code is merged, the samples are the method's own (it then takes the
     * number checked and the zone), so a later change of its wording fails here.
     */
    public function test_the_old_times_lines_of_the_update_are_known_in_every_form(): void
    {
        $merged = (new \ReflectionMethod(LegacyRowTimes::class, 'auditDetails'))->getNumberOfParameters() >= 4;
        $samples = $merged
            ? [
                'put right' => LegacyRowTimes::auditDetails('views', 1204, 1300, 'Asia/Bangkok'),
                'one put right' => LegacyRowTimes::auditDetails('subscriptions', 1, 1, 'America/New_York'),
                'none needed' => LegacyRowTimes::auditDetails('views', 0, 1300, 'UTC'),
                'none needed, one' => LegacyRowTimes::auditDetails('subscriptions', 0, 1, 'UTC'),
                'none needed, playlists' => LegacyRowTimes::auditDetails('saved_playlists', 0, 3, 'Europe/Paris'),
            ]
            : [
                // what lane CL's LegacyRowTimes::auditDetails() writes (28 Oct)
                'put right' => '1.2.3: 1,204 views in History recorded before the update now show their times in the site time zone (Asia/Bangkok)',
                'one put right' => '1.2.3: 1 subscription recorded before the update now shows its time in the site time zone (America/New_York)',
                'none needed' => '1.2.3: the 1,300 views in History recorded before the update already showed their times in the site time zone (UTC), nothing was changed',
                'none needed, one' => '1.2.3: the 1 subscription recorded before the update already showed its time in the site time zone (UTC), nothing was changed',
                'none needed, playlists' => '1.2.3: the 3 saved playlists recorded before the update already showed their times in the site time zone (Europe/Paris), nothing was changed',
            ];

        $key = ':version: the 1 view in History recorded before the update already showed its time in the site time zone (:zone), nothing was changed|:version: the :count views in History recorded before the update already showed their times in the site time zone (:zone), nothing was changed';
        $this->arabicLines([
            $key => ':version: لا مشاهدات|:version: المشاهدة الوحيدة كانت تُظهر وقتها بالمنطقة الزمنية للموقع (:zone)، فلم يتغير شيء|:version: المشاهدتان كانتا تُظهران وقتيهما بالمنطقة الزمنية للموقع (:zone)، فلم يتغير شيء|:version: الـ :count مشاهدات كانت تُظهر أوقاتها بالمنطقة الزمنية للموقع (:zone)، فلم يتغير شيء|:version: الـ :count مشاهدة كانت تُظهر أوقاتها بالمنطقة الزمنية للموقع (:zone)، فلم يتغير شيء|:version: الـ :count مشاهدة كانت تُظهر أوقاتها بالمنطقة الزمنية للموقع (:zone)، فلم يتغير شيء',
        ]);

        foreach ($samples as $name => $details) {
            $this->assertTrue(AuditDetails::known('update.row_times', $details), "{$name}: no template knows \"{$details}\"");
        }

        app()->setLocale('ar');
        $html = (string) AuditDetails::html('update.row_times', $samples['none needed']);
        $this->assertStringContainsString('<bdi dir="ltr">UTC</bdi>', $html, 'the zone set apart, left to right');
        $this->assertStringContainsString('<bdi dir="ltr">1.2.3</bdi>', $html);
        $this->assertStringNotContainsString('already showed', $html, 'shown in Arabic');
    }

    public function test_settings_are_named_by_their_card_and_label(): void
    {
        $text = AuditDetails::text('settings.saved', AuditDetails::names(['site_name', 'smtp_password', 'an_old_key']));

        $this->assertStringContainsString(__((string) config('admin_settings.settings.site_name.label')), $text);
        $this->assertStringContainsString(__((string) config('admin_settings.settings.smtp_password.label')), $text);
        $this->assertStringContainsString('an_old_key', $text, 'a key the catalogue does not know stays as it is');
        $this->assertStringNotContainsString('site_name', $text);
    }

    public function test_the_log_and_the_activity_cards_show_labels_and_translated_details_and_keep_the_english(): void
    {
        $admin = User::factory()->admin()->create(['language' => 'ar']);
        $rows = [
            ['update.premium_page_texts', '1.2.3: Premium page texts set to the new wording: billing line, cancel line, savings note'],
            ['cron.task.pause', 'Paused scheduled task: views:prune-history'],
            ['queue.job.retry', 'Reset queued job #12.'],
        ];

        foreach ($rows as [$action, $details]) {
            DB::table('admin_audit_logs')->insert(['user_id' => $admin->id, 'action' => $action, 'details' => $details, 'created_at' => now()]);
        }

        $this->arabicLines([
            ':version: Premium page texts set to the new wording: :texts' => ':version: نصوص صفحة بريميوم بالصياغة الجديدة: :texts',
            'Task: :command' => 'المهمة: :command',
            'Job :job starts over.' => 'تبدأ المهمة :job من جديد.',
        ]);
        config(['admin.operational_tools_enabled' => true, 'admin.ui' => 'v2']);

        $log = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get('/admin/audit')->assertOk()->getContent();
        $premium = explode(':texts', __(':version: Premium page texts set to the new wording: :texts', [], 'ar'))[0];
        $this->assertStringContainsString(e(trim(str_replace(':version:', '', $premium))), $log);
        $this->assertStringNotContainsString('set to the new wording', $log);

        $cron = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get('/admin/cron')->assertOk()->getContent();
        $this->assertStringContainsString('title="cron.task.pause"', $cron);
        $this->assertStringContainsString('>'.e(__(AuditActions::LABELS['cron.task.pause'])).'</b>', $cron, 'the label, not the key');
        $this->assertStringNotContainsString('<code>cron.task.pause</code>', $cron);
        $this->assertStringContainsString(str_replace(':command', '<bdi dir="ltr">views:prune-history</bdi>', e(__('Task: :command', [], 'ar'))), $cron);
        $this->assertStringNotContainsString('Task: <bdi', $cron);

        $queue = (string) $this->actingAs($admin)->withSession(['app_locale' => 'ar'])->get('/admin/queue')->assertOk()->getContent();
        $this->assertStringContainsString('title="queue.job.retry"', $queue);
        $this->assertStringNotContainsString('<code>queue.job.retry</code>', $queue);
        $this->assertStringContainsString(str_replace(':job', '<bdi dir="ltr">#12</bdi>', e(__('Job :job starts over.', [], 'ar'))), $queue);
        $this->assertStringNotContainsString('starts over', $queue);

        // stored as written
        $this->assertSame(array_column($rows, 1), DB::table('admin_audit_logs')->orderBy('id')->pluck('details')->all());
    }
}
