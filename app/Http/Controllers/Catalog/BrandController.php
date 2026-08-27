<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Support\ExportsCsv;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BrandController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $brands = Brand::query()
            ->withCount('products')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->filled('status'), fn ($q) => $q->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('catalog.brands.index', compact('brands'));
    }

    public function create(): View
    {
        return view('catalog.brands.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        try {
            $brand = Brand::create([
                'name' => $data['name'],
                'slug' => unique_slug(Brand::class, $data['name']),
                'description' => $data['description'] ?? null,
                'is_active' => $request->boolean('is_active', true),
            ]);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return back()->withInput()
                    ->with('toasts', [['type' => 'danger', 'message' => 'A brand with the name "' . $data['name'] . '" already exists. Please choose a different name.']]);
            }
            throw $e;
        }

        return redirect()->route('catalog.brands.index')
            ->with('toasts', [['type' => 'success', 'message' => "Brand \"{$brand->name}\" created."]]);
    }

    public function edit(Brand $brand): View
    {
        return view('catalog.brands.edit', compact('brand'));
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $data = $this->validateData($request, $brand->id);

        $brand->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Brand \"{$brand->name}\" updated."]]);
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        if ($brand->productCount() > 0) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Brand is assigned to products and cannot be deleted.']]);
        }

        $name = $brand->name;
        $brand->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "Brand \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $brands = Brand::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->orderBy('name')
            ->get();

        return $this->streamCsv('brands-'.now()->format('Y-m-d').'.csv', ['ID', 'Name', 'Slug', 'Description', 'Status', 'Products'], $brands->map(fn (Brand $b) => [
            $b->id,
            $b->name,
            $b->slug,
            $b->description,
            $b->is_active ? 'Active' : 'Inactive',
            $b->products()->count(),
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('brands', 'name')->ignore($ignoreId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);
    }
}
