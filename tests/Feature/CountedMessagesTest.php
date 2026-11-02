<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\BulkActionController;
use App\Jobs\RunAvdbImport;
use App\Models\AvdbImportSchedule;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminPreferences;
use App\Support\VideoBans;
use Avd\I18n\Placeholders;
use Avd\I18n\Plurals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AVDBCMS 1.2.3: counted messages read right in every language. The bulk bars said "Made 1 users
 * admin." and "Removed admin from 1 users." in English whatever the admin's language; the other counted answers of the
 * admin said "1 videos", "1 files", "job(s)", and the old admin's pages printed "1 subscribers", "1 folders, 1 files",
 * "1 lines" or "5 {0} :count results|{1} ..." (a plural line shown whole). They are trans_choice() lines now ("one|other"
 * in English, the forms of each language in lang/<locale>.json), and a sentence with two numbers became a label
 * ("Videos: 5. Categories: 3.").
 *
 * The language test checks every counted line of the admin in every language file that holds it (the release's
 * translation step adds them; scripts/i18n_check.php is the gate for the rest). The guard tests scan every page,
 * component, page script and controller of the admin for a number printed next to a fixed plural.
 */
class CountedMessagesTest extends TestCase
{
    use RefreshDatabase;

    /** The plural nouns a count can stand next to in the admin's texts. */
    private const NOUNS = 'videos|files|folders|pages|members|users|comments|rows|results|lines|entries|records|tags|categories|days|hours'
        .'|minutes|seconds|errors|changes|keys|texts|strings|jobs|schedules|ids|batches|candidates|locales|languages|images|thumbnails'
        .'|playlists|reports|messages|notifications|devices|sessions|bytes|subscribers|followers|views|likes|dislikes|columns|channels'
        .'|admins|administrators|accounts|plans|payments|invoices|transactions|themes|looks|steps|tables|words|translations|links|ads'
        .'|slots|attempts|issues|warnings|items|studios|designs|families|deltas|colors|colours|imports|runs|backups|searches|blocks'
        .'|missing'; // not a noun, but its translations agree with the number too ("1 отсутствует", "5 отсутствуют")

    /**
     * Texts whose numbers are fixed by the product, always more than one: the Appearance › Themes help line names the
     * catalogue (32 themes in 13 families, 174 colour styles). Its translations read right for any number (labels where a
     * language needs them). Community › Notifications prints its count cap only once the count passes it
     * (NotificationController::$countCap, 100,000), and its tidy-up line names NotificationRetention's constants (180 days,
     * 1,000 kept, 30 days); their translations are written for those numbers.
     */
    private const FIXED_NUMBERS = [
        ':themes designs in :families families, :flavors colour styles. Each theme has its own layout, fonts, shapes and a light and dark version.',
        'More than :count notifications were sent.',
        'Old notifications are tidied up by themselves: read ones after :days days, and each member keeps their newest :keep. Unread ones from the last :unread days always stay.',
    ];

    /**
     * Lines of 1.2.2 that another change of 1.2.3 replaces, tolerated only as long as they are
     * exactly these lines: both broadcast answers were rewritten (NotificationController::sentMessage(), trans_choice), and
     * the old settings page got its counted texts
     * with its own test, OldSettingsPageTextsTest.
     */
    public const REPLACED_ELSEWHERE = [
        'app/Http/Controllers/Admin/NotificationController.php' => ['->withSuccess("Notification broadcast to {$count} users.");'],
        'app/Http/Controllers/Admin/BroadcastController.php' => ['->withSuccess("Notification sent to {$count} users.");'],
        'resources/views/admin/settings/index.blade.php' => [
            '<span class="badge bg-secondary-subtle text-body-secondary rounded-pill small">{{ count($masterExperiences) }} {{ __(\'Studios\') }}</span>',
            '<option value="{{ $i }}" @selected((int)$value(\'appearance\', \'grid_columns\', 4) === $i)>{{ $i }} {{__(\'columns\')}}</option>',
            '<option value="{{ $key }}" @selected((int)$value(\'appearance\', \'mobile_video_columns\', $appearanceDefaults[\'mobile_video_columns\']) === $key)>{{ $display($label, $key . \' columns\') }}</option>',
            'alertToast(`🔍 Found ${diffs.length} color delta(s) vs "${presetName}":\n` + diffs.slice(0, 3).join(\' | \') + (diffs.length > 3 ? \'...\' : \'\'), \'info\');',
            '<div class="fw-black">{{ $systemInfo[\'total_plans\'] ?? 0 }} {{__(\'total\')}} / {{ $systemInfo[\'plans_missing_stripe\'] ?? 0 }} {{__(\'missing Stripe ID\')}}</div>',
            '<div class="fw-black">{{ $systemInfo[\'total_plans\'] ?? 0 }} {{__(\'total\')}} / {{ $systemInfo[\'plans_missing_paypal\'] ?? 0 }} {{__(\'missing PayPal ID\')}}</div>',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['admin.ui' => 'v2', 'admin.operational_tools_enabled' => true]);
        require_once base_path('scripts/i18n/I18n.php');
    }

