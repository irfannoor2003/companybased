<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Support\ExportsCsv;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $products = Product::query()
            ->with(['brand', 'category'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand_id', $request->brand))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->category))
            ->when($request->filled('status'), fn ($q) => $q->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $brands = Brand::query()->orderBy('name')->get();
        $categories = Category::query()->orderBy('name')->get();

        return view('catalog.products.index', compact('products', 'brands', 'categories'));
    }

    public function create(): View
    {
        $brands = Brand::query()->orderBy('name')->get();
        $categories = Category::query()->orderBy('name')->get();

        return view('catalog.products.create', compact('brands', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $slug = unique_slug(Product::class, $data['name']);

        try {
            $product = Product::create([
                'name' => $data['name'],
                'slug' => $slug,
                'sku' => $data['sku'] ?? null,
                'barcode' => $data['barcode'] ?? null,
                'brand_id' => $data['brand_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'unit' => $data['unit'] ?? 'pcs',
                'description' => $data['description'] ?? null,
                'cost_price' => $data['cost_price'] ?? 0,
                'retail_price' => $data['retail_price'] ?? 0,
                'wholesale_price' => $data['wholesale_price'] ?? null,
                'min_price' => $data['min_price'] ?? null,
                'is_active' => $request->boolean('is_active', true),
            ]);
        } catch (QueryException $e) {
            if ($e->errorInfo[1] == 1062) {
                return back()->withInput()
                    ->with('toasts', [['type' => 'danger', 'message' => 'A product with the name "' . $data['name'] . '" already exists. Please choose a different name.']]);
            }
            throw $e;
        }

        return redirect()->route('catalog.products.index')
            ->with('toasts', [['type' => 'success', 'message' => "Product \"{$product->name}\" created."]]);
    }

    public function edit(Product $product): View
    {
        $brands = Brand::query()->orderBy('name')->get();
        $categories = Category::query()->orderBy('name')->get();

        return view('catalog.products.edit', compact('product', 'brands', 'categories'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validateData($request, $product->id);

        $product->update([
            'name' => $data['name'],
            'sku' => $data['sku'] ?? null,
            'barcode' => $data['barcode'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'unit' => $data['unit'] ?? 'pcs',
            'description' => $data['description'] ?? null,
            'cost_price' => $data['cost_price'] ?? 0,
            'retail_price' => $data['retail_price'] ?? 0,
            'wholesale_price' => $data['wholesale_price'] ?? null,
            'min_price' => $data['min_price'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Product \"{$product->name}\" updated."]]);
    }

    public function destroy(Product $product): RedirectResponse
    {
        $name = $product->name;
        $product->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "Product \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $products = Product::query()
            ->with(['brand', 'category'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand_id', $request->brand))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->category))
            ->orderBy('name')
            ->get();

        return $this->streamCsv('products-'.now()->format('Y-m-d').'.csv', ['ID', 'Name', 'SKU', 'Barcode', 'Brand', 'Category', 'Unit', 'Cost', 'Retail', 'Wholesale', 'Min price', 'Status'], $products->map(fn (Product $p) => [
            $p->id,
            $p->name,
            $p->sku,
            $p->barcode,
            $p->brand?->name,
            $p->category?->name,
            $p->unit,
            $p->cost_price,
            $p->retail_price,
            $p->wholesale_price,
            $p->min_price,
            $p->is_active ? 'Active' : 'Inactive',
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:120', Rule::unique('products', 'sku')->ignore($ignoreId)],
            'barcode' => ['nullable', 'string', 'max:120'],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'unit' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'retail_price' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
