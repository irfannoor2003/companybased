<?php

namespace App\Console\Commands;

use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Support\DocumentItems;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-convert documents from one currency to another.
 *
 * Three things this command must never do, all of which it used to do:
 *
 *  1. Multiply subtotal, tax_amount and total independently. Each is rounded on
 *     its own, so a converted document could satisfy neither
 *     subtotal + tax == total nor "the printed lines add up to the printed
 *     total". Header figures are now derived from the converted lines by
 *     DocumentItems::totalsFromPersistedItems(), the same arithmetic the create
 *     and edit screens use.
 *
 *  2. Convert paid_amount without converting the payment rows behind it.
 *     InvoicePaymentService recomputes paid_amount from sum(payments.amount) on
 *     every status recalculation, so the next recalculation after a conversion
 *     reverted the invoice to its old-currency settlement and silently marked a
 *     paid invoice unpaid. Payments are converted in the same transaction, and
 *     paid_amount is then set from them rather than converted on its own.
 *
 *  3. Run unguarded. It rewrites every document in a currency, so it now previews
 *     by default and only writes when --force is passed.
 *
 * Multiplication goes through bcmath rather than round($x * $rate, 2): a float
 * rate times a float amount drifts, and the drift lands in the ledger.
 */
class ConvertCurrency extends Command
{
    protected $signature = 'currency:convert
        {from=USD : Source currency}
        {to=PKR : Target currency}
        {--rate= : Exchange rate (1 FROM = ? TO). If omitted, uses the exchange_rates table.}
        {--force : Actually write. Without this the command only previews.}';

    protected $description = 'Bulk-convert all documents from one currency to another (previews unless --force)';

    public function handle(): int
    {
        $from = strtoupper((string) $this->argument('from'));
        $to = strtoupper((string) $this->argument('to'));
        $force = (bool) $this->option('force');

        if ($from === $to) {
            $this->error("Source and target currency are the same ({$from}).");

            return 1;
        }

        $rate = $this->resolveRate($from, $to);

        if ($rate === null) {
            return 1;
        }

        $this->info("Converting {$from} -> {$to} at rate {$rate}");
        $this->newLine();

        $saleInvoices = SalesInvoice::where('currency', $from)->get();
        $purchaseInvoices = PurchaseInvoice::where('currency', $from)->get();
        $total = $saleInvoices->count() + $purchaseInvoices->count();

        if ($total === 0) {
            $this->warn("No documents found with currency {$from}. Nothing to convert.");

            return 0;
        }

        $this->info(sprintf(
            'Found %d sales invoices, %d purchase invoices (%d total)',
            $saleInvoices->count(),
            $purchaseInvoices->count(),
            $total,
        ));
        $this->newLine();

        $this->table(
            ['Type', 'Number', 'Old Total', 'New Total', 'Payments', 'Lines'],
            collect()
                ->concat($saleInvoices->map(fn ($inv) => $this->previewRow('Sale', $inv, $from, $to, $rate)))
                ->concat($purchaseInvoices->map(fn ($inv) => $this->previewRow('Purchase', $inv, $from, $to, $rate)))
                ->toArray(),
        );

        if (! $force) {
            $this->newLine();
            $this->warn('Dry run: nothing was written.');
            $this->warn('Re-run with --force to apply this conversion.');

            return 0;
        }

        $this->newLine();
        $this->alert("Rewriting {$total} documents. Take a backup first.");

        if (! $this->confirm('Apply the conversion now?', true)) {
            $this->info('Aborted.');

            return 0;
        }

        // One transaction for the whole run: a failure half way through must not
        // leave a currency change applied to some invoices and not others.
        DB::transaction(function () use ($saleInvoices, $purchaseInvoices, $from, $to, $rate) {
            foreach ($saleInvoices as $invoice) {
                $this->convertDocument($invoice, $from, $to, $rate);
            }

            foreach ($purchaseInvoices as $invoice) {
                $this->convertDocument($invoice, $from, $to, $rate);
            }
        });

        $this->newLine();
        $this->info("Done. Converted {$total} documents from {$from} to {$to} at rate {$rate}.");

        return 0;
    }

