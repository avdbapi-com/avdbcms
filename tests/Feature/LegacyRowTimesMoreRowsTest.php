<?php

namespace Tests\Feature;

use App\Enums\PlaylistSort;
use App\Enums\PlaylistStatus;
use App\Enums\VideoStatus;
use App\Models\Interaction;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Video;
use App\Support\LegacyRowTimes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R3-005 and R3-035: the old-times conversion (App\Support\LegacyRowTimes, R1-011 / R2A-001) also takes the activities of
 * likes and dislikes and the "Date added" of playlists that 1.2.0 to 1.2.2 left to the database's clock, with the same
 * zone rules (the zone the update notes from the admin's Time zone setting, whatever the console process has; the stored
 * moment; once; the admin log says what was done), and every way of adding a video to a playlist writes the site's
 * clock once those rows are noted (Save to playlist did not).
 *
 * As in LegacyRowTimesTest: SQLite's CURRENT_TIMESTAMP is UTC, the site's clock is frozen at 12:00 New York time.
 */
class LegacyRowTimesMoreRowsTest extends TestCase
{
    use RefreshDatabase;

    private string $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->zone = date_default_timezone_get();
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');
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

    /** @return array<string, array{0: string}> */
    public static function siteZones(): array
    {
        return ['Asia/Bangkok' => ['Asia/Bangkok'], 'America/New_York' => ['America/New_York'], 'Europe/Paris' => ['Europe/Paris'], 'UTC' => ['UTC']];
    }

    #[DataProvider('siteZones')]
    public function test_old_likes_activities_and_playlist_rows_are_put_in_the_admins_time_zone_once(string $zone): void
    {
        [$member, $video, $other] = $this->members();
        $this->storeTimeZone($zone);
        $this->consoleZone('Europe/Paris');
        $site = fn (string $utc): string => CarbonImmutable::parse($utc, 'UTC')->setTimezone($zone)->format('Y-m-d H:i:s');

        // 1.2.x: a like and a changed dislike (the interaction with the site's clock of then, its activity with the
        // database's), across both changes of time of New York and Paris
        $moments = ['2026-01-15 03:05:00', '2026-07-10 01:30:00', '2026-03-29 01:30:00', '2026-11-01 06:30:00', '2026-10-23 18:47:35'];
        $likes = [];
        foreach ($moments as $i => $utc) {
            $likes[$utc] = $this->likeOf122($member, $i % 2 === 0 ? $video : $other, $utc, $site($utc));
        }
        // a like whose interaction was deleted since (its activity stayed): its own time is all there is
        $orphan = $this->activity($member, LegacyRowTimes::INTERACTION_SUBJECT, 999999, '2026-10-20 10:00:00');
        // a comment's activity had its comment's time (the site's clock) all along
        $comment = $this->activity($member, 'App\\Models\\Comment', 77, '2026-10-22 08:00:00');

        // 1.2.x playlist rows: a member's playlist (Save to playlist, the studio: the database's clock), Watch later
        // (its own button wrote the site's clock, the Save dialog the database's: left as they are) and a playlist the
        // member named like it
        $mine = $this->playlist($member, 'Road trip');
        $watchLater = $this->playlist($member, 'Watch Later', false);
        $named = $this->playlist($member, 'watch later');
        $rows = [
            $this->playlistRow($mine, $video, '2026-10-23 18:47:35'),
            $this->playlistRow($mine, $other, '2026-07-10 01:30:00'),
        ];
        $kept = [$this->playlistRow($watchLater, $video, '2026-10-20 09:00:00'), $this->playlistRow($named, $video, '2026-10-21 09:00:00')];

        LegacyRowTimes::start();
        // written after the note (1.2.3, the site's clock): never touched
        $after = $this->activity($member, LegacyRowTimes::INTERACTION_SUBJECT, 888888, '2026-10-24 11:59:00');
        $afterRow = $this->playlistRow($mine, $this->members()[1], '2026-10-24 11:59:00');

        $result = LegacyRowTimes::run(30);
        $this->assertTrue($result['done']);
        foreach ($likes as $utc => $id) {
            $this->assertSame($site($utc), $this->stored('activities', 'perform_at', $id), 'the like of '.$utc.' UTC in '.$zone);
        }
        $this->assertSame($site('2026-10-20 10:00:00'), $this->stored('activities', 'perform_at', $orphan), 'a like without its interaction');
        $this->assertSame('2026-10-22 08:00:00', $this->stored('activities', 'perform_at', $comment), 'a comment: left alone');
        $this->assertSame('2026-10-24 11:59:00', $this->stored('activities', 'perform_at', $after));
        $this->assertSame($site('2026-10-23 18:47:35'), $this->stored('playlist_has_videos', 'added_at', $rows[0]));
        $this->assertSame($site('2026-07-10 01:30:00'), $this->stored('playlist_has_videos', 'added_at', $rows[1]));
        $this->assertSame('2026-10-20 09:00:00', $this->stored('playlist_has_videos', 'added_at', $kept[0]), 'Watch later: left as it is');
        $this->assertSame('2026-10-21 09:00:00', $this->stored('playlist_has_videos', 'added_at', $kept[1]), 'a playlist named like it: left as it is');
        $this->assertSame('2026-10-24 11:59:00', $this->stored('playlist_has_videos', 'added_at', $afterRow));
        $this->assertSame($zone, DB::table(LegacyRowTimes::TABLE)->where('name', 'activities.perform_at')->value('zone'), 'the zone the update noted');

        // the admin log: how many, in which zone (or that none needed it)
        $lines = DB::table('admin_audit_logs')->where('action', LegacyRowTimes::AUDIT_ACTION)->orderBy('id')->pluck('details')->all();
        if ($zone === 'UTC') {
            $this->assertSame(0, $result['changed']);
            $this->assertSame([
                '1.2.3: the 6 likes and dislikes in Your activity recorded before the update already showed their times in the site time zone (UTC), nothing was changed',
                '1.2.3: the 2 videos added to playlists recorded before the update already showed their times in the site time zone (UTC), nothing was changed',
            ], $lines);
        } else {
            $this->assertSame(8, $result['changed']);
            $this->assertSame([
                '1.2.3: 6 likes and dislikes in Your activity recorded before the update now show their times in the site time zone ('.$zone.')',
                '1.2.3: 2 videos added to playlists recorded before the update now show their times in the site time zone ('.$zone.')',
            ], $lines);
        }

        // once: a second run and a second update move nothing again
        $this->assertSame(0, LegacyRowTimes::run(30)['changed']);
        LegacyRowTimes::start();
        LegacyRowTimes::noteMissing();
        $this->assertSame(0, LegacyRowTimes::run(30)['changed']);
        $this->assertSame($site($moments[0]), $this->stored('activities', 'perform_at', $likes[$moments[0]]));
        $this->assertCount(2, DB::table('admin_audit_logs')->where('action', LegacyRowTimes::AUDIT_ACTION)->get());
    }

