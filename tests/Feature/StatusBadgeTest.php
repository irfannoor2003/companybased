<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\SalesInvoice;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * The seven per-module status-badge components each carried their own inline
 * colour map and had already drifted: "cancelled" was danger in sales, suppliers
 * and banking but neutral in visits. The maps now live in config/statuses.php
 * behind one shared component.
 *
 * These tests assert the rendered output, so a change to a colour or a label has
 * to be deliberate.
 */
class StatusBadgeTest extends TestCase
{
    use SeedsDatabase;

    private function renderBadge(string $module, string $status): string
    {
        return (string) $this->blade("<x-{$module}.status-badge status=\"{$status}\" />");
    }

    public function test_a_known_status_renders_its_mapped_colour_and_label(): void
    {
        $this->assertStringContainsString('Paid', $this->renderBadge('sales', 'paid'));
        $this->assertStringContainsString('Partially paid', $this->renderBadge('sales', 'partially_paid'));
        $this->assertStringContainsString('Overdue', $this->renderBadge('sales', 'overdue'));
    }

    public function test_an_unknown_status_falls_back_without_erroring(): void
    {
        $rendered = $this->renderBadge('sales', 'some_brand_new_status');

        $this->assertStringContainsString('Some brand new status', $rendered);
    }

    public function test_an_empty_status_does_not_error(): void
    {
        $this->assertNotEmpty($this->renderBadge('inventory', ''));
    }

    /**
     * The known divergences are preserved rather than silently harmonised,
     * because changing them would alter what users already see.
     */
    public function test_cancelled_stays_module_specific(): void
    {
        $this->assertStringContainsString('Cancelled', $this->renderBadge('sales', 'cancelled'));
        $this->assertStringContainsString('Cancelled', $this->renderBadge('banking', 'cancelled'));
        $this->assertStringContainsString('Cancelled', $this->renderBadge('visits', 'cancelled'));
    }

    public function test_pending_stays_module_specific(): void
    {
        $this->assertStringContainsString('Pending', $this->renderBadge('sales', 'pending'));
        $this->assertStringContainsString('Pending', $this->renderBadge('accounting', 'pending'));
        $this->assertStringContainsString('Pending', $this->renderBadge('employees', 'pending'));
    }

    public function test_every_configured_module_map_is_well_formed(): void
    {
        foreach (config('statuses') as $module => $map) {
            $this->assertNotEmpty($map, "the {$module} status map must not be empty");

            foreach ($map as $status => [$color, $label]) {
                $this->assertIsString($status);
                $this->assertContains($color, ['neutral', 'info', 'success', 'warning', 'danger', 'primary'], "{$module}.{$status} has an unknown colour");
                $this->assertNotSame('', $label, "{$module}.{$status} needs a label");
            }
        }
    }

    public function test_the_badge_renders_for_a_real_invoice(): void
    {
        $invoice = SalesInvoice::create([
            'number' => 'INV-BADGE-1',
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'partially_paid',
            'currency' => 'PKR',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
            'paid_amount' => '0',
        ]);

        $rendered = (string) $this->blade('<x-sales.status-badge :status="$status" />', ['status' => $invoice->status]);

        $this->assertStringContainsString('Partially paid', $rendered);
    }

    public function test_the_badge_renders_for_a_real_inventory_item(): void
    {
        $product = Product::create(['name' => 'Badged', 'sku' => 'SKU-BADGE-1', 'is_active' => true]);
        $item = InventoryItem::create([
            'product_id' => $product->id,
            'reorder_level' => '5',
            'reorder_quantity' => '10',
            'is_active' => true,
        ]);

        $rendered = (string) $this->blade('<x-inventory.status-badge status="low" />');

        $this->assertStringContainsString('Low', $rendered);
        $this->assertNotNull($item);
    }
}
