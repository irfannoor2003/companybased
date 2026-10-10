<?php
namespace Tests\Feature;

use App\Models\Account;
use App\Models\PosPaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Services\Accounting\LedgerPosting;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The till has to appear in the cash flow statement.
 *
 * A POS sale taken in cash posts a debit to account 1000 and never touches
 * `bank_transactions`, which is all the cash flow used to read. Till takings were
 * therefore invisible: a shop trading mostly over the counter reported a cash flow
 * with no income in it, and its cash position did not reconcile to the trial
 * balance.
 *
 * Card sales must NOT appear here — they clear through a bank account and are
 * already reported with the bank rows, so counting both double-counts.
 */
class PosCashFlowTest extends TestCase
{
    use SeedsDatabase;

    /**
     * Memoised: `pos_payment_methods.code` is unique, so a test that makes two
     * sales must reuse the one method rather than insert it twice.
     */
    private ?PosPaymentMethod $cash = null;

    private ?PosPaymentMethod $card = null;

    private function cashMethod(): PosPaymentMethod
    {
        return $this->cash ??= PosPaymentMethod::create([
            'code' => 'CASH',
            'name' => 'Cash',
            'is_cash' => true,
            'is_active' => true,
        ]);
    }

    private function cardMethod(): PosPaymentMethod
    {
        return $this->card ??= PosPaymentMethod::create([
            'code' => 'CARD',
            'name' => 'Card',
            'is_cash' => false,
            'is_active' => true,
        ]);
    }

    private function shift(): PosShift
    {
        $open = PosShift::query()->whereNull('closed_at')->first();

        if ($open) {
            return $open;
        }

        // Who opened the till is part of the shift record, not optional. The seeded
        // Admin is the actor; the test is about cash, not about roles.
        $actor = \App\Models\User::query()->first();

        return PosShift::create([
            'shift_number' => 'SH-'.uniqid(),
            'opened_by' => $actor->id,
            'opened_at' => now()->subHour(),
            'opening_cash' => '0',
        ]);
    }

    private function sale(PosPaymentMethod $method, float $total): PosSale
    {
        return PosSale::create([
            'receipt_number' => 'RCPT-'.uniqid(),
            // NOT NULL: a sale belongs to a till session.
            'shift_id' => $this->shift()->id,
            'customer_name' => 'Walk-in',
            'subtotal' => (string) $total,
            'discount' => '0',
            'tax' => '0',
            'total' => (string) $total,
            'payment_method_id' => $method->id,
            'amount_paid' => (string) $total,
            'change_due' => '0',
            'status' => 'completed',
            'sold_at' => now()->subMinute(),
        ]);
    }

    private function controller(): \App\Http\Controllers\CashFlowController
    {
        return new \App\Http\Controllers\CashFlowController();
    }

    private function cashAndBank(): float
    {
        $controller = $this->controller();
        $method = new \ReflectionMethod($controller, 'cashAndBank');
        $method->setAccessible(true);

        return (float) $method->invoke($controller);
    }

    /**
     * The whole claim: a cash sale is money in the till and shows up.
     */
    public function test_a_cash_till_sale_appears_in_cash_and_bank(): void
    {
        $before = $this->cashAndBank();

        // Post it the way the POS does, so the ledger and the sale agree.
        $sale = $this->sale($this->cashMethod(), 5000.00);
        app(LedgerPosting::class)->posSaleCompleted($sale, LedgerPosting::CASH);

        $after = $this->cashAndBank();

        $this->assertSame(
            round($before + 5000.00, 2),
            round($after, 2),
            'a cash POS sale did not increase the reported cash position',
        );
    }

    /**
     * And it reconciles: the reported cash has to equal what account 1000 says,
     * because that is the number the trial balance is built from.
     */
    public function test_cash_flow_cash_reconciles_to_the_ledger(): void
    {
        $this->sale($this->cashMethod(), 7500.00);
        app(LedgerPosting::class)->posSaleCompleted(
            $this->sale($this->cashMethod(), 2500.00),
            LedgerPosting::CASH,
        );

        $ledgerCash = Account::where('code', LedgerPosting::CASH)->first()?->balance() ?? 0.0;

        // Debit-positive: cash is debited by a cash sale, so the balance rises.
        $this->assertGreaterThan(0, $ledgerCash, 'the ledger recorded no cash for the POS sales');

        $this->assertSame(
            round($ledgerCash, 2),
            round($this->cashAndBank(), 2),
            'the cash flow statement disagrees with the ledger on cash',
        );
    }

    /**
     * A card sale must not appear as till cash, or the same money is counted
     * once against the bank and once against the counter.
     */
    public function test_a_card_sale_does_not_inflate_cash_and_bank(): void
    {
        $before = $this->cashAndBank();

        // A card sale settles to the BANK account, not the till -- which is the
        // whole reason it must not be counted here as well.
        app(LedgerPosting::class)->posSaleCompleted(
            $this->sale($this->cardMethod(), 9000.00),
            LedgerPosting::BANK,
        );

        $this->assertSame(
            round($before, 2),
            round($this->cashAndBank(), 2),
            'a card sale was counted as till cash',
        );
    }

    /**
     * A cancelled sale never happened, so it cannot appear as income.
     */
    public function test_a_cancelled_sale_is_not_counted(): void
    {
        $before = $this->cashAndBank();

        $sale = $this->sale($this->cashMethod(), 4000.00);
        $sale->update(['status' => 'cancelled']);

        $this->assertSame(
            round($before, 2),
            round($this->cashAndBank(), 2),
            'a cancelled sale was counted as cash',
        );
    }
}