    /**
     * @return array<int, string>
     */
    private function previewRow(string $type, Model $invoice, string $from, string $to, string $rate): array
    {
        $projection = $this->projectTotals($invoice, $rate);

        return [
            $type,
            (string) $invoice->number,
            money($invoice->total, $from),
            money($projection['total'], $to),
            (string) $invoice->payments()->count(),
            (string) $invoice->items()->count(),
        ];
    }

    /**
     * What the header will be after conversion, without writing anything.
     *
     * @return array{subtotal: float, tax: float, total: float}
     */
    private function projectTotals(Model $document, string $rate): array
    {
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($document->items()->get() as $item) {
            $totals = DocumentItems::lineTotals(
                (float) $item->qty,
                $this->convertAmount((string) $item->unit_price, $rate),
                (float) ($item->discount_percent ?? 0),
                (float) ($item->tax_percent ?? 0),
            );

            $subtotal += $totals['line_net'];
            $tax += $totals['line_tax'];
        }

        $subtotal = round($subtotal, 2);
        $tax = round($tax, 2);

        return ['subtotal' => $subtotal, 'tax' => $tax, 'total' => round($subtotal + $tax, 2)];
    }

    /**
     * Convert one document and everything that has to move with it.
     */
    private function convertDocument(Model $document, string $from, string $to, string $rate): void
    {
        // 1. Lines first. The header is derived from these, so they are the source
        //    of truth; converting the header independently is what broke it.
        foreach ($document->items()->get() as $item) {
            $convertedUnitPrice = $this->convertAmount((string) $item->unit_price, $rate);

            $totals = DocumentItems::lineTotals(
                (float) $item->qty,
                $convertedUnitPrice,
                (float) ($item->discount_percent ?? 0),
                (float) ($item->tax_percent ?? 0),
            );

            $item->update([
                'unit_price' => $convertedUnitPrice,
                'line_total' => $totals['line_net'],
            ]);
        }

        // 2. Header, recomputed from the converted lines by the shared arithmetic.
        $totals = DocumentItems::totalsFromPersistedItems($document->fresh());

        $document->update([
            'currency' => $to,
            'exchange_rate' => $rate,
            'subtotal' => $totals['subtotal'],
            'tax_amount' => $totals['tax'],
            'total' => $totals['total'],
        ]);

        // 3. Payments, so sum(payments.amount) still equals paid_amount.
        $paid = 0.0;

        foreach ($document->payments()->get() as $payment) {
            $amount = $this->convertAmount((string) $payment->amount, $rate);

            $payment->update([
                'amount' => $amount,
                'currency' => $to,
                'exchange_rate' => $rate,
            ]);

            $paid += $amount;
        }

        $document->update(['paid_amount' => round($paid, 2)]);
    }

    /**
     * Multiply money by a rate in decimal arithmetic.
     *
     * bcmath rather than round($amount * $rate, 2): the operands arrive as decimal
     * strings and a float multiply introduces error that is invisible in the
     * ledger until a statement is audited.
     */
    private function convertAmount(string $amount, string $rate): string
    {
        if (! function_exists('bcmul')) {
            // ext-bcmath is declared in composer.json but a misconfigured host can
            // still lack it. Float is wrong by a fraction of a cent; refusing to run
            // would be worse than converting with a documented caveat.
            return number_format((float) $amount * (float) $rate, 2, '.', '');
        }

        return bcmul($amount, $rate, 2);
    }

    private function resolveRate(string $from, string $to): ?string
    {
        $option = $this->option('rate');

        if ($option !== null && $option !== '') {
            $rate = (string) (float) $option;

            if ((float) $rate <= 0) {
                $this->error('The exchange rate must be greater than zero.');

                return null;
            }

            return $rate;
        }

        $row = DB::table('exchange_rates')
            ->where('currency_code', $to)
            ->latest('effective_date')
            ->first();

        if ($row) {
            return (string) (float) $row->rate_to_base;
        }

        $answer = $this->ask("No exchange rate found for {$to}. Enter 1 {$from} = ? {$to}");

        if ($answer === null || (float) $answer <= 0) {
            $this->error('A positive exchange rate is required. Nothing was converted.');

            return null;
        }

        return (string) (float) $answer;
    }
}
