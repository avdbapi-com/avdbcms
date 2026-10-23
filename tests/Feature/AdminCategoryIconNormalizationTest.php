<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use App\Support\FontAwesomeIconRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCategoryIconNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_normalizes_an_unsupported_icon_and_generates_a_slug_when_omitted(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'title' => 'Unsupported Icon Category',
                'icon' => 'not-a-real-font-awesome-glyph',
                'in_menu' => '1',
            ])
            ->assertRedirect(route('admin.categories.index'));

        $category = Category::query()->where('title', 'Unsupported Icon Category')->firstOrFail();

        $this->assertSame('unsupported-icon-category', $category->slug);
        $this->assertSame(FontAwesomeIconRegistry::FALLBACK, $category->icon);
        $this->assertSame(FontAwesomeIconRegistry::FALLBACK, $category->icon_name);
    }

    public function test_store_preserves_a_supported_canonical_icon(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'title' => 'Video Icon Category',
                'icon' => 'video',
            ])
            ->assertRedirect(route('admin.categories.index'));

        $category = Category::query()->where('title', 'Video Icon Category')->firstOrFail();

        $this->assertSame('video', $category->icon);
        $this->assertSame('video', $category->icon_name);
        $this->assertSame('video-icon-category', $category->slug);
    }

    public function test_update_without_a_slug_keeps_the_generated_slug_and_normalizes_the_icon(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::query()->create([
            'title' => 'Original Category',
            'slug' => 'original-category',
            'icon' => 'film',
            'in_menu' => false,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), [
                'title' => 'Renamed Category',
                'icon' => 'female',
            ])
            ->assertRedirect(route('admin.categories.index'));

        $category->refresh();

        $this->assertSame('renamed-category', $category->slug);
        $this->assertSame('person-dress', $category->icon);
        $this->assertSame('person-dress', $category->icon_name);
    }
}
