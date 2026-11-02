<?php

namespace App\Http\Controllers\Admin;
use App\Models\Category;
use App\Support\AdminPreferences;
use App\Support\AuditDetails;
use App\Support\ContentSafety;
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
            // the sort window: the menu's categories visitors see (1.2.3 round 3, R3-072)
            'active_categories' => ContentSafety::visibleCategories(Category::query()
                ->where('in_menu', true)
                ->ordered()
                ->get())
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
            // the menu visitors see: a category named with a word that is always blocked has no place in it (round 3, R3-072)
            'menu' => ContentSafety::visibleCategories(Category::query()->where('in_menu', true)->ordered()->get(['id', 'title', 'icon', 'position'])),
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

        $category = Category::create([
            'title' => $validated['title'],
            'slug' => ($validated['slug'] ?? '') ?: Str::slug($validated['title']),
            'icon' => FontAwesomeIconRegistry::normalize($validated['icon'] ?? null),
            'in_menu' => $request->has('in_menu'),
        ]);

        // Tools > Logs > Admin actions (R3-019): the category by its name and number.
        AuditLogController::log('category.created', AuditDetails::titled($category));

        return redirect()
            ->route('admin.categories.index')
            ->withSuccess(__('Category created'));
    }

    public function edit (Category $category) : View {
        if (AdminPreferences::usesNewAdmin()) {
            // Its place in the menu, counted in the menu's order, among the categories visitors see (round 3, R3-072).
            $place = ContentSafety::visibleCategories(Category::query()->where('in_menu', true)->ordered()->get(['id', 'title']))
                ->pluck('id')->search($category->id);

            return view('admin.categories.record', ['category' => $category->loadCount('videos'), 'place' => $place === false ? null : $place + 1]);
        }

        return view('admin.categories.edit', compact('category'));
    }

    public function update (Category $category, Request $request) : RedirectResponse {

        // what it was, to name the fields a save changes (in_menu compared as on/off: the column keeps 1, the form true)
        $before = ['title' => (string) $category->title, 'slug' => (string) $category->slug, 'icon' => (string) $category->icon, 'in_menu' => (bool) $category->in_menu];

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

        // Which fields changed (never their values); a save that changed nothing writes nothing (R3-019).
        $after = ['title' => (string) $category->title, 'slug' => (string) $category->slug, 'icon' => (string) $category->icon, 'in_menu' => (bool) $category->in_menu];
        $changed = array_values(array_intersect_key(['title' => 'name', 'slug' => 'address', 'icon' => 'icon', 'in_menu' => 'menu'], array_diff_assoc(array_map('json_encode', $after), array_map('json_encode', $before))));

        if ($changed !== []) {
            AuditLogController::log('category.updated', AuditDetails::titled($category).': '.implode(', ', $changed));
        }

        return redirect()
            ->route('admin.categories.index')
            ->withSuccess(__('Changes saved.'));
    }

    public function destroy (Category $category) : RedirectResponse {

        if ($category->videos()->count() > 0) {
            return redirect()
                ->route('admin.categories.index')
                ->withErrors(__('Cannot delete category with existing videos. Reassign videos first.'));
        }

        $name = AuditDetails::titled($category);
        $category->delete();
        AuditLogController::log('category.deleted', $name);

        return redirect()
            ->route('admin.categories.index')
            ->withSuccess(__('Category deleted'));
    }

    public function organize (Request $request) : RedirectResponse {

        $categories = (array) $request->get('categories', []);
        $moved = false;

        foreach ($categories as $index => $id) {
            $category = Category::find($id);

            if ($category !== null) {
                $category->update([
                    'position' => $index
                ]);
                $moved = $moved || $category->wasChanged('position');
            }
        }

        // The menu in its new order (R3-019), only when a place changed.
        if ($moved) {
            AuditLogController::log('categories.reordered', AuditDetails::names(
                Category::query()->where('in_menu', true)->ordered()->get(['id', 'title'])->map(fn (Category $category): string => AuditDetails::titled($category))
            ));
        }

        // The order panels list the categories visitors see; the menu's others (named with a word that is always
        // blocked) go after them, in their order, so no two share a place (1.2.3 round 3, R3-072).
        $place = count((array) $categories);
        Category::query()->where('in_menu', true)->whereNotIn('id', array_map('intval', (array) $categories))->ordered()->get()
            ->each(function (Category $category) use (&$place): void {
                $category->update(['position' => $place++]);
            });

        return redirect()->route('admin.categories.index');
    }

}

