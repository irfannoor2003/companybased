<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SalesPayment;
use App\Models\SupplierPayment;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Recording a payment writes to sales_payments / supplier_payments and to the
 * invoice's paid_amount, and nowhere else. That left the bank ledger, the bank
 * account balance, reconciliation and the dashboard cash position all blind to
 * money that had actually been received: a receipt with a bank account selected
 * moved nothing in banking at all.
 *
 * BankLedger now posts a leg for each payment, keyed by a polymorphic reference
 * so it can be reversed, and CashFlow excludes those derived legs because the
 * payment itself is already reported there.
 */
class PaymentReachesBankLedgerTest extends TestCase
{
    use SeedsDatabase;

    private function bankAccount(float $opening = 0.00): BankAccount
    {
        return BankAccount::create([
            'name' => 'Operating '.uniqid(),
            'account_type' => 'checking',
            'currency' => 'PKR',
            'opening_balance' => (string) $opening,
            'is_active' => true,
        ]);
    }

    private function invoice(float $total = 1000.00, string $currency = 'PKR'): SalesInvoice
    {
        return SalesInvoice::create([
            'number' => 'INV-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => $currency,
            'exchange_rate' => 1,
            'subtotal' => (string) $total,
            'tax_amount' => '0',
            'total' => (string) $total,
            'paid_amount' => '0',
        ]);
    }

    private function payload(SalesInvoice $invoice, array $overrides = []): array
    {
        return array_merge([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => '100.00',
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
        ], $overrides);
    }

