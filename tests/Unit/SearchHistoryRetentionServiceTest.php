<?php

namespace Tests\Unit;

use App\Models\Search;
use App\Services\SearchHistoryRetentionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SearchHistoryRetentionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('searches');
        Schema::create('searches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('query');
            $table->string('ip')->nullable();
            $table->string('lang')->nullable();
            $table->unsignedSmallInteger('results')->default(0);
            $table->timestamp('perform_at')->useCurrent();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('searches');

        parent::tearDown();
    }

    public function test_cleanup_deletes_expired_rows_and_caps_total_rows(): void
    {
        config()->set('site.search_history_retention_days', 30);
        config()->set('site.search_history_max_rows', 100);

        foreach (range(1, 3) as $index) {
            Search::query()->create([
                'query' => 'expired-' . $index,
                'perform_at' => now()->subDays(45),
            ]);
        }

        foreach (range(1, 105) as $index) {
            Search::query()->create([
                'query' => 'recent-' . $index,
                'perform_at' => now()->subMinutes(105 - $index),
            ]);
        }

        app(SearchHistoryRetentionService::class)->cleanup();

        $this->assertSame(100, Search::query()->count());
        $this->assertFalse(Search::query()->where('query', 'expired-1')->exists());
        $this->assertFalse(Search::query()->where('query', 'recent-1')->exists());
        $this->assertTrue(Search::query()->where('query', 'recent-105')->exists());
    }
}
