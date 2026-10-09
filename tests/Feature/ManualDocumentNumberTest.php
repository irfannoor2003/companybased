<?php

namespace Tests\Feature;

use App\Models\DebitNote;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\SalesCreditNote;
use App\Models\SalesDeliveryNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesPayment;
use App\Models\SalesQuote;
use App\Models\SupplierPayment;
use App\Support\DocumentReference;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Businesses that already issue documents from another system need that system's
 * number on the document — it is the number the customer and the accountant
 * quote. The field is optional and the sequential system number is never
 * replaced by it, because that number drives the counters, the PDF filenames and
 * the exports.
 *
 * These tests pin the two halves of that: the manual number round-trips when
 * given, and the system number is untouched either way.
 */
class ManualDocumentNumberTest extends TestCase
{
    use SeedsDatabase;

    private function buyer()
    {
        return $this->customer('Manual Number Co');
    }

    private function vendor()
    {
        return $this->supplier('Manual Number Vendor');
    }

    private function items(): array
    {
        return [[
            'description' => 'Line',
            'qty' => '1',
            'unit_price' => '100.00',
            'discount_percent' => '0',
            'tax_percent' => '0',
        ]];
    }

    public function test_an_invoice_keeps_its_system_number_when_a_manual_number_is_given(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/invoices', [
                'reference_number' => 'INV-OS-4471',
                'customer_id' => $this->buyer()->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'items' => $this->items(),
            ])
            ->assertSessionHasNoErrors();

        $invoice = SalesInvoice::firstOrFail();

        $this->assertSame('INV-OS-4471', $invoice->reference_number);
        $this->assertNotSame('INV-OS-4471', $invoice->number, 'the sequential number must not be replaced');
        $this->assertStringStartsWith('INV-', $invoice->number);
    }

    public function test_the_system_number_is_used_alone_when_the_field_is_left_blank(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/invoices', [
                'customer_id' => $this->buyer()->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'items' => $this->items(),
            ])
            ->assertSessionHasNoErrors();

        $invoice = SalesInvoice::firstOrFail();

        $this->assertNull($invoice->reference_number);
        $this->assertStringStartsWith('INV-', $invoice->number);
    }

    public function test_a_whitespace_only_manual_number_is_stored_as_absent(): void
    {
        // An empty-but-present value must become NULL, not ''. Two blank
        // submissions would otherwise collide on the unique index.
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/invoices', [
                'reference_number' => '   ',
                'customer_id' => $this->buyer()->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'items' => $this->items(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(SalesInvoice::firstOrFail()->reference_number);
    }

    public function test_two_documents_cannot_share_a_manual_number(): void
    {
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/invoices', [
            'reference_number' => 'INV-OS-4471',
            'customer_id' => $this->buyer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post('/sales/invoices', [
            'reference_number' => 'INV-OS-4471',
            'customer_id' => $this->buyer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasErrors('reference_number');

        $this->assertSame(1, SalesInvoice::count());
    }

    public function test_a_document_may_keep_its_own_manual_number_on_edit(): void
    {
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/invoices', [
            'reference_number' => 'INV-OS-4471',
            'customer_id' => $this->buyer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::firstOrFail();

        // The uniqueness rule ignores the row under edit; without that, saving an
        // unchanged document would fail against itself.
        $this->actingAs($user)->put("/sales/invoices/{$invoice->id}", [
            'reference_number' => 'INV-OS-4471',
            'customer_id' => $invoice->customer_id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('INV-OS-4471', $invoice->fresh()->reference_number);
    }

    public function test_an_edit_can_steal_another_documents_manual_number_but_not_its_own(): void
    {
        $user = $this->userForRole('Admin');
        $customer = $this->buyer();

        foreach (['INV-OS-1', 'INV-OS-2'] as $reference) {
            $this->actingAs($user)->post('/sales/invoices', [
                'reference_number' => $reference,
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'items' => $this->items(),
            ])->assertSessionHasNoErrors();
        }

        [$first, $second] = SalesInvoice::orderBy('id')->get()->all();

        $this->actingAs($user)->put("/sales/invoices/{$second->id}", [
            'reference_number' => 'INV-OS-1',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasErrors('reference_number');

        $this->assertSame('INV-OS-2', $second->fresh()->reference_number, 'the refused edit must change nothing');
        $this->assertSame('INV-OS-1', $first->fresh()->reference_number);
    }

    public function test_clearing_the_manual_number_on_edit_removes_it(): void
    {
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/invoices', [
            'reference_number' => 'INV-OS-4471',
            'customer_id' => $this->buyer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasNoErrors();

        $invoice = SalesInvoice::firstOrFail();

        $this->actingAs($user)->put("/sales/invoices/{$invoice->id}", [
            'reference_number' => '',
            'customer_id' => $invoice->customer_id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasNoErrors();

        $this->assertNull($invoice->fresh()->reference_number);
    }

    public function test_a_manual_number_longer_than_the_column_is_rejected(): void
    {
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/invoices', [
                'reference_number' => str_repeat('X', DocumentReference::MAX_LENGTH + 1),
                'customer_id' => $this->buyer()->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'items' => $this->items(),
            ])
            ->assertSessionHasErrors('reference_number');
    }

    public function test_the_same_manual_number_may_exist_on_different_document_types(): void
    {
        // Uniqueness is per table: a purchase order and an invoice are different
        // documents and may legitimately share a reference.
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/invoices', [
            'reference_number' => 'DOC-1',
            'customer_id' => $this->buyer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post('/suppliers/purchase-invoices', [
            'reference_number' => 'DOC-1',
            'supplier_id' => $this->vendor()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('DOC-1', SalesInvoice::firstOrFail()->reference_number);
        $this->assertSame('DOC-1', PurchaseInvoice::firstOrFail()->reference_number);
    }

    /**
     * Every document type that gained the field, with the minimum payload its
     * store() requires. Keeps the coverage honest as documents are added.
     */
    public static function documentScreens(): array
    {
        return [
            'quote' => ['/sales/quotes', 'sales_quotes', SalesQuote::class],
            'sales order' => ['/sales/orders', 'sales_orders', SalesOrder::class],
            'invoice' => ['/sales/invoices', 'sales_invoices', SalesInvoice::class],
            'credit note' => ['/sales/credit-notes', 'sales_credit_notes', SalesCreditNote::class],
            'delivery note' => ['/sales/delivery-notes', 'sales_delivery_notes', SalesDeliveryNote::class],
            'purchase quote' => ['/suppliers/purchase-quotes', 'purchase_quotes', PurchaseQuote::class],
            'purchase order' => ['/suppliers/purchase-orders', 'purchase_orders', PurchaseOrder::class],
            'purchase invoice' => ['/suppliers/purchase-invoices', 'purchase_invoices', PurchaseInvoice::class],
            'debit note' => ['/suppliers/debit-notes', 'debit_notes', DebitNote::class],
        ];
    }

    #[DataProvider('documentScreens')]
    public function test_every_document_type_accepts_and_persists_a_manual_number(string $route, string $table, string $model): void
    {
        $payload = [
            'reference_number' => 'MANUAL-'.$table,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ];

        // Party keys and a couple of type-specific fields differ per document,
        // as does the status vocabulary: a delivery note has no "draft".
        $payload += match ($table) {
            'sales_quotes', 'sales_orders', 'sales_invoices', 'sales_credit_notes', 'sales_delivery_notes' => [
                'customer_id' => $this->buyer()->id,
            ],
            'purchase_quotes', 'purchase_orders', 'purchase_invoices', 'debit_notes' => [
                'supplier_id' => $this->vendor()->id,
            ],
        };

        if (in_array($table, ['purchase_orders', 'purchase_quotes'], true)) {
            $payload['order_date'] = now()->toDateString();
        }

        if (in_array($table, ['sales_quotes', 'purchase_quotes'], true)) {
            $payload['valid_until'] = now()->addWeek()->toDateString();
        }

        if ($table === 'sales_delivery_notes') {
            $payload['status'] = 'pending';
        }

        $this->actingAs($this->userForRole('Admin'))
            ->post($route, $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'MANUAL-'.$table,
            $model::firstOrFail()->reference_number,
            "{$table} must persist the manual number",
        );
    }

    public function test_a_payment_accepts_a_manual_receipt_number(): void
    {
        $invoice = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->buyer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '500.00',
            'tax_amount' => '0',
            'total' => '500.00',
            'paid_amount' => '0',
        ]);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', [
                'reference_number' => 'RC-OS-1',
                'invoice_id' => $invoice->id,
                'customer_id' => $invoice->customer_id,
                'amount' => '100.00',
                'payment_date' => now()->toDateString(),
                'method' => 'bank_transfer',
            ])
            ->assertSessionHasNoErrors();

        $payment = SalesPayment::firstOrFail();

        $this->assertSame('RC-OS-1', $payment->reference_number);
        $this->assertStringStartsWith('RC-', $payment->number);
    }

    public function test_a_supplier_payment_accepts_a_manual_number(): void
    {
        $invoice = PurchaseInvoice::create([
            'number' => 'PINV-'.uniqid(),
            'supplier_id' => $this->vendor()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '500.00',
            'tax_amount' => '0',
            'total' => '500.00',
            'paid_amount' => '0',
        ]);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/suppliers/supplier-payments', [
                'reference_number' => 'SP-OS-1',
                'invoice_id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'amount' => '100.00',
                'payment_date' => now()->toDateString(),
                'method' => 'bank_transfer',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('SP-OS-1', SupplierPayment::firstOrFail()->reference_number);
    }

    public function test_the_create_forms_expose_the_field(): void
    {
        $user = $this->userForRole('Admin');

        foreach (self::documentScreens() as $label => [$route]) {
            $this->actingAs($user)
                ->get($route.'/create')
                ->assertOk()
                ->assertSee('name="reference_number"', false);
        }
    }

    public function test_the_document_shows_both_numbers(): void
    {
        $user = $this->userForRole('Admin');

        $this->actingAs($user)
            ->post('/sales/quotes', [
                'reference_number' => 'Q-OS-9',
                'customer_id' => $this->buyer()->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'items' => $this->items(),
            ])
            ->assertSessionHasNoErrors();

        $quote = SalesQuote::firstOrFail();

        $html = $this->actingAs($user)
            ->get("/sales/quotes/{$quote->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($quote->number, $html, 'the system number must still be shown');
        $this->assertStringContainsString('Q-OS-9', $html, 'the manual number must be shown');
    }

    public function test_the_invoice_shows_the_manual_number(): void
    {
        $user = $this->userForRole('Admin');

        $this->actingAs($user)
            ->post('/sales/invoices', [
                'reference_number' => 'INV-OS-4471',
                'customer_id' => $this->buyer()->id,
                'issue_date' => now()->toDateString(),
                'status' => 'draft',
                'items' => $this->items(),
            ])
            ->assertSessionHasNoErrors();

        $invoice = SalesInvoice::firstOrFail();

        $html = $this->actingAs($user)
            ->get("/sales/invoices/{$invoice->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('INV-OS-4471', $html);
    }

    public function test_the_edit_form_is_prefilled_with_the_manual_number(): void
    {
        $invoice = SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'reference_number' => 'INV-OS-4471',
            'customer_id' => $this->buyer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
            'paid_amount' => '0',
        ]);

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/invoices/{$invoice->id}/edit")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('INV-OS-4471', $html);
    }

    public function test_a_manual_number_never_breaks_the_sequential_counter(): void
    {
        // The counter must keep advancing independently of anything typed, or a
        // manually-referenced document would shift every number after it.
        $user = $this->userForRole('Admin');
        $customer = $this->buyer();

        $this->actingAs($user)->post('/sales/invoices', [
            'reference_number' => 'MANUAL',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->post('/sales/invoices', [
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'items' => $this->items(),
        ])->assertSessionHasNoErrors();

        $numbers = SalesInvoice::orderBy('id')->pluck('number')->all();

        $this->assertNotSame($numbers[0], $numbers[1], 'the second invoice must get its own sequential number');
    }
}
