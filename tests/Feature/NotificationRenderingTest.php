<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Product;
use App\Notifications\LowStockAlert;
use App\Notifications\OrderTrackingNotification;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Both notification classes rendered their mail body through
 * MailMessage::table(), which does not exist. The call resolved to
 * __call() and every low-stock or order-tracking email would have thrown a
 * BadMethodCallException at render time in production.
 *
 * These tests call toMail()/toArray() directly, because Notification::fake()
 * records the notification object without ever rendering it, so a faked test
 * cannot catch a broken mail body.
 */
class NotificationRenderingTest extends TestCase
{
    use SeedsDatabase;

    private function stockItem(string $name = 'Rendered Item'): InventoryItem
    {
        $product = Product::create([
            'name' => $name,
            'sku' => 'SKU-RENDER-1',
            'is_active' => true,
        ]);

        return InventoryItem::create([
            'product_id' => $product->id,
            'reorder_level' => '5',
            'reorder_quantity' => '25',
            'is_active' => true,
        ]);
    }

    public function test_the_low_stock_alert_renders_a_mail_body(): void
    {
        $item = $this->stockItem('Rendered Widget');

        $message = (new LowStockAlert($item, 3.0, 5.0))->toMail($this->userForRole('Admin'));

        $this->assertNotEmpty($message->subject, 'the alert must carry a subject');
        $this->assertNotEmpty($message->introLines, 'the alert must have an intro line');
    }

    public function test_the_low_stock_alert_reports_the_key_figures(): void
    {
        $item = $this->stockItem();

        $payload = (new LowStockAlert($item, 3.0, 5.0))->toArray($this->userForRole('Admin'));

        $this->assertStringContainsString('Rendered Item', $payload['title']);
        $this->assertStringContainsString('3', $payload['message']);
        $this->assertStringContainsString('reorder level of 5', $payload['message']);
        $this->assertSame('warning', $payload['type']);
        $this->assertStringContainsString('/inventory/items/'.$item->id, $payload['url']);
    }

    public function test_the_tracking_alert_renders_a_mail_body_for_each_fulfilled_status(): void
    {
        $customer = $this->customer('Rendering Co');
        [$product] = $this->productWithStockItem('Tracked Item');
        $order = $this->salesOrder($customer, $product, '2', '25.00', 'confirmed');

        foreach (['packed', 'shipped', 'delivered'] as $status) {
            $notification = new OrderTrackingNotification($order, $status);

            $message = $notification->toMail($customer);

            $this->assertNotEmpty($message->subject, "the {$status} email must carry a subject");
            $this->assertNotEmpty($message->introLines, "the {$status} email must have an intro line");
        }
    }
}
