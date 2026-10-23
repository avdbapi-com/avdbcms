<?php

namespace App\Observers;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class CategoryObserver
{
    /**
     * Handle the Category "created" event.
     *
     * @param Category $category
     * @return void
     */
    public function created(Category $category): void
    {
        // Titles without a Latin transliteration (Japanese, Chinese, Thai, Korean, ...) slug to '', which breaks
        // the category URL: keep a slug the admin typed, or fall back to a stable 'category-<id>'.
        $slug = Str::slug((string) $category->title) ?: Str::slug((string) $category->getOriginal('slug')) ?: 'category-' . $category->id;
        $category->update(['slug' => $slug]);
    }

    public function saved(Category $category): void
    {
        Cache::forget('categories');
    }

    public function deleted(Category $category): void
    {
        Cache::forget('categories');
    }
}
