<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use App\Support\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (lane KS, R3-080): the public API's list of a category's videos answers a category visitors never see
 * (its name uses a word that is always blocked, App\Support\ContentSafety) exactly like a number that does not exist:
 * the same status, the same body (the address it names is the one asked for) and the same headers (no limiter headers,
 * no cache headers of the route), refused where the missing one is, when the route's category is found. Its web page
 * already answers like a missing address. The admin's pages still find it.
 *
 * Test data: neutral names with one listed word.
 */
class HiddenCategoryAnswersLikeMissingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
    }

    public function test_the_api_answers_a_hidden_category_exactly_like_a_missing_one(): void
    {
        $creator = User::factory()->create(['email_verified_at' => now()]);
        $hidden = Category::create(['title' => 'QA child category', 'slug' => 'qa-child-category', 'in_menu' => true, 'position' => 1]);
        $garden = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 2]);

        foreach ([$hidden, $garden] as $category) {
            Video::factory()->create(['user_id' => $creator->id, 'title' => 'QA video of '.$category->slug, 'slug' => Str::slug('QA video of '.$category->slug),
                'category_id' => $category->id, 'status' => VideoStatus::PUBLIC->value, 'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        }

        $missing = 999999;
        $this->assertNull(Category::find($missing));

        $hiddenAnswer = $this->ask($hidden->id);
        $missingAnswer = $this->ask($missing);

        $this->assertSame(404, $hiddenAnswer->getStatusCode());
        $this->assertSame($missingAnswer->getStatusCode(), $hiddenAnswer->getStatusCode());
        $this->assertSame(
            str_replace((string) $missing, '#', (string) $missingAnswer->getContent()),
            str_replace((string) $hidden->id, '#', (string) $hiddenAnswer->getContent()),
            'the same body, but for the number asked for'
        );
        $this->assertSame($this->headers($missingAnswer), $this->headers($hiddenAnswer), 'the same headers');

        // a category visitors see answers its videos as before
        $this->ask($garden->id)->assertOk()->assertJsonFragment(['title' => 'QA video of qa-garden-category']);

        // the web page of the hidden category answers like a missing address, as before
        $this->get(route('pages.category', 'qa-child-category'))->assertNotFound();
    }

    public function test_the_admin_still_finds_the_hidden_category(): void
    {
        config(['admin.ui' => 'v2']);
        $admin = User::factory()->admin()->create();
        $hidden = Category::create(['title' => 'QA child category', 'slug' => 'qa-child-category', 'in_menu' => true, 'position' => 1]);

        $this->actingAs($admin)->get(route('admin.categories.edit', $hidden))->assertOk()->assertSee('QA child category');
        $this->actingAs($admin)->put(route('admin.categories.update', $hidden), ['title' => 'QA reading category', 'slug' => 'qa-reading-category', 'in_menu' => '1'])
            ->assertRedirect(route('admin.categories.index'));
        $this->assertSame('QA reading category', $hidden->fresh()->title);

        // renamed: visitors see it again, its API list too
        ContentSafety::forget();
        auth()->logout();
        $this->ask($hidden->id)->assertOk();
    }

    private function ask(int $id): TestResponse
    {
        return $this->getJson('/api/categories/'.$id.'/videos');
    }

    /** @return array<string, string> the answer's headers but its date, in a fixed order */
    private function headers(TestResponse $response): array
    {
        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            if (! in_array($name, ['date'], true)) {
                $headers[$name] = implode(', ', $values);
            }
        }

        ksort($headers);

        return $headers;
    }
}
