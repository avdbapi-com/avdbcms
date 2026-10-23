<?php

namespace Tests\Feature;

use App\Console\Commands\ImportXvidVideos;
use App\Http\Controllers\Admin\AvdbImportController;
use App\Http\Controllers\Admin\XvidImportController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ImportApiSummaryTest extends TestCase
{
    #[DataProvider('providers')]
    public function test_summary_uses_provider_pagination_and_caches_success(string $controllerClass, string $url, string $cacheKey): void
    {
        Cache::forget($cacheKey);
        Http::fake([$url => Http::response([
            'code' => 1,
            'total' => 250,
            'pagecount' => 4,
            'limit' => 75,
        ])]);

        $controller = new $controllerClass;
        $summary = $this->invokeSummary($controller);

        $this->assertTrue($summary['available']);
        $this->assertSame(250, $summary['total']);
        $this->assertSame(4, $summary['pages']);
        $this->assertSame(75, $summary['page_size']);
        $this->assertSame(
            $controllerClass === XvidImportController::class ? count(ImportXvidVideos::CATEGORY_MAP) : [],
            $summary['categories']
        );
        $this->assertSame($summary, $this->invokeSummary($controller));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['ac'] === 'list' && (int) $request['pagesize'] === 100);
    }

    #[DataProvider('providers')]
    public function test_empty_catalog_is_available_with_zero_pages(string $controllerClass, string $url, string $cacheKey): void
    {
        Cache::forget($cacheKey);
        Http::fake([$url => Http::response([
            'code' => '1',
            'total' => '0',
            'pagecount' => 1,
            'limit' => '100',
        ])]);

        $summary = $this->invokeSummary(new $controllerClass);

        $this->assertTrue($summary['available']);
        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['pages']);
        $this->assertSame(100, $summary['page_size']);
    }

    #[DataProvider('providers')]
    public function test_unavailable_summary_is_not_cached_and_retries(string $controllerClass, string $url, string $cacheKey): void
    {
        Cache::forget($cacheKey);
        Http::fake([
            $url => Http::sequence()
                ->push(['code' => 0], 503)
                ->push([
                    'code' => 1,
                    'total' => 250,
                    'pagecount' => 4,
                    'limit' => 75,
                ]),
        ]);

        $controller = new $controllerClass;
        $unavailable = $this->invokeSummary($controller);

        $this->assertFalse($unavailable['available']);
        $this->assertTrue(Cache::missing($cacheKey));

        $recovered = $this->invokeSummary($controller);

        $this->assertTrue($recovered['available']);
        $this->assertSame(250, $recovered['total']);
        $this->assertSame(4, $recovered['pages']);
        $this->assertSame(75, $recovered['page_size']);
        $this->assertSame($recovered, Cache::get($cacheKey));
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidMetadata')]
    public function test_invalid_provider_metadata_is_unavailable(
        string $controllerClass,
        string $url,
        string $cacheKey,
        array|string $body,
        int $status
    ): void {
        Cache::forget($cacheKey);
        Http::fake([$url => Http::response($body, $status)]);

        $summary = $this->invokeSummary(new $controllerClass);

        $this->assertFalse($summary['available']);
    }

    public static function providers(): array
    {
        return [
            'AVDB' => [AvdbImportController::class, 'https://avdbapi.com/*', 'avdbapi_admin_api_summary_v4'],
            'XVID' => [XvidImportController::class, 'https://xvidapi.com/*', 'xvidapi_admin_api_summary_v4'],
        ];
    }

    public static function invalidMetadata(): array
    {
        $valid = ['code' => 1, 'total' => 250, 'pagecount' => 3, 'limit' => 100];
        $cases = [
            'malformed JSON' => ['{broken', 200],
            'missing total' => [array_diff_key($valid, ['total' => true]), 200],
            'missing page count' => [array_diff_key($valid, ['pagecount' => true]), 200],
            'missing page size' => [array_diff_key($valid, ['limit' => true]), 200],
            'provider error code' => [array_replace($valid, ['code' => 0]), 200],
            'negative total' => [array_replace($valid, ['total' => -1]), 200],
            'decimal total' => [array_replace($valid, ['total' => '1.5']), 200],
            'boolean total' => [array_replace($valid, ['total' => true]), 200],
            'overflow total' => [array_replace($valid, ['total' => '9223372036854775808']), 200],
            'zero page size' => [array_replace($valid, ['limit' => 0]), 200],
            'negative page count' => [array_replace($valid, ['pagecount' => -1]), 200],
            'positive total with zero pages' => [array_replace($valid, ['pagecount' => 0]), 200],
            'HTTP failure' => [$valid, 503],
        ];

        $scenarios = [];
        foreach (self::providers() as $name => [$controllerClass, $url, $cacheKey]) {
            foreach ($cases as $case => [$body, $status]) {
                $scenarios["{$name}: {$case}"] = [$controllerClass, $url, $cacheKey, $body, $status];
            }
        }

        return $scenarios;
    }

    private function invokeSummary(object $controller): array
    {
        $method = new ReflectionMethod($controller, 'cachedApiSummary');
        $method->setAccessible(true);

        return $method->invoke($controller);
    }
}