    // ------------------------------------------------------------------ the members' bulk bar

    public function test_making_members_administrators_counts_right(): void
    {
        $owner = User::factory()->admin()->create();
        [$one, $two, $three] = User::factory()->count(3)->create();

        $this->actingAs($owner)->post(route('admin.bulk.users'), ['action' => 'make_admin', 'ids' => [$one->id]])
            ->assertRedirect()->assertSessionHas('success', 'Made 1 member an administrator.');
        $this->actingAs($owner)->post(route('admin.bulk.users'), ['action' => 'make_admin', 'ids' => [$two->id, $three->id]])
            ->assertSessionHas('success', 'Made 2 members administrators.');

        $this->actingAs($owner)->post(route('admin.bulk.users'), ['action' => 'remove_admin', 'ids' => [$one->id]])
            ->assertSessionHas('success', '1 member is no longer an administrator.');
        $this->actingAs($owner)->post(route('admin.bulk.users'), ['action' => 'remove_admin', 'ids' => [$two->id, $three->id]])
            ->assertSessionHas('success', '2 members are no longer administrators.');

        $this->assertFalse((bool) $one->fresh()->is_admin);
    }

    public function test_the_admins_language_and_its_plural_rule_are_used(): void
    {
        $owner = User::factory()->admin()->create();
        $members = User::factory()->count(5)->create();
        app()->setLocale('ru');
        $line = trans_choice('Made 1 member an administrator.|Made :count members administrators.', 5);
        $translated = (array) json_decode((string) File::get(lang_path('ru.json')), true);

        $this->actingAs($owner)->post(route('admin.bulk.users'), ['action' => 'make_admin', 'ids' => $members->pluck('id')->all()])
            ->assertSessionHas('success', $line);

        if (array_key_exists('Made 1 member an administrator.|Made :count members administrators.', $translated)) {
            // the Russian form for 5 ("участников"), not the English line and not the form for 1
            $this->assertStringContainsString('5', $line);
            $this->assertStringNotContainsString('members', $line);
            $this->assertNotSame(trans_choice('Made 1 member an administrator.|Made :count members administrators.', 1), str_replace('5', '1', $line));
        }
    }

    // ------------------------------------------------------------------ every bulk bar, one and two

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> route, action, the line for 1, for 2 */
    public static function bulkLines(): array
    {
        return [
            'videos delete' => ['videos', 'delete', 'Deleted 1 video permanently.', 'Deleted 2 videos permanently.'],
            'videos ban' => ['videos', 'ban', 'Banned 1 video.', 'Banned 2 videos.'],
            'videos unban' => ['videos', 'unban', 'Unbanned 1 video.', 'Unbanned 2 videos.'],
            'videos public' => ['videos', 'make_public', 'Made 1 video public.', 'Made 2 videos public.'],
            'videos private' => ['videos', 'make_private', 'Made 1 video private.', 'Made 2 videos private.'],
            'videos unlisted' => ['videos', 'make_unlisted', 'Made 1 video unlisted.', 'Made 2 videos unlisted.'],
            'videos durations' => ['videos', 'resync_duration', '1 video gets its duration checked again.', '2 videos get their durations checked again.'],
            'members delete' => ['users', 'delete', 'Deleted 1 member permanently.', 'Deleted 2 members permanently.'],
            'members block' => ['users', 'ban', 'Blocked 1 member.', 'Blocked 2 members.'],
            'members unblock' => ['users', 'unban', 'Unblocked 1 member.', 'Unblocked 2 members.'],
            'members confirm' => ['users', 'verify', 'Marked 1 email as confirmed.', 'Marked 2 emails as confirmed.'],
            'comments delete' => ['comments', 'delete', 'Deleted 1 comment permanently.', 'Deleted 2 comments permanently.'],
            'comments hide' => ['comments', 'ban', 'Hid 1 comment.', 'Hid 2 comments.'],
            'comments show' => ['comments', 'unban', '1 comment shows again.', '2 comments show again.'],
            'playlists delete' => ['playlists', 'delete', 'Deleted 1 playlist permanently.', 'Deleted 2 playlists permanently.'],
            'notifications delete' => ['notifications', 'delete', 'Deleted 1 notification permanently.', 'Deleted 2 notifications permanently.'],
            'notifications read' => ['notifications', 'mark_read', 'Marked 1 notification as read.', 'Marked 2 notifications as read.'],
            'log delete' => ['activities', 'delete', 'Deleted 1 entry from the log.', 'Deleted 2 entries from the log.'],
            'likes delete' => ['interactions', 'delete', 'Deleted 1 like or dislike.', 'Deleted 2 likes and dislikes.'],
            'devices sign out' => ['devices', 'delete', 'Signed out 1 device.', 'Signed out 2 devices.'],
            'failed jobs delete' => ['failed-jobs', 'delete', 'Deleted 1 failed job permanently.', 'Deleted 2 failed jobs permanently.'],
            'messages delete' => ['contacts', 'delete', 'Deleted 1 message permanently.', 'Deleted 2 messages permanently.'],
            'messages read' => ['contacts', 'mark_read', 'Marked 1 message as read.', 'Marked 2 messages as read.'],
            'reports delete' => ['reports', 'delete', 'Deleted 1 report permanently.', 'Deleted 2 reports permanently.'],
            'reports resolve' => ['reports', 'resolve', 'Resolved 1 report.', 'Resolved 2 reports.'],
        ];
    }

