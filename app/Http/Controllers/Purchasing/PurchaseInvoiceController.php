<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\InventoryProductionOrder;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseStatusEvent;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\Accounting\LedgerPosting;
use App\Services\NotificationService;
use App\Support\BankLedger;
use App\Support\DiscountLimit;
use App\Support\DocumentItems;
use App\Support\DocumentData;
use App\Support\DocumentReference;
use App\Support\ExportsCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseInvoiceController extends Controller
{
    use DiscountLimit;
    use ExportsCsv;

    public function index(Request $request): View
    {
        $invoices = PurchaseInvoice::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->paginate(20)
            ->withQueryString();

        $suppliers = Supplier::query()->orderBy('company_name')->get();

        return view('suppliers.purchase_invoices.index', compact('invoices', 'suppliers'));
    }

    public function create(Request $request): View
    {
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();

        $fromOrder = null;
        if ($request->filled('order')) {
            $fromOrder = PurchaseOrder::query()->with(['items'])->findOrFail($request->order);
        }

        $fromProduction = null;
        if ($request->filled('production_order')) {
            $fromProduction = InventoryProductionOrder::query()
                ->with(['items.componentItem.product'])
                ->findOrFail($request->production_order);
        }

        // What the tax engine expects on this document's date.

        $taxHint = $this->taxRateHint('purchase', now()->toDateString());


        return view('suppliers.purchase_invoices.create', compact('suppliers', 'products', 'fromOrder', 'fromProduction', 'taxHint'));;
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $invoice = PurchaseInvoice::create([
            'number' => next_document_number('purchase_invoice', 'PIN'),
            'reference_number' => DocumentReference::fromRequest($data['reference_number'] ?? null),
            'order_id' => $data['order_id'] ?? null,
            'production_order_id' => $data['production_order_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->validateTaxRates($request->input('items', []), 'purchase', $data['issue_date'] ?? null);

        $totals = DocumentItems::sync($invoice, $request->input('items', []));
        $invoice->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

        $this->recalculateStatus($invoice);

        // Recognise the liability and the recoverable input tax. Idempotent, and a
        // no-op where the chart of accounts has not been seeded.
        app(LedgerPosting::class)->purchaseBillIssued($invoice->fresh());

        app(NotificationService::class)->notifyStaff(
            'accounting.bills.view',
            'Supplier invoice recorded',
            'Purchase invoice '.$invoice->number.' from '.($invoice->supplier?->company_name ?? 'a supplier').' for '.money($invoice->total, $invoice->currency).' needs review.',
            'info',
            route('suppliers.purchase_invoices.show', $invoice),
            auth()->id(),
        );

        return redirect()->route('suppliers.purchase_invoices.index')
            ->with('toasts', [['type' => 'success', 'message' => "Purchase invoice {$invoice->number} created."]]);
    }

    public function edit(PurchaseInvoice $invoice): View
    {
        $invoice->load(['supplier', 'items.product', 'payments.bankAccount', 'statusEvents.user', 'debitNotes', 'productionOrder']);
        $suppliers = Supplier::query()->orderBy('company_name')->get();
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();
        $bankAccounts = BankAccount::query()->active()->orderBy('name')->get();

        return view('suppliers.purchase_invoices.edit', compact('invoice', 'suppliers', 'products', 'bankAccounts'));
    }

    public function show(PurchaseInvoice $invoice): View
    {
        $invoice->load(['supplier', 'items.product', 'payments.bankAccount', 'statusEvents.user', 'debitNotes', 'productionOrder']);

        return view('suppliers.purchase_invoices.show', compact('invoice'));
    }

    public function pdf(PurchaseInvoice $invoice): Response
    {
        $this->preparePdf();
        $invoice->load(['supplier', 'items.product', 'payments', 'debitNotes', 'productionOrder']);

        // The four purchase-invoice templates are gone. They were independently
        // authored from the sales ones and drifted: a purchase bill printed with
        // a different letterhead, different column rules and a totals block the
        // sales invoice no longer matched. DocumentData already normalises a
        // purchase invoice, so it renders on the one shared document design.
        $html = view('documents.pdf', DocumentData::build($invoice))->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4', 'portrait');

        return $pdf->stream('purchase-invoice-'.$invoice->number.'.pdf');
    }

    public function updateTemplate(Request $request, PurchaseInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'template' => ['required', Rule::in(array_keys(PurchaseInvoice::templateOptions()))],
        ]);

        $invoice->update(['template' => $data['template']]);

        return redirect()->route('suppliers.purchase_invoices.show', $invoice)
            ->with('toasts', [['type' => 'success', 'message' => 'Invoice template set to '.ucfirst($data['template']).'.']]);
    }

    public function update(Request $request, PurchaseInvoice $invoice): RedirectResponse
    {
        $data = $this->validateData($request, $invoice->id);

        $invoice->update([
            'reference_number' => DocumentReference::fromRequest($data['reference_number'] ?? null),
            'order_id' => $data['order_id'] ?? null,
            'production_order_id' => $data['production_order_id'] ?? null,
            'supplier_id' => $data['supplier_id'],
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'],
            'currency' => $data['currency'] ?? null,
            'exchange_rate' => exchange_rate_for($data['currency'] ?? null),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->validateTaxRates($request->input('items', []), 'purchase', $data['issue_date'] ?? null);

        $totals = DocumentItems::sync($invoice, $request->input('items', []));
        $invoice->update(['subtotal' => $totals['subtotal'], 'tax_amount' => $totals['tax'], 'total' => $totals['total']]);

$this->recalculateStatus($invoice);

        // Void the journal raised for the old bill and raise one for the amended
        // figures, so the payable on the ledger follows the document.
        app(LedgerPosting::class)->repost(
            $invoice->fresh(),
            fn ($ledger) => $ledger->purchaseBillIssued($invoice->fresh()),
            'Purchase bill amended',
        );

        return back()->with('toasts', [['type' => 'success', 'message' => "Purchase invoice {$invoice->number} updated."]]);
    }

    public function destroy(PurchaseInvoice $invoice): RedirectResponse
    {
        $number = $invoice->number;

        // Void the bill's journal before the row goes, so the payable does not
        // stay on the books for a bill that no longer exists.
        app(LedgerPosting::class)->reverse($invoice, 'Purchase bill deleted');

        $invoice->delete();

        return redirect()->route('suppliers.purchase_invoices.index')
            ->with('toasts', [['type' => 'success', 'message' => "Purchase invoice {$number} deleted."]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $invoices = PurchaseInvoice::query()
            ->with(['supplier'])
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', "%{$request->search}%"))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('issue_date')
            ->get();

        return $this->streamCsv('purchase-invoices-'.now()->format('Y-m-d').'.csv', ['Number', 'Supplier', 'Issue date', 'Due date', 'Status', 'Subtotal', 'Tax', 'Total', 'Paid', 'Balance'], $invoices->map(fn (PurchaseInvoice $i) => [
            $i->number,
            $i->supplier?->company_name,
            $i->issue_date?->format('Y-m-d'),
            $i->due_date?->format('Y-m-d'),
            ucfirst($i->status),
            $i->subtotal,
            $i->tax_amount,
            $i->total,
            $i->paid_amount,
            $i->balance(),
        ]));
    }

    public function recordPayment(Request $request, PurchaseInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::in(SupplierPayment::methodOptions())],
            'bank_account_id' => ['nullable', 'integer', Rule::exists('bank_accounts', 'id')],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            DB::transaction(function () use ($data, $invoice): void {
                $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
                if ((float) $data['amount'] > $invoice->balance()) {
                    throw new \RuntimeException('Payment exceeds the outstanding balance of '.money($invoice->balance(), $invoice->currency).'.');
                }

                $payment = SupplierPayment::create([
                    'number' => next_document_number('supplier_payment', 'SP', SupplierPayment::class),
                    'invoice_id' => $invoice->id,
                    'supplier_id' => $invoice->supplier_id,
                    'bank_account_id' => $data['bank_account_id'] ?? null,
                    'amount' => $data['amount'],
                    'payment_date' => $data['payment_date'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'currency' => $invoice->currency,
                    'exchange_rate' => $invoice->exchange_rate ?? exchange_rate_for($invoice->currency, $data['payment_date']),
                    'notes' => $data['notes'] ?? null,
                ]);

                // The money left the bank account, so it has to reach the ledger.
                // Both sibling entry points — the standalone supplier payment
                // form and the sales equivalent — post here; this one did not,
                // so a bill paid from its own screen left the bank balance
                // overstating cash and the statement permanently out of balance.
                BankLedger::postDisbursement($payment->load('invoice'));

                // Recomputed from the rows rather than incremented, matching the
                // other two payment paths. An increment here drifts if a payment
                // is ever edited, because nothing else recalculates it.
                $invoice->update([
                    'paid_amount' => round((float) $invoice->payments()->sum('amount'), 2),
                ]);

                $this->recalculateStatus($invoice->refresh());
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()
                ->with('toasts', [['type' => 'danger', 'message' => $e->getMessage()]]);
        }

        app(NotificationService::class)->notifyStaff(
            'suppliers.purchase_invoices.view',
            'Payment made to supplier',
            money($data['amount'], $invoice->currency).' was paid against purchase invoice '.$invoice->number.' to '.($invoice->supplier?->company_name ?? 'a supplier').'.',
            'info',
            route('suppliers.purchase_invoices.show', $invoice),
            auth()->id(),
        );

        return back()->with('toasts', [['type' => 'success', 'message' => 'Payment of '.money($data['amount'], $invoice->currency).' recorded.']]);
    }

    private function recalculateStatus(PurchaseInvoice $invoice): void
    {
        if ($invoice->status === 'cancelled') {
            return;
        }

        $newStatus = $invoice->isPaid()
            ? 'paid'
            : ((float) $invoice->paid_amount > 0 ? 'partially_paid' : $invoice->status);

        if ($newStatus === $invoice->status) {
            return;
        }

        PurchaseStatusEvent::create([
            'trackable_type' => PurchaseInvoice::class,
            'trackable_id' => $invoice->id,
            'from_status' => $invoice->status,
            'to_status' => $newStatus,
            'user_id' => auth()->id(),
            'note' => 'Automatic status update',
        ]);

        $invoice->update(['status' => $newStatus]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'reference_number' => DocumentReference::rules('purchase_invoices', $ignoreId),
            'order_id' => ['nullable', 'integer', Rule::exists('purchase_orders', 'id')],
            'production_order_id' => ['nullable', 'integer', Rule::exists('inventory_production_orders', 'id')],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(PurchaseInvoice::statusOptions())],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            // Optional per line. Constrained to expense-type accounts so a bill
            // cannot be posted into an asset, a liability or revenue.
            'items.*.account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where(
                fn ($q) => $q->whereIn('type', ['expense', 'asset'])->whereNull('deleted_at'),
            )],
        ]);
    }
}
