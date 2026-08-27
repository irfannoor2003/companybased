<?php

namespace Database\Seeders;

use App\Models\DiscountRule;
use Illuminate\Database\Seeder;

class DiscountRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'name' => 'Salesman Max Discount',
                'description' => 'Salesmen cannot offer more than 25% discount on any line item.',
                'type' => 'percentage',
                'max_value' => 25.00,
                'currency' => null,
                'roles' => ['Salesman'],
                'is_active' => true,
            ],
            [
                'name' => 'Employee Discount Limit',
                'description' => 'Employees cannot offer more than 10% discount on any line item.',
                'type' => 'percentage',
                'max_value' => 10.00,
                'currency' => null,
                'roles' => ['Employee'],
                'is_active' => true,
            ],
            [
                'name' => 'Inventory Manager Discount Limit',
                'description' => 'Inventory managers cannot offer more than 15% discount.',
                'type' => 'percentage',
                'max_value' => 15.00,
                'currency' => null,
                'roles' => ['Inventory Manager'],
                'is_active' => true,
            ],
        ];

        foreach ($rules as $data) {
            DiscountRule::updateOrCreate(
                ['name' => $data['name']],
                $data
            );
        }
    }
}