    #[DataProvider('bulkLines')]
    public function test_every_bulk_bar_counts_right_for_one_and_two(string $list, string $action, string $one, string $two): void
    {
        $owner = User::factory()->admin()->create();

        foreach ([1 => $one, 2 => $two] as $count => $expected) {
            $ids = $this->records($list, $action, $count);
            $this->actingAs($owner)->post(route('admin.bulk.'.$list), ['action' => $action, 'ids' => $ids])
                ->assertRedirect()
                ->assertSessionHas('success', $expected);
        }
    }

    public function test_moving_videos_names_the_category_and_a_wrong_one_is_an_error(): void
    {
        $owner = User::factory()->admin()->create();
        $category = Category::query()->create(['title' => 'Travel', 'slug' => 'travel', 'icon' => 'film', 'in_menu' => true]);
        $videos = $this->videos(3);

        $this->actingAs($owner)->post(route('admin.bulk.videos'), ['action' => 'change_category', 'category_id' => $category->id, 'ids' => [$videos[0]]])
            ->assertSessionHas('success', 'Moved 1 video to the category "Travel".');
        // two that are not in it yet (MySQL / MariaDB count only the rows an update changes)
        $videos = array_slice($videos, 1);
        $this->actingAs($owner)->post(route('admin.bulk.videos'), ['action' => 'change_category', 'category_id' => $category->id, 'ids' => $videos])
            ->assertSessionHas('success', 'Moved 2 videos to the category "Travel".');

        // 1.2.2 showed "Invalid category selected." as a success toast
        $this->actingAs($owner)->post(route('admin.bulk.videos'), ['action' => 'change_category', 'category_id' => 999999, 'ids' => $videos])
            ->assertSessionMissing('success')
            ->assertSessionHasErrors(['category_id' => 'Pick a category from the list.']);
    }

    public function test_check_durations_again_starts_the_retry_tracking_over_instead_of_a_server_error(): void
    {
        // 1.2.2 wrote avdb_duration_sync_status, a column no version has: the bulk button ended in a server error
        $owner = User::factory()->admin()->create();
        $due = $this->durationVideos(2);
        $tried = now()->subHour()->startOfSecond();
        DB::table('videos')->whereIn('id', $due)->update(['avdb_duration_sync_attempted_at' => $tried, 'avdb_duration_sync_failed_count' => 4]);

        $this->actingAs($owner)->post(route('admin.bulk.videos'), ['action' => 'resync_duration', 'ids' => $due])
            ->assertRedirect()
            ->assertSessionHas('success', '2 videos get their durations checked again.');

        foreach (DB::table('videos')->whereIn('id', $due)->get(['avdb_duration_sync_attempted_at', 'avdb_duration_sync_failed_count']) as $row) {
            $this->assertNull($row->avdb_duration_sync_attempted_at);
            $this->assertSame(0, (int) $row->avdb_duration_sync_failed_count);
        }
    }

    public function test_check_durations_again_counts_only_the_videos_the_duration_sync_checks(): void
    {
        // The sync (avdb:sync-durations) only ever takes AVDB videos without a duration: a video with a duration, or one
        // that did not come from AVDB, is never checked, so it is neither counted nor touched.
        $owner = User::factory()->admin()->create();
        $due = $this->durationVideos(1);
        $hasDuration = $this->durationVideos(1, ['duration' => 754]);
        $uploaded = $this->videos(1); // a member's upload: no avdb_id (the factory gives it a duration)
        $noAvdbNoDuration = $this->durationVideos(1, ['avdb_id' => null]);
        $tried = now()->subHour()->startOfSecond();
        $others = array_merge($hasDuration, $uploaded, $noAvdbNoDuration);
        DB::table('videos')->whereIn('id', array_merge($due, $others))->update(['avdb_duration_sync_attempted_at' => $tried, 'avdb_duration_sync_failed_count' => 3]);

        $this->actingAs($owner)->post(route('admin.bulk.videos'), ['action' => 'resync_duration', 'ids' => array_merge($due, $others)])
            ->assertRedirect()
            ->assertSessionHas('success', '1 video gets its duration checked again. 3 other chosen videos need no check: they already have a duration or are not AVDB videos.');

        $this->assertNull(DB::table('videos')->where('id', $due[0])->value('avdb_duration_sync_attempted_at'));
        foreach (DB::table('videos')->whereIn('id', $others)->get(['avdb_duration_sync_attempted_at', 'avdb_duration_sync_failed_count']) as $row) {
            $this->assertNotNull($row->avdb_duration_sync_attempted_at, 'a video the sync never checks keeps its tracking');
            $this->assertSame(3, (int) $row->avdb_duration_sync_failed_count);
        }

        // one other video
        $this->actingAs($owner)->post(route('admin.bulk.videos'), ['action' => 'resync_duration', 'ids' => array_merge($due, $hasDuration)])
            ->assertSessionHas('success', '1 video gets its duration checked again. 1 other chosen video needs no check: it already has a duration or is not an AVDB video.');

        // nothing the sync would check
        $this->actingAs($owner)->post(route('admin.bulk.videos'), ['action' => 'resync_duration', 'ids' => $others])
            ->assertSessionHas('success', 'Nothing to check: the chosen videos already have a duration or are not AVDB videos.');
        $this->assertSame(3, (int) DB::table('videos')->where('id', $hasDuration[0])->value('avdb_duration_sync_failed_count'));
    }

