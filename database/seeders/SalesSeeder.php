<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\SalesCreditNote;
use App\Models\SalesCustomer;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesPayment;
use App\Models\SalesQuote;
use App\Models\SalesRecurringInvoice;
use App\Models\SalesStatusEvent;
use App\Models\WithholdingTaxReceipt;
use Illuminate\Database\Seeder;

class SalesSeeder extends Seeder
{
    /**
     * Demo sales data: customers, quotes, orders, invoices, payments,
     * credit notes, delivery notes, recurring templates and a WHT receipt.
     * Money stays in decimal strings — never floats.
     */
    public function run(): void
    {
        if (SalesCustomer::exists() || SalesQuote::exists()) {
            return;
        }

        $products = Product::query()->get();

        $customers = collect([
            ['company_name' => 'Acme Traders LLC', 'contact_name' => 'Jane Doe', 'email' => 'billing@acme.test', 'phone' => '+1 555 0100', 'city' => 'New York', 'country' => 'USA', 'tax_number' => 'US-1294001', 'credit_limit' => 25000],
            ['company_name' => 'Beacon Supplies', 'contact_name' => 'John Smith', 'email' => 'john@beaconsupplies.test', 'phone' => '+1 555 0111', 'city' => 'Boston', 'country' => 'USA', 'tax_number' => 'US-2199002', 'credit_limit' => 12000],
            ['company_name' => 'Vertex Hardware', 'contact_name' => 'Alice Brown', 'email' => 'purchasing@vertexhw.test', 'phone' => '+44 20 7946 0000', 'city' => 'London', 'country' => 'UK', 'tax_number' => 'GB-550013', 'credit_limit' => 0],
            ['company_name' => 'Crestline Co.', 'contact_name' => 'Robert Green', 'email' => 'orders@crestline.test', 'phone' => '+1 555 0122', 'city' => 'Chicago', 'country' => 'USA', 'tax_number' => 'US-3102201', 'credit_limit' => 8000],
            ['company_name' => 'Nova Retail', 'contact_name' => 'Maria Lopez', 'email' => 'maria@novaretail.test', 'phone' => '+34 91 123 4567', 'city' => 'Madrid', 'country' => 'Spain', 'tax_number' => 'ES-B1234567', 'credit_limit' => 0],
            ['company_name' => 'Summit Trading', 'contact_name' => 'David Kim', 'email' => 'david@summittrade.test', 'phone' => '+1 555 0133', 'city' => 'Seattle', 'country' => 'USA', 'tax_number' => 'US-4299003', 'credit_limit' => 15000],
            ['company_name' => 'Lakeside Foods', 'contact_name' => 'Sarah Chen', 'email' => 'sarah@lakesidefoods.test', 'phone' => '+1 555 0144', 'city' => 'Denver', 'country' => 'USA', 'tax_number' => 'US-3382004', 'credit_limit' => 5000],
            ['company_name' => 'Granite Works', 'contact_name' => 'Omar Haddad', 'email' => 'omar@graniteworks.test', 'phone' => '+971 4 123 4567', 'city' => 'Dubai', 'country' => 'UAE', 'tax_number' => 'AE-1002241', 'credit_limit' => 0],
        ])->map(fn (array $def) => SalesCustomer::create($def + [
            'mobile' => null,
            'address' => null,
            'price_list_id' => null,
            'currency' => 'USD',
            'is_active' => true,
            'notes' => 'Demo customer created by SalesSeeder.',
        ]));

        $pick = fn () => $products->random(min(3, $products->count()))->all();

        // Quote 1 — draft, will later be sent.
        $quote = $this->makeDocument(SalesQuote::class, next_document_number('quote', 'Q'), [
            'customer_id' => $customers[0]->id,
            'issue_date' => now()->subDays(4)->toDateString(),
            'valid_until' => now()->addDays(26)->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'notes' => 'Initial proposal for the annual tooling order.',
        ], $pick());
        $this->events($quote, 'draft');

        // Quote 2 — accepted (ready to convert).
        $quote2 = $this->makeDocument(SalesQuote::class, next_document_number('quote', 'Q'), [
            'customer_id' => $customers[1]->id,
            'issue_date' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->addDays(20)->toDateString(),
            'status' => 'accepted',
            'currency' => 'USD',
            'notes' => 'Accepted by customer.',
        ], $pick());
        $this->events($quote2, 'draft');
        $this->events($quote2, 'sent');
        $this->events($quote2, 'accepted');

        // Quote 3 — converted into an order.
        $quote3 = $this->makeDocument(SalesQuote::class, next_document_number('quote', 'Q'), [
            'customer_id' => $customers[2]->id,
            'issue_date' => now()->subDays(14)->toDateString(),
            'valid_until' => now()->addDays(16)->toDateString(),
            'status' => 'converted',
            'currency' => 'USD',
        ], $pick());
        $this->events($quote3, 'draft');
        $this->events($quote3, 'sent');
        $this->events($quote3, 'accepted');

        // Order 1 — confirmed, fulfilment in progress.
        $order = $this->makeDocument(SalesOrder::class, next_document_number('order', 'SO'), [
            'customer_id' => $customers[3]->id,
            'issue_date' => now()->subDays(6)->toDateString(),
            'expected_delivery_date' => now()->addDays(2)->toDateString(),
            'status' => 'confirmed',
            'currency' => 'USD',
            'shipping_address' => '1210 Warehouse Ave, Chicago',
            'notes' => 'Confirm stock before packing.',
        ], $pick());
        $this->events($order, 'draft');
        $this->events($order, 'confirmed');

        // Order 2 — delivered (from converted quote 3).
        $order2 = $this->makeDocument(SalesOrder::class, next_document_number('order', 'SO'), [
            'quote_id' => $quote3->id,
            'customer_id' => $customers[2]->id,
            'issue_date' => now()->subDays(14)->toDateString(),
            'expected_delivery_date' => now()->subDays(1)->toDateString(),
            'status' => 'delivered',
            'currency' => 'USD',
            'shipping_address' => '10 Fleet Street, London',
            'notes' => 'Converted from quote '.$quote3->number.'.',
        ], $quote3->items()->get(['product_id', 'description', 'qty', 'unit_price', 'discount_percent', 'tax_percent', 'line_total'])->toArray());
        $quote3->update(['converted_to_order_id' => $order2->id]);
        $this->events($order2, 'draft');
        $this->events($order2, 'confirmed');
        $this->events($order2, 'packed');
        $this->events($order2, 'shipped');
        $this->events($order2, 'delivered');

        // Invoice 1 — paid in full.
        $inv1 = $this->makeDocument(SalesInvoice::class, next_document_number('invoice', 'INV'), [
            'customer_id' => $customers[2]->id,
            'issue_date' => now()->subDays(12)->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'status' => 'paid',
            'currency' => 'USD',
            'notes' => 'Order '.$order2->number,
        ], $order2->items()->get(['product_id', 'description', 'qty', 'unit_price', 'discount_percent', 'tax_percent', 'line_total'])->toArray());
        SalesPayment::create([
            'number' => next_document_number('sales_payment', 'RC'),
            'invoice_id' => $inv1->id,
            'customer_id' => $inv1->customer_id,
            'amount' => $inv1->total,
            'payment_date' => now()->subDays(2)->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'TRF-881234',
            'currency' => 'USD',
        ]);
        $inv1->update(['paid_amount' => $inv1->total]);
        $this->events($inv1, 'sent');
        $this->events($inv1, 'paid');

        // Invoice 2 — partially paid.
        $inv2 = $this->makeDocument(SalesInvoice::class, next_document_number('invoice', 'INV'), [
            'customer_id' => $customers[4]->id,
            'issue_date' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
            'status' => 'partially_paid',
            'currency' => 'USD',
        ], $pick());
        $partial = round((float) $inv2->total * 0.4, 2);
        SalesPayment::create([
            'number' => next_document_number('sales_payment', 'RC'),
            'invoice_id' => $inv2->id,
            'customer_id' => $inv2->customer_id,
            'amount' => $partial,
            'payment_date' => now()->subDays(10)->toDateString(),
            'method' => 'cash',
            'reference' => 'POS-4477',
            'currency' => 'USD',
        ]);
        $inv2->update(['paid_amount' => $partial]);
        $this->events($inv2, 'sent');
        $this->events($inv2, 'partially_paid');

        // Invoice 3 — unpaid, now overdue.
        $inv3 = $this->makeDocument(SalesInvoice::class, next_document_number('invoice', 'INV'), [
            'customer_id' => $customers[5]->id,
            'issue_date' => now()->subDays(45)->toDateString(),
            'due_date' => now()->subDays(15)->toDateString(),
            'status' => 'overdue',
            'currency' => 'USD',
            'notes' => 'Chase payment on this invoice.',
        ], $pick());
        $this->events($inv3, 'sent');
        $this->events($inv3, 'overdue');

        // Credit note against invoice 2 (return of damaged goods).
        $note = $this->makeDocument(SalesCreditNote::class, next_document_number('credit_note', 'CN'), [
            'invoice_id' => $inv2->id,
            'customer_id' => $inv2->customer_id,
            'issue_date' => now()->subDays(3)->toDateString(),
            'reason' => 'Damaged goods returned',
            'currency' => 'USD',
        ], $pick());
        $note->update(['applied_amount' => round((float) $note->total * 0.5, 2)]);

        // Delivery note 1 — pending, tied to order 1.
        $dn1 = SalesDeliveryNote::create([
            'number' => next_document_number('delivery_note', 'DN'),
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'issue_date' => now()->toDateString(),
            'status' => 'pending',
            'shipping_address' => $order->shipping_address,
            'carrier' => 'DHL',
            'tracking_number' => 'DHL-500-8899',
            'notes' => 'Awaiting pack.',
        ]);
        $dn1->items()->createMany($order->items()->get(['product_id', 'description', 'qty'])->toArray());
        $this->events($dn1, 'pending');

        // Delivery note 2 — shipped.
        $dn2 = SalesDeliveryNote::create([
            'number' => next_document_number('delivery_note', 'DN'),
            'order_id' => $order2->id,
            'customer_id' => $order2->customer_id,
            'issue_date' => now()->subDays(3)->toDateString(),
            'status' => 'shipped',
            'shipping_address' => $order2->shipping_address,
            'carrier' => 'FedEx',
            'tracking_number' => 'FDX-9101234567',
            'notes' => 'In transit to London.',
        ]);
        $dn2->items()->createMany($order2->items()->get(['product_id', 'description', 'qty'])->toArray());
        $this->events($dn2, 'pending');
        $this->events($dn2, 'packed');
        $this->events($dn2, 'shipped');

        // Recurring invoice — monthly hosting.
        $recurring = SalesRecurringInvoice::create([
            'customer_id' => $customers[0]->id,
            'name' => 'Monthly support retainer',
            'frequency' => 'monthly',
            'next_run_date' => now()->startOfMonth()->addMonth()->toDateString(),
            'day_of_cycle' => 1,
            'currency' => 'USD',
            'is_active' => true,
            'notes' => 'Billed on the first of every month.',
        ]);
        $first = $products->first();
        $recurring->items()->createMany([
            [
                'product_id' => $first->id,
                'description' => 'Support retainer (monthly)',
                'qty' => 1,
                'unit_price' => 500,
                'discount_percent' => 0,
                'tax_percent' => 0,
                'line_total' => 500,
            ],
        ]);
        $recurring->update(['subtotal' => 500, 'tax_amount' => 0, 'total' => 500]);

        // Withholding tax receipt.
        WithholdingTaxReceipt::create([
            'number' => next_document_number('withholding_tax_receipt', 'WHT'),
            'customer_id' => $customers[2]->id,
            'invoice_id' => $inv1->id,
            'receipt_date' => now()->subDays(2)->toDateString(),
            'amount' => $inv1->total,
            'tax_rate_percent' => 5,
            'tax_amount' => round((float) $inv1->total * 0.05, 2),
            'currency' => 'USD',
            'notes' => '5% withholding retained per tax office directive.',
        ]);
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
            $unitPrice = (float) ($item['unit_price'] ?? $item['retail_price'] ?? 0);
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
        SalesStatusEvent::create([
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
