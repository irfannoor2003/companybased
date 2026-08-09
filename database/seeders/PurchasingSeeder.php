<?php

namespace Database\Seeders;

use App\Models\DebitNote;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\PurchaseStatusEvent;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Database\Seeder;

class PurchasingSeeder extends Seeder
{
    /**
     * Demo purchasing data: suppliers, purchase quotes, orders, invoices,
     * debit notes and payments. Money stays in decimal strings — never floats.
     */
    public function run(): void
    {
        if (Supplier::exists() || PurchaseQuote::exists()) {
            return;
        }

        $products = Product::query()->get();

        $suppliers = collect([
            ['company_name' => 'Bluepine Industrial', 'contact_name' => 'Oliver West', 'email' => 'sales@bluepine.test', 'phone' => '+1 555 2000', 'city' => 'Chicago', 'country' => 'USA', 'tax_number' => 'US-8802301', 'payment_terms' => 'net 30'],
            ['company_name' => 'Meridian Parts', 'contact_name' => 'Priya Nair', 'email' => 'orders@meridianparts.test', 'phone' => '+44 20 7946 0101', 'city' => 'Manchester', 'country' => 'UK', 'tax_number' => 'GB-771234', 'payment_terms' => 'net 45'],
            ['company_name' => 'Northwind Goods', 'contact_name' => 'Lars Olsen', 'email' => 'contact@northwind.test', 'phone' => '+45 32 12 34 56', 'city' => 'Copenhagen', 'country' => 'Denmark', 'tax_number' => 'DK-99887766', 'payment_terms' => 'net 14'],
            ['company_name' => 'Apex Components', 'contact_name' => 'Grace Liu', 'email' => 'grace@apexcomp.test', 'phone' => '+1 555 2022', 'city' => 'Austin', 'country' => 'USA', 'tax_number' => 'US-9911204', 'payment_terms' => 'net 30'],
            ['company_name' => 'Summit Packaging', 'contact_name' => 'Tom Becker', 'email' => 'orders@summitpkg.test', 'phone' => '+49 30 901820', 'city' => 'Berlin', 'country' => 'Germany', 'tax_number' => 'DE-112233445', 'payment_terms' => 'net 30'],
        ])->map(fn (array $def) => Supplier::create($def + [
            'mobile' => null,
            'address' => null,
            'currency' => 'USD',
            'is_active' => true,
            'notes' => 'Demo supplier created by PurchasingSeeder.',
        ]));

        $pick = fn () => $products->random(min(3, $products->count()))->all();

        // Purchase quote 1 — draft.
        $quote = $this->makeDocument(PurchaseQuote::class, next_document_number('purchase_quote', 'PQ'), [
            'supplier_id' => $suppliers[0]->id,
            'issue_date' => now()->subDays(3)->toDateString(),
            'valid_until' => now()->addDays(27)->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'notes' => 'Requested pricing for the quarterly restock.',
        ], $pick());
        $this->events($quote, 'draft');

        // Purchase quote 2 — accepted.
        $quote2 = $this->makeDocument(PurchaseQuote::class, next_document_number('purchase_quote', 'PQ'), [
            'supplier_id' => $suppliers[1]->id,
            'issue_date' => now()->subDays(9)->toDateString(),
            'valid_until' => now()->addDays(21)->toDateString(),
            'status' => 'accepted',
            'currency' => 'USD',
            'notes' => 'Accepted — best total landed cost.',
        ], $pick());
        $this->events($quote2, 'draft');
        $this->events($quote2, 'sent');
        $this->events($quote2, 'accepted');

        // Purchase quote 3 — converted into an order.
        $quote3 = $this->makeDocument(PurchaseQuote::class, next_document_number('purchase_quote', 'PQ'), [
            'supplier_id' => $suppliers[3]->id,
            'issue_date' => now()->subDays(13)->toDateString(),
            'valid_until' => now()->addDays(17)->toDateString(),
            'status' => 'converted',
            'currency' => 'USD',
        ], $pick());
        $this->events($quote3, 'draft');
        $this->events($quote3, 'sent');
        $this->events($quote3, 'accepted');

        // Purchase order 1 — confirmed, awaiting delivery.
        $order = $this->makeDocument(PurchaseOrder::class, next_document_number('purchase_order', 'POR'), [
            'supplier_id' => $suppliers[2]->id,
            'order_date' => now()->subDays(5)->toDateString(),
            'expected_delivery_date' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
            'currency' => 'USD',
            'shipping_address' => '14 Harbour Way, Copenhagen',
            'notes' => 'Confirm freight cost before dispatch.',
        ], $pick());
        $this->events($order, 'draft');
        $this->events($order, 'sent');
        $this->events($order, 'confirmed');

        // Purchase order 2 — received (converted from quote 3).
        $order2 = $this->makeDocument(PurchaseOrder::class, next_document_number('purchase_order', 'POR'), [
            'quote_id' => $quote3->id,
            'supplier_id' => $suppliers[3]->id,
            'order_date' => now()->subDays(13)->toDateString(),
            'expected_delivery_date' => now()->subDays(1)->toDateString(),
            'status' => 'received',
            'currency' => 'USD',
            'shipping_address' => '2200 Tech Park Blvd, Austin',
            'notes' => 'Converted from purchase quote '.$quote3->number.'.',
        ], $quote3->items()->get(['product_id', 'description', 'qty', 'unit_price', 'discount_percent', 'tax_percent', 'line_total'])->toArray());
        $quote3->update(['converted_to_order_id' => $order2->id]);
        $order2->items()->update(['received_qty' => $order2->items()->sum('qty')]);
        $this->events($order2, 'draft');
        $this->events($order2, 'sent');
        $this->events($order2, 'confirmed');
        $this->events($order2, 'received');

        // Purchase invoice 1 — paid in full.
        $inv1 = $this->makeDocument(PurchaseInvoice::class, next_document_number('purchase_invoice', 'PIN'), [
            'supplier_id' => $suppliers[2]->id,
            'issue_date' => now()->subDays(11)->toDateString(),
            'due_date' => now()->addDays(4)->toDateString(),
            'status' => 'paid',
            'currency' => 'USD',
            'notes' => 'Invoice '.$order->number,
        ], $order->items()->get(['product_id', 'description', 'qty', 'unit_price', 'discount_percent', 'tax_percent', 'line_total'])->toArray());
        SupplierPayment::create([
            'number' => next_document_number('supplier_payment', 'SP'),
            'invoice_id' => $inv1->id,
            'supplier_id' => $inv1->supplier_id,
            'amount' => $inv1->total,
            'payment_date' => now()->subDays(2)->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'PAY-77210',
            'currency' => 'USD',
        ]);
        $inv1->update(['paid_amount' => $inv1->total]);
        $this->events($inv1, 'sent');
        $this->events($inv1, 'paid');

        // Purchase invoice 2 — partially paid.
        $inv2 = $this->makeDocument(PurchaseInvoice::class, next_document_number('purchase_invoice', 'PIN'), [
            'supplier_id' => $suppliers[4]->id,
            'issue_date' => now()->subDays(19)->toDateString(),
            'due_date' => now()->subDays(4)->toDateString(),
            'status' => 'partially_paid',
            'currency' => 'USD',
        ], $pick());
        $partial = round((float) $inv2->total * 0.5, 2);
        SupplierPayment::create([
            'number' => next_document_number('supplier_payment', 'SP'),
            'invoice_id' => $inv2->id,
            'supplier_id' => $inv2->supplier_id,
            'amount' => $partial,
            'payment_date' => now()->subDays(9)->toDateString(),
            'method' => 'cheque',
            'reference' => 'CHQ-9912',
            'currency' => 'USD',
        ]);
        $inv2->update(['paid_amount' => $partial]);
        $this->events($inv2, 'sent');
        $this->events($inv2, 'partially_paid');

        // Purchase invoice 3 — unpaid, overdue.
        $inv3 = $this->makeDocument(PurchaseInvoice::class, next_document_number('purchase_invoice', 'PIN'), [
            'supplier_id' => $suppliers[1]->id,
            'issue_date' => now()->subDays(42)->toDateString(),
            'due_date' => now()->subDays(12)->toDateString(),
            'status' => 'overdue',
            'currency' => 'USD',
            'notes' => 'Follow up with supplier on this invoice.',
        ], $pick());
        $this->events($inv3, 'sent');
        $this->events($inv3, 'overdue');

        // Debit note against invoice 2 (short delivery).
        $note = $this->makeDocument(DebitNote::class, next_document_number('debit_note', 'DBN'), [
            'invoice_id' => $inv2->id,
            'supplier_id' => $inv2->supplier_id,
            'issue_date' => now()->subDays(2)->toDateString(),
            'reason' => 'Short delivery',
            'currency' => 'USD',
        ], $pick());
        $note->update(['applied_amount' => round((float) $note->total * 0.3, 2)]);
    }

