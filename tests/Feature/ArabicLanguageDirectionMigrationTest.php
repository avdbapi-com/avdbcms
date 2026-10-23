<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArabicLanguageDirectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_25_000001_correct_untouched_seeded_arabic_direction.php';

    private const LANGUAGE_CACHE_KEYS = [
        'languages_config_array',
        'languages_all_collection',
        'schema.has_languages_table',
    ];

    public function test_fresh_sqlite_migrations_seed_arabic_rtl_and_preserve_other_language_defaults(): void
    {
        $languages = require config_path('languages.php');

        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_09_25_000001_correct_untouched_seeded_arabic_direction',
        ]);
        $this->assertTrue($languages['ar']['rtl']);
        $this->assertSame(count($languages), DB::table('languages')->count());
        foreach ($languages as $code => $metadata) {
            $this->assertDatabaseHas('languages', [
                'code' => $code,
                'direction' => ($metadata['rtl'] ?? false) ? 'rtl' : 'ltr',
            ]);
        }
    }

    public function test_upgrade_corrects_only_untouched_arabic_preserving_timestamps_and_other_language_data(): void
    {
        $this->restoreLegacyArabic();
        $before = $this->languageRows();
        $this->warmCaches();

        $this->migration()->up();

        $expected = array_map(static function (array $row): array {
            if ($row['code'] === 'ar') {
                $row['direction'] = 'rtl';
            }

            return $row;
        }, $before);
        $this->assertSame($expected, $this->languageRows());
        foreach (self::LANGUAGE_CACHE_KEYS as $key) {
            $this->assertFalse(Cache::has($key));
        }
        $this->assertSame('keep', Cache::get('unrelated-cache'));

        // A repeated migration has no changes and must leave warm caches intact.
        $this->warmCaches();
        $this->migration()->up();
        $this->assertSame($expected, $this->languageRows());
        $this->assertWarmCaches();
    }

    #[DataProvider('protectedArabicMetadata')]
    public function test_upgrade_preserves_recorded_edits_and_nondefault_metadata(array $changes): void
    {
        $this->restoreLegacyArabic($changes);
        $before = $this->languageRows();
        $this->warmCaches();

        $this->migration()->up();

        $this->assertSame($before, $this->languageRows());
        $this->assertWarmCaches();
    }

    public static function protectedArabicMetadata(): array
    {
        return [
            'administrator selected ltr later' => [['updated_at' => '2026-04-22 12:00:01']],
            'custom Arabic label' => [['label' => 'العربية المخصصة']],
            'custom flag' => [['emoji' => '🌐']],
            'custom display order' => [['sort_order' => 4]],
            'inactive language' => [['is_active' => false]],
            'site default language' => [['is_default' => true]],
            'already correct rtl' => [['direction' => 'rtl']],
            'missing creation timestamp' => [['created_at' => null]],
            'missing update timestamp' => [['updated_at' => null]],
            'both timestamps missing' => [['created_at' => null, 'updated_at' => null]],
            'non Arabic language code' => [['code' => 'ur']],
        ];
    }

    public function test_upgrade_does_not_recreate_removed_arabic_or_flush_unchanged_caches(): void
    {
        DB::table('languages')->where('code', 'ar')->delete();
        $before = $this->languageRows();
        $this->warmCaches();

        $this->migration()->up();

        $this->assertSame($before, $this->languageRows());
        $this->assertWarmCaches();
    }

    public function test_rollback_preserves_the_corrected_direction_and_subsequent_admin_edits(): void
    {
        $this->restoreLegacyArabic();
        $migration = $this->migration();
        $migration->up();
        $corrected = $this->languageRows();

        $migration->down();
        $this->assertSame($corrected, $this->languageRows());

        DB::table('languages')->where('code', 'ar')->update([
            'direction' => 'ltr',
            'updated_at' => '2026-09-25 12:00:00',
        ]);
        $edited = $this->languageRows();
        $migration->down();
        $this->assertSame($edited, $this->languageRows());
    }

    private function restoreLegacyArabic(array $changes = []): void
    {
        DB::table('languages')->where('code', 'ar')->update(array_replace([
            'direction' => 'ltr',
            'label' => 'العربية',
            'emoji' => '🇸🇦',
            'sort_order' => 12,
            'is_active' => true,
            'is_default' => false,
            'created_at' => '2026-04-22 12:00:00',
            'updated_at' => '2026-04-22 12:00:00',
        ], $changes));
    }

    private function languageRows(): array
    {
        return DB::table('languages')->orderBy('id')->get()->map(static fn ($row) => (array) $row)->all();
    }

    private function warmCaches(): void
    {
        foreach (self::LANGUAGE_CACHE_KEYS as $key) {
            Cache::forever($key, 'unchanged');
        }
        Cache::forever('unrelated-cache', 'keep');
    }

    private function assertWarmCaches(): void
    {
        foreach (self::LANGUAGE_CACHE_KEYS as $key) {
            $this->assertSame('unchanged', Cache::get($key));
        }
        $this->assertSame('keep', Cache::get('unrelated-cache'));
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }
}
