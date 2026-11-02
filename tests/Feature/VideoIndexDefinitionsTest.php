<?php

namespace Tests\Feature;

use App\Support\VideoIndexes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * R3-009: the two install modes give the same index definitions under the same names. A site installed from the bundled
 * database had videos_user_active_count_idx (user_id, deleted_at, status, language), videos_avdb_category_metric_idx
 * (avdb_id, deleted_at, category_id) and no videos_xvid_release_date_index; the update step 2026_10_29_110000 (and on a
 * big table the scheduler's videos:index) gives every site the migrations' definitions (App\Support\VideoIndexes), once,
 * and leaves a site that has them alone. R3-022's look-around: every site also gets videos_views_published_id_idx, the
 * order of the trending and most viewed feeds (1.2.2 had it in neither mode).
 */
class VideoIndexDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_29_110000_align_video_index_definitions.php';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        VideoIndexes::forget();
    }

    protected function tearDown(): void
    {
        VideoIndexes::$buildNowRows = 200000;
        VideoIndexes::forget();
        parent::tearDown();
    }

    public function test_a_site_installed_by_the_migrations_has_the_definitions_and_the_step_does_nothing(): void
    {
        $this->assertSame([], VideoIndexes::pending());
        $this->assertDefinitions();
        $this->listen($statements);

        (require base_path(self::MIGRATION))->up();

        $this->assertSame([], array_values(array_filter($statements, fn (string $sql) => preg_match('/^\s*(create|drop) index/i', $sql) === 1)), 'nothing rebuilt');
        $this->assertFalse(VideoIndexes::due());
    }

    public function test_a_site_installed_by_the_migrations_of_1_2_2_gets_the_trending_index_only(): void
    {
        Schema::table('videos', fn (Blueprint $table) => $table->dropIndex('videos_views_published_id_idx'));
        VideoIndexes::forget();
        $this->assertSame(['videos_views_published_id_idx'], array_keys(VideoIndexes::pending()));
        $this->listen($statements);

        (require base_path(self::MIGRATION))->up();

        $this->assertDefinitions();
        $this->assertSame([], VideoIndexes::pending());
        $rebuilt = array_values(array_filter($statements, fn (string $sql) => preg_match('/^\s*(create|drop) index/i', $sql) === 1));
        $this->assertCount(1, $rebuilt, 'only the missing index is built');
        $this->assertStringContainsString('videos_views_published_id_idx', $rebuilt[0]);
    }

    /**
     * The fifth pass (DB-V3-1): videos_language_active_idx (the language's videos a visitor can be shown, counted and read
     * by the public lists) is one more of 1.2.3's indexes: a site without it (every site before 1.2.3, a site an earlier
     * 1.2.3 build updated) gets it from the update step once (the scheduler on a big table), and nothing else.
     */
    public function test_a_site_without_the_language_index_gets_it_once(): void
    {
        $this->assertSame(['language', 'status', 'deleted_at', 'user_id', 'scheduled_at', 'published_at', 'uploaded_at'], VideoIndexes::WANTED['videos_language_active_idx']);
        Schema::table('videos', fn (Blueprint $table) => $table->dropIndex('videos_language_active_idx'));
        VideoIndexes::forget();
        $this->assertSame(['videos_language_active_idx'], array_keys(VideoIndexes::pending()));
        $this->listen($statements);

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $this->assertDefinitions();
        $rebuilt = array_values(array_filter($statements, fn (string $sql) => preg_match('/^\s*(create|drop) index/i', $sql) === 1));
        $this->assertCount(1, $rebuilt, 'only the missing index is built');
        $this->assertStringContainsString('videos_language_active_idx', $rebuilt[0]);
        $statements = [];
        $migration->up();
        $this->assertSame([], array_values(array_filter($statements, fn (string $sql) => preg_match('/^\s*(create|drop) index/i', $sql) === 1)), 'a second run rebuilds nothing');
    }

    public function test_the_update_step_gives_a_bundled_site_the_definitions_once(): void
    {
        $this->bundledShapes();
        $this->assertSame(['videos_user_active_count_idx', 'videos_avdb_category_metric_idx', 'videos_xvid_release_date_index', 'videos_views_published_id_idx'], array_keys(VideoIndexes::pending()));

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $this->assertDefinitions();
        $this->assertSame([], VideoIndexes::pending());
        $this->listen($statements);
        $migration->up();
        $this->assertSame([], array_values(array_filter($statements, fn (string $sql) => preg_match('/^\s*(create|drop) index/i', $sql) === 1)), 'a second run rebuilds nothing');
        $this->assertFalse(VideoIndexes::due(), 'nothing for the scheduler');
    }

    public function test_a_big_table_is_left_to_the_scheduler_which_aligns_it_once(): void
    {
        $this->bundledShapes();
        VideoIndexes::$buildNowRows = 0;
        DB::table('videos')->insert($this->videoRow());

        (require base_path(self::MIGRATION))->up();

        $this->assertCount(4, VideoIndexes::pending(), 'the update step left them');
        $this->assertTrue(VideoIndexes::due(), 'the scheduler starts videos:index');

        $this->artisan('videos:index')->assertSuccessful();

        $this->assertDefinitions();
        $this->assertFalse(VideoIndexes::due());
        $this->artisan('videos:index')->expectsOutputToContain('the indexes are in place')->assertSuccessful();
    }

    public function test_before_finish_update_the_scheduler_aligns_nothing(): void
    {
        $this->bundledShapes();
        DB::table('migrations')->where('migration', '2026_10_29_110000_align_video_index_definitions')->delete();

        $this->assertFalse(VideoIndexes::due(), 'the step has not run: the files alone change nothing');
    }

    /** The definitions of the bundled database (example.sql of 1.2.0 to 1.2.3 RC2; no index of the trending order). */
    private function bundledShapes(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex('videos_user_active_count_idx');
            $table->dropIndex('videos_avdb_category_metric_idx');
            $table->dropIndex('videos_xvid_release_date_index');
            $table->dropIndex('videos_views_published_id_idx');
        });
        Schema::table('videos', function (Blueprint $table): void {
            $table->index(['user_id', 'deleted_at', 'status', 'language'], 'videos_user_active_count_idx');
            $table->index(['avdb_id', 'deleted_at', 'category_id'], 'videos_avdb_category_metric_idx');
        });
        VideoIndexes::forget();
    }

    private function assertDefinitions(): void
    {
        $indexes = collect(Schema::getIndexes('videos'))->keyBy('name');
        foreach (VideoIndexes::WANTED as $name => $columns) {
            $this->assertTrue($indexes->has($name), $name.' exists');
            $this->assertSame($columns, $indexes[$name]['columns'], $name.': the migrations\' definition');
        }
    }

    /** @param  list<string>|null  $statements */
    private function listen(?array &$statements): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
    }

    /** @return array<string, mixed> */
    private function videoRow(): array
    {
        $owner = \App\Models\User::factory()->create();

        return array_merge(\App\Models\Video::factory()->for($owner)->make()->getAttributes(), ['created_at' => now(), 'updated_at' => now()]);
    }
}
