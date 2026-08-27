<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

class TemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            // Invoice templates
            [
                'name' => 'Classic Invoice',
                'slug' => 'classic-invoice',
                'type' => 'invoice',
                'description' => 'Clean, professional layout with traditional styling. Best for formal businesses.',
                'colors' => ['primary' => '#1e3a5f', 'accent' => '#2563eb', 'text' => '#1f2937'],
                'layout' => ['header' => 'left', 'show_logo' => true, 'show_tax' => true],
                'is_default' => true,
                'is_system' => true,
            ],
            [
                'name' => 'Modern Invoice',
                'slug' => 'modern-invoice',
                'type' => 'invoice',
                'description' => 'Bold, vibrant design with gradient header. Ideal for tech and creative companies.',
                'colors' => ['primary' => '#7c3aed', 'accent' => '#ec4899', 'text' => '#111827'],
                'layout' => ['header' => 'center', 'show_logo' => true, 'show_tax' => true],
                'header_html' => null,
                'footer_html' => '<p style="text-align:center;color:#9ca3af;font-size:9px;">Payment is due within 30 days. Thank you for your business!</p>',
                'css' => null,
                'is_default' => false,
                'is_system' => true,
            ],

            // Quote templates
            [
                'name' => 'Classic Quote',
                'slug' => 'classic-quote',
                'type' => 'quote',
                'description' => 'Professional quote layout matching the classic invoice design.',
                'colors' => ['primary' => '#1e3a5f', 'accent' => '#2563eb', 'text' => '#1f2937'],
                'layout' => ['header' => 'left', 'show_logo' => true, 'show_tax' => true],
                'is_default' => true,
                'is_system' => true,
            ],
            [
                'name' => 'Modern Quote',
                'slug' => 'modern-quote',
                'type' => 'quote',
                'description' => 'Bold, vibrant quote design with modern styling.',
                'colors' => ['primary' => '#7c3aed', 'accent' => '#ec4899', 'text' => '#111827'],
                'layout' => ['header' => 'center', 'show_logo' => true, 'show_tax' => true],
                'footer_html' => '<p style="text-align:center;color:#9ca3af;font-size:9px;">This quote is valid for 15 days from the date of issue.</p>',
                'is_default' => false,
                'is_system' => true,
            ],

            // Order templates
            [
                'name' => 'Classic Order',
                'slug' => 'classic-order',
                'type' => 'order',
                'description' => 'Standard order confirmation layout with professional styling.',
                'colors' => ['primary' => '#1e3a5f', 'accent' => '#2563eb', 'text' => '#1f2937'],
                'layout' => ['header' => 'left', 'show_logo' => true, 'show_tax' => true],
                'is_default' => true,
                'is_system' => true,
            ],

            // Delivery Note templates
            [
                'name' => 'Classic Delivery Note',
                'slug' => 'classic-delivery-note',
                'type' => 'delivery_note',
                'description' => 'Simple, clean delivery note layout.',
                'colors' => ['primary' => '#1e3a5f', 'accent' => '#2563eb', 'text' => '#1f2937'],
                'layout' => ['header' => 'left', 'show_logo' => true, 'show_tax' => false],
                'is_default' => true,
                'is_system' => true,
            ],

            // Credit Note templates
            [
                'name' => 'Classic Credit Note',
                'slug' => 'classic-credit-note',
                'type' => 'credit_note',
                'description' => 'Professional credit note layout.',
                'colors' => ['primary' => '#1e3a5f', 'accent' => '#2563eb', 'text' => '#1f2937'],
                'layout' => ['header' => 'left', 'show_logo' => true, 'show_tax' => true],
                'is_default' => true,
                'is_system' => true,
            ],

            // Purchase Order templates
            [
                'name' => 'Classic Purchase Order',
                'slug' => 'classic-purchase-order',
                'type' => 'purchase_order',
                'description' => 'Standard purchase order layout.',
                'colors' => ['primary' => '#1e3a5f', 'accent' => '#2563eb', 'text' => '#1f2937'],
                'layout' => ['header' => 'left', 'show_logo' => true, 'show_tax' => true],
                'is_default' => true,
                'is_system' => true,
            ],

            // Purchase Invoice templates
            [
                'name' => 'Classic Purchase Invoice',
                'slug' => 'classic-purchase-invoice',
                'type' => 'purchase_invoice',
                'description' => 'Standard purchase invoice layout.',
                'colors' => ['primary' => '#1e3a5f', 'accent' => '#2563eb', 'text' => '#1f2937'],
                'layout' => ['header' => 'left', 'show_logo' => true, 'show_tax' => true],
                'is_default' => true,
                'is_system' => true,
            ],

            // Receipt templates
            [
                'name' => 'Classic Receipt',
                'slug' => 'classic-receipt',
                'type' => 'receipt',
                'description' => 'Simple, clean receipt layout.',
                'colors' => ['primary' => '#059669', 'accent' => '#10b981', 'text' => '#1f2937'],
                'layout' => ['header' => 'center', 'show_logo' => true, 'show_tax' => false],
                'footer_html' => '<p style="text-align:center;color:#9ca3af;font-size:9px;">Thank you for your payment!</p>',
                'is_default' => true,
                'is_system' => true,
            ],
        ];

        foreach ($templates as $data) {
            DocumentTemplate::updateOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }
}
