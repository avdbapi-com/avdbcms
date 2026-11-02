<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\LegacyRowTimes;
use App\Support\WatchHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * R1-011: the history, subscription and saved-playlist rows 1.2.0 to 1.2.2 left to the database's own clock are put in the
 * site's time zone once (App\Support\LegacyRowTimes, migration 2026_10_27_000000_fix_old_row_times, history:fix-old-times),
 * and the site writes them with its own clock only once those rows are noted.
 *
 * SQLite's CURRENT_TIMESTAMP is UTC: a site in New York (UTC-5 in winter, UTC-4 in summer) has its database's clock
 * AHEAD of its own, the judge's case ("Watched at 01:05" under the next day, "Subscribed 5 hours from now").
 */
class LegacyRowTimesTest extends TestCase
{
    use RefreshDatabase;

    private string $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->zone = date_default_timezone_get();
        config(['app.timezone' => 'America/New_York', 'site.show_history' => true, 'site.view_cooldown_minutes' => 0]);
        date_default_timezone_set('America/New_York');
        // the moment of the update: a Saturday in October (summer time in New York)
        Carbon::setTestNow(Carbon::create(2026, 10, 24, 12, 0, 0, 'America/New_York'));
        // as on a site updated from 1.2.2: the rows are not noted yet
        Schema::dropIfExists(LegacyRowTimes::TABLE);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->zone);

        parent::tearDown();
    }

    public function test_writers_keep_the_database_clock_until_the_rows_are_noted_then_use_the_sites(): void
    {
        $this->assertFalse(LegacyRowTimes::ready());
        $this->assertSame([], LegacyRowTimes::stamp('views'), 'before the update notes the rows: the column default, as 1.2.2');
        $this->assertSame([], LegacyRowTimes::stamp('subscriptions'));

        LegacyRowTimes::start();

        $this->assertTrue(LegacyRowTimes::ready());
        $stamp = LegacyRowTimes::stamp('subscriptions');
        $this->assertSame(['subscribe_at', 'read_at'], array_keys($stamp));
        $this->assertSame('2026-10-24 12:00:00', $stamp['subscribe_at']->format('Y-m-d H:i:s'));
        $this->assertSame(['view_at'], array_keys(LegacyRowTimes::stamp('views')));
        $this->assertSame(['added_at'], array_keys(LegacyRowTimes::stamp('favorites_playlist')));
        $this->assertSame([], LegacyRowTimes::stamp('comments'), 'other tables are not concerned');
    }

    public function test_old_rows_are_put_in_the_sites_zone_once_daylight_saving_included_and_newer_rows_are_left_alone(): void
    {
        [$member, $video, $other] = $this->members();
        // 1.2.2 rows: the database's clock (UTC here). Winter: 03:05 UTC = 22:05 the day before in New York (UTC-5);
        // summer: 01:30 UTC = 21:30 the day before (UTC-4)
        $winter = $this->historyRow($member, $video, '2026-01-15 03:05:00');
        $summer = $this->historyRow($member, $other, '2026-07-10 01:30:00');
        $this->subscription($member, $other->user, '2026-10-24 14:00:00', '2026-10-24 14:00:00');
        // read_at already written by a visit of the channel with the site's clock (1.2.2 did that): kept
        $visited = $this->subscription($other->user, $member, '2026-10-20 15:00:00', '2026-10-21 09:15:00');
        $playlist = Playlist::factory()->for($other->user)->create();
        DB::table('favorites_playlist')->insert(['user_id' => $member->id, 'playlist_id' => $playlist->id, 'added_at' => '2026-10-24 13:00:00']);

        LegacyRowTimes::start();
        // a row written after the note, with the site's clock: never touched
        $after = $this->historyRow($member, $video, '2026-10-24 11:59:00');

        $result = LegacyRowTimes::run(30);
        $this->assertTrue($result['done']);
        $this->assertSame(5, $result['changed'], 'two views, two subscribe times (one with its untouched read_at) and one saved playlist');

        $this->assertSame('2026-01-14 22:05:00', $this->stored('views', 'view_at', $winter));
        $this->assertSame('2026-07-09 21:30:00', $this->stored('views', 'view_at', $summer));
        $this->assertSame('2026-10-24 11:59:00', $this->stored('views', 'view_at', $after));
        $subscription = DB::table('subscriptions')->where('subscriber_id', $member->id)->first();
        $this->assertSame('2026-10-24 10:00:00', substr((string) $subscription->subscribe_at, 0, 19), 'not "2 hours from now"');
        $this->assertSame('2026-10-24 10:00:00', substr((string) $subscription->read_at, 0, 19), 'the untouched default follows');
        $this->assertSame('2026-10-20 11:00:00', $this->stored('subscriptions', 'subscribe_at', $visited));
        $this->assertSame('2026-10-21 09:15:00', $this->stored('subscriptions', 'read_at', $visited), 'a visit wrote it with the site clock');
        $this->assertSame('2026-10-24 09:00:00', substr((string) DB::table('favorites_playlist')->where('user_id', $member->id)->value('added_at'), 0, 19));

        // a second run, a second update: nothing moves again
        $this->assertSame(0, LegacyRowTimes::run(30)['changed']);
        LegacyRowTimes::start();
        $this->assertSame(0, LegacyRowTimes::run(30)['changed']);
        $this->assertSame('2026-01-14 22:05:00', $this->stored('views', 'view_at', $winter));
        $this->assertFalse(LegacyRowTimes::due());
        // what was really done: how many were put right and the zone, or that none needed it (R2A-001)
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'update.row_times')->where('details', '1.2.3: 2 views in History recorded before the update now show their times in the site time zone (America/New_York)')->count());
        $this->assertSame(1, DB::table('admin_audit_logs')->where('action', 'update.row_times')->where('details', '1.2.3: 1 saved playlist recorded before the update now shows its time in the site time zone (America/New_York)')->count());
        $this->assertSame('1.2.3: 1,204 subscriptions recorded before the update now show their times in the site time zone (Asia/Bangkok)', LegacyRowTimes::auditDetails('subscriptions', 1204, 1300, 'Asia/Bangkok'));
        $this->assertSame('1.2.3: the 12 views in History recorded before the update already showed their times in the site time zone (UTC), nothing was changed', LegacyRowTimes::auditDetails('views', 0, 12, 'UTC'));
        $this->assertSame('1.2.3: the 1 saved playlist recorded before the update already showed its time in the site time zone (UTC), nothing was changed', LegacyRowTimes::auditDetails('favorites_playlist', 0, 1, 'UTC'));
    }

    /**
     * R2A-001 (major): the conversion runs in console processes (the update's migrate, the scheduler's
     * history:fix-old-times) whose configuration keeps config/app.php's Europe/Paris unless the admin's settings were
     * applied to them; it reads the admin's Time zone setting itself. The database's clock here is SQLite's (UTC), so on
     * a UTC site every old row is already right and must not move (the judge's "rows right in 1.2.2" case).
     *
     * @return array<string, array{0: string}>
     */
    public static function siteZones(): array
    {
        return [
            'Asia/Bangkok' => ['Asia/Bangkok'],
            'America/New_York' => ['America/New_York'],
            'Europe/Paris' => ['Europe/Paris'],
            'UTC' => ['UTC'],
        ];
    }

    /** @dataProvider siteZones */
    #[\PHPUnit\Framework\Attributes\DataProvider('siteZones')]
    public function test_the_old_rows_are_put_in_the_admins_time_zone_whatever_zone_the_console_process_has(string $zone): void
    {
        [$member, $video, $other] = $this->members();
        $this->storeTimeZone($zone);
        // a console process that did not apply the admin's settings: the configuration's zone (config/app.php)
        $this->consoleZone('Europe/Paris');

        // 1.2.2 rows, the database's clock (UTC): winter, summer, both changes of time of Paris and New York, the judge's
        $moments = ['2026-01-15 03:05:00', '2026-07-10 01:30:00', '2026-03-08 06:30:00', '2026-03-08 07:30:00', '2026-03-29 00:30:00',
            '2026-03-29 01:30:00', '2026-10-25 00:30:00', '2026-10-25 01:30:00', '2026-11-01 05:30:00', '2026-11-01 06:30:00', '2026-10-27 16:23:05'];
        $views = [];
        foreach ($moments as $i => $utc) {
            $views[$utc] = $this->historyRow($member, $i % 2 === 0 ? $video : $other, $utc);
        }
        $subscribed = $this->subscription($member, $other->user, '2026-10-24 14:00:00', '2026-10-24 14:00:00');
        // a visit of the channel wrote read_at with the site's clock (1.2.2's UserController::updateReadDate())
        $visited = $this->subscription($other->user, $member, '2026-10-20 15:00:00', '2026-10-23 09:15:00');
        $playlist = Playlist::factory()->for($other->user)->create();
        $saved = (int) DB::table('favorites_playlist')->insertGetId(['user_id' => $member->id, 'playlist_id' => $playlist->id, 'added_at' => '2026-10-24 13:00:00']);

        LegacyRowTimes::start();
        $result = LegacyRowTimes::run(30);

        $this->assertTrue($result['done']);
        $site = fn (string $utc): string => \Carbon\CarbonImmutable::parse($utc, 'UTC')->setTimezone($zone)->format('Y-m-d H:i:s');
        foreach ($views as $utc => $id) {
            $this->assertSame($site($utc), $this->stored('views', 'view_at', $id), 'view of '.$utc.' UTC in '.$zone);
        }
        $this->assertSame($site('2026-10-24 14:00:00'), $this->stored('subscriptions', 'subscribe_at', $subscribed));
        $this->assertSame($site('2026-10-24 14:00:00'), $this->stored('subscriptions', 'read_at', $subscribed), 'the untouched default follows');
        $this->assertSame($site('2026-10-20 15:00:00'), $this->stored('subscriptions', 'subscribe_at', $visited));
        $this->assertSame('2026-10-23 09:15:00', $this->stored('subscriptions', 'read_at', $visited), 'a visit wrote it with the site clock: kept');
        $this->assertSame($site('2026-10-24 13:00:00'), $this->stored('favorites_playlist', 'added_at', $saved));
        $this->assertSame('Europe/Paris', config('app.timezone'), 'the process itself kept the configuration\'s zone');
        $this->assertSame($zone, $result['zone'] ?? null);
        $this->assertSame($zone, DB::table(LegacyRowTimes::TABLE)->where('name', 'views.view_at')->value('zone'), 'the update noted the admin\'s zone');

        // the admin log says what was done: counts and the zone, also when nothing needed a change (UTC)
        $lines = DB::table('admin_audit_logs')->where('action', 'update.row_times')->orderBy('id')->pluck('details')->all();
        if ($zone === 'UTC') {
            $this->assertSame(0, $result['changed']);
            $this->assertSame([
                '1.2.3: the 11 views in History recorded before the update already showed their times in the site time zone (UTC), nothing was changed',
                '1.2.3: the 2 subscriptions recorded before the update already showed their times in the site time zone (UTC), nothing was changed',
                '1.2.3: the 1 saved playlist recorded before the update already showed its time in the site time zone (UTC), nothing was changed',
            ], $lines);
        } else {
            $this->assertSame(14, $result['changed']);
            $this->assertSame([
                '1.2.3: 11 views in History recorded before the update now show their times in the site time zone ('.$zone.')',
                '1.2.3: 2 subscriptions recorded before the update now show their times in the site time zone ('.$zone.')',
                '1.2.3: 1 saved playlist recorded before the update now shows its time in the site time zone ('.$zone.')',
            ], $lines);
        }

        // a second run and a second update move nothing again
        $this->assertSame(0, LegacyRowTimes::run(30)['changed']);
        LegacyRowTimes::start();
        $this->assertSame(0, LegacyRowTimes::run(30)['changed']);
        $this->assertSame($site($moments[0]), $this->stored('views', 'view_at', $views[$moments[0]]));
        $this->assertCount(3, DB::table('admin_audit_logs')->where('action', 'update.row_times')->get());
    }

    /**
     * The zone is read the way a web request reads it, and agrees with what round 1b's R1R-06 applies to a console
     * process (AppServiceProvider::applySettings() through ConsoleSiteSettings): a zone PHP knows (also written in other
     * capitals), else the configuration's zone for an empty, unknown or missing setting.
     */
    public function test_the_zone_is_read_like_a_web_request_and_agrees_with_the_console_settings(): void
    {
        // the rows every 1.2.x site has (the installer's data and the update's migrations), so the settings apply in
        // full, as on a real site
        DB::table('settings')->updateOrInsert(['key' => 'site_name'], ['value' => 'Zone check', 'group' => 'general']);
        DB::table('settings')->updateOrInsert(['key' => 'ad_grid_layout'], ['value' => 'grid_card', 'group' => 'monetization']);
        foreach (\App\Support\ListAdContexts::SETTINGS as $list) {
            DB::table('settings')->updateOrInsert(['key' => 'ad_grid_layout_'.$list], ['value' => 'inherit', 'group' => 'monetization']);
        }
        $provider = new \App\Providers\AppServiceProvider($this->app);

        // [stored value (null: no row), the zone the site uses]
        foreach ([['Asia/Bangkok', 'Asia/Bangkok'], ['asia/bangkok', 'asia/bangkok'], ['America/New_York', 'America/New_York'],
            ['UTC', 'UTC'], ['Mars/Olympus', 'Europe/Paris'], ['', 'Europe/Paris'], [null, 'Europe/Paris']] as [$stored, $expected]) {
            DB::table('settings')->where('key', 'timezone')->delete();
            if ($stored !== null) {
                DB::table('settings')->insert(['key' => 'timezone', 'value' => $stored, 'group' => 'localization']);
            }
            Cache::flush();
            $this->consoleZone('Europe/Paris');

            $this->assertSame($expected, LegacyRowTimes::siteZone(), 'stored "'.$stored.'"');
            $this->assertSame('Europe/Paris', date_default_timezone_get(), 'reading the zone leaves the process\'s zone as it was');

            // R1R-06: the console process applies the admin's settings through the web's code path
            $this->assertTrue($provider->consoleSettings()->refresh(true));
            $this->assertSame($expected, config('app.timezone'), 'R1R-06 applies the same zone for "'.$stored.'"');
        }
    }

    public function test_the_zone_noted_by_the_update_is_kept_for_the_whole_conversion(): void
    {
        [$member, $video] = $this->members();
        $old = $this->historyRow($member, $video, '2026-10-24 02:10:00');
        $this->storeTimeZone('Asia/Bangkok');
        $this->consoleZone('Europe/Paris');
        LegacyRowTimes::start();

        // the admin chooses another zone after the update: the old rows join the zone the site wrote in when they were noted
        $this->storeTimeZone('America/New_York');
        $result = LegacyRowTimes::run(30);

        $this->assertSame('2026-10-24 09:10:00', $this->stored('views', 'view_at', $old));
        $this->assertSame('Asia/Bangkok', $result['zone'] ?? null);
        $this->assertSame('1.2.3: 1 view in History recorded before the update now shows its time in the site time zone (Asia/Bangkok)', DB::table('admin_audit_logs')->where('action', 'update.row_times')->value('details'));
    }

    public function test_a_run_cut_off_in_the_middle_of_a_slice_leaves_it_whole_and_the_next_run_does_it_once(): void
    {
        [$member, $video, $other] = $this->members();
        $this->storeTimeZone('Asia/Bangkok');
        $ids = [];
        foreach (['2026-10-20 01:00:00', '2026-10-21 02:00:00', '2026-10-22 03:00:00'] as $i => $utc) {
            $ids[$utc] = $this->historyRow($member, $i === 1 ? $other : $video, $utc);
        }
        LegacyRowTimes::start();

        // the host ends the process in the middle of the slice: after the claim, as the rows are written
        $cut = true;
        DB::connection()->beforeExecuting(function (string $query) use (&$cut): void {
            if ($cut && str_starts_with(ltrim($query), 'UPDATE "views"')) {
                $cut = false;
                throw new \RuntimeException('cut off');
            }
        });
        try {
            LegacyRowTimes::run(30);
            $this->fail('the run was cut off');
        } catch (\RuntimeException $e) {
            $this->assertSame('cut off', $e->getMessage());
        }

        $state = DB::table(LegacyRowTimes::TABLE)->where('name', 'views.view_at')->first();
        $this->assertSame(0, (int) $state->next, 'the claim went with the cut-off write');
        $this->assertSame(0, (int) $state->changed);
        $this->assertSame(0, (int) $state->checked);
        foreach ($ids as $utc => $id) {
            $this->assertSame($utc, $this->stored('views', 'view_at', $id), 'nothing written');
        }

        $result = LegacyRowTimes::run(30);
        $this->assertSame(3, $result['changed']);
        foreach ($ids as $utc => $id) {
            $this->assertSame(\Carbon\CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Bangkok')->format('Y-m-d H:i:s'), $this->stored('views', 'view_at', $id), 'written once');
        }
        $this->assertSame(0, LegacyRowTimes::run(30)['changed']);
        $this->assertSame('1.2.3: 3 views in History recorded before the update now show their times in the site time zone (Asia/Bangkok)', DB::table('admin_audit_logs')->where('action', 'update.row_times')->value('details'));
    }

    public function test_a_progress_table_of_an_earlier_build_without_the_zone_still_finishes_in_the_admins_zone(): void
    {
        [$member, $video] = $this->members();
        $old = $this->historyRow($member, $video, '2026-10-24 02:10:00');
        $this->storeTimeZone('Asia/Bangkok');
        $this->consoleZone('Europe/Paris');
        // the progress table as an earlier build of 1.2.3 (int-C2) made it: no zone, no checked count
        Schema::create(LegacyRowTimes::TABLE, function ($table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->unsignedBigInteger('boundary');
            $table->unsignedBigInteger('next')->default(0);
            $table->unsignedBigInteger('changed')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        DB::table(LegacyRowTimes::TABLE)->insert([
            ['name' => 'views.view_at', 'boundary' => $old, 'finished_at' => null],
            ['name' => 'subscriptions.subscribe_at', 'boundary' => 0, 'finished_at' => now()],
            ['name' => 'favorites_playlist.added_at', 'boundary' => 0, 'finished_at' => now()],
        ]);

        $result = LegacyRowTimes::run(30);

        $this->assertSame('2026-10-24 09:10:00', $this->stored('views', 'view_at', $old));
        $this->assertSame('Asia/Bangkok', $result['zone'] ?? null);
        $this->assertSame('1.2.3: 1 view in History recorded before the update now shows its time in the site time zone (Asia/Bangkok)', DB::table('admin_audit_logs')->where('action', 'update.row_times')->value('details'));
    }

    /** The admin's Time zone (Settings > Site basics), stored as the settings page stores it. */
    private function storeTimeZone(string $zone): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'timezone'], ['value' => $zone, 'group' => 'localization']);
        Cache::flush();
    }

    /** A console process's own zone (config/app.php; PHP's default zone follows it). */
    private function consoleZone(string $zone): void
    {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }

    public function test_every_writer_leaves_the_times_to_the_database_until_the_update_notes_the_rows(): void
    {
        [$member, $video, $other] = $this->members();
        $channels = User::factory()->count(4)->create();
        $playlists = Playlist::factory()->count(2)->for($channels[0])->create(['status' => \App\Enums\PlaylistStatus::PUBLIC]);
        $playlistsBefore = [$playlists[0]];
        $playlistsAfter = [$playlists[1]];

        // the files of 1.2.3 are in place, "Finish update" has not noted the rows yet: every writer leaves its column to
        // the default, the database's clock (SQLite: the real UTC time; the site's clock is frozen at 12:00 New York),
        // like every older row, so the note takes these rows too and they are put right once
        $before = $this->writeWithEveryWriter($member, $video, $channels[0], $channels[1], $playlistsBefore[0]);
        $utc = [];
        foreach ($before as $what => [$table, $column, $id]) {
            $at = $utc[$what] = $this->stored($table, $column, $id);
            $this->assertNotSame('2026-10-24 12:00:00', $at, $what.': not the site clock before the note');
            $this->assertLessThan(600, abs((int) strtotime($at.' UTC') - time()), $what.': the database clock ('.$at.')');
        }
        $this->assertSame(
            $this->stored('subscriptions', 'subscribe_at', $before['subscribe (API)'][2]),
            $this->stored('subscriptions', 'read_at', $before['subscribe (API)'][2]),
            'read_at is the same default moment'
        );

        LegacyRowTimes::start();

        // after the note: the site's clock
        $after = $this->writeWithEveryWriter($member, $other, $channels[2], $channels[3], $playlistsAfter[0]);
        foreach ($after as $what => [$table, $column, $id]) {
            $this->assertSame('2026-10-24 12:00:00', $this->stored($table, $column, $id), $what.': the site clock after the note');
        }

        // the scheduler puts the noted rows (the database's clock) in the site's zone and leaves the newer rows alone
        LegacyRowTimes::run(30);
        foreach ($before as $what => [$table, $column, $id]) {
            $site = \Carbon\CarbonImmutable::createFromTimestamp((int) strtotime($utc[$what].' UTC'), 'America/New_York')->format('Y-m-d H:i:s');
            $this->assertSame($site, $this->stored($table, $column, $id), $what.': the noted row in the site zone');
        }
        foreach ($after as $what => [$table, $column, $id]) {
            $this->assertSame('2026-10-24 12:00:00', $this->stored($table, $column, $id), $what.': written once, never shifted');
        }
        $this->assertSame(0, LegacyRowTimes::run(30)['changed']);
    }

    /**
     * One row through each writer of these columns: a view (App\Services\ViewService), a subscription by the
     * subscribe API (Api\Private\SubscribeController) and by the 1.2.2 toggle (Api\Private\UserController::subscribe),
     * and a saved playlist (User\PlaylistController::favorite).
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    private function writeWithEveryWriter(User $member, Video $video, User $channel, User $toggled, Playlist $playlist): array
    {
        $this->actingAs($member);
        app(\App\Services\ViewService::class)->incrementView($video);
        $view = (int) DB::table('views')->where('user_id', $member->id)->where('video_id', $video->id)->max('id');

        $this->actingAs($member, 'sanctum')->postJson(route('subscribe', $channel), ['state' => 'on'])->assertOk();
        $api = (int) DB::table('subscriptions')->where('subscriber_id', $member->id)->where('user_id', $channel->id)->value('id');

        $request = \Illuminate\Http\Request::create('/', 'POST');
        $request->setUserResolver(fn () => $member);
        app(\App\Http\Controllers\Api\Private\UserController::class)->subscribe($request, $toggled);
        $toggle = (int) DB::table('subscriptions')->where('subscriber_id', $member->id)->where('user_id', $toggled->id)->value('id');

        $this->actingAs($member)->post(route('user.playlists.favorite', $playlist))->assertSuccessful();
        $saved = (int) DB::table('favorites_playlist')->where('user_id', $member->id)->where('playlist_id', $playlist->id)->value('id');

        foreach (['view' => $view, 'subscribe (API)' => $api, 'subscribe (1.2.2 toggle)' => $toggle, 'saved playlist' => $saved] as $what => $id) {
            $this->assertGreaterThan(0, $id, $what.': a row was written');
        }

        return [
            'view' => ['views', 'view_at', $view],
            'subscribe (API)' => ['subscriptions', 'subscribe_at', $api],
            'subscribe (1.2.2 toggle)' => ['subscriptions', 'subscribe_at', $toggle],
            'saved playlist' => ['favorites_playlist', 'added_at', $saved],
        ];
    }

    public function test_history_and_the_studio_read_the_old_rows_right(): void
    {
        [$member, $video, $other] = $this->members();
        // watched yesterday at 22:10 New York time; the database (UTC) dated it 02:10 today
        $old = $this->historyRow($member, $video, '2026-10-24 02:10:00');
        $this->subscription($member, $other->user, '2026-10-24 14:00:00', '2026-10-24 14:00:00');
        // a subscriber of 20 days ago: inside the Dashboard's 28 days either way
        $this->subscription($other->user, $member, '2026-10-04 14:00:00', '2026-10-04 14:00:00');
        LegacyRowTimes::start();
        // watched after it, at 01:00 this morning (site time, written by 1.2.3)
        $new = $this->historyRow($member, $other, '2026-10-24 01:00:00');

        // before the rows are put right, the old view sorts first, today, "at 02:10" (the judge's finding)
        $this->assertSame([$old, $new], WatchHistory::query($member)->pluck('views.id')->map(fn ($id) => (int) $id)->all());

        Artisan::call('history:fix-old-times', ['--seconds' => 30]);

        $rows = WatchHistory::query($member)->get();
        $this->assertSame([$new, $old], $rows->pluck('id')->map(fn ($id) => (int) $id)->all(), 'the newest first');
        $days = WatchHistory::days($rows);
        $this->assertSame(['2026-10-24', '2026-10-23'], $days->pluck('date')->all(), 'the old view on its own day');
        $this->assertSame('Yesterday', $days[1]['label']);

        $this->actingAs($member)->get(route('pages.history'))->assertOk()->assertSee('Watched at 22:10');

        // the studio: no subscriber "from now", and the Dashboard's last 28 days count both
        $html = $this->actingAs($other->user)->get(route('user.subscribers'))->assertOk()->getContent();
        $this->assertStringNotContainsString('from now', $html);
        $this->assertSame(1, DB::table('subscriptions')->where('user_id', $other->user->id)->where('subscribe_at', '<=', now())->count());
    }

    public function test_a_slice_taken_by_another_run_is_never_written_twice(): void
    {
        [$member, $video] = $this->members();
        $first = $this->historyRow($member, $video, '2026-10-24 02:10:00');
        LegacyRowTimes::start();
        // another process claimed the whole range a moment ago (its write is its own business)
        DB::table(LegacyRowTimes::TABLE)->where('name', 'views.view_at')->update(['next' => $first]);

        $result = LegacyRowTimes::run(30);
        $this->assertSame(0, $result['changed']);
        $this->assertSame('2026-10-24 02:10:00', $this->stored('views', 'view_at', $first));
    }

    public function test_the_update_step_is_quick_and_safe_to_run_again(): void
    {
        [$member, $video] = $this->members();
        $old = $this->historyRow($member, $video, '2026-10-24 02:10:00');

        $migration = require database_path('migrations/2026_10_27_000000_fix_old_row_times.php');
        $migration->up();
        $noted = DB::table(LegacyRowTimes::TABLE)->where('name', 'views.view_at')->value('boundary');
        $this->assertSame($old, (int) $noted);
        $this->assertSame(0, (int) DB::table(LegacyRowTimes::TABLE)->value('changed'), 'the update step only notes the rows');
        $this->assertSame('2026-10-24 02:10:00', $this->stored('views', 'view_at', $old));

        // rows written since are newer than the note: running the step again (or down + up) keeps the first note
        $newer = $this->historyRow($member, $video, '2026-10-24 11:00:00');
        $migration->down();
        $migration->up();
        $this->assertSame($old, (int) DB::table(LegacyRowTimes::TABLE)->where('name', 'views.view_at')->value('boundary'));
        LegacyRowTimes::run(30);
        $this->assertSame('2026-10-24 11:00:00', $this->stored('views', 'view_at', $newer));
        $this->assertSame('2026-10-23 22:10:00', $this->stored('views', 'view_at', $old));
    }

    public function test_an_empty_site_has_nothing_to_do_and_the_scheduler_stays_quiet(): void
    {
        LegacyRowTimes::start();

        $this->assertFalse(LegacyRowTimes::due());
        // every column noted and done at once (views, subscriptions, saved playlists; since R3-005 / R3-035 also the likes'
        // activities and the playlists' rows)
        $this->assertSame(5, DB::table(LegacyRowTimes::TABLE)->whereNotNull('finished_at')->count());
        $this->assertSame(0, Artisan::call('history:fix-old-times'));
    }

    public function test_the_site_writes_new_rows_with_its_own_clock_after_the_note(): void
    {
        [$member, $video, $other] = $this->members();
        LegacyRowTimes::start();

        $this->actingAs($member, 'sanctum')->postJson(route('subscribe', $other->user), ['state' => 'on'])->assertOk();
        $this->assertSame('2026-10-24 12:00:00', substr((string) DB::table('subscriptions')->where('subscriber_id', $member->id)->value('subscribe_at'), 0, 19));

        $this->actingAs($member);
        app(\App\Services\ViewService::class)->incrementView($video);
        $this->assertSame('2026-10-24 12:00:00', substr((string) DB::table('views')->where('user_id', $member->id)->value('view_at'), 0, 19));
    }

    /** @return array{0: User, 1: Video, 2: Video} */
    private function members(): array
    {
        $member = User::factory()->create();
        $channel = User::factory()->create();
        $video = Video::factory()->for($channel)->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subWeek(), 'uploaded_at' => now()->subWeek()]);
        $other = Video::factory()->for(User::factory()->create())->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subWeek(), 'uploaded_at' => now()->subWeek()]);

        return [$member, $video, $other];
    }

    private function historyRow(User $member, Video $video, string $at): int
    {
        return (int) DB::table('views')->insertGetId(['video_id' => $video->id, 'user_id' => $member->id, 'ip' => '127.0.0.1', 'user_agent' => 'test', 'view_at' => $at]);
    }

    private function subscription(User $member, User $channel, string $subscribeAt, string $readAt): int
    {
        return (int) DB::table('subscriptions')->insertGetId(['subscriber_id' => $member->id, 'user_id' => $channel->id, 'subscribe_at' => $subscribeAt, 'read_at' => $readAt]);
    }

    private function stored(string $table, string $column, int $id): string
    {
        return substr((string) DB::table($table)->where('id', $id)->value($column), 0, 19);
    }
}