    /**
     * Create a document with items and computed money totals.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     */
    private function makeDocument(string $model, string $number, array $attributes, array $rawItems): mixed
    {
        $document = $model::create($attributes + ['number' => $number]);

        $cleaned = [];
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($rawItems as $item) {
            $description = $item['description'] ?? $item['name'] ?? 'Demo line';
            $qty = (float) ($item['qty'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? $item['retail_price'] ?? $item['cost_price'] ?? 0);
            $discount = (float) ($item['discount_percent'] ?? 0);
            $taxPercent = (float) ($item['tax_percent'] ?? 0);

            $lineNet = round($qty * $unitPrice * (1 - $discount / 100), 2);
            $lineTax = round($lineNet * ($taxPercent / 100), 2);

            $cleaned[] = [
                'product_id' => $item['product_id'] ?? null,
                'description' => $description,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount_percent' => $discount,
                'tax_percent' => $taxPercent,
                'line_total' => $lineNet,
            ];

            $subtotal += $lineNet;
            $tax += $lineTax;
        }

        $document->items()->createMany($cleaned);
        $document->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($tax, 2),
            'total' => round($subtotal + $tax, 2),
        ]);

        return $document;
    }

    private function events($trackable, string $toStatus, ?string $note = null): void
    {
        PurchaseStatusEvent::create([
            'trackable_type' => $trackable->getMorphClass(),
            'trackable_id' => $trackable->id,
            'from_status' => $trackable->status === $toStatus ? null : $trackable->status,
            'to_status' => $toStatus,
            'user_id' => null,
            'note' => $note,
        ]);
        $trackable->update(['status' => $toStatus]);
    }
}
