<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Support\AdminPreferences;
use App\Support\ContentSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 1.2.3 round 3 (lane KS, R3-072): Content > Categories shows a category visitors never see (its name uses a word that
 * is always blocked, App\Support\ContentSafety) as hidden: its menu cell says so (not "Shown, place N"), it has no place
 * in "Order in the menu" and the places of the others count what visitors see, its address is not offered as a page
 * (it answers 404), and its record page says the same. The old admin's list and its sort window too. Saving the order
 * puts the menu's hidden categories after the others, so no two share a place.
 *
 * Test data: neutral names with one listed word.
 */
class AdminHiddenCategoryMenuTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, Category> */
    private array $categories;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        ContentSafety::forget();
        config(['admin.ui' => 'v2']);
        $this->admin = User::factory()->admin()->create();
        $this->categories = [
            'garden' => Category::create(['title' => 'QA garden category', 'slug' => 'qa-garden-category', 'in_menu' => true, 'position' => 1]),
            'hidden' => Category::create(['title' => 'QA child category', 'slug' => 'qa-child-category', 'in_menu' => true, 'position' => 2]),
            'river' => Category::create(['title' => 'QA river category', 'slug' => 'qa-river-category', 'in_menu' => true, 'position' => 3]),
        ];
    }

    public function test_the_list_shows_a_hidden_category_as_hidden_and_counts_the_places_visitors_see(): void
    {
        $html = (string) $this->actingAs($this->admin)->get(route('admin.categories.index'))->assertOk()->getContent();
        $hidden = $this->categories['hidden'];

        $row = $this->row($html, $hidden);
        $this->assertStringNotContainsString('Shown, place', $row);
        $this->assertStringContainsString('>Hidden from visitors</span>', $row, 'its menu cell');
        $this->assertStringContainsString('Hidden from visitors: its name uses a word that is always blocked.', $row);
        $this->assertStringNotContainsString('/categories/qa-child-category', $row, 'no address of a page that answers 404');
        $this->assertStringNotContainsString('data-url=', $row, 'its row menu offers no "Open on site"');

        // the places visitors see: the river category is the second
        $this->assertStringContainsString('Shown, place 1', $this->row($html, $this->categories['garden']));
        $this->assertStringContainsString('Shown, place 2', $this->row($html, $this->categories['river']));
        $this->assertStringContainsString('data-url="/categories/qa-river-category"', $this->row($html, $this->categories['river']));
        $this->assertMatchesRegularExpression('#<a href="__URL__"[^>]*data-ad-if="url"#', $html, '"Open on site" only for a row that has an address');

        // "Order in the menu": the categories visitors see, in their order
        $order = substr($html, (int) strpos($html, 'id="ad-categories-order"'));
        $this->assertStringContainsString('id="ad-o-'.$this->categories['garden']->id.'"', $order);
        $this->assertStringContainsString('id="ad-o-'.$this->categories['river']->id.'"', $order);
        $this->assertStringNotContainsString('id="ad-o-'.$hidden->id.'"', $order);
        $this->assertStringNotContainsString('QA child category', $order);
    }

    public function test_the_record_page_says_it_is_hidden(): void
    {
        $hidden = (string) $this->actingAs($this->admin)->get(route('admin.categories.edit', $this->categories['hidden']))->assertOk()->getContent();
        $this->assertStringContainsString('Hidden from visitors: its name uses a word that is always blocked.', $hidden);
        $this->assertMatchesRegularExpression('#<dt>In the menu</dt><dd>Hidden from visitors</dd>#', $hidden);
        $this->assertStringNotContainsString('/categories/qa-child-category"', $hidden, 'no "Open on site" to a page that answers 404');

        $river = (string) $this->actingAs($this->admin)->get(route('admin.categories.edit', $this->categories['river']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<dt>In the menu</dt><dd>Place 2</dd>#', $river);
        $this->assertStringContainsString('/categories/qa-river-category"', $river);
    }

    public function test_the_old_admin_says_it_too(): void
    {
        AdminPreferences::set($this->admin, 'admin_ui', 'v1');

        $html = (string) $this->actingAs($this->admin)->get(route('admin.categories.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'Hidden from visitors: its name uses a word that is always blocked.'));

        // the sort window lists the menu's categories visitors see
        $modal = substr($html, (int) strpos($html, 'id="categories_organise"'));
        $this->assertStringContainsString('value="'.$this->categories['garden']->id.'"', $modal);
        $this->assertStringContainsString('value="'.$this->categories['river']->id.'"', $modal);
        $this->assertStringNotContainsString('value="'.$this->categories['hidden']->id.'"', $modal);
    }

    public function test_saving_the_order_puts_the_hidden_ones_after_the_others(): void
    {
        // it was first in the menu: kept there, it would share the first place with the category the admin puts first
        $this->categories['hidden']->update(['position' => 0]);

        $this->actingAs($this->admin)->post(route('admin.categories.organize'), ['categories' => [$this->categories['river']->id, $this->categories['garden']->id]])
            ->assertRedirect(route('admin.categories.index'));

        $this->assertSame(
            ['QA river category' => 0, 'QA garden category' => 1, 'QA child category' => 2],
            Category::query()->orderBy('position')->pluck('position', 'title')->map(fn ($position): int => (int) $position)->all()
        );
    }

    /** The table row of a category in the new admin's list. */
    private function row(string $html, Category $category): string
    {
        $start = strpos($html, 'id="ad-c-'.$category->id.'"');
        $this->assertNotFalse($start, 'the list shows '.$category->title);
        $from = strrpos(substr($html, 0, $start), '<tr>');

        return substr($html, $from, strpos($html, '</tr>', $start) - $from);
    }
}
