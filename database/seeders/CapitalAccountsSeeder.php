<?php

namespace Database\Seeders;

use App\Models\CapitalContribution;
use App\Models\CapitalDrawing;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class CapitalAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $methods = ['bank_transfer', 'cash', 'bank_transfer', 'cheque'];

        if (CapitalContribution::query()->count() === 0) {
            $contributions = [
                ['contributor' => 'Kwame Mensah', 'amount' => 50000, 'date' => Carbon::now()->subMonths(18)],
                ['contributor' => 'Ama Owusu', 'amount' => 35000, 'date' => Carbon::now()->subMonths(18)],
                ['contributor' => 'Daniel Boateng', 'amount' => 25000, 'date' => Carbon::now()->subMonths(15)],
                ['contributor' => 'Kwame Mensah', 'amount' => 20000, 'date' => Carbon::now()->subMonths(8)],
                ['contributor' => 'Ama Owusu', 'amount' => 15000, 'date' => Carbon::now()->subMonths(5)],
                ['contributor' => 'Kwame Mensah', 'amount' => 10000, 'date' => Carbon::now()->subMonths(2)],
            ];

            foreach ($contributions as $index => $data) {
                CapitalContribution::create([
                    'reference' => next_document_number('capital_contribution', 'CAP'),
                    'contribution_date' => $data['date']->toDateString(),
                    'contributor' => $data['contributor'],
                    'amount' => $data['amount'],
                    'currency' => 'GHS',
                    'method' => $methods[$index % count($methods)],
                    'notes' => 'Capital injection.',
                ]);
            }
        }

        if (CapitalDrawing::query()->count() === 0) {
            $drawings = [
                ['recipient' => 'Kwame Mensah', 'amount' => 8000, 'date' => Carbon::now()->subMonths(10)],
                ['recipient' => 'Ama Owusu', 'amount' => 5000, 'date' => Carbon::now()->subMonths(7)],
                ['recipient' => 'Daniel Boateng', 'amount' => 6000, 'date' => Carbon::now()->subMonths(4)],
                ['recipient' => 'Kwame Mensah', 'amount' => 4000, 'date' => Carbon::now()->subMonths(1)],
            ];

            foreach ($drawings as $index => $data) {
                CapitalDrawing::create([
                    'reference' => next_document_number('capital_drawing', 'DRW'),
                    'drawing_date' => $data['date']->toDateString(),
                    'recipient' => $data['recipient'],
                    'amount' => $data['amount'],
                    'currency' => 'GHS',
                    'method' => $methods[$index % count($methods)],
                    'notes' => 'Owner distribution.',
                ]);
            }
        }
    }
}