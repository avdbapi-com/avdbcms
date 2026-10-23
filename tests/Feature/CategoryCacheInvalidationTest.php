<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CategoryCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_menu_cache_is_cleared_when_category_is_saved(): void
    {
        $category = $this->createCategory('Original Category');

        Cache::forever('categories', collect([$category]));

        $category->update(['title' => 'Updated Category']);

        $this->assertFalse(Cache::has('categories'));
    }

    public function test_category_menu_cache_is_cleared_when_category_is_deleted(): void
    {
        $category = $this->createCategory('Disposable Category');

        Cache::forever('categories', collect([$category]));

        $category->delete();

        $this->assertFalse(Cache::has('categories'));
    }

    private function createCategory(string $title): Category
    {
        return Category::query()->create([
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'icon' => 'folder',
            'in_menu' => true,
            'position' => 1,
        ]);
    }
}
