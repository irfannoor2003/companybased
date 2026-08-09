<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CatalogSeeder extends Seeder
{
    /**
     * Sample catalogue so the Sales module has realistic reference data to
     * work against. Everything here is demo data — safe to re-run.
     */
    public function run(): void
    {
        if (Product::exists() || Brand::exists() || Category::exists()) {
            return;
        }

        $brands = collect([
            'PowerTech', 'BuildPro', 'Apex Industrial', 'HomeCraft', 'Nordic Tools',
        ])->map(fn ($name) => Brand::create([
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => "Brand: {$name}.",
            'is_active' => true,
        ]));

        $power = Category::create(['name' => 'Power Tools', 'slug' => 'power-tools', 'is_active' => true]);
        $hand = Category::create(['name' => 'Hand Tools', 'slug' => 'hand-tools', 'is_active' => true]);
        $fasteners = Category::create(['name' => 'Fasteners', 'slug' => 'fasteners', 'is_active' => true]);
        $drills = Category::create(['name' => 'Drills', 'slug' => 'drills', 'parent_id' => $power->id, 'is_active' => true]);
        $saws = Category::create(['name' => 'Saws', 'slug' => 'saws', 'parent_id' => $power->id, 'is_active' => true]);
        $wrenches = Category::create(['name' => 'Wrenches', 'slug' => 'wrenches', 'parent_id' => $hand->id, 'is_active' => true]);
        $screws = Category::create(['name' => 'Screws', 'slug' => 'screws', 'parent_id' => $fasteners->id, 'is_active' => true]);

        $categories = collect([$power, $hand, $fasteners, $drills, $saws, $wrenches, $screws]);

        $products = [
            ['name' => 'Cordless Drill 20V', 'brand' => 0, 'category' => $drills, 'sku' => 'DRL-20V', 'cost' => 68, 'retail' => 119],
            ['name' => 'Impact Driver 20V', 'brand' => 0, 'category' => $drills, 'sku' => 'IMP-20V', 'cost' => 74, 'retail' => 129],
            ['name' => 'Circular Saw 7-1/4"', 'brand' => 1, 'category' => $saws, 'sku' => 'CSA-725', 'cost' => 52, 'retail' => 89],
            ['name' => 'Jigsaw Variable Speed', 'brand' => 1, 'category' => $saws, 'sku' => 'JIG-VS', 'cost' => 31, 'retail' => 55],
            ['name' => 'Combination Wrench Set (12pc)', 'brand' => 3, 'category' => $wrenches, 'sku' => 'WRH-12', 'cost' => 18, 'retail' => 34],
            ['name' => 'Adjustable Wrench 10"', 'brand' => 3, 'category' => $wrenches, 'sku' => 'WRH-ADJ10', 'cost' => 9, 'retail' => 17],
            ['name' => 'Self-Tapping Screws 4x40 (100pc)', 'brand' => 4, 'category' => $screws, 'sku' => 'SCR-440', 'cost' => 3.5, 'retail' => 6.5],
            ['name' => 'Drywall Screws #8 x 1-1/4" (500pc)', 'brand' => 4, 'category' => $screws, 'sku' => 'SCR-8DW', 'cost' => 6, 'retail' => 11],
            ['name' => 'Angle Grinder 4-1/2"', 'brand' => 2, 'category' => $power, 'sku' => 'GRD-45', 'cost' => 41, 'retail' => 72],
            ['name' => 'Heat Gun 1500W', 'brand' => 2, 'category' => $power, 'sku' => 'HTG-15', 'cost' => 22, 'retail' => 39],
        ];

        foreach ($products as $def) {
            Product::create([
                'name' => $def['name'],
                'slug' => Str::slug($def['name']),
                'sku' => $def['sku'],
                'barcode' => null,
                'brand_id' => $brands[$def['brand']]->id,
                'category_id' => $def['category']->id,
                'unit' => 'pcs',
                'description' => "{$def['name']} — retail and wholesale demo product.",
                'cost_price' => $def['cost'],
                'retail_price' => $def['retail'],
                'wholesale_price' => round($def['cost'] * 1.18, 2),
                'min_price' => round($def['cost'] * 1.05, 2),
                'is_active' => true,
            ]);
        }

        $retail = PriceList::create([
            'name' => 'Retail Pricing',
            'slug' => 'retail-pricing',
            'type' => 'retail',
            'currency' => settings('company.currency'),
            'markup_percent' => 0,
            'is_default' => true,
            'is_active' => true,
            'description' => 'Standard retail prices used across sales channels.',
        ]);

        $wholesale = PriceList::create([
            'name' => 'Wholesale Pricing',
            'slug' => 'wholesale-pricing',
            'type' => 'wholesale',
            'currency' => settings('company.currency'),
            'markup_percent' => 0,
            'is_default' => false,
            'is_active' => true,
            'description' => 'Preferred pricing for wholesale and bulk customers.',
        ]);

        $retail->products()->attach(Product::pluck('id')->mapWithKeys(fn ($id) => [$id => ['price' => Product::find($id)->retail_price]]));
        $wholesale->products()->attach(Product::pluck('id')->mapWithKeys(fn ($id) => [$id => ['price' => Product::find($id)->wholesale_price]]));
    }
}