    // ------------------------------------------------------------------ the other counted answers of the admin

    public function test_the_other_counted_answers_say_one_and_many_right(): void
    {
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner);

        // Members > Email confirmation: "Mark all as confirmed"
        User::factory()->unverified()->create();
        $this->post(route('admin.verification.verify-all'))->assertSessionHas('success', 'Marked 1 email as confirmed.');

        // Content > Tags: "Delete the tags no video uses"
        DB::table('tags')->insert([['name' => 'unused-a', 'slug' => 'unused-a', 'usage_count' => 0, 'created_at' => now(), 'updated_at' => now()]]);
        $this->post(route('admin.tags.delete-unused'))->assertSessionHas('success', 'Deleted 1 tag no video uses.');
        DB::table('tags')->insert([
            ['name' => 'unused-b', 'slug' => 'unused-b', 'usage_count' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'unused-c', 'slug' => 'unused-c', 'usage_count' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->post(route('admin.tags.delete-unused'))->assertSessionHas('success', 'Deleted 2 tags no video uses.');

        // Members > IP blocks: "Clear the ended blocks"
        DB::table('ip_bans')->insert(['ip_address' => '203.0.113.7', 'reason' => 'test', 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->post(route('admin.ip-bans.clear-expired'))->assertSessionHas('success', 'Cleared 1 ended block.');
    }

    public function test_the_member_studio_counts_right_too(): void
    {
        $member = User::factory()->create();
        $video = $this->videos(1, $member)[0];
        $comments = collect(range(1, 3))->map(fn () => Comment::query()->create(['content' => 'Nice video', 'user_id' => $member->id, 'video_id' => $video]));
        $this->actingAs($member);

        $this->delete(route('user.comments.mass-delete'), ['ids' => [$comments[0]->id]])->assertSessionHas('success', 'Deleted 1 comment.');
        $this->delete(route('user.comments.mass-delete'), ['ids' => [$comments[1]->id, $comments[2]->id]])->assertSessionHas('success', 'Deleted 2 comments.');
    }

    // ------------------------------------------------------------------ Import videos › AVDBAPI and the old Theme Studio

    public function test_the_avdb_cron_schedules_answer_counts_and_says_what_each_one_did(): void
    {
        // 1.2.2 showed the command's English lines ("Checked 1 AVDB import schedule(s).") in every language
        $owner = User::factory()->admin()->create();
        $this->actingAs($owner);
        Queue::fake();
        $mode = $_ENV['AVDB_IMPORT_MODE'] ?? null;
        $_ENV['AVDB_IMPORT_MODE'] = 'queue'; // a started run only goes to the (fake) queue: nothing is imported here

        try {
            $this->post(route('admin.avdb-import.schedules.run-due'))->assertSessionHas('success', 'No AVDB cron schedule was due.');

            $first = $this->dueSchedule('First');
            $response = $this->post(route('admin.avdb-import.schedules.run-now', $first));
            $run = $first->fresh()->last_run_id;
            $this->assertNotNull($run);
            $response->assertSessionHas('success', "Checked 1 AVDB cron schedule. Schedule #{$first->id} started import run #{$run}.");
            Queue::assertPushed(RunAvdbImport::class);

            // that run is still queued, so two due schedules both wait for it (the command moved the first one's next run on)
            AvdbImportSchedule::query()->whereKey($first->id)->update(['next_run_at' => now()->subMinutes(5)]);
            $second = $this->dueSchedule('Second');
            $this->post(route('admin.avdb-import.schedules.run-due'))->assertSessionHas('success',
                "Checked 2 AVDB cron schedules. Schedule #{$first->id} was skipped: another AVDB import is still running."
                ." Schedule #{$second->id} was skipped: another AVDB import is still running.");

            // a paused schedule still answers as before
            AvdbImportSchedule::query()->whereKey($second->id)->update(['enabled' => false]);
            $this->post(route('admin.avdb-import.schedules.run-now', $second))->assertSessionHasErrors();
        } finally {
            if ($mode === null) {
                unset($_ENV['AVDB_IMPORT_MODE']);
            } else {
                $_ENV['AVDB_IMPORT_MODE'] = $mode;
            }
        }
    }

    public function test_a_duration_batch_that_ran_answers_with_its_counts_only(): void
    {
        // 1.2.2 added the command's English lines ("123: Title => 1:02:03", "Updated 1 video(s).") to the answer
        $owner = User::factory()->admin()->create();
        $video = Video::factory()->for(User::factory()->create(), 'user')->create(['avdb_id' => 79643, 'duration' => null]);
        Http::fake(fn ($request) => str_contains((string) $request->url(), 'avdbapi.com')
            ? Http::response(['list' => [['id' => 79643, 'name' => 'Lane A duration', 'time' => '01:02:03', 'episodes' => ['server_data' => []]]]], 200)
            : Http::response('', 404));

        $message = (string) $this->actingAs($owner)
            ->postJson(route('admin.cron.duration.runner.batch'), ['limit' => 1, 'chunk' => 1])
            ->assertOk()
            ->json('message');

        $this->assertSame(3723, (int) $video->fresh()->getRawOriginal('duration'));
        $this->assertMatchesRegularExpression('/^Ran one duration batch for up to 1 video in [\d.]+ s\. Missing before: 1\. Missing now: 0\. Found now: 1\.$/', $message);
    }

    public function test_the_page_scripts_build_their_counted_texts_from_translated_labels(): void
    {
        Http::fake();
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT; // as @json writes them
        $lines = [
            'Connected. Videos available: :count.',
            'Direct ID mode. AVDB IDs: :count, fetched up to 100 at a time. The category and page settings are not used.',
            'Categories: :categories. Pages per category: :pages. API pages in all: :total. Videos to scan: up to :videos.',
            'Starting the import. Categories: :categories. Start page: :from. Pages per category: :pages. API pages in all: :total.',
        ];

        foreach (['v2', 'v1'] as $ui) {
            config(['admin.ui' => $ui]);
            $admin = User::factory()->admin()->create();
            AdminPreferences::set($admin, 'developer_mode', true);
            $page = (string) $this->actingAs($admin)->get(route('admin.avdb-import.index'))->assertOk()->getContent();

            foreach ($lines as $line) {
                $this->assertStringContainsString((string) json_encode($line, $flags), $page, "{$ui}: {$line}");
            }
            $this->assertStringNotContainsString('page(s)', $page);
            $this->assertStringNotContainsString('ID(s)', $page);
        }
    }

    public function test_no_page_or_script_of_the_admin_prints_a_count_with_a_fixed_plural(): void
    {
        // "${count} page(s)", "categor${n === 1 ? 'y' : 'ies'}", "{{ $n }} subscribers", "{{ $i }} {{ __('columns') }}",
        // __(':count videos'): every page, component and script of the admin, both layouts (the update pages belong to the
        // updater's own tests)
        $files = [];
        foreach ([resource_path('views/admin'), resource_path('views/components/admin'), resource_path('admin')] as $top) {
            foreach (File::allFiles($top) as $file) {
                $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
                if (! str_contains($path, '/updates/') && preg_match('/\.(php|js|ts)$/', $path) === 1) {
                    $files[] = $path;
                }
            }
        }
        $this->assertGreaterThan(200, count($files));

        $offenders = [];
        foreach ($files as $path) {
            $offenders = array_merge($offenders, self::fixedPluralsIn(str_replace(str_replace(DIRECTORY_SEPARATOR, '/', base_path()).'/', '', $path), (string) File::get($path)));
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_guard_finds_each_kind_of_fixed_plural(): void
    {
        // what the scan above must catch (1.2.2's own lines), and what it must let pass
        $caught = [
            'alertToast(`Found ${diffs.length} color delta(s)`);',
            "const label = `\${n} categor\${n === 1 ? 'y' : 'ies'}`;",
            '<div class="badge bg-primary">{{$user->subscribers_count}} subscribers</div>',
            '<small>{{ $totalDirs }} folders, {{ $totalFiles }} files</small>',
            "{{ number_format(\$search->max_results) }} {{__('results')}}",
            "<option>{{ \$i }} {{__('columns')}}</option>",
            "{{ \$display(\$label, \$key . ' columns') }}",
            "{{ __(':count videos / page', ['count' => \$n]) }}",
            "{{ __(':done of :total strings translated, :missing missing.') }}",
            "text.textContent = `\${total} items`;",
            // a translated line next to a trans_choice() on the same line (Home's description in 1.2.2)
            ":description=\"trim(__(':site had :views views this week.', ['site' => \$siteName]).' '.trans_choice('1 thing needs you.|:count things need you.', \$n))\"",
            "{{ number_format(\$durationBackfill['missing']) }} {{__('missing')}}",
        ];
        foreach ($caught as $line) {
            $this->assertNotSame([], self::fixedPluralsIn('x.blade.php', $line), $line);
        }

        $passed = [
            "{{ trans_choice('1 line|:count lines', \$n, ['count' => number_format(\$n)]) }}",
            "{{ __('Translated: :done of :total. Missing: :missing.', ['done' => 1]) }}",
            "{{ AdminTheme::icon('chat') }}{{ __('Comments') }}",
            '<i class="fa-solid {{ $s[2] }} text-{{ $s[3] }}"></i>',
            "label.textContent = plural(t('changes', '1 unsaved change|:count unsaved changes'), count);",
        ];
        foreach ($passed as $line) {
            $this->assertSame([], self::fixedPluralsIn('x.blade.php', $line), $line);
        }
    }

    /**
     * The lines of $content (a page, component or script of the admin) that print a count next to a fixed plural. Lines another
     * change replaces (REPLACED_ELSEWHERE) and the catalogue's fixed numbers (FIXED_NUMBERS) are let through.
     *
     * @return list<string> "path:line text"
     */
    public static function fixedPluralsIn(string $path, string $content): array
    {
        $nouns = self::NOUNS;
        $patterns = [
            // "page(s)", "y : ies", "'' : 's'"
            '/[A-Za-z]\((?:s|es)\)|\?\s*[\'"]y[\'"]\s*:\s*[\'"]ies[\'"]|\?\s*[\'"][\'"]\s*:\s*[\'"]s[\'"]|\?\s*[\'"]s[\'"]\s*:\s*[\'"][\'"]/',
            // a number printed by Blade, then a plural noun (plain or translated on its own)
            '/\{\{\s*(?:number_format\s*\(|count\s*\(|\$)[^}]*\}\}\s*(?:\{\{\s*__\(\s*[\'"])?(?:'.$nouns.')\b/i',
            // a script: ${n} items, n + ' items', $n . ' items'
            '/\$\{[^}]+\}\s?(?:'.$nouns.')\b/i',
            '/(?:\+|\.)\s*[\'"]\s(?:'.$nouns.')\b/i',
        ];
        // a translated line (not a trans_choice line: no "|") with a number placeholder right before a plural noun
        $placeholder = '/(?<![\w>])(?:__|t|text|@lang)\(\s*([\'"])((?:(?!\1).)*?:[a-z_]+\s+(?:[a-z]+\s+)?(?:'.$nouns.')\b(?:(?!\1).)*)\1/i';

        $allowed = self::REPLACED_ELSEWHERE[$path] ?? [];
        $offenders = [];

        foreach (preg_split('/\R/', $content) ?: [] as $number => $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || in_array($trimmed, $allowed, true)) {
                continue;
            }

            // the patterns for printed numbers skip a line that already counts with trans_choice(); the check of the
            // translated lines below never does (one line can hold both)
            $hit = false;
            foreach (str_contains($line, 'trans_choice(') ? [] : $patterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit && preg_match_all($placeholder, $line, $matches) > 0) {
                foreach ($matches[2] as $key) {
                    if (! str_contains($key, '|') && ! in_array($key, self::FIXED_NUMBERS, true)) {
                        $hit = true;
                    }
                }
            }

            if ($hit) {
                $offenders[] = $path.':'.($number + 1).' '.$trimmed;
            }
        }

        return $offenders;
    }

    // ------------------------------------------------------------------ every language

    public function test_every_counted_line_prints_the_number_in_every_language_that_has_it(): void
    {
        $lines = $this->countedLines();
        $this->assertGreaterThanOrEqual(60, count($lines), 'the counted lines of the changed files');

        $checked = 0;
        $problems = [];

        foreach (glob(lang_path('*.json')) ?: [] as $file) {
            $locale = basename($file, '.json');
            $data = (array) json_decode((string) File::get($file), true);

            foreach ($lines as $english) {
                if (! is_string($data[$english] ?? null) || $locale === 'en') {
                    continue;
                }

                $value = $data[$english];
                $checked++;

                if (! Placeholders::same($english, $value)) {
                    $problems[] = "{$locale}: placeholders of {$english}";
                }

                if (str_contains($english, '|')) {
                    if (($wrong = Plurals::wrongNumbers($english, $value, $locale, base_path())) !== []) {
                        $problems[] = "{$locale}: no number for ".implode(',', array_slice((array) $wrong, 0, 5))." in {$english}";
                    }

                    if (Plurals::lostOne($english, $value, $locale, base_path())) {
                        $problems[] = "{$locale}: the form for 1 says no number in {$english}";
                    }
                }
            }
        }

        $this->assertSame([], $problems);
        // the English line itself: one and other
        foreach ($lines as $english) {
            if (str_contains($english, '|') && str_contains($english, ':count')) {
                $this->assertStringNotContainsString('(s)', $english);
            }
        }
        $this->addToAssertionCount($checked);
    }

    public function test_no_counted_answer_of_the_admin_is_built_from_a_number_and_a_fixed_plural(): void
    {
        // "{$count} users", '. $count . ' videos', __(':count videos') without a form for 1, "job(s)"
        $offenders = [];
        $files = array_merge(
            glob(app_path('Http/Controllers/Admin/*.php')) ?: [],
            glob(app_path('Http/Controllers/User/*.php')) ?: [],
            [app_path('Support/AvdbDurationBackfill.php')],
        );

        foreach ($files as $file) {
            $allowed = self::REPLACED_ELSEWHERE[str_replace([str_replace(DIRECTORY_SEPARATOR, '/', base_path()).'/', base_path().DIRECTORY_SEPARATOR], '', str_replace(DIRECTORY_SEPARATOR, '/', $file))] ?? [];

            foreach (preg_split('/\R/', (string) File::get($file)) ?: [] as $number => $line) {
                if (str_contains($line, '$this->audit(') || in_array(trim($line), $allowed, true)) {
                    continue; // the admin log keeps English records (Str::plural); a line another change replaces
                }

                $shown = preg_match('/with(Success|Errors?)\(|\'message\'\s*=>|\$msg\s*=|\$message\s*[.]?=/', $line) === 1;

                // a number ({$count}, {$deleted}, {$users->count()}) and a plural noun right after it
                $number_ = '\{\$(?:\w*(?:count|total|deleted|cleared|removed|skipped|updated|purged|resolved|missing|days|jobs|files|runs)\w*|\w+->count\(\))\}';
                if ($shown && preg_match('/"[^"]*'.$number_.'\s+(?:[a-z-]+\s+){0,2}(?!is\b|was\b|has\b|this\b)[a-z]+s\b[^"]*"/i', $line)) {
                    $offenders[] = basename($file).':'.($number + 1).' '.trim($line);
                }

                if (preg_match("/__\\('[^'|]*:count [a-z ]*s\\b[^'|]*'/", $line) || str_contains($line, '(s)') && $shown) {
                    $offenders[] = basename($file).':'.($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_console_commands_count_in_plain_english(): void
    {
        // their lines show in a terminal, in the cron output, in Tools > Commands and (the import's) in the import log:
        // "Deleted 1 row." instead of "1 row(s)". SyncUpload18Metadata is Upload18's own (left as it is).
        $offenders = [];
        foreach (glob(app_path('Console/Commands/*.php')) ?: [] as $file) {
            if (basename($file) === 'SyncUpload18Metadata.php') {
                continue;
            }

            foreach (preg_split('/\R/', (string) File::get($file)) ?: [] as $number => $line) {
                if (preg_match('/[A-Za-z]\((?:s|es)\)/', $line) === 1) {
                    $offenders[] = basename($file).':'.($number + 1).' '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders);

        // the import log's own helper
        $import = (new \ReflectionClass(\App\Console\Commands\ImportAvdbVideos::class))->newInstanceWithoutConstructor();
        $counted = new \ReflectionMethod($import, 'counted');
        $this->assertSame('1 ID', $counted->invoke($import, 1, 'ID'));
        $this->assertSame('100 IDs', $counted->invoke($import, 100, 'ID'));
        $this->assertSame('0 items', $counted->invoke($import, 0, 'item'));
        $this->assertSame('1 API batch', $counted->invoke($import, 1, 'API batch', 'API batches'));
        $this->assertSame('3 API batches', $counted->invoke($import, 3, 'API batch', 'API batches'));
    }

    // ------------------------------------------------------------------ helpers

    /** @return list<string> the trans_choice() lines and labels the changed files use */
    private function countedLines(): array
    {
        // every admin controller, page and component, both layouts, and the duration backfill's answer
        $files = array_merge(
            glob(app_path('Http/Controllers/Admin/*.php')) ?: [],
            glob(app_path('Http/Controllers/User/*.php')) ?: [],
            [app_path('Support/AvdbDurationBackfill.php')],
            array_map(static fn ($file): string => $file->getPathname(), File::allFiles(resource_path('views/admin'))),
            array_map(static fn ($file): string => $file->getPathname(), File::allFiles(resource_path('views/components/admin'))),
        );
        $lines = [];

        foreach ($files as $file) {
            if (str_contains(str_replace(DIRECTORY_SEPARATOR, '/', $file), '/updates/')) {
                continue; // the updater's own texts (its own tests)
            }

            // the plural lines (trans_choice) and the labels ("Videos: :count.") of __()
            preg_match_all("/(trans_choice|__)\\('((?:\\\\'|[^'])*)'/", (string) File::get($file), $m, PREG_SET_ORDER);
            foreach ($m as [, $function, $raw]) {
                $key = str_replace("\\'", "'", $raw);
                if ($function === 'trans_choice' ? str_contains($key, '|') : preg_match('/: :[a-z_]+[.\s]/', $key) === 1) {
                    $lines[$key] = true;
                }
            }
        }

        return array_keys($lines);
    }

    /** @return list<int|string> ids of $count records the bulk action can act on */
    private function records(string $list, string $action, int $count): array
    {
        $now = now();

        return match ($list) {
            'videos' => match ($action) {
                'resync_duration' => $this->durationVideos($count),
                // Unban acts on banned videos only (each goes back to the status it kept, App\Support\VideoBans)
                'unban' => tap($this->videos($count), fn (array $ids) => VideoBans::ban(Video::query()->whereIn('id', $ids))),
                default => $this->videos($count),
            },
            // blocked members to unblock: MySQL / MariaDB count only the rows an update changes
            'users' => User::factory()->count($count)->create(match ($action) { 'verify' => ['email_verified_at' => null], 'unban' => ['banned_at' => $now], default => [] })->pluck('id')->all(),
            'comments' => collect(range(1, $count))->map(fn () => Comment::query()->create([
                'content' => 'A comment', 'user_id' => User::factory()->create()->id, 'video_id' => $this->videos(1)[0],
                'banned_at' => $action === 'unban' ? $now : null,
            ])->id)->all(),
            'playlists' => Playlist::factory()->count($count)->for(User::factory()->create(), 'user')->create()->pluck('id')->all(),
            'notifications' => collect(range(1, $count))->map(function () use ($now): string {
                $id = (string) \Illuminate\Support\Str::uuid();
                DB::table('notifications')->insert(['id' => $id, 'type' => 'App\\Notifications\\Test', 'notifiable_type' => User::class,
                    'notifiable_id' => User::factory()->create()->id, 'data' => '{}', 'read_at' => null, 'created_at' => $now, 'updated_at' => $now]);

                return $id;
            })->all(),
            'activities' => collect(range(1, $count))->map(fn () => DB::table('activities')->insertGetId([
                'user_id' => User::factory()->create()->id, 'subject_type' => Video::class, 'subject_id' => $this->videos(1)[0], 'perform_at' => $now,
            ]))->all(),
            'interactions' => collect(range(1, $count))->map(fn () => DB::table('interactions')->insertGetId([
                'user_id' => User::factory()->create()->id, 'likeable_type' => Video::class, 'likeable_id' => $this->videos(1)[0],
                'status' => 1, 'perform_at' => $now,
            ]))->all(),
            'devices' => collect(range(1, $count))->map(fn () => DB::table('personal_access_tokens')->insertGetId([
                'tokenable_type' => User::class, 'tokenable_id' => User::factory()->create()->id, 'name' => 'phone',
                'token' => hash('sha256', \Illuminate\Support\Str::random(40)), 'abilities' => '["*"]', 'created_at' => $now, 'updated_at' => $now,
            ]))->all(),
            'failed-jobs' => collect(range(1, $count))->map(function () use ($now): string {
                $uuid = (string) \Illuminate\Support\Str::uuid();
                DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
                    'exception' => 'test', 'failed_at' => $now]);

                return $uuid;
            })->all(),
            'contacts' => collect(range(1, $count))->map(fn () => DB::table('contact_messages')->insertGetId([
                'name' => 'Visitor', 'email' => 'visitor@example.com', 'subject' => 'Hello', 'message' => 'Hello there', 'is_read' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]))->all(),
            'reports' => collect(range(1, $count))->map(fn () => DB::table('reports')->insertGetId([
                'user_id' => User::factory()->create()->id, 'reportable_type' => Video::class, 'reportable_id' => $this->videos(1)[0],
                'reason' => 1, 'status' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]))->all(),
        };
    }

    private function dueSchedule(string $name): AvdbImportSchedule
    {
        return AvdbImportSchedule::query()->create([
            'name' => $name,
            'enabled' => true,
            'frequency' => AvdbImportSchedule::FREQUENCY_DAILY,
            'run_time' => '03:00',
            'skip_if_active' => true,
            'settings' => [],
            'next_run_at' => now()->subMinute(),
        ]);
    }

    /** @return list<int> */
    private function videos(int $count, ?User $owner = null): array
    {
        $category = Category::query()->firstOrCreate(['slug' => 'lane-a'], ['title' => 'Lane A', 'icon' => 'film', 'in_menu' => true]);

        return Video::factory()->count($count)->for($category)->for($owner ?? User::factory()->create(), 'user')->create()->pluck('id')->all();
    }

    /**
     * AVDB videos still without a duration (what the duration sync checks), unless $attributes say otherwise.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<int>
     */
    private function durationVideos(int $count, array $attributes = []): array
    {
        static $avdbId = 880000;
        $ids = $this->videos($count);

        foreach ($ids as $id) {
            DB::table('videos')->where('id', $id)->update(array_merge(['avdb_id' => ++$avdbId, 'duration' => null], $attributes));
        }

        return $ids;
    }
}
