<?php

namespace App\Console\Commands;

use App\Models\SalesInvoice;
use App\Models\PurchaseInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConvertCurrency extends Command
{
    protected $signature = 'currency:convert {from=USD} {to=PKR} {--rate= : Exchange rate (1 FROM = ? TO). If omitted, uses exchange_rates table.}';
    protected $description = 'Bulk-convert all documents from one currency to another';

    public function handle(): int
    {
        $from = strtoupper($this->argument('from'));
        $to = strtoupper($this->argument('to'));

        if ($from === $to) {
            $this->error("Source and target currency are the same ({$from}).");
            return 1;
        }

        // Determine exchange rate
        $rateOption = $this->option('rate');
        if ($rateOption !== null && $rateOption !== '') {
            $rate = (float) $rateOption;
        } else {
            $row = DB::table('exchange_rates')->where('currency_code', $to)->latest('effective_date')->first();
            if ($row) {
                $rate = (float) $row->rate_to_base;
            } else {
                $rate = (float) $this->ask("No exchange rate found for {$to}. Enter 1 {$from} = ? {$to}");
            }
        }

        $this->info("Converting {$from} → {$to} at rate {$rate}");
        $this->newLine();

        // Show preview
        $saleInvoices = SalesInvoice::where('currency', $from)->get();
        $purchaseInvoices = PurchaseInvoice::where('currency', $from)->get();
        $total = $saleInvoices->count() + $purchaseInvoices->count();

        if ($total === 0) {
            $this->warn("No documents found with currency {$from}. Nothing to convert.");
            return 0;
        }

        $this->info("Found {$saleInvoices->count()} sales invoices, {$purchaseInvoices->count()} purchase invoices ({$total} total)");
        $this->newLine();

        $this->table(
            ['Type', 'Number', 'Old Total', 'New Total', 'Old Paid', 'New Paid'],
            collect()
                ->concat($saleInvoices->map(fn ($inv) => ['Sale', $inv->number, money($inv->total, $from), money(round($inv->total * $rate, 2), $to), money($inv->paid_amount, $from), money(round($inv->paid_amount * $rate, 2), $to)]))
                ->concat($purchaseInvoices->map(fn ($inv) => ['Purchase', $inv->number, money($inv->total, $from), money(round($inv->total * $rate, 2), $to), money($inv->paid_amount, $from), money(round($inv->paid_amount * $rate, 2), $to)]))
                ->toArray()
        );

        if (! $this->confirm("Proceed with conversion? This will update {$total} documents.")) {
            $this->info("Aborted.");
            return 0;
        }

        DB::transaction(function () use ($saleInvoices, $purchaseInvoices, $from, $to, $rate) {
            foreach ($saleInvoices as $inv) {
                $inv->update([
                    'currency' => $to,
                    'exchange_rate' => $rate,
                    'subtotal' => round($inv->subtotal * $rate, 2),
                    'tax_amount' => round($inv->tax_amount * $rate, 2),
                    'total' => round($inv->total * $rate, 2),
                    'paid_amount' => round($inv->paid_amount * $rate, 2),
                ]);
            }

            foreach ($purchaseInvoices as $inv) {
                $inv->update([
                    'currency' => $to,
                    'exchange_rate' => $rate,
                    'subtotal' => round($inv->subtotal * $rate, 2),
                    'tax_amount' => round($inv->tax_amount * $rate, 2),
                    'total' => round($inv->total * $rate, 2),
                    'paid_amount' => round($inv->paid_amount * $rate, 2),
                ]);
            }
        });

        $this->newLine();
        $this->info("Done. Converted {$total} documents from {$from} to {$to} at rate {$rate}.");
        return 0;
    }
}
