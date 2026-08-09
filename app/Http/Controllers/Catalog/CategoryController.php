<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CategoryController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $categories = Category::query()
            ->with('parent')
            ->withCount('products')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('status'), fn ($q) => $q->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('catalog.categories.index', compact('categories'));
    }

    public function create(): View
    {
        $parents = Category::query()->orderBy('name')->get();

        return view('catalog.categories.create', compact('parents'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $category = Category::create([
            'name' => $data['name'],
            'slug' => unique_slug(Category::class, $data['name']),
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('catalog.categories.edit', $category)
            ->with('toasts', [['type' => 'success', 'message' => "Category \"{$category->name}\" created."]]);
    }

    public function edit(Category $category): View
    {
        $parents = Category::query()->whereKeyNot($category->id)->orderBy('name')->get();

        return view('catalog.categories.edit', compact('category', 'parents'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validateData($request, $category->id);

        if ((int) $data['parent_id'] === $category->id) {
            return back()->withErrors(['parent_id' => 'A category cannot be its own parent.']);
        }

        $category->update([
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Category \"{$category->name}\" updated."]]);
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->children()->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Category has sub-categories and cannot be deleted.']]);
        }

        if ($category->products()->exists()) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Category is assigned to products and cannot be deleted.']]);
        }

        $name = $category->name;
        $category->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "Category \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $categories = Category::query()
            ->with('parent')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->get();

        return $this->streamCsv('categories-'.now()->format('Y-m-d').'.csv', ['ID', 'Name', 'Parent', 'Description', 'Status', 'Products'], $categories->map(fn (Category $c) => [
            $c->id,
            $c->name,
            $c->parent?->name,
            $c->description,
            $c->is_active ? 'Active' : 'Inactive',
            $c->products()->count(),
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('categories', 'name')->ignore($ignoreId)],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
