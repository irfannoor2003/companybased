<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosPaymentMethod;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosShift;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SaleScreenController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::query()
            ->active()
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$request->q}%")->orWhere('sku', 'like', "%{$request->q}%")->orWhere('barcode', 'like', "%{$request->q}%")) )
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

        $subtotal = 0.0;
        foreach ($data['items'] as $item) {
            $subtotal += (float) $item['qty'] * (float) $item['price'];
        }

        $discount = round((float) ($data['discount'] ?? 0), 2);
        $tax = round((float) ($data['tax'] ?? 0), 2);
        $total = round($subtotal - $discount + $tax, 2);
        $amountPaid = round((float) $data['amount_paid'], 2);
        $changeDue = round(max($amountPaid - $total, 0), 2);

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

        foreach ($data['items'] as $item) {
            $qty = (float) $item['qty'];
            $price = (float) $item['price'];

            PosSaleItem::create([
                'pos_sale_id' => $sale->id,
                'product_id' => $item['product_id'] ?? null,
                'item_name' => $item['name'],
                'quantity' => (string) $qty,
                'unit_price' => (string) $price,
                'line_total' => (string) round($qty * $price, 2),
            ]);
        }

        return redirect()->route('pos.receipts.show', $sale)
            ->with('toasts', [['type' => 'success', 'message' => "Receipt {$sale->receipt_number} issued."]]);
    }
}