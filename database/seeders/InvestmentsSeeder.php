<?php

namespace Database\Seeders;

use App\Models\Investment;
use App\Models\InvestmentDividend;
use App\Models\InvestmentTransaction;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class InvestmentsSeeder extends Seeder
{
    public function run(): void
    {
        if (Investment::query()->count() > 0) {
            return;
        }

        $investments = [
            ['name' => 'MTN Ghana GSE', 'type' => 'stock', 'institution' => 'Databank Brokerage', 'purchase_date' => Carbon::now()->subYears(2)->startOfMonth(), 'quantity' => 8000, 'unit_cost' => 2.10, 'total_cost' => 16800, 'current_price' => 2.75, 'currency' => 'GHS'],
            ['name' => '78-Day Government T-Bill', 'type' => 'treasury_bill', 'institution' => 'Bank of Ghana', 'purchase_date' => Carbon::now()->subMonths(6)->startOfMonth(), 'quantity' => 1, 'unit_cost' => 100000, 'total_cost' => 100000, 'current_value' => 100000, 'currency' => 'GHS', 'maturity_date' => Carbon::now()->addMonths(3)],
            ['name' => 'Databank Fixed Deposit', 'type' => 'bond', 'institution' => 'Databank', 'purchase_date' => Carbon::now()->subMonths(12)->startOfMonth(), 'quantity' => 1, 'unit_cost' => 50000, 'total_cost' => 50000, 'current_value' => 54200, 'currency' => 'GHS'],
            ['name' => 'SIC Equity Fund', 'type' => 'mutual_fund', 'institution' => 'SIC Life', 'purchase_date' => Carbon::now()->subMonths(30)->startOfMonth(), 'quantity' => 12500, 'unit_cost' => 1.40, 'total_cost' => 17500, 'current_price' => 1.75, 'currency' => 'GHS'],
            ['name' => 'Stanchart Frontier ETF', 'type' => 'etf', 'institution' => 'Stanbic', 'purchase_date' => Carbon::now()->subMonths(9)->startOfMonth(), 'quantity' => 3000, 'unit_cost' => 12.0, 'total_cost' => 36000, 'current_price' => 11.20, 'currency' => 'USD'],
            ['name' => 'Lagos-land Gold', 'type' => 'real_estate', 'institution' => 'Direct purchase', 'purchase_date' => Carbon::now()->subMonths(48)->startOfMonth(), 'quantity' => 1, 'unit_cost' => 250000, 'total_cost' => 250000, 'current_value' => 310000, 'currency' => 'GHS'],
            ['name' => 'Palm Oil Crude Futures', 'type' => 'other', 'institution' => 'GCB Asset', 'purchase_date' => Carbon::now()->subMonths(4)->startOfMonth(), 'quantity' => 5000, 'unit_cost' => 8.0, 'total_cost' => 40000, 'current_price' => 8.40, 'currency' => 'GHS'],
        ];

        foreach ($investments as $data) {
            $currentValue = $data['current_value'] ?? round($data['quantity'] * $data['current_price'], 2);

            $investment = Investment::create(array_merge($data, [
                'code' => next_document_number('investment', 'INV'),
                'current_value' => $currentValue,
                'status' => 'active',
                'notes' => 'Seeded portfolio holding.',
            ]));

            // Opening buy transaction.
            InvestmentTransaction::create([
                'investment_id' => $investment->id,
                'transaction_date' => $data['purchase_date']->toDateString(),
                'type' => 'buy',
                'quantity' => (string) $data['quantity'],
                'unit_price' => (string) $data['unit_cost'],
                'fees' => 0,
                'total' => (string) $data['total_cost'],
            ]);

            // Dividends for stocks/funds.
            if (in_array($data['type'], ['stock', 'mutual_fund', 'etf'])) {
                for ($i = 0; $i < 3; $i++) {
                    InvestmentDividend::create([
                        'investment_id' => $investment->id,
                        'dividend_date' => $data['purchase_date']->copy()->addMonths(($i + 1) * 4)->toDateString(),
                        'amount' => round($data['total_cost'] * (0.01 + 0.005 * $i), 2),
                        'currency' => $data['currency'],
                    ]);
                }
            }
        }
    }
}