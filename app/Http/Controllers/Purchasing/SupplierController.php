<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Support\ExportsCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $suppliers = Supplier::query()
            ->withCount(['purchaseOrders', 'purchaseInvoices'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->when($request->filled('status'), fn ($q) => $q->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false)))
            ->orderBy('company_name')
            ->paginate(20)
            ->withQueryString();

        return view('suppliers.suppliers.index', compact('suppliers'));
    }

    public function create(): View
    {
        return view('suppliers.suppliers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $supplier = Supplier::create([
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'currency' => $data['currency'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('suppliers.suppliers.show', $supplier)
            ->with('toasts', [['type' => 'success', 'message' => "Supplier \"{$supplier->company_name}\" created."]]);
    }

    public function show(Supplier $supplier): View
    {
        $supplier->load([
            'purchaseQuotes' => fn ($q) => $q->latest('issue_date')->limit(5),
            'purchaseOrders' => fn ($q) => $q->latest('order_date')->limit(5),
            'purchaseInvoices' => fn ($q) => $q->latest('issue_date')->limit(5),
        ]);

        return view('suppliers.suppliers.show', compact('supplier'));
    }

    public function edit(Supplier $supplier): View
    {
        return view('suppliers.suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $this->validateData($request, $supplier->id);

        $supplier->update([
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'tax_number' => $data['tax_number'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'currency' => $data['currency'] ?? null,
            'is_active' => $request->boolean('is_active', true),
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Supplier \"{$supplier->company_name}\" updated."]]);
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $name = $supplier->company_name;
        $supplier->delete();

        return back()->with('toasts', [['type' => 'success', 'message' => "Supplier \"{$name}\" deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $suppliers = Supplier::query()
            ->withCount(['purchaseOrders', 'purchaseInvoices'])
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->orderBy('company_name')
            ->get();

        return $this->streamCsv('suppliers-'.now()->format('Y-m-d').'.csv', ['ID', 'Company', 'Contact', 'Email', 'Phone', 'City', 'Country', 'Tax no.', 'Payment terms', 'Balance', 'Status'], $suppliers->map(fn (Supplier $s) => [
            $s->id,
            $s->company_name,
            $s->contact_name,
            $s->email,
            $s->phone,
            $s->city,
            $s->country,
            $s->tax_number,
            $s->payment_terms,
            $s->balance(),
            $s->is_active ? 'Active' : 'Inactive',
        ]));
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('suppliers', 'email')->ignore($ignoreId)],
            'phone' => ['nullable', 'string', 'max:60'],
            'mobile' => ['nullable', 'string', 'max:60'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:120'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
