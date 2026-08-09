<?php

namespace Database\Seeders;

use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\FixedAssetDisposal;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class FixedAssetsSeeder extends Seeder
{
    public function run(): void
    {
        if (FixedAsset::query()->count() > 0) {
            return;
        }

        $assets = [
            [
                'name' => 'Toyota Hilux 2021', 'category' => 'Vehicles', 'purchase_date' => Carbon::now()->subMonths(30),
                'purchase_cost' => 380000, 'salvage_value' => 80000, 'useful_life_months' => 60,
                'depreciation_method' => 'straight_line', 'serial_number' => 'AH4G-2108421', 'supplier' => 'Toyota Ghana',
                'location' => 'Accra HQ', 'department' => 'Operations',
            ],
            [
                'name' => 'Forklift 3T', 'category' => 'Machinery', 'purchase_date' => Carbon::now()->subMonths(24),
                'purchase_cost' => 450000, 'salvage_value' => 90000, 'useful_life_months' => 72,
                'depreciation_method' => 'straight_line', 'serial_number' => 'FL-88451', 'supplier' => 'Tema Industrial',
                'location' => 'Tema Warehouse', 'department' => 'Warehouse',
            ],
            [
                'name' => 'HP ProLiant Server', 'category' => 'IT Equipment', 'purchase_date' => Carbon::now()->subMonths(18),
                'purchase_cost' => 120000, 'salvage_value' => 10000, 'useful_life_months' => 48,
                'depreciation_method' => 'straight_line', 'serial_number' => 'SN-2045123', 'supplier' => 'ComSys Ghana',
                'location' => 'Accra HQ', 'department' => 'IT',
            ],
            [
                'name' => 'Conference Room Projector', 'category' => 'Office Equipment', 'purchase_date' => Carbon::now()->subMonths(12),
                'purchase_cost' => 18000, 'salvage_value' => 2000, 'useful_life_months' => 36,
                'depreciation_method' => 'straight_line', 'serial_number' => 'EHP-7712', 'supplier' => 'Office Depot',
                'location' => 'Accra HQ', 'department' => 'Administration',
            ],
            [
                'name' => 'Delivery Van Toyota Hiace', 'category' => 'Vehicles', 'purchase_date' => Carbon::now()->subMonths(9),
                'purchase_cost' => 295000, 'salvage_value' => 60000, 'useful_life_months' => 60,
                'depreciation_method' => 'reducing_balance', 'depreciation_rate' => 1.667,
                'serial_number' => 'AHH-5521', 'supplier' => 'Toyota Ghana',
                'location' => 'Accra HQ', 'department' => 'Logistics',
            ],
            [
                'name' => 'Air Conditioner 24K BTU', 'category' => 'Office Equipment', 'purchase_date' => Carbon::now()->subMonths(6),
                'purchase_cost' => 25000, 'salvage_value' => 0, 'useful_life_months' => 60,
                'depreciation_method' => 'straight_line', 'serial_number' => 'AC-22415', 'supplier' => 'Cold Chain Ltd',
                'location' => 'Accra HQ', 'department' => 'Administration',
            ],
            [
                'name' => 'Photocopier Ricoh', 'category' => 'Office Equipment', 'purchase_date' => Carbon::now()->subMonths(42),
                'purchase_cost' => 85000, 'salvage_value' => 5000, 'useful_life_months' => 60,
                'depreciation_method' => 'straight_line', 'serial_number' => 'RIC-99011', 'supplier' => 'Office Depot',
                'location' => 'Accra HQ', 'department' => 'Administration',
            ],
        ];

        foreach ($assets as $index => $data) {
            $asset = FixedAsset::create(array_merge($data, [
                'asset_code' => next_document_number('fixed_asset', 'AST'),
                'status' => 'in_use',
                'notes' => 'Registered during initial asset count.',
            ]));

            // Accrue depreciation for each full month owned (up to a ceiling so records stay small).
            $monthsOwned = max($asset->purchase_date->copy()->startOfMonth()->diffInMonths(now()->startOfMonth()), 0);
            $monthsOwned = min($monthsOwned, 8);
            for ($i = 1; $i <= $monthsOwned; $i++) {
                $period = $asset->purchase_date->copy()->startOfMonth()->addMonths($i)->format('Y-m');

                if ($asset->isFullyDepreciated()) {
                    break;
                }

                FixedAssetDepreciation::create([
                    'fixed_asset_id' => $asset->id,
                    'period' => $period,
                    'amount' => (string) $asset->monthlyDepreciation(),
                ]);
            }
        }

        // Dispose one asset to exercise the disposals flow.
        $dispose = FixedAsset::query()->where('name', 'like', '%Photocopier%')->first();
        if ($dispose) {
            FixedAssetDisposal::create([
                'fixed_asset_id' => $dispose->id,
                'disposal_date' => Carbon::now()->subDays(15)->toDateString(),
                'method' => 'sold',
                'proceeds' => 12000,
                'book_value' => (string) $dispose->bookValue(),
                'notes' => 'Sold to office equipment reseller.',
            ]);
            $dispose->update(['status' => 'disposed']);
        }

        // Stored flag for one asset.
        FixedAsset::query()->where('name', 'like', '%Forklift%')->update(['status' => 'stored']);
    }
}