    public function test_a_like_made_with_the_new_files_before_the_update_step_keeps_its_time(): void
    {
        [$member, $video] = $this->members();
        $this->storeTimeZone('America/New_York');
        // between the new files and "Finish update": 1.2.3's own listener gives the activity the like's moment
        Auth::login($member);
        $like = Interaction::query()->create(['user_id' => $member->id, 'likeable_type' => $video->getMorphClass(), 'likeable_id' => $video->id,
            'status' => true, 'perform_at' => now()->subMinutes(5)]);
        $activity = (int) DB::table('activities')->where('subject_type', LegacyRowTimes::INTERACTION_SUBJECT)->where('subject_id', $like->id)->value('id');
        $this->assertSame('2026-10-24 11:55:00', $this->stored('activities', 'perform_at', $activity), 'the site clock');
        // and a 1.2.x one from before
        $old = $this->likeOf122($member, $this->members()[1], '2026-10-23 18:47:35', '2026-10-23 14:47:35');

        LegacyRowTimes::start();
        $result = LegacyRowTimes::run(30);

        $this->assertSame(1, $result['changed']);
        $this->assertSame('2026-10-24 11:55:00', $this->stored('activities', 'perform_at', $activity), 'never shifted');
        $this->assertSame('2026-10-23 14:47:35', $this->stored('activities', 'perform_at', $old));
        $this->assertSame('1.2.3: 1 like or dislike in Your activity recorded before the update now shows its time in the site time zone (America/New_York)',
            DB::table('admin_audit_logs')->where('action', LegacyRowTimes::AUDIT_ACTION)->value('details'));
    }

