<?php

namespace App\Http\Controllers\Admin;
use App\Models\Category;
use App\Support\AdminPreferences;
use App\Support\FontAwesomeIconRegistry;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class CategoryController
{
    public function index (Request $request) : View {
        if (AdminPreferences::usesNewAdmin($request->user())) {
            return view('admin.categories.list', $this->listPage($request));
        }

        return view('admin.categories.index', [
            'all_categories' => Category::query()
                ->orderBy('in_menu', 'desc')
                ->orderBy('position', 'asc')
                ->withCount('videos')
                ->paginate(\App\Support\AdminPagination::perPage(null, 25)),
            'active_categories' => Category::query()
                ->where('in_menu', true)
                ->ordered()
                ->get()
        ]);
    }

    /**
     * The new admin's list: 25 per page (the menu's first, in their order), a search on the name and address, and the
     * menu's categories for the order panel.
     */
    private function listPage(Request $request): array
    {
        $search = trim((string) $request->query('search', ''));

        return [
            'categories' => Category::query()
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('title', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%')))
                ->orderBy('in_menu', 'desc')
                ->orderBy('position', 'asc')
                ->withCount('videos')
                ->paginate(\App\Support\AdminPagination::perPage($request, 25))
                ->withQueryString(),
            'menu' => Category::query()->where('in_menu', true)->ordered()->get(['id', 'title', 'icon', 'position']),
        ];
    }

    public function create () : View {
        if (AdminPreferences::usesNewAdmin()) {
            return view('admin.categories.record', ['category' => new Category(['in_menu' => true]), 'place' => null]);
        }

        return view('admin.categories.create');
    }

    public function store (Request $request) : RedirectResponse {

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100', 'unique:categories,title'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:categories,slug'],
            'icon' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9-]+$/'],
            'in_menu' => ['nullable', 'boolean'],
        ]);

        Category::create([
            'title' => $validated['title'],
            'slug' => ($validated['slug'] ?? '') ?: Str::slug($validated['title']),
            'icon' => FontAwesomeIconRegistry::normalize($validated['icon'] ?? null),
            'in_menu' => $request->has('in_menu'),
        ]);

        return redirect()
            ->route('admin.categories.index')
            ->withSuccess('Category created successfully.');
    }

    public function edit (Category $category) : View {
        if (AdminPreferences::usesNewAdmin()) {
            // Its place in the menu, counted in the menu's order.
            $place = Category::query()->where('in_menu', true)->ordered()->pluck('id')->search($category->id);

            return view('admin.categories.record', ['category' => $category->loadCount('videos'), 'place' => $place === false ? null : $place + 1]);
        }

        return view('admin.categories.edit', compact('category'));
    }

    public function update (Category $category, Request $request) : RedirectResponse {

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9-]+$/'],
            'in_menu' => ['nullable', 'boolean'],
        ]);

        $category->update([
            'title' => $validated['title'],
            'slug' => ($validated['slug'] ?? '') ?: Str::slug($validated['title']),
            'icon' => FontAwesomeIconRegistry::normalize($validated['icon'] ?? $category->icon),
            'in_menu' => $request->has('in_menu'),
        ]);

        return redirect()
            ->route('admin.categories.index')
            ->withSuccess('Category updated successfully.');
    }

    public function destroy (Category $category) : RedirectResponse {

        if ($category->videos()->count() > 0) {
            return redirect()
                ->route('admin.categories.index')
                ->withErrors(__('Cannot delete category with existing videos. Reassign videos first.'));
        }

        $category->delete();

        return redirect()
            ->route('admin.categories.index')
            ->withSuccess('Category deleted successfully.');
    }

    public function organize (Request $request) : RedirectResponse {

        $categories = $request->get('categories');

        foreach ($categories as $index => $id) {
            Category::find($id)->update([
                'position' => $index
            ]);
        }

        return redirect()->route('admin.categories.index');
    }

}

