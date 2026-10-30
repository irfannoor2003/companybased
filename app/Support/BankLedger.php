<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\BankTransfer;
use App\Models\ExchangeRate;
use App\Models\SalesPayment;
use App\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Posts money movements to bank transaction ledgers. Transfers always move
 * as two atomic legs (transfer_out on source, transfer_in on destination)
 * and are fully reversible. Amounts stay decimal strings — never floats.
 *
 * Invoice payments post here too. A receipt is real money landing in a real
 * account, so leaving it out of bank_transactions made the bank ledger, the
 * account balance and reconciliation all disagree with what had actually been
 * received. Each posted row is stamped with the originating payment as a
 * polymorphic reference, which is what lets it be reversed on edit or delete
 * and what stops CashFlow counting the same receipt twice.
 */
class BankLedger
{
    public static function postTransfer(BankTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            $transfer->fromAccount?->load('transactions');
            $transfer->toAccount?->load('transactions');

            static::leg($transfer->from_account_id, $transfer->amount, 'transfer_out', $transfer, 'Transfer to '.($transfer->toAccount?->name ?? 'account'));
            static::leg($transfer->to_account_id, $transfer->amount, 'transfer_in', $transfer, 'Transfer from '.($transfer->fromAccount?->name ?? 'account'));
        });
    }

    public static function reverseTransfer(BankTransfer $transfer): void
    {
        DB::transaction(function () use ($transfer) {
            BankTransaction::query()
                ->where('reference_type', $transfer->getMorphClass())
                ->where('reference_id', $transfer->id)
                ->delete();
        });
    }

    /**
     * Post a customer receipt against the bank account it landed in.
     *
     * Money received is only visible in the bank ledger once it has been posted
     * here, so a payment recorded with an account selected must produce a
     * matching deposit or the account balance silently under-reports cash.
     */
    public static function postReceipt(Model $payment): void
    {
        if (! $payment->bank_account_id) {
            return;
        }

        static::postPaymentLeg(
            $payment,
            'deposit',
            'Receipt '.$payment->number.($payment->invoice?->number ? ' for invoice '.$payment->invoice->number : ''),
        );
    }

    /**
     * Post a supplier disbursement against the bank account it left from.
     */
    public static function postDisbursement(Model $payment): void
    {
        if (! $payment->bank_account_id) {
            return;
        }

        static::postPaymentLeg(
            $payment,
            'withdrawal',
            'Payment '.$payment->number.($payment->invoice?->number ? ' for bill '.$payment->invoice->number : ''),
        );
    }

    /**
     * Post an approved expense claim as a withdrawal from the account it was
     * paid from.
     *
     * The claim is the disbursement: approving it is the moment the money is
     * committed out of the account the claim named, so the withdrawal posts at
     * approval and is dated THAT day. The bank ledger reports what left an
     * account and when — and a reimbursement flag set later is paperwork, not a
     * second payment. A claim that named no account posts nothing here.
     *
     * The polymorphic reference is what lets deletion unwind the leg — the same
     * mechanism payments already use.
     */
    public static function postExpenseClaim(Model $claim): void
    {
        if (! $claim->bank_account_id) {
            return;
        }

        $account = BankAccount::query()->find($claim->bank_account_id);

        if (! $account) {
            return;
        }

        BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => next_document_number('bank_transaction', 'BT', BankTransaction::class),
            'transaction_date' => today()->toDateString(),
            'type' => 'withdrawal',
            'amount' => static::amountInAccountCurrency($claim, $account, $claim->effectiveAmount()),
            'counterparty' => $claim->employee_name ?: 'Employee',
            'description' => 'Expense claim '.$claim->number
                .($claim->expenseCategory?->name ? ' ('.$claim->expenseCategory->name.')' : ''),
            'reference' => $claim->number,
            'is_reconciled' => false,
            'reference_type' => $claim->getMorphClass(),
            'reference_id' => $claim->id,
        ]);
    }

    /**
     * Drop the bank leg for a payment. Called before the payment is re-posted on
     * an edit and when it is deleted, so the ledger tracks the payment rather
     * than accumulating a row per edit.
     */
    public static function reversePayment(Model $payment): void
    {
        BankTransaction::query()
            ->where('reference_type', $payment->getMorphClass())
            ->where('reference_id', $payment->id)
            ->delete();
    }

    /**
     * The bank_transactions rows a given payment owns. Used by CashFlow to
     * exclude them from the bank side of the report: the payment is already
     * counted there, and counting both would double every receipt.
     *
     * @return array<int, int>
     */
    public static function paymentTransactionIds(): array
    {
        return BankTransaction::query()
            ->whereIn('reference_type', [SalesPayment::class, SupplierPayment::class])
            ->pluck('id')
            ->all();
    }

    /**
     * @param  'deposit'|'withdrawal'  $type
     */
    private static function postPaymentLeg(Model $payment, string $type, string $description): void
    {
        $account = BankAccount::query()->find($payment->bank_account_id);

        if (! $account) {
            return;
        }

        $counterparty = $payment instanceof SalesPayment
            ? ($payment->customer?->company_name ?? 'Customer')
            : ($payment->supplier?->company_name ?? 'Supplier');

        BankTransaction::create([
            'bank_account_id' => $account->id,
            'number' => next_document_number('bank_transaction', 'BT', BankTransaction::class),
            'transaction_date' => $payment->payment_date,
            'type' => $type,
            'amount' => static::amountInAccountCurrency($payment, $account),
            'counterparty' => $counterparty,
            'description' => $description,
            'reference' => $payment->number,
            'is_reconciled' => false,
            'reference_type' => $payment->getMorphClass(),
            'reference_id' => $payment->id,
        ]);
    }

    /**
     * bank_transactions.amount is denominated in the account's own currency,
     * while a payment carries its own currency and a snapshotted rate. When the
     * two differ the amount is converted through the base currency, which is
     * exact whenever the account is held in the base currency (the common case)
     * and otherwise uses the latest configured reference rate for the account.
     */
    private static function amountInAccountCurrency(Model $payment, BankAccount $account, ?float $amount = null): string
    {
        $value = $amount ?? (float) $payment->amount;
        $accountCurrency = strtoupper((string) $account->currency);
        $paymentCurrency = strtoupper((string) ($payment->currency ?: $accountCurrency));

        if ($paymentCurrency === $accountCurrency) {
            return (string) $value;
        }

        $inBase = to_base_currency($value, $payment->exchange_rate ?? 1);

        if ($accountCurrency === base_currency()) {
            return number_format($inBase, 2, '.', '');
        }

        $rate = ExchangeRate::latestFor($accountCurrency)?->rate_to_base;

        // With no rate to convert back, the base-currency figure is the closest
        // honest answer and is better than silently posting a foreign figure as
        // though it were the account's own currency.
        if ($rate === null || (float) $rate == 0.0) {
            return number_format($inBase, 2, '.', '');
        }

        return number_format($inBase / (float) $rate, 2, '.', '');
    }

    private static function leg(int $accountId, string $amount, string $type, BankTransfer $transfer, string $description): void
    {
        BankTransaction::create([
            'bank_account_id' => $accountId,
            'number' => next_document_number('bank_transaction', 'BT'),
            'transaction_date' => $transfer->transfer_date,
            'type' => $type,
            'amount' => $amount,
            'counterparty' => $type === 'transfer_out' ? $transfer->toAccount?->name : $transfer->fromAccount?->name,
            'description' => $description,
            'reference' => $transfer->number,
            'is_reconciled' => false,
            'reference_type' => $transfer->getMorphClass(),
            'reference_id' => $transfer->id,
        ]);
    }
}