    public function test_a_receipt_appears_on_the_bank_ledger(): void
    {
        $account = $this->bankAccount();
        $invoice = $this->invoice();

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice, ['bank_account_id' => $account->id]))
            ->assertSessionHasNoErrors();

        $transaction = BankTransaction::where('reference_type', SalesPayment::class)->sole();

        $this->assertSame($account->id, $transaction->bank_account_id);
        $this->assertSame('deposit', $transaction->type);
        $this->assertEqualsWithDelta(100.0, (float) $transaction->amount, 0.0005);
        $this->assertSame(SalesPayment::firstOrFail()->id, $transaction->reference_id);
        $this->assertFalse($transaction->is_reconciled, 'an unreconciled receipt must not appear as cleared');
    }

    public function test_a_receipt_moves_the_bank_account_balance(): void
    {
        $account = $this->bankAccount(500.00);
        $invoice = $this->invoice();

        $this->assertEqualsWithDelta(500.0, $account->balance(), 0.0005, 'baseline before any payment');

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice, ['bank_account_id' => $account->id]));

        $this->assertEqualsWithDelta(600.0, $account->fresh()->balance(), 0.0005);
    }

    public function test_a_payment_with_no_bank_account_posts_nothing(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SalesPayment::count(), 'the payment itself is still recorded');
        $this->assertSame(0, BankTransaction::count(), 'with no account there is nothing to post against');
    }

    public function test_a_rejected_payment_posts_nothing(): void
    {
        $account = $this->bankAccount(500.00);
        $invoice = $this->invoice(100.00);

        // Overpayment is refused, so no money arrived and the bank must not move.
        $this->actingAs($this->userForRole('Admin'))
            ->post('/sales/payment-in', $this->payload($invoice, [
                'bank_account_id' => $account->id,
                'amount' => '900.00',
            ]))
            ->assertSessionHas('toasts');

        $this->assertSame(0, BankTransaction::count());
        $this->assertEqualsWithDelta(500.0, $account->fresh()->balance(), 0.0005);
    }

    public function test_editing_a_payment_replaces_its_bank_leg_rather_than_adding_one(): void
    {
        $account = $this->bankAccount();
        $invoice = $this->invoice();
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/payment-in', $this->payload($invoice, ['bank_account_id' => $account->id]));

        $payment = SalesPayment::firstOrFail();

        $this->actingAs($user)->put("/sales/payment-in/{$payment->id}", $this->payload($invoice, [
            'bank_account_id' => $account->id,
            'amount' => '250.00',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, BankTransaction::count(), 'an edit must not leave a stale leg behind');
        $this->assertEqualsWithDelta(250.0, (float) BankTransaction::sole()->amount, 0.0005);
        $this->assertEqualsWithDelta(250.0, $account->fresh()->balance(), 0.0005);
    }

    public function test_moving_a_payment_to_a_different_account_moves_the_money(): void
    {
        $from = $this->bankAccount(1000.00);
        $to = $this->bankAccount();
        $invoice = $this->invoice();
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/payment-in', $this->payload($invoice, ['bank_account_id' => $from->id]));

        $payment = SalesPayment::firstOrFail();

        $this->actingAs($user)->put("/sales/payment-in/{$payment->id}", $this->payload($invoice, [
            'bank_account_id' => $to->id,
        ]))->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(1000.0, $from->fresh()->balance(), 0.0005, 'the old account must be credited back');
        $this->assertEqualsWithDelta(100.0, $to->fresh()->balance(), 0.0005, 'the new account must carry the money');
    }

    public function test_deleting_a_payment_removes_its_bank_leg(): void
    {
        $account = $this->bankAccount(200.00);
        $invoice = $this->invoice();
        $user = $this->userForRole('Admin');

        $this->actingAs($user)
            ->post('/sales/payment-in', $this->payload($invoice, ['bank_account_id' => $account->id]));

        $payment = SalesPayment::firstOrFail();

        $this->actingAs($user)
            ->delete("/sales/payment-in/{$payment->id}")
            ->assertRedirect();

        $this->assertSame(0, BankTransaction::count(), 'a deleted payment must not leave money in the bank');
        $this->assertEqualsWithDelta(200.0, $account->fresh()->balance(), 0.0005);
    }

    public function test_the_inline_invoice_payment_also_reaches_the_bank_ledger(): void
    {
        $account = $this->bankAccount();
        $invoice = $this->invoice();

        $this->actingAs($this->userForRole('Admin'))
            ->post("/sales/invoices/{$invoice->id}/payments", [
                'amount' => '150.00',
                'payment_date' => now()->toDateString(),
                'method' => 'bank_transfer',
                'bank_account_id' => $account->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, BankTransaction::count(), 'the inline path bypasses PaymentController and must post too');
        $this->assertEqualsWithDelta(150.0, $account->fresh()->balance(), 0.0005);
    }

    public function test_a_supplier_payment_posts_a_withdrawal(): void
    {
        $account = $this->bankAccount(1000.00);

        $invoice = PurchaseInvoice::create([
            'number' => 'PINV-'.uniqid(),
            'supplier_id' => $this->supplier('Payment Vendor')->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '400.00',
            'tax_amount' => '0',
            'total' => '400.00',
            'paid_amount' => '0',
        ]);

        $this->actingAs($this->userForRole('Admin'))
            ->post('/suppliers/supplier-payments', [
                'invoice_id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'bank_account_id' => $account->id,
                'amount' => '400.00',
                'payment_date' => now()->toDateString(),
                'method' => 'bank_transfer',
            ])
            ->assertSessionHasNoErrors();

        $transaction = BankTransaction::where('reference_type', SupplierPayment::class)->sole();

        $this->assertSame('withdrawal', $transaction->type, 'paying a supplier takes money out');
        $this->assertEqualsWithDelta(600.0, $account->fresh()->balance(), 0.0005);
    }

    public function test_a_payment_leg_is_distinguishable_from_a_manual_deposit(): void
    {
        // Both are `deposit` rows on the same account, so the reference columns
        // are the only thing that separates an earned receipt from a keyed-in one.
        // CashFlow and any future exclusion depend on that distinction holding.
        $account = $this->bankAccount();
        $invoice = $this->invoice();
        $user = $this->userForRole('Admin');

        $this->actingAs($user)->post('/sales/payment-in', $this->payload($invoice, ['bank_account_id' => $account->id]));

        BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => 'BT-MANUAL',
            'transaction_date' => now()->toDateString(),
            'type' => 'deposit',
            'amount' => '50.00',
            'description' => 'Cash in the door',
            'is_reconciled' => false,
        ]);

        $fromPayment = BankTransaction::where('reference_type', SalesPayment::class)->sole();
        $manual = BankTransaction::whereNull('reference_type')->sole();

        $this->assertSame('deposit', $manual->type);
        $this->assertNull($manual->reference_id);
        $this->assertNotNull($fromPayment->reference_id);
    }

    public function test_cash_flow_counts_a_receipt_once_not_twice(): void
    {
        $account = $this->bankAccount();
        $invoice = $this->invoice(500.00);
        $user = $this->userForRole('Admin');

        $this->actingAs($user)
            ->post('/sales/payment-in', $this->payload($invoice, ['bank_account_id' => $account->id]))
            ->assertSessionHasNoErrors();

        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $html = $this->actingAs($user)
            ->get("/cash-flow/inflows?from={$from}&to={$to}")
            ->assertOk()
            ->getContent();

        $payment = SalesPayment::firstOrFail();
        $amount = money($payment->amount, $payment->currency);

        // The row itself plus the period total, and no third appearance. A
        // duplicate would come from the derived bank leg being counted alongside
        // the payment, which is the bug the exclusion exists to prevent.
        $this->assertSame(
            2,
            substr_count($html, $amount),
            'the receipt must appear once as a payment row and once in the period total',
        );

        $this->assertStringNotContainsString(
            'Bank deposit',
            $html,
            'a receipt must not also be listed as a keyed-in bank deposit',
        );
    }
}
