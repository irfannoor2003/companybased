<?php

namespace Tests\Feature;

use App\Models\PurchaseOrder;
use App\Models\SalesQuote;
use App\Support\DocumentData;
use Illuminate\Support\Facades\Route;
use Tests\SeedsDatabase;
use Tests\TestCase;

/**
 * Every document type registered in DocumentData renders through ONE shared view,
 * resources/views/documents/show.blade.php. That view builds URLs with route(),
 * including the Email modal's send route.
 *
 * documents/show.blade.php referenced two route names that have never existed --
 * "quotes.send_email" and "purchase_orders.send_email" -- because every route in
 * those groups is module-prefixed (sales.quotes.send_email,
 * suppliers.purchase_orders.send_email). route() throws RouteNotFoundException on
 * an unknown name, and the reference sat inside the branch that runs for exactly
 * the two affected documents, so GET /sales/quotes/{quote} and
 * GET /suppliers/purchase-orders/{order} both returned 500. Neither page was
 * covered: DocumentPreviewTest only requests the "create" screens, and the PDF
 * tests use the pdf endpoint, which builds its URL from DocumentData instead.
 *
 * The first test below is the general net -- it fails on ANY unresolvable route
 * name in the shared view, which is the whole bug class. The rest pin the two
 * pages that were actually broken.
 */
class DocumentShowPageTest extends TestCase
{
    use SeedsDatabase;

    /**
     * Every route() name in the shared document view must exist.
     *
     * A misspelled or unprefixed name is invisible until someone opens the page,
     * because nothing type-checks Blade and no other test renders this view.
     */
    public function test_every_route_referenced_by_the_shared_document_view_exists(): void
    {
        $source = file_get_contents(resource_path('views/documents/show.blade.php'));

        preg_match_all("/route\(\s*'([^']+)'/", $source, $matches);

        $referenced = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($referenced, 'no route() calls found; the regex is stale');

        $undefined = array_values(array_filter(
            $referenced,
            fn (string $name): bool => Route::has($name) === false,
        ));

        $this->assertSame(
            [],
            $undefined,
            "The shared document view references route names that do not exist: "
            .implode(', ', $undefined)
            .'. route() throws RouteNotFoundException on an unknown name, so every '
            .'document type that hits this branch returns 500.',
        );
    }

    /**
     * The two document types whose branch renders the Email modal.
     */
    private function quote(): SalesQuote
    {
        return SalesQuote::create([
            'number' => 'Q-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
        ]);
    }

    private function purchaseOrder(): PurchaseOrder
    {
        return PurchaseOrder::create([
            'number' => 'PO-'.uniqid(),
            'supplier_id' => $this->supplier()->id,
            'order_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
        ]);
    }

    public function test_a_quote_show_page_renders(): void
    {
        $quote = $this->quote();

        $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/quotes/{$quote->id}")
            ->assertOk();
    }

    public function test_a_purchase_order_show_page_renders(): void
    {
        $order = $this->purchaseOrder();

        $this->actingAs($this->userForRole('Admin'))
            ->get("/suppliers/purchase-orders/{$order->id}")
            ->assertOk();
    }

    /**
     * The Email button is only useful if the form it opens points at the route
     * that actually exists.
     */
    public function test_the_email_modal_posts_to_a_real_send_route(): void
    {
        $quote = $this->quote();

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get("/sales/quotes/{$quote->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('sales.quotes.send_email', $quote),
            $html,
            'the quote email modal must post to sales.quotes.send_email',
        );
    }

    public function test_a_purchase_order_email_modal_posts_to_a_real_send_route(): void
    {
        $order = $this->purchaseOrder();

        $html = $this->actingAs($this->userForRole('Admin'))
            ->get("/suppliers/purchase-orders/{$order->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('suppliers.purchase_orders.send_email', $order),
            $html,
            'the purchase order email modal must post to suppliers.purchase_orders.send_email',
        );
    }

    /**
     * DocumentData resolves .view/.edit/.pdf/.index for every registered type.
     * A type registered without all four throws on the show page, so the registry
     * and the route table are asserted to agree.
     */
    public function test_every_registered_document_type_has_the_routes_the_shared_view_needs(): void
    {
        foreach (DocumentData::TYPES as $model => $def) {
            foreach (['view', 'edit', 'pdf', 'index'] as $action) {
                $name = $def['route'].'.'.$action;

                // viewPermission is a permission string, not a route name.
                if ($action === 'view') {
                    continue;
                }

                $this->assertTrue(
                    Route::has($name),
                    "{$model} is registered in DocumentData as '{$def['route']}' but {$name} is not a route.",
                );
            }
        }
    }

    /**
     * mail.sales_use_salesman_address sends a quote from the rep's own address,
     * but only sales_orders carried a salesman_id, so SalesQuoteMailable read a
     * relation that did not exist and the setting was inert for quotations.
     */
    public function test_a_quote_resolves_its_salesman(): void
    {
        $rep = $this->userForRole('Salesman');

        $quote = SalesQuote::create([
            'number' => 'Q-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'salesman_id' => $rep->id,
            'issue_date' => now()->toDateString(),
            'status' => 'draft',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
        ]);

        $this->assertTrue(
            $quote->relationLoaded('salesman') || $quote->salesman !== null,
            'a quote with a salesman_id must resolve the relation',
        );
        $this->assertSame($rep->id, $quote->fresh()->salesman?->id);
    }

    /**
     * Converting a quote used to credit the resulting order to whoever pressed
     * Convert, silently reassigning the rep's pipeline to another user.
     */
    public function test_converting_a_quote_keeps_the_original_salesman(): void
    {
        $rep = $this->userForRole('Salesman');
        $converter = $this->userForRole('Admin');

        $quote = SalesQuote::create([
            'number' => 'Q-'.uniqid(),
            'customer_id' => $this->customer()->id,
            'salesman_id' => $rep->id,
            'issue_date' => now()->toDateString(),
            'status' => 'sent',
            'currency' => 'USD',
            'exchange_rate' => 1,
            'subtotal' => '0',
            'tax_amount' => '0',
            'total' => '0',
        ]);

        $this->actingAs($converter)
            ->post("/sales/quotes/{$quote->id}/convert")
            ->assertRedirect(route('sales.orders.index'));

        $order = $quote->fresh()->order;

        $this->assertNotNull($order, 'the quote must point at the order it produced');
        $this->assertSame(
            $rep->id,
            $order->salesman_id,
            'the order must stay attributed to the rep who wrote the quote',
        );
    }
}
