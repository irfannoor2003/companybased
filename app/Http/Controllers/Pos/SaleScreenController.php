<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\InventoryWarehouse;
use App\Models\PosPaymentMethod;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosShift;
use App\Models\Product;
use App\Support\InventoryLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SaleScreenController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::query()
            ->active()
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$request->q}%")->orWhere('sku', 'like', "%{$request->q}%")->orWhere('barcode', 'like', "%{$request->q}%")))
            ->orderBy('name')
            ->limit(60)
            ->get();

        $paymentMethods = PosPaymentMethod::query()->where('is_active', true)->orderBy('name')->get();

        $openShift = PosShift::query()->where('status', 'open')->latest('opened_at')->first();

        return view('pos.sale-screen', compact('products', 'paymentMethods', 'openShift'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.name' => ['required', 'string', 'max:190'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'payment_method_id' => ['nullable', 'integer', 'exists:pos_payment_methods,id'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $shift = PosShift::query()->where('status', 'open')->latest('opened_at')->first();

        if (! $shift) {
            return back()->with('toasts', [['type' => 'error', 'message' => 'Open a shift before making a sale.']]);
        }

        // Each line is rounded to the currency's 2dp before being summed, so the
        // header subtotal always equals the sum of the printed line totals. A
        // float accumulated across unrounded lines can drift by a cent and
        // produce a receipt that does not add up to its own total.
        $lines = [];
        $subtotal = 0.0;

        foreach ($data['items'] as $item) {
            $qty = (float) $item['qty'];
            $price = round((float) $item['price'], 2);
            $lineTotal = round($qty * $price, 2);

            $lines[] = $item + ['qty' => $qty, 'price' => $price, 'line_total' => $lineTotal];
            $subtotal += $lineTotal;
        }

        $subtotal = round($subtotal, 2);
        $discount = round((float) ($data['discount'] ?? 0), 2);
        $tax = round((float) ($data['tax'] ?? 0), 2);

        // A discount larger than the subtotal would persist a negative total.
        if ($discount > $subtotal) {
            return back()->withInput()->with('toasts', [['type' => 'error', 'message' => 'The discount cannot exceed the subtotal.']]);
        }

        $total = round($subtotal - $discount + $tax, 2);
        $amountPaid = round((float) $data['amount_paid'], 2);
        $changeDue = round(max($amountPaid - $total, 0), 2);

        // POS stock is real stock: every tracked line decrements the ledger, so
        // on-hand can no longer drift upward after a till sale. The ledger
        // refuses to go negative, which surfaces as a validation message rather
        // than silently overselling.
        // inventory_warehouses has no is_default column, so the first active
        // warehouse is the POS default. Deliberate: a sale must always post
        // somewhere rather than silently skipping the stock movement.
        $warehouse = InventoryWarehouse::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        $tracked = $warehouse
            ? Product::query()
                ->whereIn('id', array_filter(array_column($lines, 'product_id')))
                ->with('inventoryItem')
                ->get()
                ->filter(fn (Product $p) => $p->inventoryItem)
                ->keyBy('id')
            : collect();

        try {
            $sale = DB::transaction(function () use ($data, $shift, $subtotal, $discount, $tax, $total, $amountPaid, $changeDue, $lines, $warehouse, $tracked): PosSale {
                $sale = PosSale::create([
                    'receipt_number' => next_document_number('pos_receipt', 'RC'),
                    'shift_id' => $shift->id,
                    'customer_name' => $data['customer_name'] ?? null,
                    'subtotal' => (string) $subtotal,
                    'discount' => (string) $discount,
                    'tax' => (string) $tax,
                    'total' => (string) $total,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'amount_paid' => (string) $amountPaid,
                    'change_due' => (string) $changeDue,
                    'status' => 'completed',
                    'sold_at' => now(),
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($lines as $line) {
                    PosSaleItem::create([
                        'pos_sale_id' => $sale->id,
                        'product_id' => $line['product_id'] ?? null,
                        'item_name' => $line['name'],
                        'quantity' => (string) $line['qty'],
                        'unit_price' => (string) $line['price'],
                        'line_total' => (string) $line['line_total'],
                    ]);

                    $item = $line['product_id'] ? $tracked->get($line['product_id'])?->inventoryItem : null;

                    if ($item && $warehouse) {
                        InventoryLedger::adjust(
                            $item->id,
                            $warehouse->id,
                            '-'.$line['qty'],
                            'pos_sale',
                            $sale,
                            "POS {$sale->receipt_number}",
                        );
                    }
                }

                return $sale;
            });
        } catch (\DomainException $e) {
            return back()->withInput()->with('toasts', [['type' => 'error', 'message' => $e->getMessage()]]);
        }

        return redirect()->route('pos.receipts.show', $sale)
            ->with('toasts', [['type' => 'success', 'message' => "Receipt {$sale->receipt_number} issued."]]);
    }
}