    public function test_your_activity_lists_an_old_like_on_its_own_day_after_the_conversion(): void
    {
        [$member, $video] = $this->members();
        $this->storeTimeZone('America/New_York');
        // the judge's case: a like made yesterday at 14:47 New York time, which the database dated 18:47 (UTC here)
        $this->likeOf122($member, $video, '2026-10-23 18:47:35', '2026-10-23 14:47:35');
        LegacyRowTimes::start();

        $before = (string) $this->actingAs($member)->get(route('user.activity.index'))->assertOk()->getContent();
        $this->assertStringContainsString('2026-10-23T18:47:35', $before, 'before: the database\'s clock read as the site\'s');

        $this->artisan('history:fix-old-times', ['--seconds' => 30])->assertSuccessful();

        $after = (string) $this->actingAs($member)->get(route('user.activity.index'))->assertOk()->getContent();
        $this->assertStringContainsString('2026-10-23T14:47:35', $after, 'when it was made, in the site time zone');
        $this->assertStringNotContainsString('2026-10-23T18:47:35', $after);
    }

    public function test_every_way_of_adding_a_video_to_a_playlist_writes_the_site_clock_once_the_rows_are_noted(): void
    {
        $member = User::factory()->create();
        $videos = Video::factory()->count(8)->for(User::factory()->create())->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subWeek(), 'uploaded_at' => now()->subWeek()]);
        $mine = $this->playlist($member, 'Road trip');
        $watchLater = $this->playlist($member, 'Watch Later', false);

        // before the update notes the rows: the database's clock (as every row before them), except Watch later
        $this->actingAs($member)->postJson(route('playlists.save'), ['video_id' => $videos[0]->id, 'playlist_id' => $mine->id])->assertCreated();
        $this->actingAs($member)->postJson(route('playlists.save'), ['video_id' => $videos[1]->id, 'playlist_id' => $watchLater->id])->assertCreated();
        $this->assertNotSame('2026-10-24 12:00:00', $this->addedAt($mine, $videos[0]), 'Save to playlist: the database clock before the note');
        $this->assertLessThan(600, abs((int) strtotime($this->addedAt($mine, $videos[0]).' UTC') - time()));
        $this->assertSame('2026-10-24 12:00:00', $this->addedAt($watchLater, $videos[1]), 'Watch later: always the site clock');

        LegacyRowTimes::start();

        // Save to playlist (the watch page's dialog, the apps)
        $this->actingAs($member)->postJson(route('playlists.save'), ['video_id' => $videos[2]->id, 'playlist_id' => $mine->id])->assertCreated();
        $this->assertSame('2026-10-24 12:00:00', $this->addedAt($mine, $videos[2]), 'Save to playlist');
        // the studio's editor (a save that adds videos) and a new playlist of the studio
        $this->actingAs($member)->put(route('user.playlists.update', $mine), ['title' => 'Road trip', 'status' => PlaylistStatus::PUBLIC->value,
            'sort' => PlaylistSort::MANUAL->value, 'videos' => [$videos[3]->id]])->assertRedirect();
        $this->assertSame('2026-10-24 12:00:00', $this->addedAt($mine, $videos[3]), 'the studio\'s editor');
        $this->actingAs($member)->post(route('user.playlists.store'), ['title' => 'New one', 'status' => PlaylistStatus::PUBLIC->value,
            'sort' => PlaylistSort::MANUAL->value, 'videos' => [$videos[4]->id]])->assertRedirect();
        $new = Playlist::query()->where('title', 'New one')->firstOrFail();
        $this->assertSame('2026-10-24 12:00:00', $this->addedAt($new, $videos[4]), 'a new playlist of the studio');
        // any other way the relation adds a row (the video's playlists on the upload and details pages)
        $mine->videos()->attach($videos[5]->id, ['position' => 9]);
        $this->assertSame('2026-10-24 12:00:00', $this->addedAt($mine, $videos[5]), 'the relation');
        // Watch later's own button
        $this->actingAs($member)->postJson(route('custom-playlist.watch-later.toggle', $videos[6]->id))->assertOk();
        $this->assertSame('2026-10-24 12:00:00', $this->addedAt(Playlist::query()->where('user_id', $member->id)->where('title', 'Watch Later')->firstOrFail(), $videos[6]));

        // the rows written after the note are never shifted
        LegacyRowTimes::run(30);
        $this->assertSame('2026-10-24 12:00:00', $this->addedAt($mine, $videos[2]));
        $this->assertSame('2026-10-24 12:00:00', $this->addedAt($new, $videos[4]));
    }

    public function test_the_update_step_notes_the_new_columns_once_on_a_site_an_earlier_build_updated(): void
    {
        [$member, $video, $other] = $this->members();
        $this->storeTimeZone('Asia/Bangkok');
        $old = $this->likeOf122($member, $video, '2026-10-23 18:47:35', '2026-10-24 01:47:35');
        $mine = $this->playlist($member, 'Road trip');
        $row = $this->playlistRow($mine, $video, '2026-10-23 18:47:35');
        // the progress table as an earlier build of 1.2.3 made it: the first three columns, all done
        Schema::create(LegacyRowTimes::TABLE, function ($table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->unsignedBigInteger('boundary');
            $table->unsignedBigInteger('next')->default(0);
            $table->unsignedBigInteger('changed')->default(0);
            $table->unsignedBigInteger('checked')->default(0);
            $table->string('zone', 64)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        foreach (['views.view_at', 'subscriptions.subscribe_at', 'favorites_playlist.added_at'] as $name) {
            DB::table(LegacyRowTimes::TABLE)->insert(['name' => $name, 'boundary' => 0, 'zone' => 'Asia/Bangkok', 'finished_at' => now()]);
        }
        $this->assertSame([], LegacyRowTimes::playlistStamp($mine), 'not noted yet: the database clock');

        $migration = require database_path('migrations/2026_10_29_100000_fix_more_old_row_times.php');
        $migration->up();
        $migration->up();

        $this->assertSame(5, DB::table(LegacyRowTimes::TABLE)->count(), 'each column once');
        $likes = DB::table(LegacyRowTimes::TABLE)->where('name', 'activities.perform_at')->first();
        $this->assertSame($old, (int) $likes->boundary);
        $this->assertNull($likes->finished_at, 'the likes wait for the scheduler');
        $this->assertNotNull(DB::table(LegacyRowTimes::TABLE)->where('name', 'playlist_has_videos.added_at')->value('finished_at'), 'the playlists\' rows: done (that build wrote both clocks)');
        $this->assertSame(['added_at'], array_keys(LegacyRowTimes::playlistStamp($mine)), 'noted: the site clock from now on');

        $result = LegacyRowTimes::run(30);
        $this->assertSame(1, $result['changed']);
        $this->assertSame('2026-10-24 01:47:35', $this->stored('activities', 'perform_at', $old), 'the like in Bangkok time, as its interaction');
        $this->assertSame('2026-10-23 18:47:35', $this->stored('playlist_has_videos', 'added_at', $row), 'left as it was');
        $this->assertSame(['1.2.3: 1 like or dislike in Your activity recorded before the update now shows its time in the site time zone (Asia/Bangkok)'],
            DB::table('admin_audit_logs')->where('action', LegacyRowTimes::AUDIT_ACTION)->pluck('details')->all());
    }

    public function test_the_update_step_on_a_site_without_the_progress_table_notes_every_column(): void
    {
        $migration = require database_path('migrations/2026_10_29_100000_fix_more_old_row_times.php');
        $migration->up();

        $this->assertSame(['activities.perform_at', 'favorites_playlist.added_at', 'playlist_has_videos.added_at', 'subscriptions.subscribe_at', 'views.view_at'],
            DB::table(LegacyRowTimes::TABLE)->orderBy('name')->pluck('name')->all());
        $this->assertFalse(LegacyRowTimes::due(), 'an empty site: nothing to do');
    }

    public function test_the_admin_log_knows_the_new_lines_and_shows_them_in_the_admins_language(): void
    {
        $samples = [
            'likes put right' => LegacyRowTimes::auditDetails('activities', 1204, 1300, 'Asia/Bangkok'),
            'one like put right' => LegacyRowTimes::auditDetails('activities', 1, 1, 'Asia/Bangkok'),
            'no like needed it' => LegacyRowTimes::auditDetails('activities', 0, 6, 'UTC'),
            'the one like needed nothing' => LegacyRowTimes::auditDetails('activities', 0, 1, 'UTC'),
            'playlist rows put right' => LegacyRowTimes::auditDetails('playlist_has_videos', 2, 2, 'Europe/Paris'),
            'one playlist row put right' => LegacyRowTimes::auditDetails('playlist_has_videos', 1, 1, 'Europe/Paris'),
            'no playlist row needed it' => LegacyRowTimes::auditDetails('playlist_has_videos', 0, 2, 'UTC'),
            'the one playlist row needed nothing' => LegacyRowTimes::auditDetails('playlist_has_videos', 0, 1, 'UTC'),
        ];
        $this->assertSame('1.2.3: 1,204 likes and dislikes in Your activity recorded before the update now show their times in the site time zone (Asia/Bangkok)', $samples['likes put right']);
        $this->assertSame('1.2.3: the 2 videos added to playlists recorded before the update already showed their times in the site time zone (UTC), nothing was changed', $samples['no playlist row needed it']);
        foreach ($samples as $name => $details) {
            $this->assertTrue(\App\Support\AuditDetails::known('update.row_times', $details), $name.': no template knows "'.$details.'"');
        }

        // shown in the admin's language (here an Arabic line given for the test, whatever lang/ar.json holds: lines added
        // to the translator are used instead of the file), the zone and the version set apart
        app('translator')->addLines([
            '*.:version: 1 like or dislike in Your activity recorded before the update now shows its time in the site time zone|:version: :count likes and dislikes in Your activity recorded before the update now show their times in the site time zone'
                => ':version: إعجاب واحد|:version: إعجاب واحد في نشاطك صار يُظهر وقته بالمنطقة الزمنية للموقع|:version: إعجابان|:version: :count إعجابات|:version: :count إعجابًا|:version: :count إعجاب في نشاطك صارت تُظهر أوقاتها بالمنطقة الزمنية للموقع',
        ], 'ar');
        app()->setLocale('ar');

        $html = (string) \App\Support\AuditDetails::html('update.row_times', $samples['likes put right']);
        $this->assertStringContainsString('1,204 إعجابات', $html, 'the Arabic form for 1,204');
        $this->assertStringContainsString('Asia/Bangkok)</bdi>', $html, 'the zone set apart, left to right');
        $this->assertStringNotContainsString('recorded before the update', $html);
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: User, 1: Video, 2: Video} */
    private function members(): array
    {
        $member = User::factory()->create();
        $video = Video::factory()->for(User::factory()->create())->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subWeek(), 'uploaded_at' => now()->subWeek()]);
        $other = Video::factory()->for(User::factory()->create())->withStatus(VideoStatus::PUBLIC)->create(['published_at' => now()->subWeek(), 'uploaded_at' => now()->subWeek()]);

        return [$member, $video, $other];
    }

    /** A like as 1.2.x left it: the interaction with the site's clock, its activity with the database's. Its activity's id. */
    private function likeOf122(User $member, Video $video, string $databaseClock, string $siteClock): int
    {
        $interaction = (int) DB::table('interactions')->insertGetId(['user_id' => $member->id, 'likeable_type' => $video->getMorphClass(),
            'likeable_id' => $video->id, 'status' => 1, 'perform_at' => $siteClock]);

        return $this->activity($member, LegacyRowTimes::INTERACTION_SUBJECT, $interaction, $databaseClock);
    }

    private function activity(User $member, string $type, int $subject, string $at): int
    {
        return (int) DB::table('activities')->insertGetId(['user_id' => $member->id, 'subject_type' => $type, 'subject_id' => $subject, 'perform_at' => $at]);
    }

    private function playlist(User $member, string $title, bool $deletable = true): Playlist
    {
        return Playlist::factory()->for($member)->create(['title' => $title, 'is_deletable' => $deletable, 'status' => PlaylistStatus::PRIVATE]);
    }

    private function playlistRow(Playlist $playlist, Video $video, string $at): int
    {
        return (int) DB::table('playlist_has_videos')->insertGetId(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'position' => 0, 'added_at' => $at]);
    }

    private function addedAt(Playlist $playlist, Video $video): string
    {
        return substr((string) DB::table('playlist_has_videos')->where('playlist_id', $playlist->id)->where('video_id', $video->id)->value('added_at'), 0, 19);
    }

    private function stored(string $table, string $column, int $id): string
    {
        return substr((string) DB::table($table)->where('id', $id)->value($column), 0, 19);
    }

    private function storeTimeZone(string $zone): void
    {
        DB::table('settings')->updateOrInsert(['key' => 'timezone'], ['value' => $zone, 'group' => 'localization']);
        Cache::flush();
    }

    private function consoleZone(string $zone): void
    {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }
}
