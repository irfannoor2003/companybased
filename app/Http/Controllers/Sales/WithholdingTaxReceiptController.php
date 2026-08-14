<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\SalesCustomer;
use App\Models\SalesInvoice;
use App\Models\WithholdingTaxReceipt;
use App\Support\ExportsCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WithholdingTaxReceiptController extends Controller
{
    use ExportsCsv;

    public function index(Request $request): View
    {
        $receipts = WithholdingTaxReceipt::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->latest('receipt_date')
            ->paginate(20)
            ->withQueryString();

        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.withholding_tax_receipts.index', compact('receipts', 'customers'));
    }

    public function create(): View
    {
        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.withholding_tax_receipts.create', compact('customers'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $receipt = WithholdingTaxReceipt::create([
            'number' => next_document_number('withholding_tax_receipt', 'WHT'),
            'customer_id' => $data['customer_id'],
            'invoice_id' => $data['invoice_id'] ?? null,
            'receipt_date' => $data['receipt_date'],
            'amount' => $data['amount'],
            'tax_rate_percent' => $data['tax_rate_percent'],
            'tax_amount' => round((float) $data['amount'] * ((float) $data['tax_rate_percent'] / 100), 2),
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('sales.withholding_tax_receipts.edit', $receipt)
            ->with('toasts', [['type' => 'success', 'message' => "Withholding tax receipt {$receipt->number} created."]]);
    }

    public function edit(WithholdingTaxReceipt $withholdingTaxReceipt): View
    {
        $customers = SalesCustomer::query()->orderBy('company_name')->get();

        return view('sales.withholding_tax_receipts.edit', compact('withholdingTaxReceipt', 'customers'));
    }

    public function update(Request $request, WithholdingTaxReceipt $withholdingTaxReceipt): RedirectResponse
    {
        $data = $this->validateData($request);

        $withholdingTaxReceipt->update([
            'customer_id' => $data['customer_id'],
            'invoice_id' => $data['invoice_id'] ?? null,
            'receipt_date' => $data['receipt_date'],
            'amount' => $data['amount'],
            'tax_rate_percent' => $data['tax_rate_percent'],
            'tax_amount' => round((float) $data['amount'] * ((float) $data['tax_rate_percent'] / 100), 2),
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('toasts', [['type' => 'success', 'message' => "Withholding tax receipt {$withholdingTaxReceipt->number} updated."]]);
    }

    public function destroy(WithholdingTaxReceipt $withholdingTaxReceipt): RedirectResponse
    {
        $number = $withholdingTaxReceipt->number;
        $withholdingTaxReceipt->delete();

        return redirect()->route('sales.withholding_tax_receipts.index')
            ->with('toasts', [['type' => 'success', 'message' => "Withholding tax receipt {$number} deleted."]]);
    }

    public function pdf(WithholdingTaxReceipt $withholdingTaxReceipt): StreamedResponse
    {
        $withholdingTaxReceipt->load(['customer', 'invoice']);

        $html = view('sales.withholding_tax_receipts.pdf', compact('withholdingTaxReceipt'))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, 'wht-'.$withholdingTaxReceipt->number.'.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="wht-'.$withholdingTaxReceipt->number.'.pdf"',
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $receipts = WithholdingTaxReceipt::query()
            ->with(['customer'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('customer'), fn ($q) => $q->where('customer_id', $request->customer))
            ->latest('receipt_date')
            ->get();

        return $this->streamCsv('withholding-tax-receipts-'.now()->format('Y-m-d').'.csv', ['Number', 'Customer', 'Date', 'Amount', 'Rate %', 'Tax amount'], $receipts->map(fn (WithholdingTaxReceipt $r) => [
            $r->number,
            $r->customer?->company_name,
            $r->receipt_date?->format('Y-m-d'),
            $r->amount,
            $r->tax_rate_percent,
            $r->tax_amount,
        ]));
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('sales_customers', 'id')],
            'invoice_id' => ['nullable', 'integer', Rule::exists('sales_invoices', 'id')],
            'receipt_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'tax_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
