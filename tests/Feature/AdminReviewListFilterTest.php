<?php

namespace Tests\Feature;

use App\Enums\VideoStatus;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use App\Models\Video;
use App\Support\AdminPreferences;
use App\Support\BlockedWordsPass;
use App\Support\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (lane KS, R3-073): the review list of the update's content-safety pass (Home's "Review": Content > All
 * videos, Banned, ?reason=blocked-words) names its filter, at the top of the page where it shows at every width, with a
 * link back to every banned video; searching inside it keeps the filter. The old admin's list names it too.
 * With it (look-around of R3-004): Content > Tags offers no "Its videos on the site" for a tag visitors never see (its
 * name is no search key).
 *
 * Test data: neutral titles and names with one listed word.
 */
class AdminReviewListFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        BlockedWordsPass::forget();
        DB::table(BlockedWordsPass::PASSES)->delete();
        config(['admin.ui' => 'v2']);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_review_list_names_its_filter_with_a_way_back_to_every_banned_video(): void
    {
        $this->bannedByThePass();
        $review = route('admin.videos.index', ['status' => VideoStatus::BANNED->value, 'reason' => BlockedWordsPass::FILTER]);
        $allBanned = route('admin.videos.index', ['status' => VideoStatus::BANNED->value], false);

        $html = (string) $this->actingAs($this->admin)->get($review)->assertOk()->getContent();
        // in the page's header, under its title: shown at every width (the tab strip scrolls on phones)
        $header = substr($html, (int) strpos($html, 'class="ad-ph"'), 4000);
        $this->assertStringContainsString('Reason: Blocked words (1.2.3)', $header);
        $this->assertStringContainsString('href="'.e($allBanned).'"', $header);
        $this->assertStringContainsString('Show all banned videos', $header);
        // a search inside the list keeps the filter
        $this->assertMatchesRegularExpression('#<input type="hidden" name="reason" value="'.BlockedWordsPass::FILTER.'">#', $html);

        // every banned video: no such line
        $all = (string) $this->actingAs($this->admin)->get(route('admin.videos.index', ['status' => VideoStatus::BANNED->value]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Reason: Blocked words (1.2.3)', $all);
        $this->assertStringNotContainsString('name="reason"', $all);
    }

    public function test_the_old_admins_list_names_it_too(): void
    {
        $this->bannedByThePass();
        AdminPreferences::set($this->admin, 'admin_ui', 'v1');

        $html = (string) $this->actingAs($this->admin)
            ->get(route('admin.videos.index', ['status' => VideoStatus::BANNED->value, 'reason' => BlockedWordsPass::FILTER]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Reason: Blocked words (1.2.3)', $html);
        $this->assertStringContainsString('href="'.e(route('admin.videos.index', ['status' => VideoStatus::BANNED->value])).'"', $html);
    }

    public function test_the_tags_list_offers_no_search_page_for_a_hidden_tag(): void
    {
        $hidden = Tag::create(['name' => 'QA child tag', 'slug' => 'qa-child-tag', 'usage_count' => 2]);
        $visible = Tag::create(['name' => 'QA harbour tag', 'slug' => 'qa-harbour-tag', 'usage_count' => 2]);

        $html = (string) $this->actingAs($this->admin)->get(route('admin.tags.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-id="'.$visible->id.'"[^>]*data-used="1"#', $html);
        $this->assertDoesNotMatchRegularExpression('#data-id="'.$hidden->id.'"[^>]*data-used="1"#', $html, 'its name finds no video on the site');
    }

    /** Two videos the update's pass banned (their titles use a listed word) and one an admin banned. */
    private function bannedByThePass(): void
    {
        $creator = User::factory()->create(['email_verified_at' => now()]);
        $category = Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 1]);

        foreach (['QA child test video', 'QA kid test video', 'QA evening walk'] as $title) {
            Video::factory()->create(['user_id' => $creator->id, 'title' => $title, 'slug' => Str::slug($title), 'category_id' => $category->id,
                'status' => VideoStatus::PUBLIC->value, 'published_at' => now()->subDay(), 'uploaded_at' => now()->subDay()]);
        }

        BlockedWordsPass::start();
        $this->assertTrue(BlockedWordsPass::run(600.0)['done']);
        Video::query()->where('title', 'QA evening walk')->firstOrFail()->update(['status' => VideoStatus::BANNED->value, 'banned_at' => now()]);
        Cache::flush();
        $this->assertSame(2, BlockedWordsPass::hiddenCount());
    }
}
