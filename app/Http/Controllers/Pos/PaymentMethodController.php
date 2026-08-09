<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosPaymentMethod;
use App\Support\ExportsCsv;
use App\Support\ExportsJson;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentMethodController extends Controller
{
    use ExportsCsv;
    use ExportsJson;

    public function index(Request $request): View
    {
        $methods = PosPaymentMethod::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('pos.payment-methods.index', compact('methods'));
    }

    public function create(): View
    {
        return view('pos.payment-methods.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        PosPaymentMethod::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'is_cash' => $request->boolean('is_cash'),
            'is_active' => $request->boolean('is_active'),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('pos.payment_methods.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Payment method created.']]);
    }

    public function edit(PosPaymentMethod $paymentMethod): View
    {
        return view('pos.payment-methods.edit', compact('paymentMethod'));
    }

    public function update(Request $request, PosPaymentMethod $paymentMethod): RedirectResponse
    {
        $data = $this->validateData($request);

        $paymentMethod->update([
            'code' => $data['code'],
            'name' => $data['name'],
            'is_cash' => $request->boolean('is_cash'),
            'is_active' => $request->boolean('is_active'),
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => 'Payment method updated.']]);
    }

    public function destroy(PosPaymentMethod $paymentMethod): RedirectResponse
    {
        $paymentMethod->delete();

        return redirect()->route('pos.payment_methods.index')
            ->with('toasts', [['type' => 'success', 'message' => 'Payment method deleted.']]);
    }

    public function export(Request $request): StreamedResponse
    {
        $methods = PosPaymentMethod::query()
            ->when($request->filled('search'), fn ($q) => $q->search($request->search))
            ->orderBy('name')
            ->get();

        $rows = $methods->map(fn (PosPaymentMethod $m) => [
            'code' => $m->code,
            'name' => $m->name,
            'cash' => $m->is_cash ? 'Yes' : 'No',
            'active' => $m->is_active ? 'Yes' : 'No',
        ]);

        $filename = 'pos-payment-methods-'.now()->format('Y-m-d').'.'.($request->query('format') === 'json' ? 'json' : 'csv');

        return $request->query('format') === 'json'
            ? $this->streamJson($filename, $rows)
            : $this->streamCsv($filename, ['Code', 'Name', 'Cash', 'Active'], $rows->values());
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:190'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}