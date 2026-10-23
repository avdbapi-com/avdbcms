<?php

namespace Tests\Feature;

use App\Filters\SearchFilters;
use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchFilterStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_keyword_and_pagination_do_not_expand_the_filter_form(): void
    {
        $this->get('/search?q=coastal&page=1&order=latest&theme=daylight_paper')
            ->assertOk()
            ->assertViewHas('filters', [])
            ->assertSee('x-data="{ filters: false }"', false);
    }

    public function test_valid_filters_remain_selected_and_expand_the_filter_form(): void
    {
        $this->get('/search?q=coastal&date=week&duration=4-20')
            ->assertOk()
            ->assertViewHas('filters', ['date' => 'week', 'duration' => '4-20'])
            ->assertSee('x-data="{ filters: true }"', false);
    }

    public function test_invalid_and_array_filters_are_ignored_without_breaking_search(): void
    {
        $this->get('/search?q=coastal&date[]=week&duration=invalid&apply=anything')
            ->assertOk()
            ->assertViewHas('filters', []);

        $filter = new SearchFilters(['date' => ['week'], 'duration' => 'invalid', 'apply' => 'anything']);
        $this->assertSame('', $filter->apply());
    }

    public function test_cached_pagination_does_not_reuse_another_requests_preview_parameters(): void
    {
        $this->get('/search?q=coastal&theme=daylight_paper')->assertOk();
        $response = $this->get('/search?q=coastal')->assertOk();

        $this->assertStringNotContainsString('theme=', $response->viewData('results')->url(2));
    }

    public function test_duration_filters_compare_numeric_seconds_in_string_backed_columns(): void
    {
        $videos = collect([90, 240, 1200, 1500])->map(fn ($seconds) => Video::factory()
            ->forUser()->withStatus(VideoStatus::PUBLIC)->create([
                'title' => 'Harbor duration '.$seconds,
                'duration' => (string) $seconds,
            ]));

        foreach (['4' => [0], '4-20' => [1, 2], '20' => [3]] as $duration => $indexes) {
            $expected = collect($indexes)->map(fn ($index) => $videos[$index]->id)->sort()->values()->all();
            $this->get('/search?q=Harbor&duration='.$duration)->assertOk()
                ->assertViewHas('results', fn ($results) => $results->pluck('id')->sort()->values()->all() === $expected);
        }
    }
}
